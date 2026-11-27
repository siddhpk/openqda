package cluster

import (
	"context"
	"encoding/json"
	"errors"
	"net"
	"net/http"
	"sort"
	"strings"
	"sync"
	"time"

	"github.com/coder/websocket"
)

// Options configure an Agent.
type Options struct {
	Identity Identity
	Store    Store
	Logf     func(string, ...any)
	DataDir  string // partial downloads (<data>/collab)
	LiveKey  []byte // HMAC key for browser tokens (DeriveKey(token, "live-token-v1"))

	Heartbeat  time.Duration
	Offline    time.Duration
	Discover   time.Duration
	FlushEvery time.Duration
	SyncEvery  time.Duration
}

// Agent is the node agent: it keeps links to the online peers of every
// project this node collaborates on and moves operations, presence, files
// and co-editing updates between them.
type Agent struct {
	opt Options

	mu       sync.Mutex
	net      Network
	netGen   int
	listener net.Listener
	srv      *http.Server
	roster   Roster
	links    map[string]*Link
	dialing  map[string]bool
	backoff  map[string]backoffState
	remote   map[string]*remotePeer
	docs     map[string]docEntry
	waiting  map[string]time.Time

	hub     *LiveHub
	blobs   *blobFetcher
	applyCh chan applyJob
	kick    chan struct{}
	rosterC chan struct{}

	ctx    context.Context
	cancel context.CancelFunc
}

// dialGrace is how long the node with the larger id waits for the other
// side to dial before it dials itself.
const dialGrace = 2 * time.Second

type backoffState struct {
	next  time.Time
	delay time.Duration
}

type remotePeer struct {
	Name     string
	Addr     string
	DNS      string
	LastSeen time.Time
	Presence []Presence
}

type docEntry struct {
	info DocInfo
	at   time.Time
}

type applyJob struct {
	from    string
	project string
	ops     []json.RawMessage
}

// PeerStatus is one roster peer as shown on the Collaboration page.
type PeerStatus struct {
	Node     string    `json:"node"`
	Name     string    `json:"name"`
	Online   bool      `json:"online"`
	Linked   bool      `json:"linked"`
	Direct   bool      `json:"direct"`
	Addr     string    `json:"addr,omitempty"`
	LastSeen time.Time `json:"last_seen,omitempty"`
	Projects []string  `json:"projects"`
}

// AgentStatus summarizes the cluster for the UI.
type AgentStatus struct {
	Node     string       `json:"node"`
	Network  bool         `json:"network"`
	Peers    []PeerStatus `json:"peers"`
	Presence []Presence   `json:"presence"`
}

func NewAgent(opt Options) *Agent {
	if opt.Logf == nil {
		opt.Logf = func(string, ...any) {}
	}
	if opt.Heartbeat == 0 {
		opt.Heartbeat = HeartbeatEvery
	}
	if opt.Offline == 0 {
		opt.Offline = OfflineAfter
	}
	if opt.Discover == 0 {
		opt.Discover = 5 * time.Second
	}
	if opt.FlushEvery == 0 {
		opt.FlushEvery = 30 * time.Second
	}
	if opt.SyncEvery == 0 {
		opt.SyncEvery = 60 * time.Second
	}
	a := &Agent{
		opt:     opt,
		links:   map[string]*Link{},
		dialing: map[string]bool{},
		backoff: map[string]backoffState{},
		remote:  map[string]*remotePeer{},
		docs:    map[string]docEntry{},
		waiting: map[string]time.Time{},
		applyCh: make(chan applyJob, 256),
		kick:    make(chan struct{}, 1),
		rosterC: make(chan struct{}, 1),
	}
	a.hub = newLiveHub(a, opt.LiveKey)
	a.blobs = newBlobFetcher(a)
	return a
}

func (a *Agent) Node() string            { return a.opt.Identity.Node }
func (a *Agent) Hub() *LiveHub           { return a.hub }
func (a *Agent) logf(f string, v ...any) { a.opt.Logf("cluster: "+f, v...) }

// Start runs the agent's loops until Stop.
func (a *Agent) Start(ctx context.Context) {
	a.ctx, a.cancel = context.WithCancel(ctx)
	a.loadRoster()
	go a.loop()
	go a.applyWorker()
	go a.blobs.loop(a.ctx)
}

func (a *Agent) Stop() {
	if a.cancel != nil {
		a.cancel()
	}
	a.mu.Lock()
	links := a.allLinks()
	if a.srv != nil {
		_ = a.srv.Close()
		a.srv, a.listener = nil, nil
	}
	a.mu.Unlock()
	for _, l := range links {
		l.Send(Frame{T: "bye"})
		l.Close("shutting down")
	}
	a.hub.closeAll()
}

// SetNetwork switches to a (new) private network, or none. Links of the
// previous network are closed; the listener starts on the next discovery.
func (a *Agent) SetNetwork(n Network) {
	a.mu.Lock()
	a.net = n
	a.netGen++
	if a.srv != nil {
		_ = a.srv.Close()
		a.srv, a.listener = nil, nil
	}
	links := a.allLinks()
	a.backoff = map[string]backoffState{}
	a.mu.Unlock()
	for _, l := range links {
		l.Close("network changed")
	}
	a.Kick()
}

// Kick runs discovery and a sync round soon.
func (a *Agent) Kick() {
	select {
	case a.kick <- struct{}{}:
	default:
	}
}

// ReloadRoster re-reads projects and members from PHP.
func (a *Agent) ReloadRoster() {
	select {
	case a.rosterC <- struct{}{}:
	default:
	}
}

func (a *Agent) allLinks() []*Link {
	out := make([]*Link, 0, len(a.links))
	for _, l := range a.links {
		out = append(out, l)
	}
	return out
}

func (a *Agent) loop() {
	discover := time.NewTicker(a.opt.Discover)
	flush := time.NewTicker(a.opt.FlushEvery)
	sync := time.NewTicker(a.opt.SyncEvery)
	defer discover.Stop()
	defer flush.Stop()
	defer sync.Stop()
	a.discover()
	for {
		select {
		case <-a.ctx.Done():
			return
		case <-discover.C:
			a.discover()
		case <-a.kick:
			a.discover()
			a.antiEntropy()
		case <-a.rosterC:
			a.loadRoster()
			a.discover()
			a.antiEntropy()
		case <-flush.C:
			a.flush()
		case <-sync.C:
			a.loadRoster()
			a.antiEntropy()
		}
	}
}

func (a *Agent) loadRoster() {
	ctx, cancel := context.WithTimeout(a.ctx, 20*time.Second)
	defer cancel()
	r, err := a.opt.Store.Roster(ctx)
	if err != nil {
		a.logf("roster: %v", err)
		return
	}
	a.mu.Lock()
	a.roster = r
	// links to nodes that are no longer members of any shared project end
	var drop []*Link
	for node, l := range a.links {
		if len(a.sharedProjectsLocked(node)) == 0 {
			drop = append(drop, l)
		}
	}
	a.mu.Unlock()
	for _, l := range drop {
		l.Close("no longer a member")
	}
	a.blobs.wantMissing(r)
}

func (a *Agent) flush() {
	ctx, cancel := context.WithTimeout(a.ctx, 60*time.Second)
	defer cancel()
	batches, err := a.opt.Store.Flush(ctx)
	if err != nil {
		a.logf("flush: %v", err)
		return
	}
	a.Publish(batches)
}

// SyncNow flushes local changes and compares version vectors with every
// connected peer.
func (a *Agent) SyncNow() {
	a.flush()
	a.Kick()
}

// member returns the roster entry of node in project.
func (a *Agent) memberLocked(project, node string) (Member, bool) {
	for _, p := range a.roster.Projects {
		if p.Gid != project {
			continue
		}
		for _, m := range p.Members {
			if m.Node == node && !m.Removed {
				return m, true
			}
		}
	}
	return Member{}, false
}

// sharedProjectsLocked lists the projects node is an active member of.
func (a *Agent) sharedProjectsLocked(node string) []string {
	var out []string
	for _, p := range a.roster.Projects {
		for _, m := range p.Members {
			if m.Node == node && !m.Removed {
				out = append(out, p.Gid)
				break
			}
		}
	}
	return out
}

func (a *Agent) shares(project, node string) bool {
	a.mu.Lock()
	defer a.mu.Unlock()
	_, ok := a.memberLocked(project, node)
	return ok
}

// pubOfLocked returns the key the roster records for node (any project).
func (a *Agent) pubOfLocked(node string) string {
	for _, p := range a.roster.Projects {
		for _, m := range p.Members {
			if m.Node == node && !m.Removed {
				return m.Pub
			}
		}
	}
	return ""
}

func (a *Agent) linksFor(project, except string) []*Link {
	a.mu.Lock()
	defer a.mu.Unlock()
	var out []*Link
	for node, l := range a.links {
		if node == except {
			continue
		}
		if _, ok := a.memberLocked(project, node); ok {
			out = append(out, l)
		}
	}
	return out
}

// ---- discovery and links -------------------------------------------------

func (a *Agent) selfHello() helloInfo {
	a.mu.Lock()
	h := helloInfo{Node: a.Node(), Name: a.roster.Name}
	n := a.net
	a.mu.Unlock()
	if n != nil {
		ctx, cancel := context.WithTimeout(a.ctx, 3*time.Second)
		h.Addr, h.DNS, _ = n.Self(ctx)
		cancel()
	}
	return h
}

func (a *Agent) discover() {
	a.mu.Lock()
	n := a.net
	gen := a.netGen
	if n == nil {
		a.mu.Unlock()
		return
	}
	if a.srv == nil {
		if ln, err := n.Listen(); err == nil {
			mux := http.NewServeMux()
			mux.HandleFunc(LinkPath, a.serveLink)
			a.listener = ln
			a.srv = &http.Server{Handler: mux, ReadHeaderTimeout: 15 * time.Second}
			go func(srv *http.Server, ln net.Listener) { _ = srv.Serve(ln) }(a.srv, ln)
		}
	}
	type target struct {
		key, addr, pub string
		join           *PendingJoin
	}
	var targets []target
	linkedAddr := map[string]bool{}
	for _, l := range a.links {
		linkedAddr[l.Peer.Addr] = true
	}
	wanted := map[string]Member{}
	for _, p := range a.roster.Projects {
		for _, m := range p.Members {
			if m.Node != a.Node() && !m.Removed {
				wanted[m.Node] = m
			}
		}
	}
	joins := append([]PendingJoin(nil), a.roster.Joins...)
	a.mu.Unlock()

	ctx, cancel := context.WithTimeout(a.ctx, 5*time.Second)
	peers, err := n.Peers(ctx)
	cancel()
	if err != nil {
		return // not running yet (signed out, starting, offline)
	}
	online := map[string]NetPeer{}
	for _, p := range peers {
		if p.Online {
			online[p.IP] = p
		}
	}

	a.mu.Lock()
	now := time.Now()
	for node, m := range wanted {
		if a.links[node] != nil {
			delete(a.waiting, node)
			continue
		}
		if _, ok := online[m.Addr]; !ok || a.dialing[node] || !a.dueLocked(node) {
			continue
		}
		// The node with the smaller id dials; the other waits a moment so
		// both do not dial at once (it still dials if no link appears, for
		// example when the network only allows one direction).
		if a.Node() > node {
			since, ok := a.waiting[node]
			if !ok {
				a.waiting[node] = now
				continue
			}
			if now.Sub(since) < dialGrace {
				continue
			}
		}
		targets = append(targets, target{key: node, addr: m.Addr, pub: m.Pub})
	}
	for i := range joins {
		j := joins[i]
		key := "join:" + j.Inviter.Node
		if a.links[j.Inviter.Node] != nil || a.dialing[key] || !a.dueLocked(key) {
			continue
		}
		if _, ok := online[j.Inviter.Addr]; ok {
			targets = append(targets, target{key: key, addr: j.Inviter.Addr, pub: j.Inviter.Pub, join: &j})
		}
	}
	// a member whose address changed is found by probing other OpenQDA nodes
	for ip, p := range online {
		key := "ip:" + ip
		if linkedAddr[ip] || a.dialing[key] || !a.dueLocked(key) || !strings.HasPrefix(strings.ToLower(p.Host), "openqda") {
			continue
		}
		known := false
		for _, t := range targets {
			if t.addr == ip {
				known = true
			}
		}
		if !known {
			targets = append(targets, target{key: key, addr: ip})
		}
	}
	for _, t := range targets {
		a.dialing[t.key] = true
	}
	a.mu.Unlock()

	for _, t := range targets {
		go func(t target) {
			err := a.dial(gen, t.addr, t.pub, t.join)
			a.mu.Lock()
			delete(a.dialing, t.key)
			if err != nil {
				a.failLocked(t.key, strings.HasPrefix(t.key, "ip:"))
			} else {
				delete(a.backoff, t.key)
			}
			a.mu.Unlock()
			if err != nil && !errors.Is(err, context.Canceled) {
				a.logf("link to %s: %v", t.addr, err)
			}
		}(t)
	}
}

func (a *Agent) dueLocked(key string) bool {
	b, ok := a.backoff[key]
	return !ok || time.Now().After(b.next)
}

func (a *Agent) failLocked(key string, probe bool) {
	b := a.backoff[key]
	switch {
	case probe:
		b.delay = 5 * time.Minute
	case b.delay == 0:
		b.delay = 3 * time.Second
	default:
		b.delay *= 2
		if b.delay > time.Minute {
			b.delay = time.Minute
		}
	}
	b.next = time.Now().Add(b.delay)
	a.backoff[key] = b
}

func (a *Agent) dial(gen int, addr, expectPub string, join *PendingJoin) error {
	a.mu.Lock()
	n := a.net
	a.mu.Unlock()
	if n == nil {
		return errors.New("no network")
	}
	ctx, cancel := context.WithTimeout(a.ctx, handshakeTimeout)
	defer cancel()
	conn, _, err := websocket.Dial(ctx, "ws://"+net.JoinHostPort(addr, LinkPort)+LinkPath, &websocket.DialOptions{HTTPClient: httpClientVia(n)})
	if err != nil {
		return err
	}
	self := a.selfHello()
	if join != nil {
		self.Invite = join.Invite
	}
	peer, welcome, err := dialHandshake(ctx, conn, a.opt.Identity, self, expectPub)
	if err != nil {
		_ = conn.Close(websocket.StatusPolicyViolation, "handshake failed")
		if join != nil && IsRefusal(err) {
			_ = a.opt.Store.JoinFailed(a.ctx, join.ID, err.Error())
			a.ReloadRoster()
		}
		return err
	}
	if join != nil {
		if err := a.opt.Store.Joined(a.ctx, join.ID, welcome); err != nil {
			_ = conn.Close(websocket.StatusInternalError, "join failed")
			return err
		}
		a.loadRoster()
	}
	a.mu.Lock()
	member := len(a.sharedProjectsLocked(peer.Node)) > 0 && a.pubOfLocked(peer.Node) == peer.Pub
	stale := gen != a.netGen
	a.mu.Unlock()
	if stale {
		_ = conn.Close(websocket.StatusGoingAway, "network changed")
		return context.Canceled
	}
	if !member {
		_ = conn.Close(websocket.StatusPolicyViolation, "not a member")
		return errors.New("peer is not a member of a shared project")
	}
	a.runLink(newLink(conn, peer, a.Node()))
	return nil
}

func (a *Agent) serveLink(w http.ResponseWriter, r *http.Request) {
	conn, err := websocket.Accept(w, r, nil)
	if err != nil {
		return
	}
	peer, err := acceptHandshake(r.Context(), conn, a.opt.Identity, a.selfHello(), a.authorize)
	if err != nil {
		_ = conn.Close(websocket.StatusPolicyViolation, "handshake failed")
		return
	}
	// the handler goroutine stays with the link; the link uses the agent's context
	a.runLink(newLink(conn, peer, peer.Node))
}

// authorize admits members of a shared project, or a node presenting a
// valid invite (PHP checks it and adds the node to the roster).
func (a *Agent) authorize(ctx context.Context, peer helloInfo) (json.RawMessage, error) {
	a.mu.Lock()
	pub := a.pubOfLocked(peer.Node)
	a.mu.Unlock()
	if pub != "" {
		if pub != peer.Pub {
			return nil, errors.New("key differs from the roster")
		}
		return nil, nil
	}
	if len(peer.Invite) == 0 {
		return nil, errors.New("not a member of a shared project")
	}
	welcome, err := a.opt.Store.Join(ctx, JoinRequest{Node: peer.Node, Pub: peer.Pub, Name: peer.Name, Addr: peer.Addr, DNS: peer.DNS, Invite: peer.Invite})
	if err != nil {
		if errors.Is(err, ErrRejected) {
			return nil, errors.New(strings.TrimPrefix(err.Error(), ErrRejected.Error()+": "))
		}
		return nil, errors.New("the invitation could not be checked, try again")
	}
	a.loadRoster()
	return welcome, nil
}

// runLink registers the link (dropping a duplicate) and serves it.
func (a *Agent) runLink(l *Link) {
	node := l.Peer.Node
	a.mu.Lock()
	if old := a.links[node]; old != nil {
		preferred := minNode(a.Node(), node)
		if l.Dialer == preferred && old.Dialer != preferred {
			old.Close("replaced by preferred link")
		} else {
			a.mu.Unlock()
			l.Close("duplicate link")
			return
		}
	}
	a.links[node] = l
	rp := a.remote[node]
	if rp == nil {
		rp = &remotePeer{}
		a.remote[node] = rp
	}
	rp.Name, rp.Addr, rp.DNS, rp.LastSeen = l.Peer.Name, l.Peer.Addr, l.Peer.DNS, time.Now()
	delete(a.backoff, node)
	a.mu.Unlock()
	a.logf("linked with %s (%s)", node, l.Peer.Name)

	go a.sendVVs(l)
	l.run(a.ctx, a.opt.Heartbeat, a.opt.Offline, a.heartbeat, a.handleFrame)

	a.mu.Lock()
	if a.links[node] == l {
		delete(a.links, node)
		if rp := a.remote[node]; rp != nil {
			rp.Presence = nil
			rp.LastSeen = time.Now()
		}
	}
	a.mu.Unlock()
	a.hub.presenceChanged("")
	a.logf("link with %s closed", node)
}

func minNode(x, y string) string {
	if x < y {
		return x
	}
	return y
}

func (a *Agent) heartbeat() Frame {
	a.mu.Lock()
	name := a.roster.Name
	a.mu.Unlock()
	return Frame{T: "hb", Presence: a.hub.localPresence(""), Name: name}
}

// sendHeartbeats pushes presence right away (after a local change).
func (a *Agent) sendHeartbeats() {
	a.mu.Lock()
	links := a.allLinks()
	a.mu.Unlock()
	hb := a.heartbeat()
	for _, l := range links {
		l.Send(hb)
	}
}

func (a *Agent) handleFrame(l *Link, f Frame) {
	node := l.Peer.Node
	switch f.T {
	case "hb":
		a.mu.Lock()
		rp := a.remote[node]
		if rp != nil {
			rp.LastSeen = time.Now()
			// presence is accepted only for projects the peer shares with us
			var keep []Presence
			for _, p := range f.Presence {
				if _, ok := a.memberLocked(p.Project, node); ok {
					p.Node = node
					keep = append(keep, p)
				}
			}
			changed := !samePresence(rp.Presence, keep)
			rp.Presence = keep
			a.mu.Unlock()
			if changed {
				a.hub.presenceChanged("")
			}
		} else {
			a.mu.Unlock()
		}
	case "vv":
		if a.shares(f.Project, node) {
			go a.sendMissing(l, f.Project, f.VV)
		}
	case "ops":
		if a.shares(f.Project, node) && len(f.Ops) > 0 {
			select {
			case a.applyCh <- applyJob{from: node, project: f.Project, ops: f.Ops}:
			case <-a.ctx.Done():
			}
		}
	case "bget":
		go a.serveBlob(l, f)
	case "bdat", "berr":
		a.blobs.onFrame(l, f)
	case "y", "aw":
		go a.onRemoteDoc(l, f)
	}
}

func samePresence(x, y []Presence) bool {
	if len(x) != len(y) {
		return false
	}
	for i := range x {
		if x[i] != y[i] {
			return false
		}
	}
	return true
}

// ---- replication ----------------------------------------------------------

// Publish sends new local operations to the online members of their
// project and the matching events to the browsers on this computer.
func (a *Agent) Publish(batches []OutBatch) {
	for _, b := range batches {
		if len(b.Ops) > 0 {
			for _, l := range a.linksFor(b.Project, "") {
				for _, chunk := range chunkOps(b.Ops, 200) {
					l.Send(Frame{T: "ops", Project: b.Project, Ops: chunk})
				}
			}
		}
		if len(b.Events) > 0 {
			a.hub.events(b.Project, b.Events)
		}
	}
}

func chunkOps(ops []json.RawMessage, n int) [][]json.RawMessage {
	var out [][]json.RawMessage
	for len(ops) > n {
		out = append(out, ops[:n])
		ops = ops[n:]
	}
	if len(ops) > 0 {
		out = append(out, ops)
	}
	return out
}

func (a *Agent) applyWorker() {
	for {
		select {
		case <-a.ctx.Done():
			return
		case job := <-a.applyCh:
			a.apply(job)
		}
	}
}

func (a *Agent) apply(job applyJob) {
	ctx, cancel := context.WithTimeout(a.ctx, 5*time.Minute)
	defer cancel()
	res, err := a.opt.Store.Apply(ctx, job.from, job.project, job.ops)
	if err != nil {
		a.logf("apply from %s: %v", job.from, err)
		return
	}
	if len(res.Events) > 0 {
		a.hub.events(job.project, res.Events)
	}
	// forward what was new here, so peers that are not linked to the
	// origin get it too (duplicates are ignored by PHP)
	if len(res.New) > 0 {
		fwd := make([]json.RawMessage, 0, len(res.New))
		for _, i := range res.New {
			if i >= 0 && i < len(job.ops) {
				fwd = append(fwd, job.ops[i])
			}
		}
		for _, l := range a.linksFor(job.project, job.from) {
			for _, chunk := range chunkOps(fwd, 200) {
				l.Send(Frame{T: "ops", Project: job.project, Ops: chunk})
			}
		}
	}
	for _, sha := range res.NeedBlobs {
		a.blobs.want(sha, job.project, job.from)
	}
	if res.Roster {
		a.ReloadRoster()
	}
}

// sendVVs tells a new peer what we have for every shared project; the peer
// answers with what we lack (and vice versa).
func (a *Agent) sendVVs(l *Link) {
	a.mu.Lock()
	projects := a.sharedProjectsLocked(l.Peer.Node)
	a.mu.Unlock()
	for _, p := range projects {
		ctx, cancel := context.WithTimeout(a.ctx, 30*time.Second)
		vv, err := a.opt.Store.VV(ctx, p)
		cancel()
		if err != nil {
			a.logf("vv %s: %v", p, err)
			continue
		}
		l.Send(Frame{T: "vv", Project: p, VV: vv})
	}
}

func (a *Agent) antiEntropy() {
	a.mu.Lock()
	links := a.allLinks()
	a.mu.Unlock()
	for _, l := range links {
		go a.sendVVs(l)
	}
}

// sendMissing streams the operations the peer lacks, page by page.
func (a *Agent) sendMissing(l *Link, project string, vv map[string]int64) {
	have := map[string]int64{}
	for k, v := range vv {
		have[k] = v
	}
	for i := 0; i < 10000; i++ {
		ctx, cancel := context.WithTimeout(a.ctx, 2*time.Minute)
		page, err := a.opt.Store.Ops(ctx, project, have, opsPageSize)
		cancel()
		if err != nil {
			a.logf("ops for %s: %v", l.Peer.Node, err)
			return
		}
		if len(page.Ops) == 0 {
			return
		}
		for _, chunk := range chunkOps(page.Ops, 200) {
			if !l.SendWait(a.ctx, Frame{T: "ops", Project: project, Ops: chunk}) {
				return
			}
		}
		for _, raw := range page.Ops {
			var h struct {
				O string `json:"o"`
				S int64  `json:"s"`
			}
			if json.Unmarshal(raw, &h) == nil && h.S > have[h.O] {
				have[h.O] = h.S
			}
		}
		if !page.More {
			return
		}
	}
}

// ---- co-editing fast path -------------------------------------------------

// relayDoc sends a browser's co-editing update or cursor state to the
// online members of the project.
func (a *Agent) relayDoc(t, project, doc string, u []byte, from string) {
	for _, l := range a.linksFor(project, "") {
		l.Send(Frame{T: t, Project: project, Doc: doc, U: u, From: from})
	}
}

func (a *Agent) docInfo(key string) (DocInfo, bool) {
	a.mu.Lock()
	e, ok := a.docs[key]
	a.mu.Unlock()
	if ok && time.Since(e.at) < time.Minute {
		return e.info, true
	}
	ctx, cancel := context.WithTimeout(a.ctx, 10*time.Second)
	defer cancel()
	info, err := a.opt.Store.Doc(ctx, key)
	if err != nil {
		return DocInfo{}, false
	}
	a.mu.Lock()
	a.docs[key] = docEntry{info: info, at: time.Now()}
	a.mu.Unlock()
	return info, true
}

// onRemoteDoc delivers a peer's live update after checking that the peer
// may edit the text (cursor states only need membership).
func (a *Agent) onRemoteDoc(l *Link, f Frame) {
	node := l.Peer.Node
	if !a.shares(f.Project, node) {
		return
	}
	info, ok := a.docInfo(f.Doc)
	if !ok || info.Project != f.Project {
		return
	}
	if f.T == "y" && !contains(info.Editors, node) {
		return
	}
	a.hub.deliverDoc(f.T, f.Project, f.Doc, f.U, node+":"+f.From, "")
}

func contains(list []string, s string) bool {
	for _, v := range list {
		if v == s {
			return true
		}
	}
	return false
}

// ---- status ----------------------------------------------------------------

// Status reports peers and presence for the Collaboration page.
func (a *Agent) Status() AgentStatus {
	a.mu.Lock()
	n := a.net
	a.mu.Unlock()
	direct := map[string]bool{}
	if n != nil {
		ctx, cancel := context.WithTimeout(a.ctx, 3*time.Second)
		if peers, err := n.Peers(ctx); err == nil {
			for _, p := range peers {
				direct[p.IP] = p.Direct
			}
		}
		cancel()
	}
	a.mu.Lock()
	defer a.mu.Unlock()
	st := AgentStatus{Node: a.Node(), Network: n != nil}
	byNode := map[string]*PeerStatus{}
	for _, p := range a.roster.Projects {
		for _, m := range p.Members {
			if m.Node == a.Node() || m.Removed {
				continue
			}
			ps := byNode[m.Node]
			if ps == nil {
				ps = &PeerStatus{Node: m.Node, Name: m.Name, Addr: m.Addr}
				byNode[m.Node] = ps
			}
			ps.Projects = append(ps.Projects, p.Gid)
		}
	}
	for node, ps := range byNode {
		if l := a.links[node]; l != nil {
			ps.Linked, ps.Online, ps.Addr = true, true, l.Peer.Addr
			ps.Direct = direct[l.Peer.Addr]
		}
		if rp := a.remote[node]; rp != nil {
			ps.LastSeen = rp.LastSeen
		}
		st.Peers = append(st.Peers, *ps)
	}
	sort.Slice(st.Peers, func(i, j int) bool { return st.Peers[i].Name < st.Peers[j].Name })
	st.Presence = a.presenceLocked("")
	return st
}

// presenceLocked lists everyone online (here and on linked peers) in
// project, or in every project when project is empty.
func (a *Agent) presenceLocked(project string) []Presence {
	out := a.hub.localPresence(project)
	for node, rp := range a.remote {
		if a.links[node] == nil {
			continue
		}
		for _, p := range rp.Presence {
			if project == "" || p.Project == project {
				out = append(out, p)
			}
		}
	}
	return out
}

// Presence lists everyone online in project.
func (a *Agent) Presence(project string) []Presence {
	a.mu.Lock()
	defer a.mu.Unlock()
	return a.presenceLocked(project)
}
