package cluster

import (
	"context"
	"crypto/hmac"
	"crypto/sha256"
	"encoding/base64"
	"encoding/json"
	"errors"
	"net/http"
	"strings"
	"sync"
	"time"

	"github.com/coder/websocket"
	"github.com/coder/websocket/wsjson"
)

// LiveHub is the WebSocket the browsers on this computer use for presence,
// live changes, co-editing and cursors (path /_live, proxied by Caddy from
// every listener). A connection needs a short-lived token that PHP issues
// only to sessions allowed to open the project, so the access-code gate of
// the sharing feature also covers it.
type LiveHub struct {
	a   *Agent
	key []byte

	mu       sync.Mutex
	sessions map[string]*liveSession
	pending  *time.Timer
}

type liveToken struct {
	Kind    string `json:"k"`
	User    string `json:"u"`
	Name    string `json:"n,omitempty"`
	Color   string `json:"c,omitempty"`
	Project string `json:"p"`
	Doc     string `json:"d,omitempty"`
	Edit    bool   `json:"ed,omitempty"`
	Exp     int64  `json:"e"`
}

type liveSession struct {
	id   string
	tok  liveToken
	conn *websocket.Conn
	out  chan []byte
	done chan struct{}
	once sync.Once

	view string
	doc  string
	edit bool
	idle bool
	docs map[string]bool // subscribed documents -> may edit
}

// browser <-> hub message
type liveMsg struct {
	T       string          `json:"t"`
	Session string          `json:"session,omitempty"`
	Node    string          `json:"node,omitempty"`
	View    string          `json:"view,omitempty"`
	Doc     string          `json:"doc,omitempty"`
	Edit    bool            `json:"edit,omitempty"`
	Idle    bool            `json:"idle,omitempty"`
	Token   string          `json:"token,omitempty"`
	U       []byte          `json:"u,omitempty"`
	From    string          `json:"from,omitempty"`
	Users   []Presence      `json:"users"`
	Events  json.RawMessage `json:"events,omitempty"`
	Project string          `json:"project,omitempty"`
}

func newLiveHub(a *Agent, key []byte) *LiveHub {
	return &LiveHub{a: a, key: key, sessions: map[string]*liveSession{}}
}

// SignToken creates a token (used by tests; PHP creates the real ones).
func SignToken(key []byte, payload any) string {
	raw, _ := json.Marshal(payload)
	body := base64.RawURLEncoding.EncodeToString(raw)
	m := hmac.New(sha256.New, key)
	m.Write([]byte(body))
	return body + "." + base64.RawURLEncoding.EncodeToString(m.Sum(nil))
}

func (h *LiveHub) verify(token, kind string) (liveToken, error) {
	var t liveToken
	body, sig, ok := strings.Cut(token, ".")
	if !ok || len(h.key) == 0 {
		return t, errors.New("malformed token")
	}
	got, err := base64.RawURLEncoding.DecodeString(sig)
	if err != nil {
		return t, errors.New("malformed token")
	}
	m := hmac.New(sha256.New, h.key)
	m.Write([]byte(body))
	if !hmac.Equal(got, m.Sum(nil)) {
		return t, errors.New("invalid token")
	}
	raw, err := base64.RawURLEncoding.DecodeString(body)
	if err != nil || json.Unmarshal(raw, &t) != nil {
		return t, errors.New("malformed token")
	}
	if t.Kind != kind || t.Project == "" || t.User == "" {
		return t, errors.New("wrong token")
	}
	if time.Now().Unix() > t.Exp {
		return t, errors.New("token expired")
	}
	return t, nil
}

func (h *LiveHub) ServeHTTP(w http.ResponseWriter, r *http.Request) {
	tok, err := h.verify(r.URL.Query().Get("token"), "live")
	if err != nil {
		http.Error(w, err.Error(), http.StatusForbidden)
		return
	}
	conn, err := websocket.Accept(w, r, nil) // same-origin only (Origin must match Host)
	if err != nil {
		return
	}
	conn.SetReadLimit(8 << 20)
	s := &liveSession{id: randomNonce()[:12], tok: tok, conn: conn, out: make(chan []byte, 512), done: make(chan struct{}), docs: map[string]bool{}}
	h.mu.Lock()
	h.sessions[s.id] = s
	h.mu.Unlock()
	h.send(s, liveMsg{T: "hello", Session: s.id, Node: h.a.Node()})
	h.localChanged()

	ctx, cancel := context.WithCancel(r.Context())
	defer cancel()
	go func() {
		for {
			select {
			case msg := <-s.out:
				wctx, wcancel := context.WithTimeout(ctx, writeTimeout)
				err := conn.Write(wctx, websocket.MessageText, msg)
				wcancel()
				if err != nil {
					s.close()
					return
				}
			case <-s.done:
				return
			}
		}
	}()
	for {
		var m liveMsg
		if err := wsjson.Read(ctx, conn, &m); err != nil {
			break
		}
		h.onMessage(s, m)
	}
	s.close()
	h.mu.Lock()
	delete(h.sessions, s.id)
	h.mu.Unlock()
	h.localChanged()
}

func (s *liveSession) close() {
	s.once.Do(func() {
		close(s.done)
		_ = s.conn.Close(websocket.StatusNormalClosure, "")
	})
}

func (h *LiveHub) send(s *liveSession, m liveMsg) {
	raw, err := json.Marshal(m)
	if err != nil {
		return
	}
	select {
	case s.out <- raw:
	case <-s.done:
	default:
		s.close() // a tab that cannot keep up reconnects and reloads
	}
}

func (h *LiveHub) onMessage(s *liveSession, m liveMsg) {
	switch m.T {
	case "view":
		h.mu.Lock()
		s.view, s.doc, s.edit, s.idle = m.View, m.Doc, m.Edit && s.docs[m.Doc], m.Idle
		h.mu.Unlock()
		h.localChanged()
	case "sub":
		t, err := h.verify(m.Token, "doc")
		if err != nil || t.Project != s.tok.Project || t.User != s.tok.User || t.Doc != m.Doc {
			return
		}
		h.mu.Lock()
		s.docs[m.Doc] = t.Edit
		h.mu.Unlock()
	case "unsub":
		h.mu.Lock()
		delete(s.docs, m.Doc)
		h.mu.Unlock()
	case "y", "aw":
		h.mu.Lock()
		editable, subscribed := s.docs[m.Doc]
		h.mu.Unlock()
		if !subscribed || (m.T == "y" && !editable) || len(m.U) == 0 {
			return
		}
		from := h.a.Node() + ":" + s.id
		h.deliverDoc(m.T, s.tok.Project, m.Doc, m.U, from, s.id)
		h.a.relayDoc(m.T, s.tok.Project, m.Doc, m.U, s.id)
	case "ping":
		h.send(s, liveMsg{T: "pong"})
	}
}

// localPresence lists the browsers on this computer (in project, or all).
func (h *LiveHub) localPresence(project string) []Presence {
	h.mu.Lock()
	defer h.mu.Unlock()
	node := h.a.Node()
	out := []Presence{}
	for _, s := range h.sessions {
		if project != "" && s.tok.Project != project {
			continue
		}
		out = append(out, Presence{Node: node, Session: s.id, User: s.tok.User, Name: s.tok.Name, Color: s.tok.Color,
			Project: s.tok.Project, View: s.view, Doc: s.doc, Edit: s.edit, Idle: s.idle})
	}
	return out
}

// localChanged tells peers and local tabs about a presence change, batched.
func (h *LiveHub) localChanged() {
	h.mu.Lock()
	defer h.mu.Unlock()
	if h.pending != nil {
		return
	}
	h.pending = time.AfterFunc(250*time.Millisecond, func() {
		h.mu.Lock()
		h.pending = nil
		h.mu.Unlock()
		h.a.sendHeartbeats()
		h.presenceChanged("")
	})
}

// presenceChanged sends the current presence to the tabs of a project
// (or of every project). Each tab gets everyone except itself.
func (h *LiveHub) presenceChanged(project string) {
	h.mu.Lock()
	byProject := map[string][]*liveSession{}
	for _, s := range h.sessions {
		if project == "" || s.tok.Project == project {
			byProject[s.tok.Project] = append(byProject[s.tok.Project], s)
		}
	}
	h.mu.Unlock()
	for p, sessions := range byProject {
		all := h.a.Presence(p)
		for _, s := range sessions {
			users := make([]Presence, 0, len(all))
			for _, u := range all {
				if !(u.Node == h.a.Node() && u.Session == s.id) {
					users = append(users, u)
				}
			}
			h.send(s, liveMsg{T: "presence", Project: p, Users: users})
		}
	}
}

// events forwards change notifications to the tabs of a project.
func (h *LiveHub) events(project string, events []json.RawMessage) {
	raw, err := json.Marshal(events)
	if err != nil {
		return
	}
	h.mu.Lock()
	var targets []*liveSession
	for _, s := range h.sessions {
		if s.tok.Project == project {
			targets = append(targets, s)
		}
	}
	h.mu.Unlock()
	for _, s := range targets {
		h.send(s, liveMsg{T: "ev", Project: project, Events: raw})
	}
}

// deliverDoc passes a co-editing update or cursor state to the local tabs
// that have the document open, except the one it came from.
func (h *LiveHub) deliverDoc(t, project, doc string, u []byte, from, except string) {
	h.mu.Lock()
	var targets []*liveSession
	for id, s := range h.sessions {
		if id == except || s.tok.Project != project {
			continue
		}
		if _, ok := s.docs[doc]; ok {
			targets = append(targets, s)
		}
	}
	h.mu.Unlock()
	for _, s := range targets {
		h.send(s, liveMsg{T: t, Doc: doc, U: u, From: from})
	}
}

func (h *LiveHub) closeAll() {
	h.mu.Lock()
	sessions := make([]*liveSession, 0, len(h.sessions))
	for _, s := range h.sessions {
		sessions = append(sessions, s)
	}
	h.mu.Unlock()
	for _, s := range sessions {
		s.close()
	}
}
