//go:build windows

package main

import (
	"fmt"
	"path/filepath"
	"runtime"
	"syscall"
	"time"
	"unsafe"

	"golang.org/x/sys/windows"
)

var (
	user32                 = windows.NewLazySystemDLL("user32.dll")
	shell32                = windows.NewLazySystemDLL("shell32.dll")
	kernel32               = windows.NewLazySystemDLL("kernel32.dll")
	registerClassEx        = user32.NewProc("RegisterClassExW")
	unregisterClass        = user32.NewProc("UnregisterClassW")
	createWindowEx         = user32.NewProc("CreateWindowExW")
	destroyWindow          = user32.NewProc("DestroyWindow")
	defWindowProc          = user32.NewProc("DefWindowProcW")
	getMessage             = user32.NewProc("GetMessageW")
	translateMessage       = user32.NewProc("TranslateMessage")
	dispatchMessage        = user32.NewProc("DispatchMessageW")
	postMessage            = user32.NewProc("PostMessageW")
	postQuitMessage        = user32.NewProc("PostQuitMessage")
	registerWindowMessage  = user32.NewProc("RegisterWindowMessageW")
	loadImage              = user32.NewProc("LoadImageW")
	destroyIcon            = user32.NewProc("DestroyIcon")
	createPopupMenu        = user32.NewProc("CreatePopupMenu")
	appendMenu             = user32.NewProc("AppendMenuW")
	destroyMenu            = user32.NewProc("DestroyMenu")
	getCursorPos           = user32.NewProc("GetCursorPos")
	setForegroundWindow    = user32.NewProc("SetForegroundWindow")
	trackPopupMenu         = user32.NewProc("TrackPopupMenu")
	shellNotifyIcon        = shell32.NewProc("Shell_NotifyIconW")
	getModuleHandle        = kernel32.NewProc("GetModuleHandleW")
	trayWindowProcCallback = syscall.NewCallback(trayWindowProc)
	activeTray             *windowsTray
)

const (
	wmDestroy       = 0x0002
	wmClose         = 0x0010
	wmCommand       = 0x0111
	wmLeftButtonUp  = 0x0202
	wmRightButtonUp = 0x0205
	wmTray          = 0x8001
	trayOpen        = 1
	trayExit        = 2
)

type windowClass struct {
	size, style             uint32
	windowProc              uintptr
	classExtra, windowExtra int32
	instance, icon, cursor  uintptr
	background              uintptr
	menuName, className     *uint16
	smallIcon               uintptr
}

type trayPoint struct{ x, y int32 }

type windowMessage struct {
	window  uintptr
	message uint32
	wparam  uintptr
	lparam  uintptr
	time    uint32
	point   trayPoint
	private uint32
}

type notificationIcon struct {
	size                uint32
	window              uintptr
	id, flags, callback uint32
	icon                uintptr
	tip                 [128]uint16
	state, stateMask    uint32
	info                [256]uint16
	version             uint32
	infoTitle           [64]uint16
	infoFlags           uint32
	guid                [16]byte
	balloonIcon         uintptr
}

type windowsTray struct {
	window, menu, icon uintptr
	taskbarCreated     uint32
	open, exit         func()
	report             func(error)
}

type trayResult struct {
	window uintptr
	err    error
}

func (t *windowsTray) notify(action uintptr) bool {
	data := notificationIcon{
		size:     uint32(unsafe.Sizeof(notificationIcon{})),
		window:   t.window,
		id:       1,
		flags:    0x1 | 0x2 | 0x4,
		callback: wmTray,
		icon:     t.icon,
	}
	copy(data.tip[:], windows.StringToUTF16("OpenQDA"))
	ok, _, _ := shellNotifyIcon.Call(action, uintptr(unsafe.Pointer(&data)))
	return ok != 0
}

func trayWindowProc(hwnd uintptr, message uint32, wparam, lparam uintptr) uintptr {
	t := activeTray
	if t != nil {
		switch {
		case message == t.taskbarCreated && t.taskbarCreated != 0:
			if !t.notify(0) {
				t.report(fmt.Errorf("Windows could not restore the system tray icon"))
			}
			return 0
		case message == wmTray && lparam == wmLeftButtonUp:
			go t.open()
			return 0
		case message == wmTray && lparam == wmRightButtonUp:
			var point trayPoint
			if ok, _, err := getCursorPos.Call(uintptr(unsafe.Pointer(&point))); ok == 0 {
				t.report(fmt.Errorf("open system tray menu: %w", err))
			} else {
				setForegroundWindow.Call(hwnd)
				trackPopupMenu.Call(t.menu, 0x0002, uintptr(point.x), uintptr(point.y), 0, hwnd, 0)
			}
			return 0
		case message == wmCommand:
			switch wparam & 0xffff {
			case trayOpen:
				go t.open()
			case trayExit:
				t.exit()
			}
			return 0
		case message == wmClose:
			postQuitMessage.Call(0)
			return 0
		case message == wmDestroy:
			postQuitMessage.Call(0)
			return 0
		}
	}
	result, _, _ := defWindowProc.Call(hwnd, uintptr(message), wparam, lparam)
	return result
}

func startTray(root string, open, exit func(), report func(error)) (func(), error) {
	ready := make(chan trayResult, 1)
	done := make(chan struct{})
	go func() {
		runtime.LockOSThread()
		defer runtime.UnlockOSThread()
		defer close(done)
		runTray(root, open, exit, report, ready)
	}()
	res := <-ready
	if res.err != nil {
		return func() {}, res.err
	}
	return func() {
		if ok, _, err := postMessage.Call(res.window, wmClose, 0, 0); ok == 0 {
			report(fmt.Errorf("close system tray: %w", err))
			return
		}
		select {
		case <-done:
		case <-time.After(5 * time.Second):
			report(fmt.Errorf("system tray did not close in time"))
		}
	}, nil
}

func runTray(root string, open, exit func(), report func(error), ready chan<- trayResult) {
	className := windows.StringToUTF16Ptr("OpenQDANotificationWindow")
	instance, _, err := getModuleHandle.Call(0)
	if instance == 0 {
		ready <- trayResult{err: fmt.Errorf("get application instance: %w", err)}
		return
	}
	class := windowClass{
		size:       uint32(unsafe.Sizeof(windowClass{})),
		windowProc: trayWindowProcCallback,
		instance:   instance,
		className:  className,
	}
	if atom, _, err := registerClassEx.Call(uintptr(unsafe.Pointer(&class))); atom == 0 {
		ready <- trayResult{err: fmt.Errorf("register system tray window: %w", err)}
		return
	}
	defer unregisterClass.Call(uintptr(unsafe.Pointer(className)), instance)

	t := &windowsTray{open: open, exit: exit, report: report}
	activeTray = t
	defer func() { activeTray = nil }()
	t.window, _, err = createWindowEx.Call(0, uintptr(unsafe.Pointer(className)), uintptr(unsafe.Pointer(className)),
		0, 0, 0, 0, 0, 0, 0, instance, 0)
	if t.window == 0 {
		ready <- trayResult{err: fmt.Errorf("create system tray window: %w", err)}
		return
	}
	defer destroyWindow.Call(t.window)
	iconPath, err := windows.UTF16PtrFromString(filepath.Join(root, "openqda.ico"))
	if err != nil {
		ready <- trayResult{err: err}
		return
	}
	t.icon, _, err = loadImage.Call(0, uintptr(unsafe.Pointer(iconPath)), 1, 0, 0, 0x10|0x40)
	if t.icon == 0 {
		ready <- trayResult{err: fmt.Errorf("load system tray icon: %w", err)}
		return
	}
	defer destroyIcon.Call(t.icon)
	t.menu, _, err = createPopupMenu.Call()
	if t.menu == 0 {
		ready <- trayResult{err: fmt.Errorf("create system tray menu: %w", err)}
		return
	}
	defer destroyMenu.Call(t.menu)
	for _, item := range []struct {
		id    uintptr
		label string
	}{{trayOpen, "Open OpenQDA"}, {trayExit, "Exit OpenQDA"}} {
		label := windows.StringToUTF16Ptr(item.label)
		if ok, _, err := appendMenu.Call(t.menu, 0, item.id, uintptr(unsafe.Pointer(label))); ok == 0 {
			ready <- trayResult{err: fmt.Errorf("create system tray menu item: %w", err)}
			return
		}
	}
	taskbarMessage := windows.StringToUTF16Ptr("TaskbarCreated")
	message, _, _ := registerWindowMessage.Call(uintptr(unsafe.Pointer(taskbarMessage)))
	t.taskbarCreated = uint32(message)
	if !t.notify(0) {
		ready <- trayResult{err: fmt.Errorf("Windows could not add the system tray icon")}
		return
	}
	defer t.notify(2)
	ready <- trayResult{window: t.window}
	var msg windowMessage
	for {
		status, _, err := getMessage.Call(uintptr(unsafe.Pointer(&msg)), 0, 0, 0)
		if int32(status) == -1 {
			report(fmt.Errorf("system tray message loop: %w", err))
			return
		}
		if status == 0 {
			return
		}
		translateMessage.Call(uintptr(unsafe.Pointer(&msg)))
		dispatchMessage.Call(uintptr(unsafe.Pointer(&msg)))
	}
}
