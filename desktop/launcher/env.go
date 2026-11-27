package main

import (
	"bufio"
	"fmt"
	"os"
	"path/filepath"
	"runtime"
	"sort"
	"strings"
)

// reservedKeys are set by the launcher and cannot be changed in settings.env.
var reservedKeys = map[string]bool{
	"APP_KEY": true, "APP_URL": true, "DB_CONNECTION": true, "DB_DATABASE": true,
	"LARAVEL_STORAGE_PATH": true, "OPENQDA_DESKTOP": true, "OPENQDA_DATA": true,
	"OPENQDA_STATE_FILE": true, "OPENQDA_SHARED_PORT": true, "OPENQDA_LAN_PORT": true,
	"OPENQDA_COLLAB_PORT": true, "OPENQDA_CONTROL_URL": true, "OPENQDA_CONTROL_TOKEN": true,
	"OPENQDA_NODE_KEY": true, "PHP_INI_SCAN_DIR": true,
}

// AppEnv builds the environment for PHP (web server and artisan).
// Optional extra settings, for example the embeddings snippet for
// autocoding, can be added in <data>/settings.env.
func AppEnv(l Layout, cfg Config) (map[string]string, []string) {
	env := map[string]string{
		"APP_NAME":             "OpenQDA",
		"APP_ENV":              "production",
		"APP_DEBUG":            "false",
		"APP_KEY":              cfg.AppKey,
		"APP_URL":              fmt.Sprintf("http://127.0.0.1:%d", cfg.LocalPort),
		"LOG_CHANNEL":          "daily",
		"LOG_LEVEL":            "info",
		"DB_CONNECTION":        "sqlite",
		"DB_DATABASE":          l.DataFile("openqda.sqlite"),
		"DB_FOREIGN_KEYS":      "true",
		"SESSION_DRIVER":       "file",
		"SESSION_LIFETIME":     "480",
		"CACHE_DRIVER":         "file",
		"QUEUE_CONNECTION":     "sync",
		"BROADCAST_DRIVER":     "log",
		"MAIL_MAILER":          "log",
		"FILESYSTEM_DISK":      "local",
		"RTFFORMATS":           "docx,odt,rtf,doc,md,html",
		"AUDITING_ENABLED":     "true",
		"ALTCHA_HMAC_KEY":      cfg.AltchaKey,
		"LARAVEL_STORAGE_PATH": l.DataFile("storage"),
		"APP_SERVICES_CACHE":   l.DataFile("cache", "services.php"),
		"APP_PACKAGES_CACHE":   l.DataFile("cache", "packages.php"),
		"APP_CONFIG_CACHE":     l.DataFile("cache", "config.php"),
		"APP_ROUTES_CACHE":     l.DataFile("cache", "routes.php"),
		"APP_EVENTS_CACHE":     l.DataFile("cache", "events.php"),
		"OPENQDA_DESKTOP":      "true",
		"OPENQDA_DATA":         l.Data,
		"OPENQDA_STATE_FILE":   l.DataFile("state.json"),
		"OPENQDA_SHARED_PORT":  fmt.Sprint(cfg.SharedPort),
		"OPENQDA_LAN_PORT":     fmt.Sprint(cfg.LanPort),
		"PHP_INI_SCAN_DIR":     l.DataFile("php"),
		// laptop collaboration: the node agent and PHP talk over loopback only
		"OPENQDA_COLLAB_PORT":   fmt.Sprint(cfg.CollabPort),
		"OPENQDA_CONTROL_URL":   fmt.Sprintf("http://127.0.0.1:%d", cfg.ControlPort),
		"OPENQDA_CONTROL_TOKEN": cfg.ControlToken,
		"OPENQDA_NODE_KEY":      cfg.NodeKey,
	}
	if pandoc := l.Binary("pandoc"); isFile(pandoc) {
		env["PANDOC_PATH"] = pandoc
	}
	var warnings []string
	extra, err := ReadSettingsEnv(l.DataFile("settings.env"))
	if err != nil && !os.IsNotExist(err) {
		warnings = append(warnings, "settings.env: "+err.Error())
	}
	for k, v := range extra {
		if reservedKeys[k] {
			warnings = append(warnings, "settings.env: "+k+" is managed by OpenQDA and was ignored")
			continue
		}
		env[k] = v
	}
	return env, warnings
}

// ReadSettingsEnv parses KEY=VALUE lines; # starts a comment, quotes are optional.
func ReadSettingsEnv(path string) (map[string]string, error) {
	f, err := os.Open(path)
	if err != nil {
		return nil, err
	}
	defer f.Close()
	out := map[string]string{}
	sc := bufio.NewScanner(f)
	for line := 1; sc.Scan(); line++ {
		text := strings.TrimSpace(sc.Text())
		if text == "" || strings.HasPrefix(text, "#") {
			continue
		}
		text = strings.TrimPrefix(text, "export ")
		k, v, ok := strings.Cut(text, "=")
		k = strings.TrimSpace(k)
		if !ok || k == "" || strings.ContainsAny(k, " \t") {
			return out, fmt.Errorf("line %d is not KEY=VALUE", line)
		}
		v = strings.TrimSpace(v)
		if len(v) >= 2 && (v[0] == '"' && v[len(v)-1] == '"' || v[0] == '\'' && v[len(v)-1] == '\'') {
			v = v[1 : len(v)-1]
		} else if i := strings.Index(v, " #"); i >= 0 {
			v = strings.TrimSpace(v[:i])
		}
		out[k] = v
	}
	return out, sc.Err()
}

// EnvList merges the app environment into the launcher's own environment.
func EnvList(env map[string]string) []string {
	base := map[string]string{}
	for _, kv := range os.Environ() {
		if k, v, ok := strings.Cut(kv, "="); ok {
			base[k] = v
		}
	}
	for k, v := range env {
		base[k] = v
	}
	keys := make([]string, 0, len(base))
	for k := range base {
		keys = append(keys, k)
	}
	sort.Strings(keys)
	out := make([]string, 0, len(keys))
	for _, k := range keys {
		out = append(out, k+"="+base[k])
	}
	return out
}

// PHPIni writes the PHP settings. On Windows the official PHP build loads
// its extensions from runtime/ext; the macOS and Linux builds have them
// compiled in. OPcache is built into PHP 8.5 on every platform.
func PHPIni(l Layout) string {
	var b strings.Builder
	b.WriteString("; written by the OpenQDA launcher on every start\n")
	b.WriteString("memory_limit = 512M\nupload_max_filesize = 100M\npost_max_size = 110M\nmax_execution_time = 300\n")
	b.WriteString("expose_php = Off\ndisplay_errors = Off\nlog_errors = On\n")
	// PHP 8.5 deprecation notices from libraries are noise for end users
	b.WriteString("error_reporting = E_ALL & ~E_DEPRECATED\n")
	b.WriteString("opcache.enable = 1\nopcache.enable_cli = 0\nopcache.memory_consumption = 128\nopcache.validate_timestamps = 0\n")
	b.WriteString("date.timezone = UTC\n")
	if runtime.GOOS == "windows" {
		fmt.Fprintf(&b, "extension_dir = \"%s\"\n", filepath.Join(l.Runtime, "ext"))
		for _, ext := range []string{"bz2", "curl", "exif", "fileinfo", "gd", "intl", "mbstring", "openssl", "pdo_sqlite", "sqlite3", "sodium", "zip"} {
			b.WriteString("extension = " + ext + "\n")
		}
	}
	return b.String()
}
