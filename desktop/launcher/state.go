package main

import (
	"encoding/json"
	"os"
	"time"
)

// AppState is the part of <data>/state.json (written by the app's Sharing
// page, see app/Services/Desktop/DesktopState.php) that the launcher acts on.
type AppState struct {
	Sharing struct {
		Mode string `json:"mode"`
	} `json:"sharing"`
	Autostart bool   `json:"autostart"`
	Shutdown  string `json:"shutdown"`
}

func ReadAppState(path string) AppState {
	var s AppState
	if raw, err := os.ReadFile(path); err == nil {
		_ = json.Unmarshal(raw, &s)
	}
	if s.Sharing.Mode == "" {
		s.Sharing.Mode = "off"
	}
	return s
}

// ShutdownRequestedAfter reports a shutdown request made after t.
func (s AppState) ShutdownRequestedAfter(t time.Time) bool {
	if s.Shutdown == "" {
		return false
	}
	at, err := time.Parse(time.RFC3339, s.Shutdown)
	return err == nil && at.After(t)
}

func (s AppState) isNewShutdownRequest(started time.Time, initial string) bool {
	return s.Shutdown != initial && s.ShutdownRequestedAfter(started.Truncate(time.Second).Add(-time.Nanosecond))
}

// StopSharingInState switches sharing off in state.json (used when the
// launcher quits) while keeping every other key the app wrote.
func StopSharingInState(path, reason string) error {
	raw, err := os.ReadFile(path)
	if err != nil {
		if os.IsNotExist(err) {
			return nil
		}
		return err
	}
	var data map[string]any
	if err := json.Unmarshal(raw, &data); err != nil {
		return err
	}
	sharing, _ := data["sharing"].(map[string]any)
	if sharing == nil || sharing["mode"] == nil || sharing["mode"] == "off" {
		return nil
	}
	data["sharing"] = map[string]any{"mode": "off", "stopped_reason": reason, "stopped_at": time.Now().UTC().Format(time.RFC3339)}
	out, err := json.MarshalIndent(data, "", "    ")
	if err != nil {
		return err
	}
	return writeFileAtomic(path, out, 0o600)
}
