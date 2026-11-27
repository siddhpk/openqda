package cluster

import (
	"context"
	"crypto/subtle"
	"encoding/json"
	"errors"
	"net/http"
	"strings"
	"time"
)

// Control is the loopback API PHP uses to drive the node: Tailscale sign-in,
// handing over new local operations, presence and status. Every request
// needs the installation's token (OPENQDA_CONTROL_TOKEN).
type Control struct {
	Agent   *Agent
	Tailnet *Tailnet
	Token   string
}

type controlStatus struct {
	Tailscale TailnetStatus `json:"tailscale"`
	Cluster   AgentStatus   `json:"cluster"`
}

func (c *Control) Handler() http.Handler {
	mux := http.NewServeMux()
	mux.HandleFunc("GET /v1/status", c.status)
	mux.HandleFunc("POST /v1/tailscale/connect", c.connect)
	mux.HandleFunc("POST /v1/tailscale/authkey", c.authKey)
	mux.HandleFunc("POST /v1/tailscale/logout", c.logout)
	mux.HandleFunc("POST /v1/tailscale/tags", c.tags)
	mux.HandleFunc("POST /v1/ops", c.ops)
	mux.HandleFunc("POST /v1/roster", func(w http.ResponseWriter, r *http.Request) {
		c.Agent.ReloadRoster()
		writeJSON(w, http.StatusOK, map[string]bool{"ok": true})
	})
	mux.HandleFunc("POST /v1/sync", func(w http.ResponseWriter, r *http.Request) {
		go c.Agent.SyncNow()
		writeJSON(w, http.StatusOK, map[string]bool{"ok": true})
	})
	mux.HandleFunc("GET /v1/presence", func(w http.ResponseWriter, r *http.Request) {
		writeJSON(w, http.StatusOK, map[string]any{"presence": c.Agent.Presence(r.URL.Query().Get("project"))})
	})
	return c.auth(mux)
}

func (c *Control) auth(next http.Handler) http.Handler {
	return http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		got := strings.TrimPrefix(r.Header.Get("Authorization"), "Bearer ")
		if c.Token == "" || subtle.ConstantTimeCompare([]byte(got), []byte(c.Token)) != 1 {
			http.Error(w, "forbidden", http.StatusForbidden)
			return
		}
		r.Body = http.MaxBytesReader(w, r.Body, 64<<20)
		next.ServeHTTP(w, r)
	})
}

func writeJSON(w http.ResponseWriter, code int, v any) {
	w.Header().Set("Content-Type", "application/json")
	w.WriteHeader(code)
	_ = json.NewEncoder(w).Encode(v)
}

func (c *Control) tailnetStatus(ctx context.Context) TailnetStatus {
	if c.Tailnet == nil {
		return TailnetStatus{State: "Unavailable"}
	}
	return c.Tailnet.Status(ctx)
}

func (c *Control) status(w http.ResponseWriter, r *http.Request) {
	ctx, cancel := context.WithTimeout(r.Context(), 5*time.Second)
	defer cancel()
	writeJSON(w, http.StatusOK, controlStatus{Tailscale: c.tailnetStatus(ctx), Cluster: c.Agent.Status()})
}

// connect starts the node; when it needs a sign-in the answer carries the
// URL the person opens in the browser.
func (c *Control) connect(w http.ResponseWriter, r *http.Request) {
	if c.Tailnet == nil {
		writeJSON(w, http.StatusServiceUnavailable, map[string]string{"error": "Tailscale is not available in this build"})
		return
	}
	ctx, cancel := context.WithTimeout(r.Context(), 30*time.Second)
	defer cancel()
	st, err := c.Tailnet.Connect(ctx)
	if err != nil {
		st.Error = err.Error()
	}
	c.Agent.SetNetwork(c.Tailnet)
	writeJSON(w, http.StatusOK, st)
}

// authKey joins a team network with a one-time key from an invite. With
// replace=false it refuses to leave another network the node is part of.
func (c *Control) authKey(w http.ResponseWriter, r *http.Request) {
	var req struct {
		Key     string `json:"key"`
		Replace bool   `json:"replace"`
	}
	if err := json.NewDecoder(r.Body).Decode(&req); err != nil || !strings.HasPrefix(req.Key, "tskey-") {
		writeJSON(w, http.StatusUnprocessableEntity, map[string]string{"error": "not a Tailscale key"})
		return
	}
	if c.Tailnet == nil {
		writeJSON(w, http.StatusServiceUnavailable, map[string]string{"error": "Tailscale is not available in this build"})
		return
	}
	ctx, cancel := context.WithTimeout(r.Context(), 60*time.Second)
	defer cancel()
	c.Agent.SetNetwork(nil)
	st, err := c.Tailnet.UseAuthKey(ctx, req.Key, req.Replace)
	c.Agent.SetNetwork(c.Tailnet)
	if err != nil {
		code := http.StatusBadGateway
		if errors.Is(err, ErrOtherNetwork) {
			code = http.StatusConflict
		}
		st.Error = err.Error()
		writeJSON(w, code, st)
		return
	}
	writeJSON(w, http.StatusOK, st)
}

func (c *Control) logout(w http.ResponseWriter, r *http.Request) {
	if c.Tailnet == nil {
		writeJSON(w, http.StatusOK, TailnetStatus{State: "Unavailable"})
		return
	}
	ctx, cancel := context.WithTimeout(r.Context(), 20*time.Second)
	defer cancel()
	c.Agent.SetNetwork(nil)
	err := c.Tailnet.Logout(ctx)
	st := c.Tailnet.Status(ctx)
	if err != nil {
		st.Error = err.Error()
	}
	writeJSON(w, http.StatusOK, st)
}

func (c *Control) tags(w http.ResponseWriter, r *http.Request) {
	var req struct {
		Tags []string `json:"tags"`
	}
	if err := json.NewDecoder(r.Body).Decode(&req); err != nil {
		writeJSON(w, http.StatusUnprocessableEntity, map[string]string{"error": "invalid request"})
		return
	}
	if c.Tailnet == nil {
		writeJSON(w, http.StatusServiceUnavailable, map[string]string{"error": "Tailscale is not available in this build"})
		return
	}
	ctx, cancel := context.WithTimeout(r.Context(), 30*time.Second)
	defer cancel()
	if err := c.Tailnet.SetTags(ctx, req.Tags); err != nil {
		writeJSON(w, http.StatusBadGateway, map[string]string{"error": err.Error()})
		return
	}
	writeJSON(w, http.StatusOK, c.Tailnet.Status(ctx))
}

// ops receives new local operations from PHP and sends them on at once.
func (c *Control) ops(w http.ResponseWriter, r *http.Request) {
	var req struct {
		Batches []OutBatch `json:"batches"`
	}
	if err := json.NewDecoder(r.Body).Decode(&req); err != nil {
		writeJSON(w, http.StatusUnprocessableEntity, map[string]string{"error": "invalid request"})
		return
	}
	c.Agent.Publish(req.Batches)
	writeJSON(w, http.StatusOK, map[string]bool{"ok": true})
}
