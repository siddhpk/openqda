package main

import (
	"encoding/json"
	"os"
	"path/filepath"
	"strings"
	"testing"
	"time"

	"github.com/openqda/openqda/desktop/launcher/cluster"
)

func fakeInstall(t *testing.T, macBundle bool) (exe string, root string) {
	t.Helper()
	base := t.TempDir()
	root = base
	exeDir := base
	if macBundle {
		exeDir = filepath.Join(base, "OpenQDA.app", "Contents", "MacOS")
		root = filepath.Join(base, "OpenQDA.app", "Contents", "Resources")
	}
	for _, d := range []string{exeDir, filepath.Join(root, "app"), filepath.Join(root, "runtime")} {
		if err := os.MkdirAll(d, 0o755); err != nil {
			t.Fatal(err)
		}
	}
	os.WriteFile(filepath.Join(root, "app", "artisan"), []byte("<?php"), 0o644)
	return filepath.Join(exeDir, "OpenQDA"), root
}

func TestResolveLayoutFindsProgramFilesNextToExeAndInMacBundle(t *testing.T) {
	for _, mac := range []bool{false, true} {
		exe, root := fakeInstall(t, mac)
		data := filepath.Join(t.TempDir(), "data")
		l, err := ResolveLayout(exe, data)
		if err != nil {
			t.Fatal(err)
		}
		if l.Root != root || l.App != filepath.Join(root, "app") || l.Data != data {
			t.Fatalf("unexpected layout %+v", l)
		}
		if err := l.EnsureDataDirs(); err != nil {
			t.Fatal(err)
		}
		if !dirExists(l.DataFile("storage", "framework", "views")) {
			t.Fatal("storage folders were not created")
		}
	}
	if _, err := ResolveLayout(filepath.Join(t.TempDir(), "OpenQDA"), ""); err == nil {
		t.Fatal("expected an error without program files")
	}
}

func dirExists(p string) bool {
	st, err := os.Stat(p)
	return err == nil && st.IsDir()
}

func TestConfigIsCreatedOnceWithSecretsAndDistinctPorts(t *testing.T) {
	path := filepath.Join(t.TempDir(), "config.json")
	cfg, created, err := LoadOrCreateConfig(path)
	if err != nil || !created {
		t.Fatalf("created=%v err=%v", created, err)
	}
	if !strings.HasPrefix(cfg.AppKey, "base64:") || len(cfg.AppKey) != 51 || len(cfg.AltchaKey) != 48 {
		t.Fatalf("bad secrets: %q %q", cfg.AppKey, cfg.AltchaKey)
	}
	ports := map[int]bool{cfg.LocalPort: true, cfg.SharedPort: true, cfg.LanPort: true, cfg.AdminPort: true}
	if len(ports) != 4 || ports[0] {
		t.Fatalf("ports not distinct: %+v", cfg)
	}
	again, created2, err := LoadOrCreateConfig(path)
	if err != nil || created2 || again != cfg {
		t.Fatalf("config changed on second load: %+v vs %+v (created=%v)", again, cfg, created2)
	}
	if st, _ := os.Stat(path); st.Mode().Perm()&0o077 != 0 && os.PathSeparator == '/' {
		t.Fatalf("config.json is readable by others: %v", st.Mode())
	}
}

func TestSettingsEnvAddsSettingsButCannotOverrideManagedOnes(t *testing.T) {
	exe, _ := fakeInstall(t, false)
	l, _ := ResolveLayout(exe, t.TempDir())
	os.WriteFile(l.DataFile("settings.env"), []byte(`# embeddings for autocoding
AI_ENABLED=true
AI_EMBEDDING_MODEL="nomic-embed-text"
export OLLAMA_BASE_URL=http://127.0.0.1:11434 # local
DB_DATABASE=/tmp/elsewhere.sqlite
`), 0o600)
	env, warnings := AppEnv(l, Config{AppKey: "base64:x", LocalPort: 8765, SharedPort: 9001, LanPort: 8766})
	if env["AI_ENABLED"] != "true" || env["AI_EMBEDDING_MODEL"] != "nomic-embed-text" || env["OLLAMA_BASE_URL"] != "http://127.0.0.1:11434" {
		t.Fatalf("settings not applied: %v", env)
	}
	if env["DB_DATABASE"] != l.DataFile("openqda.sqlite") || len(warnings) != 1 {
		t.Fatalf("managed key overridden or no warning: %q %v", env["DB_DATABASE"], warnings)
	}
	if env["RTFFORMATS"] != "docx,odt,rtf,doc,md,html" {
		t.Fatalf("document formats for upload missing: %q", env["RTFFORMATS"])
	}
	if env["OPENQDA_DESKTOP"] != "true" || env["APP_URL"] != "http://127.0.0.1:8765" || env["OPENQDA_SHARED_PORT"] != "9001" {
		t.Fatalf("desktop settings missing: %v", env)
	}
	if _, err := ReadSettingsEnv(writeTemp(t, "NOT A SETTING\n")); err == nil {
		t.Fatal("expected a parse error")
	}
}

func writeTemp(t *testing.T, content string) string {
	p := filepath.Join(t.TempDir(), "x.env")
	os.WriteFile(p, []byte(content), 0o600)
	return p
}

func TestCaddyfileSeparatesChannelsAndOpensTheNetworkOnlyWhenAsked(t *testing.T) {
	l := Layout{Root: "/opt/oq", App: "/opt/oq/app", Runtime: "/opt/oq/runtime", Data: "/home/u/.local/share/openqda"}
	cfg := Config{LocalPort: 8765, SharedPort: 9001, LanPort: 8766, AdminPort: 9002}
	off := Caddyfile(l, cfg, false)
	for _, want := range []string{
		"admin 127.0.0.1:9002",
		"http://127.0.0.1:8765, http://localhost:8765 {\n\tbind 127.0.0.1\n\timport openqda local",
		"http://:9001 {\n\tbind 127.0.0.1\n\timport openqda shared",
		"env OPENQDA_CHANNEL {args[0]}",
		`root * "/opt/oq/app/public"`,
		`root * "/home/u/.local/share/openqda/storage/app/public"`,
	} {
		if !strings.Contains(off, want) {
			t.Fatalf("Caddyfile lacks %q:\n%s", want, off)
		}
	}
	if strings.Contains(off, "0.0.0.0") || strings.Contains(off, "openqda lan") {
		t.Fatal("network listener present while sharing on this network is off")
	}
	on := Caddyfile(l, cfg, true)
	if !strings.Contains(on, "http://:8766 {\n\tbind 0.0.0.0\n\timport openqda lan") {
		t.Fatalf("network listener missing:\n%s", on)
	}
}

func TestStateFileHandling(t *testing.T) {
	path := filepath.Join(t.TempDir(), "state.json")
	if s := ReadAppState(path); s.Sharing.Mode != "off" || s.ShutdownRequestedAfter(time.Now().Add(-time.Hour)) {
		t.Fatalf("defaults wrong: %+v", s)
	}
	started := time.Now().Add(-time.Minute)
	os.WriteFile(path, []byte(`{"sharing":{"mode":"internet","code":"secret"},"autostart":true,"shutdown":"`+time.Now().Add(-time.Hour).Format(time.RFC3339)+`"}`), 0o600)
	s := ReadAppState(path)
	if s.Sharing.Mode != "internet" || !s.Autostart || s.ShutdownRequestedAfter(started) {
		t.Fatalf("stale shutdown treated as new or fields missing: %+v", s)
	}
	if err := RequestShutdown(path); err != nil {
		t.Fatal(err)
	}
	if !ReadAppState(path).ShutdownRequestedAfter(started) {
		t.Fatal("shutdown request not seen")
	}
	if err := StopSharingInState(path, "shutdown"); err != nil {
		t.Fatal(err)
	}
	var raw map[string]any
	b, _ := os.ReadFile(path)
	json.Unmarshal(b, &raw)
	sharing := raw["sharing"].(map[string]any)
	if sharing["mode"] != "off" || sharing["code"] != nil || raw["autostart"] != true {
		t.Fatalf("unexpected state after stop: %v", raw)
	}
}

func TestPHPIniLimits(t *testing.T) {
	ini := PHPIni(Layout{Runtime: "/opt/oq/runtime"})
	for _, want := range []string{"upload_max_filesize = 100M", "post_max_size = 110M", "expose_php = Off"} {
		if !strings.Contains(ini, want) {
			t.Fatalf("php ini lacks %q", want)
		}
	}
}

func TestConfigGainsCollaborationSecretsAndPortsOnUpgrade(t *testing.T) {
	path := filepath.Join(t.TempDir(), "config.json")
	// a config.json written by the first laptop edition
	old := `{"app_key":"base64:` + strings.Repeat("A", 43) + `=","altcha_key":"k","local_port":8765,"shared_port":9001,"lan_port":8766,"admin_port":9002}`
	if err := os.WriteFile(path, []byte(old), 0o600); err != nil {
		t.Fatal(err)
	}
	cfg, created, err := LoadOrCreateConfig(path)
	if err != nil || !created {
		t.Fatalf("created=%v err=%v", created, err)
	}
	if cfg.LocalPort != 8765 || cfg.SharedPort != 9001 || cfg.AppKey == "" {
		t.Fatalf("existing settings changed: %+v", cfg)
	}
	ports := map[int]bool{cfg.LocalPort: true, cfg.SharedPort: true, cfg.LanPort: true, cfg.AdminPort: true,
		cfg.CollabPort: true, cfg.ControlPort: true, cfg.LivePort: true}
	if len(ports) != 7 || ports[0] {
		t.Fatalf("collaboration ports missing or not distinct: %+v", cfg)
	}
	if len(cfg.ControlToken) != 64 {
		t.Fatalf("control token: %q", cfg.ControlToken)
	}
	id, err := cluster.IdentityFromSeed(cfg.NodeKey)
	if err != nil || !strings.HasPrefix(id.Node, "n") {
		t.Fatalf("node key: %v", err)
	}
	again, created2, _ := LoadOrCreateConfig(path)
	if created2 || again != cfg {
		t.Fatal("node identity must stay the same across starts")
	}
}

func TestCollaborationSettingsReachPHPButCannotBeOverridden(t *testing.T) {
	exe, _ := fakeInstall(t, false)
	l, _ := ResolveLayout(exe, t.TempDir())
	os.WriteFile(l.DataFile("settings.env"), []byte("OPENQDA_CONTROL_TOKEN=guess\nOPENQDA_NODE_KEY=x\n"), 0o600)
	env, warnings := AppEnv(l, Config{AppKey: "base64:x", LocalPort: 8765, CollabPort: 9101, ControlPort: 9102, ControlToken: "tok", NodeKey: "seed"})
	if env["OPENQDA_CONTROL_URL"] != "http://127.0.0.1:9102" || env["OPENQDA_COLLAB_PORT"] != "9101" ||
		env["OPENQDA_CONTROL_TOKEN"] != "tok" || env["OPENQDA_NODE_KEY"] != "seed" {
		t.Fatalf("collaboration settings: %v", env)
	}
	if len(warnings) != 2 {
		t.Fatalf("expected two warnings, got %v", warnings)
	}
}

func TestCaddyfileRoutesLiveSocketAndCollabChannel(t *testing.T) {
	l := Layout{Root: "/opt/oq", App: "/opt/oq/app", Runtime: "/opt/oq/runtime", Data: "/data"}
	cfg := Config{LocalPort: 8765, SharedPort: 9001, LanPort: 8766, AdminPort: 9002, CollabPort: 9101, LivePort: 9103}
	c := Caddyfile(l, cfg, false)
	for _, want := range []string{
		"handle /_live {\n\t\treverse_proxy 127.0.0.1:9103\n\t}",
		"http://:9101 {\n\tbind 127.0.0.1\n\timport openqda collab",
	} {
		if !strings.Contains(c, want) {
			t.Fatalf("Caddyfile lacks %q:\n%s", want, c)
		}
	}
	// the live socket is matched before PHP
	if strings.Index(c, "handle /_live") > strings.Index(c, "php_server") {
		t.Fatal("/_live must be handled before PHP")
	}
}
