//go:build windows

package main

import (
	"bytes"
	"crypto/sha256"
	"errors"
	"fmt"
	"net/http"
	"os"
	"os/exec"
	"path/filepath"
	"runtime"
	"strings"
	"syscall"
	"time"
	"unsafe"

	webview "github.com/jchv/go-webview2"
	"golang.org/x/sys/windows"
)

var (
	windowSetProp       = user32.NewProc("SetPropW")
	windowGetProp       = user32.NewProc("GetPropW")
	windowRemoveProp    = user32.NewProc("RemovePropW")
	windowEnum          = user32.NewProc("EnumWindows")
	windowGetProcessID  = user32.NewProc("GetWindowThreadProcessId")
	windowIsIconic      = user32.NewProc("IsIconic")
	windowShow          = user32.NewProc("ShowWindow")
	windowSetForeground = user32.NewProc("SetForegroundWindow")
	enumAppWindow       = syscall.NewCallback(func(hwnd uintptr, search *appWindowSearch) uintptr {
		value, _, _ := windowGetProp.Call(hwnd, uintptr(unsafe.Pointer(search.prop)))
		if value != 0 {
			search.found = hwnd
			return 0
		}
		return 1
	})
)

type appWindowSearch struct {
	prop  *uint16
	found uintptr
}

func startAppWindow(exe string, layout Layout, _ string, wait bool, onFailure func(error)) error {
	cmd := exec.Command(exe, "--window", "--data", layout.Data)
	var stderr bytes.Buffer
	cmd.Stderr = &stderr
	cmd.SysProcAttr = &syscall.SysProcAttr{CreationFlags: createNoWindow}
	result := func(err error) error {
		if err != nil && stderr.Len() > 0 {
			return fmt.Errorf("%w: %s", err, strings.TrimSpace(stderr.String()))
		}
		return err
	}
	if wait {
		return result(cmd.Run())
	}
	if err := cmd.Start(); err != nil {
		return err
	}
	go func() {
		if err := cmd.Wait(); err != nil && onFailure != nil {
			onFailure(result(err))
		}
	}()
	return nil
}

func runAppWindow(dataDir string) error {
	exe, err := os.Executable()
	if err != nil {
		return err
	}
	layout, err := ResolveLayout(exe, dataDir)
	if err != nil {
		return err
	}
	cfg, _, err := LoadOrCreateConfig(layout.DataFile("config.json"))
	if err != nil {
		return err
	}
	if !AlreadyRunning(cfg) {
		return errors.New("the OpenQDA server is not running")
	}
	closed, err := showAppWindow(layout, cfg)
	if err != nil || !closed {
		return err
	}
	return RequestShutdown(layout.DataFile("state.json"))
}

// showAppWindow returns true only for the process that owned a closed window.
func showAppWindow(layout Layout, cfg Config) (bool, error) {
	runtime.LockOSThread()
	defer runtime.UnlockOSThread()

	key := appWindowKey(layout.Data)
	name, err := windows.UTF16PtrFromString("Local\\" + key)
	if err != nil {
		return false, err
	}
	handle, err := windows.CreateMutex(nil, false, name)
	if handle != 0 {
		defer windows.CloseHandle(handle)
	}
	if errors.Is(err, windows.ERROR_ALREADY_EXISTS) {
		return false, focusAppWindow(key)
	}
	if err != nil {
		return false, fmt.Errorf("create window lock: %w", err)
	}

	w := webview.NewWithOptions(webview.WebViewOptions{
		DataPath:  layout.DataFile("browser"),
		AutoFocus: true,
		WindowOptions: webview.WindowOptions{
			Title: "OpenQDA", Width: 1200, Height: 800,
		},
	})
	if w == nil {
		return false, errors.New("could not start the Windows WebView2 runtime")
	}
	defer w.Destroy()
	prop, err := windows.UTF16PtrFromString(key)
	if err != nil {
		return false, err
	}
	hwnd := uintptr(w.Window())
	if ok, _, callErr := windowSetProp.Call(hwnd, uintptr(unsafe.Pointer(prop)), 1); ok == 0 {
		return false, fmt.Errorf("register the OpenQDA window: %w", callErr)
	}
	defer windowRemoveProp.Call(hwnd, uintptr(unsafe.Pointer(prop)))

	w.Navigate(fmt.Sprintf("http://127.0.0.1:%d", cfg.LocalPort))
	done := make(chan struct{})
	defer close(done)
	go closeWindowWhenServerStops(w, cfg, done)
	w.Run()
	return true, nil
}

func focusAppWindow(key string) error {
	for range 60 {
		found, err := findAppWindow(key)
		if err != nil {
			return err
		}
		if found != 0 {
			if iconic, _, _ := windowIsIconic.Call(found); iconic != 0 {
				windowShow.Call(found, 9) // SW_RESTORE
			}
			windowSetForeground.Call(found)
			return nil
		}
		time.Sleep(250 * time.Millisecond)
	}
	return errors.New("the existing OpenQDA window did not appear")
}

func appWindowKey(data string) string {
	sum := sha256.Sum256([]byte(strings.ToLower(filepath.Clean(data))))
	return fmt.Sprintf("OpenQDA.Window.%x", sum[:12])
}

func findAppWindow(key string) (uintptr, error) {
	prop, err := windows.UTF16PtrFromString(key)
	if err != nil {
		return 0, err
	}
	search := &appWindowSearch{prop: prop}
	ok, _, callErr := windowEnum.Call(enumAppWindow, uintptr(unsafe.Pointer(search)))
	runtime.KeepAlive(search)
	if ok == 0 && search.found == 0 {
		return 0, fmt.Errorf("find the OpenQDA window: %w", callErr)
	}
	return search.found, nil
}

func closeAppWindow(data string) error {
	hwnd, err := findAppWindow(appWindowKey(data))
	if err != nil || hwnd == 0 {
		return err
	}
	var pid uint32
	windowGetProcessID.Call(hwnd, uintptr(unsafe.Pointer(&pid)))
	process, err := windows.OpenProcess(windows.SYNCHRONIZE, false, pid)
	if err != nil {
		return fmt.Errorf("open window process: %w", err)
	}
	defer windows.CloseHandle(process)
	if ok, _, callErr := postMessage.Call(hwnd, wmClose, 0, 0); ok == 0 {
		return fmt.Errorf("close the OpenQDA window: %w", callErr)
	}
	result, err := windows.WaitForSingleObject(process, 5000)
	if err != nil {
		return fmt.Errorf("wait for the OpenQDA window: %w", err)
	}
	if result != windows.WAIT_OBJECT_0 {
		return errors.New("the OpenQDA window did not close in time")
	}
	return nil
}

func closeWindowWhenServerStops(w webview.WebView, cfg Config, done <-chan struct{}) {
	client := &http.Client{Timeout: 2 * time.Second, CheckRedirect: func(*http.Request, []*http.Request) error { return http.ErrUseLastResponse }}
	ticker := time.NewTicker(2 * time.Second)
	defer ticker.Stop()
	var offlineSince time.Time
	for {
		select {
		case <-done:
			return
		case <-ticker.C:
			resp, err := client.Get(fmt.Sprintf("http://127.0.0.1:%d/", cfg.LocalPort))
			if err == nil {
				resp.Body.Close()
				offlineSince = time.Time{}
			} else if offlineSince.IsZero() {
				offlineSince = time.Now()
			} else if time.Since(offlineSince) >= 20*time.Second {
				w.Dispatch(w.Terminate)
				return
			}
		}
	}
}
