// Command OpenQDA is the launcher of the OpenQDA laptop edition.
//
// Double-click: start OpenQDA in the background (first start sets up the
// database) and open it in the default browser. Starting it again while it
// runs only opens the browser.
//
//	OpenQDA --background   start without opening the browser (autostart)
//	OpenQDA --stop         shut a running OpenQDA down
//	OpenQDA --data DIR     use another data folder (default: per-user folder)
package main

import (
	"encoding/json"
	"flag"
	"fmt"
	"io"
	"os"
	"os/exec"
	"os/signal"
	"path/filepath"
	"runtime"
	"strings"
	"syscall"
	"time"
)

var version = "dev"

func main() {
	background := flag.Bool("background", false, "start without opening the browser")
	stop := flag.Bool("stop", false, "shut down a running OpenQDA")
	dataDir := flag.String("data", "", "data folder")
	showVersion := flag.Bool("version", false, "print the version")
	flag.Parse()

	if *showVersion {
		fmt.Println("OpenQDA launcher", version)
		return
	}
	if err := run(*background, *stop, *dataDir); err != nil {
		ShowError(err.Error())
		os.Exit(1)
	}
}

func run(background, stop bool, dataDir string) error {
	exe, err := os.Executable()
	if err != nil {
		return err
	}
	if resolved, err := filepath.EvalSymlinks(exe); err == nil {
		exe = resolved
	}
	layout, err := ResolveLayout(exe, dataDir)
	if err != nil {
		return err
	}
	if err := layout.EnsureDataDirs(); err != nil {
		return fmt.Errorf("could not create the data folder %s: %w", layout.Data, err)
	}
	logFile := openLog(layout.DataFile("storage", "logs", "launcher.log"))
	defer logFile.Close()
	logf := func(format string, args ...any) {
		fmt.Fprintf(logFile, time.Now().Format("2006-01-02 15:04:05 ")+format+"\n", args...)
	}
	statePath := layout.DataFile("state.json")

	if stop {
		logf("stop requested from the command line")
		return RequestShutdown(statePath)
	}

	cfg, created, err := LoadOrCreateConfig(layout.DataFile("config.json"))
	if err != nil {
		return err
	}
	if AlreadyRunning(cfg) {
		logf("already running, opening the browser")
		if !background {
			return OpenBrowser(fmt.Sprintf("http://127.0.0.1:%d", cfg.LocalPort))
		}
		return nil
	}
	logf("starting OpenQDA %s (data: %s, first start: %v)", version, layout.Data, created)

	env, warnings := AppEnv(layout, cfg)
	for _, w := range warnings {
		logf("warning: %s", w)
	}
	if err := os.WriteFile(layout.DataFile("php", "openqda.ini"), []byte(PHPIni(layout)), 0o600); err != nil {
		return err
	}
	if db := layout.DataFile("openqda.sqlite"); !isFile(db) {
		if err := os.WriteFile(db, nil, 0o600); err != nil {
			return err
		}
	}
	envList := EnvList(env)

	// creates the tables on first start and applies updates after an upgrade
	if err := Artisan(layout, envList, logFile, "migrate", "--force", "--no-interaction"); err != nil {
		return fmt.Errorf("setting up the database failed.\n\n%v", err)
	}

	state := ReadAppState(statePath)
	autostart := state.Autostart
	if err := SetAutostart(exe, autostart); err != nil {
		logf("warning: autostart: %v", err)
	}

	collab, err := newCollabRuntime(layout, cfg, logf)
	if err != nil {
		return err
	}
	server := &Server{Layout: layout, Config: cfg, Env: envList, Log: logFile}
	if err := server.Start(state.Sharing.Mode == "lan"); err != nil {
		collab.stop()
		return err
	}
	if err := server.WaitHealthy(60 * time.Second); err != nil {
		server.Stop()
		collab.stop()
		return err
	}
	logf("listening on %s", server.LocalURL())
	collab.start()
	if !background {
		if err := OpenBrowser(server.LocalURL()); err != nil {
			logf("warning: could not open the browser: %v", err)
		}
	}

	err = supervise(server, layout, exe, statePath, autostart, logf)
	collab.stop()
	return err
}

func supervise(server *Server, layout Layout, exe, statePath string, autostart bool, logf func(string, ...any)) error {
	started := time.Now()
	signals := make(chan os.Signal, 1)
	signal.Notify(signals, os.Interrupt, syscall.SIGTERM)
	ticker := time.NewTicker(2 * time.Second)
	defer ticker.Stop()
	var crashes []time.Time

	for {
		select {
		case sig := <-signals:
			// computer shutting down or Ctrl+C: leave the sharing settings as they are
			logf("received %v, stopping", sig)
			server.Stop()
			return nil

		case err := <-server.Exited():
			logf("web server exited unexpectedly: %v", err)
			crashes = append(crashes, time.Now())
			if len(crashes) > 3 && time.Since(crashes[len(crashes)-4]) < 10*time.Minute {
				return fmt.Errorf("the web server keeps stopping; see %s", layout.DataFile("storage", "logs"))
			}
			if err := server.Start(server.LAN()); err != nil {
				return err
			}

		case <-ticker.C:
			state := ReadAppState(statePath)
			if state.ShutdownRequestedAfter(started) {
				logf("shutdown requested")
				if state.Sharing.Mode == "internet" {
					StopFunnel()
				}
				_ = StopSharingInState(statePath, "shutdown")
				server.Stop()
				return nil
			}
			if wantLAN := state.Sharing.Mode == "lan"; wantLAN != server.LAN() {
				logf("sharing on this network: %v, restarting the web server", wantLAN)
				server.Stop()
				if err := server.Start(wantLAN); err != nil {
					return err
				}
				if err := server.WaitHealthy(30 * time.Second); err != nil {
					logf("warning: %v", err)
				}
			}
			if state.Autostart != autostart {
				autostart = state.Autostart
				if err := SetAutostart(exe, autostart); err != nil {
					logf("warning: autostart: %v", err)
				}
			}
		}
	}
}

// RequestShutdown asks a running launcher to stop (it checks every 2 s).
func RequestShutdown(path string) error {
	data := map[string]any{}
	if raw, err := os.ReadFile(path); err == nil {
		_ = json.Unmarshal(raw, &data)
	}
	data["shutdown"] = time.Now().UTC().Format(time.RFC3339)
	out, err := json.MarshalIndent(data, "", "    ")
	if err != nil {
		return err
	}
	return writeFileAtomic(path, out, 0o600)
}

// ShowError tells the user what went wrong; there is no console window.
func ShowError(msg string) {
	fmt.Fprintln(os.Stderr, "OpenQDA:", msg)
	text := "OpenQDA could not start.\n\n" + msg
	var cmd *exec.Cmd
	switch runtime.GOOS {
	case "windows":
		ps := "Add-Type -AssemblyName PresentationFramework; [System.Windows.MessageBox]::Show($env:OPENQDA_MESSAGE, 'OpenQDA') | Out-Null"
		cmd = exec.Command("powershell", "-NoProfile", "-NonInteractive", "-Command", ps)
	case "darwin":
		cmd = exec.Command("osascript", "-e", `display alert "OpenQDA" message (system attribute "OPENQDA_MESSAGE") as critical`)
	default:
		if _, err := exec.LookPath("zenity"); err != nil {
			return
		}
		cmd = exec.Command("zenity", "--error", "--title=OpenQDA", "--text="+strings.ReplaceAll(text, "&", "and"))
	}
	cmd.Env = append(os.Environ(), "OPENQDA_MESSAGE="+text)
	cmd.Stdout = io.Discard
	hideWindow(cmd)
	_ = cmd.Run()
}
