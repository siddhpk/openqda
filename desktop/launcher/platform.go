package main

import (
	"fmt"
	"os"
	"os/exec"
	"path/filepath"
	"runtime"
	"strings"
)

// OpenBrowser opens url in the default browser.
func OpenBrowser(url string) error {
	var cmd *exec.Cmd
	switch runtime.GOOS {
	case "windows":
		cmd = exec.Command("rundll32", "url.dll,FileProtocolHandler", url)
	case "darwin":
		cmd = exec.Command("open", url)
	default:
		cmd = exec.Command("xdg-open", url)
	}
	hideWindow(cmd)
	return cmd.Start()
}

// SetAutostart starts OpenQDA (in the background, no browser) when the user
// signs in. Windows: HKCU Run key; macOS: LaunchAgent; Linux: XDG autostart.
func SetAutostart(exe string, on bool) error {
	switch runtime.GOOS {
	case "windows":
		key := `HKCU\Software\Microsoft\Windows\CurrentVersion\Run`
		var cmd *exec.Cmd
		if on {
			cmd = exec.Command("reg", "add", key, "/v", "OpenQDA", "/t", "REG_SZ", "/d", fmt.Sprintf(`"%s" --background`, exe), "/f")
		} else {
			cmd = exec.Command("reg", "delete", key, "/v", "OpenQDA", "/f")
		}
		hideWindow(cmd)
		if err := cmd.Run(); err != nil && on {
			return err
		}
		return nil
	case "darwin":
		home, _ := os.UserHomeDir()
		plist := filepath.Join(home, "Library", "LaunchAgents", "org.openqda.desktop.plist")
		if !on {
			os.Remove(plist)
			return nil
		}
		if err := os.MkdirAll(filepath.Dir(plist), 0o755); err != nil {
			return err
		}
		content := `<?xml version="1.0" encoding="UTF-8"?>
<!DOCTYPE plist PUBLIC "-//Apple//DTD PLIST 1.0//EN" "http://www.apple.com/DTDs/PropertyList-1.0.dtd">
<plist version="1.0">
<dict>
	<key>Label</key><string>org.openqda.desktop</string>
	<key>ProgramArguments</key>
	<array><string>` + xmlEscape(exe) + `</string><string>--background</string></array>
	<key>RunAtLoad</key><true/>
</dict>
</plist>
`
		return os.WriteFile(plist, []byte(content), 0o644)
	default:
		cfgHome := os.Getenv("XDG_CONFIG_HOME")
		if cfgHome == "" {
			home, _ := os.UserHomeDir()
			cfgHome = filepath.Join(home, ".config")
		}
		file := filepath.Join(cfgHome, "autostart", "openqda.desktop")
		if !on {
			os.Remove(file)
			return nil
		}
		if err := os.MkdirAll(filepath.Dir(file), 0o755); err != nil {
			return err
		}
		return os.WriteFile(file, []byte("[Desktop Entry]\nType=Application\nName=OpenQDA\nExec=\""+exe+"\" --background\nX-GNOME-Autostart-enabled=true\n"), 0o644)
	}
}

func xmlEscape(s string) string {
	r := strings.NewReplacer("&", "&amp;", "<", "&lt;", ">", "&gt;", `"`, "&quot;")
	return r.Replace(s)
}

// tailscaleCLI mirrors the search in app/Services/Desktop/Tailscale.php.
func tailscaleCLI() string {
	for _, p := range []string{
		`C:\Program Files\Tailscale\tailscale.exe`,
		"/Applications/Tailscale.app/Contents/MacOS/Tailscale",
		"/opt/homebrew/bin/tailscale",
		"/usr/local/bin/tailscale",
		"/usr/bin/tailscale",
	} {
		if isFile(p) {
			return p
		}
	}
	if p, err := exec.LookPath("tailscale"); err == nil {
		return p
	}
	return ""
}

// StopFunnel turns the internet link off when OpenQDA quits while sharing.
func StopFunnel() {
	if cli := tailscaleCLI(); cli != "" {
		cmd := exec.Command(cli, "funnel", "--https=443", "off")
		hideWindow(cmd)
		_ = cmd.Run()
	}
}
