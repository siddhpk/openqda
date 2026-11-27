package cluster

import (
	"context"
	"errors"
	"fmt"
	"net"
	"net/netip"
	"os"
	"path/filepath"
	"regexp"
	"strings"
	"sync"
	"time"

	"tailscale.com/envknob"
	"tailscale.com/ipn"
	"tailscale.com/ipn/ipnstate"
	"tailscale.com/tsnet"
)

// Tailnet is the embedded Tailscale node: OpenQDA itself joins the team's
// private network as a device named "openqda-<computer>". It runs in user
// space (no admin rights, no virtual network adapter), so only OpenQDA is
// reachable through it, never the rest of the computer.
type Tailnet struct {
	Dir      string // <data>/tailscale, holds the node key (0700)
	Hostname string
	Logf     func(string, ...any)

	mu      sync.Mutex
	srv     *tsnet.Server
	lastErr string
}

// ErrOtherNetwork is returned when a key from an invite would move this
// node out of the network it already belongs to.
var ErrOtherNetwork = errors.New("this computer is already connected to another Tailscale network")

// TailnetStatus is what the Collaboration page shows about the connection.
type TailnetStatus struct {
	Configured bool     `json:"configured"`
	Started    bool     `json:"started"`
	State      string   `json:"state"`
	AuthURL    string   `json:"auth_url,omitempty"`
	Tailnet    string   `json:"tailnet,omitempty"`
	DNS        string   `json:"dns,omitempty"`
	IP         string   `json:"ip,omitempty"`
	User       string   `json:"user,omitempty"`
	Tags       []string `json:"tags,omitempty"`
	Error      string   `json:"error,omitempty"`
}

var hostClean = regexp.MustCompile(`[^a-z0-9-]+`)

// DefaultHostname derives "openqda-<computer name>".
func DefaultHostname() string {
	h, _ := os.Hostname()
	h = strings.Trim(hostClean.ReplaceAllString(strings.ToLower(h), "-"), "-")
	if h == "" {
		h = "laptop"
	}
	if len(h) > 40 {
		h = h[:40]
	}
	return "openqda-" + h
}

func init() {
	// The embedded node never uploads diagnostic logs to Tailscale.
	envknob.SetNoLogsNoSupport()
}

// Configured reports whether this computer has joined a network before
// (the node starts again by itself on the next launch).
func (t *Tailnet) Configured() bool {
	_, err := os.Stat(filepath.Join(t.Dir, "tailscaled.state"))
	return err == nil
}

func (t *Tailnet) logf(format string, args ...any) {
	if t.Logf != nil {
		// sign-in URLs are shown in the app, not written to the log
		msg := fmt.Sprintf(format, args...)
		if strings.Contains(msg, "login.tailscale.com") {
			msg = "waiting for sign-in"
		}
		t.Logf("tailscale: %s", msg)
	}
}

func (t *Tailnet) startLocked(authKey string) error {
	if t.srv != nil {
		return nil
	}
	if err := os.MkdirAll(t.Dir, 0o700); err != nil {
		return err
	}
	srv := &tsnet.Server{
		Dir:      t.Dir,
		Hostname: t.Hostname,
		AuthKey:  authKey,
		UserLogf: t.logf,
		Logf:     func(string, ...any) {},
	}
	if err := srv.Start(); err != nil {
		t.lastErr = err.Error()
		return err
	}
	t.srv = srv
	t.lastErr = ""
	return nil
}

// Start brings the node up with its saved identity (at launch).
func (t *Tailnet) Start() error {
	t.mu.Lock()
	defer t.mu.Unlock()
	return t.startLocked("")
}

// Connect starts the node and, if it is not signed in, asks Tailscale for a
// sign-in URL.
func (t *Tailnet) Connect(ctx context.Context) (TailnetStatus, error) {
	t.mu.Lock()
	err := t.startLocked("")
	srv := t.srv
	t.mu.Unlock()
	if err != nil {
		return t.Status(ctx), err
	}
	lc, err := srv.LocalClient()
	if err != nil {
		return t.Status(ctx), err
	}
	deadline := time.Now().Add(20 * time.Second)
	asked := false
	for time.Now().Before(deadline) {
		st, err := lc.StatusWithoutPeers(ctx)
		if err == nil {
			if st.BackendState == ipn.Running.String() || st.AuthURL != "" {
				break
			}
			if st.BackendState == ipn.NeedsLogin.String() && !asked {
				asked = true
				if err := lc.StartLoginInteractive(ctx); err != nil {
					return t.Status(ctx), err
				}
			}
		}
		select {
		case <-ctx.Done():
			return t.Status(context.Background()), ctx.Err()
		case <-time.After(300 * time.Millisecond):
		}
	}
	return t.Status(ctx), nil
}

// UseAuthKey joins a network with a one-time key. A node that is signed in
// to another network keeps it unless replace is set.
func (t *Tailnet) UseAuthKey(ctx context.Context, key string, replace bool) (TailnetStatus, error) {
	st := t.Status(ctx)
	if st.State == ipn.Running.String() && !replace {
		return st, ErrOtherNetwork
	}
	t.mu.Lock()
	t.closeLocked()
	if err := os.RemoveAll(t.Dir); err != nil {
		t.mu.Unlock()
		return st, err
	}
	err := t.startLocked(key)
	srv := t.srv
	t.mu.Unlock()
	if err != nil {
		return t.Status(ctx), err
	}
	upCtx, cancel := context.WithTimeout(ctx, 45*time.Second)
	defer cancel()
	if _, err := srv.Up(upCtx); err != nil {
		return t.Status(ctx), fmt.Errorf("joining the network failed (the key may be used up or expired): %w", err)
	}
	return t.Status(ctx), nil
}

// Logout signs the node out and forgets its identity.
func (t *Tailnet) Logout(ctx context.Context) error {
	t.mu.Lock()
	defer t.mu.Unlock()
	if t.srv != nil {
		lc, err := t.srv.LocalClient()
		if err != nil {
			return err
		}
		if err := lc.Logout(ctx); err != nil {
			return err
		}
	}
	if err := t.closeLocked(); err != nil {
		return err
	}
	return os.RemoveAll(t.Dir)
}

// SetTags asks Tailscale to tag this node (allowed once the network's
// policy lets the signed-in person use the tag).
func (t *Tailnet) SetTags(ctx context.Context, tags []string) error {
	t.mu.Lock()
	srv := t.srv
	t.mu.Unlock()
	if srv == nil {
		return errors.New("not connected")
	}
	lc, err := srv.LocalClient()
	if err != nil {
		return err
	}
	_, err = lc.EditPrefs(ctx, &ipn.MaskedPrefs{Prefs: ipn.Prefs{AdvertiseTags: tags}, AdvertiseTagsSet: true})
	return err
}

func (t *Tailnet) Close() {
	t.mu.Lock()
	defer t.mu.Unlock()
	t.closeLocked()
}

// Pause stops networking without signing out or removing the saved identity.
func (t *Tailnet) Pause() error {
	t.mu.Lock()
	defer t.mu.Unlock()
	return t.closeLocked()
}

func (t *Tailnet) closeLocked() error {
	if t.srv != nil {
		err := t.srv.Close()
		t.srv = nil
		return err
	}
	return nil
}

func (t *Tailnet) running() (*tsnet.Server, error) {
	t.mu.Lock()
	defer t.mu.Unlock()
	if t.srv == nil {
		return nil, errors.New("not connected")
	}
	return t.srv, nil
}

func (t *Tailnet) fullStatus(ctx context.Context) (*ipnstate.Status, error) {
	srv, err := t.running()
	if err != nil {
		return nil, err
	}
	lc, err := srv.LocalClient()
	if err != nil {
		return nil, err
	}
	return lc.Status(ctx)
}

// Status describes the node for the UI.
func (t *Tailnet) Status(ctx context.Context) TailnetStatus {
	out := TailnetStatus{Configured: t.Configured(), State: "Stopped"}
	t.mu.Lock()
	out.Started = t.srv != nil
	out.Error = t.lastErr
	t.mu.Unlock()
	st, err := t.fullStatus(ctx)
	if err != nil {
		return out
	}
	out.State = st.BackendState
	out.AuthURL = st.AuthURL
	if st.CurrentTailnet != nil {
		out.Tailnet = st.CurrentTailnet.Name
	}
	if st.Self != nil {
		out.DNS = strings.TrimSuffix(st.Self.DNSName, ".")
		out.IP = firstIPv4(st.Self.TailscaleIPs)
		if st.Self.Tags != nil {
			out.Tags = st.Self.Tags.AsSlice()
		}
		if u, ok := st.User[st.Self.UserID]; ok && (st.Self.Tags == nil || st.Self.Tags.Len() == 0) {
			out.User = u.LoginName
		}
	}
	return out
}

func firstIPv4(ips []netip.Addr) string {
	for _, ip := range ips {
		if ip.Is4() {
			return ip.String()
		}
	}
	if len(ips) > 0 {
		return ips[0].String()
	}
	return ""
}

// ---- Network ----------------------------------------------------------------

func (t *Tailnet) Listen() (net.Listener, error) {
	srv, err := t.running()
	if err != nil {
		return nil, err
	}
	return srv.Listen("tcp", ":"+LinkPort)
}

func (t *Tailnet) Dial(ctx context.Context, addr string) (net.Conn, error) {
	srv, err := t.running()
	if err != nil {
		return nil, err
	}
	return srv.Dial(ctx, "tcp", addr)
}

func (t *Tailnet) Peers(ctx context.Context) ([]NetPeer, error) {
	st, err := t.fullStatus(ctx)
	if err != nil {
		return nil, err
	}
	if st.BackendState != ipn.Running.String() {
		return nil, errors.New("not running")
	}
	out := make([]NetPeer, 0, len(st.Peer))
	for _, p := range st.Peer {
		np := NetPeer{IP: firstIPv4(p.TailscaleIPs), DNS: strings.TrimSuffix(p.DNSName, "."), Host: p.HostName,
			Online: p.Online, Direct: p.CurAddr != ""}
		if p.Tags != nil {
			np.Tags = p.Tags.AsSlice()
		}
		out = append(out, np)
	}
	return out, nil
}

func (t *Tailnet) Self(ctx context.Context) (string, string, error) {
	srv, err := t.running()
	if err != nil {
		return "", "", err
	}
	lc, err := srv.LocalClient()
	if err != nil {
		return "", "", err
	}
	st, err := lc.StatusWithoutPeers(ctx)
	if err != nil || st.Self == nil {
		return "", "", errors.New("not running")
	}
	return firstIPv4(st.Self.TailscaleIPs), strings.TrimSuffix(st.Self.DNSName, "."), nil
}
