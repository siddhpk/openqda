package main

import (
	"errors"
	"os"
	"path/filepath"
	"runtime"
)

// Layout describes where the installed program and the per-user data live.
//
// Installed program (read-only for the launcher):
//
//	<root>/app        the Laravel application (artisan, public/, vendor/)
//	<root>/runtime    frankenphp, pandoc and (Windows) the PHP files
//
// <root> is the folder of the launcher executable, or Contents/Resources
// inside a macOS .app bundle.
//
// Per-user data (database, uploads, logs, settings) lives outside the
// program folder so updates and uninstalls never touch it.
type Layout struct {
	Root    string
	App     string
	Runtime string
	Data    string
}

func exeSuffix() string {
	if runtime.GOOS == "windows" {
		return ".exe"
	}
	return ""
}

// Binary returns the path of a bundled executable in the runtime folder.
func (l Layout) Binary(name string) string {
	return filepath.Join(l.Runtime, name+exeSuffix())
}

func (l Layout) DataFile(parts ...string) string {
	return filepath.Join(append([]string{l.Data}, parts...)...)
}

// ResolveLayout finds the program root next to the executable (or in the
// macOS bundle) and the data folder (override with dataOverride / OPENQDA_DATA).
func ResolveLayout(exe, dataOverride string) (Layout, error) {
	dir := filepath.Dir(exe)
	candidates := []string{dir, filepath.Join(dir, "..", "Resources")}
	var root string
	for _, c := range candidates {
		if isFile(filepath.Join(c, "app", "artisan")) {
			root = filepath.Clean(c)
			break
		}
	}
	if root == "" {
		return Layout{}, errors.New("the OpenQDA program files were not found next to the launcher (expected app/artisan)")
	}
	data := dataOverride
	if data == "" {
		data = os.Getenv("OPENQDA_DATA")
	}
	if data == "" {
		data = defaultDataDir()
	}
	abs, err := filepath.Abs(data)
	if err != nil {
		return Layout{}, err
	}
	return Layout{Root: root, App: filepath.Join(root, "app"), Runtime: filepath.Join(root, "runtime"), Data: abs}, nil
}

func defaultDataDir() string {
	home, _ := os.UserHomeDir()
	switch runtime.GOOS {
	case "windows":
		if base := os.Getenv("LOCALAPPDATA"); base != "" {
			return filepath.Join(base, "OpenQDA")
		}
		return filepath.Join(home, "AppData", "Local", "OpenQDA")
	case "darwin":
		return filepath.Join(home, "Library", "Application Support", "OpenQDA")
	default:
		if base := os.Getenv("XDG_DATA_HOME"); base != "" {
			return filepath.Join(base, "openqda")
		}
		return filepath.Join(home, ".local", "share", "openqda")
	}
}

// EnsureDataDirs creates the folders Laravel and Caddy expect.
func (l Layout) EnsureDataDirs() error {
	for _, d := range []string{
		l.Data,
		l.DataFile("storage", "app", "public"),
		l.DataFile("storage", "framework", "cache", "data"),
		l.DataFile("storage", "framework", "sessions"),
		l.DataFile("storage", "framework", "views"),
		l.DataFile("storage", "logs"),
		l.DataFile("cache"),
		l.DataFile("php"),
		l.DataFile("caddy"),
	} {
		if err := os.MkdirAll(d, 0o700); err != nil {
			return err
		}
	}
	return nil
}

func isFile(p string) bool {
	st, err := os.Stat(p)
	return err == nil && !st.IsDir()
}
