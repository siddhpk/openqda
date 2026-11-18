package main

import (
	"crypto/rand"
	"encoding/base64"
	"encoding/hex"
	"encoding/json"
	"fmt"
	"net"
	"os"
)

// Config is created on first start and kept in <data>/config.json.
// It holds this installation's secrets and the ports it listens on.
type Config struct {
	AppKey     string `json:"app_key"`
	AltchaKey  string `json:"altcha_key"`
	LocalPort  int    `json:"local_port"`
	SharedPort int    `json:"shared_port"`
	LanPort    int    `json:"lan_port"`
	AdminPort  int    `json:"admin_port"`
}

// Preferred ports; a free port is chosen when one is taken.
const (
	preferredLocalPort = 8765
	preferredLanPort   = 8766
)

func LoadOrCreateConfig(path string) (Config, bool, error) {
	var cfg Config
	created := false
	if raw, err := os.ReadFile(path); err == nil {
		if err := json.Unmarshal(raw, &cfg); err != nil {
			return cfg, false, fmt.Errorf("config.json is damaged: %w", err)
		}
	} else if !os.IsNotExist(err) {
		return cfg, false, err
	}

	if cfg.AppKey == "" {
		key := make([]byte, 32)
		if _, err := rand.Read(key); err != nil {
			return cfg, false, err
		}
		cfg.AppKey = "base64:" + base64.StdEncoding.EncodeToString(key)
		created = true
	}
	if cfg.AltchaKey == "" {
		k := make([]byte, 24)
		if _, err := rand.Read(k); err != nil {
			return cfg, false, err
		}
		cfg.AltchaKey = hex.EncodeToString(k)
		created = true
	}
	used := map[int]bool{}
	for _, p := range []*int{&cfg.LocalPort, &cfg.SharedPort, &cfg.LanPort, &cfg.AdminPort} {
		if *p != 0 {
			used[*p] = true
		}
	}
	pick := func(target *int, preferred int) error {
		if *target != 0 {
			return nil
		}
		port, err := freePort(preferred, used)
		if err != nil {
			return err
		}
		*target = port
		used[port] = true
		created = true
		return nil
	}
	for _, p := range []struct {
		target    *int
		preferred int
	}{{&cfg.LocalPort, preferredLocalPort}, {&cfg.LanPort, preferredLanPort}, {&cfg.SharedPort, 0}, {&cfg.AdminPort, 0}} {
		if err := pick(p.target, p.preferred); err != nil {
			return cfg, false, err
		}
	}
	if created {
		if err := SaveConfig(path, cfg); err != nil {
			return cfg, false, err
		}
	}
	return cfg, created, nil
}

func SaveConfig(path string, cfg Config) error {
	raw, err := json.MarshalIndent(cfg, "", "  ")
	if err != nil {
		return err
	}
	return writeFileAtomic(path, raw, 0o600)
}

// freePort returns preferred when it can be bound on 127.0.0.1, otherwise
// a free port chosen by the operating system.
func freePort(preferred int, used map[int]bool) (int, error) {
	if preferred > 0 && !used[preferred] && portFree(preferred) {
		return preferred, nil
	}
	for i := 0; i < 20; i++ {
		l, err := net.Listen("tcp", "127.0.0.1:0")
		if err != nil {
			return 0, err
		}
		port := l.Addr().(*net.TCPAddr).Port
		l.Close()
		if !used[port] {
			return port, nil
		}
	}
	return 0, fmt.Errorf("no free port found")
}

func portFree(port int) bool {
	l, err := net.Listen("tcp", fmt.Sprintf("127.0.0.1:%d", port))
	if err != nil {
		return false
	}
	l.Close()
	return true
}

func writeFileAtomic(path string, data []byte, mode os.FileMode) error {
	tmp := path + ".tmp"
	if err := os.WriteFile(tmp, data, mode); err != nil {
		return err
	}
	if err := os.Rename(tmp, path); err != nil {
		// Windows: replacing an open file can fail; fall back to a plain write
		os.Remove(tmp)
		return os.WriteFile(path, data, mode)
	}
	return nil
}
