package cluster

import (
	"bytes"
	"context"
	"crypto/sha256"
	"encoding/hex"
	"encoding/json"
	"errors"
	"fmt"
	"io"
	"net"
	"net/http"
	"net/http/httptest"
	"sort"
	"strings"
	"sync"
	"testing"
	"time"

	"github.com/coder/websocket"
	"github.com/coder/websocket/wsjson"
)

// ---- fakes ---------------------------------------------------------------

// fakeRegistry is a pretend tailnet: every node gets a 100.64.x address
// that maps to a real loopback listener.
type fakeRegistry struct {
	mu    sync.Mutex
	nodes map[string]*fakeNet
	addrs map[string]string
}

func newRegistry() *fakeRegistry {
	return &fakeRegistry{nodes: map[string]*fakeNet{}, addrs: map[string]string{}}
}

type fakeNet struct {
	reg  *fakeRegistry
	ip   string
	host string
	up   bool
}

func (r *fakeRegistry) add(ip, host string) *fakeNet {
	r.mu.Lock()
	defer r.mu.Unlock()
	n := &fakeNet{reg: r, ip: ip, host: host, up: true}
	r.nodes[ip] = n
	return n
}

func (r *fakeRegistry) setUp(ip string, up bool) {
	r.mu.Lock()
	defer r.mu.Unlock()
	r.nodes[ip].up = up
}

func (n *fakeNet) Listen() (net.Listener, error) {
	ln, err := net.Listen("tcp", "127.0.0.1:0")
	if err != nil {
		return nil, err
	}
	n.reg.mu.Lock()
	n.reg.addrs[n.ip] = ln.Addr().String()
	n.reg.mu.Unlock()
	return ln, nil
}

func (n *fakeNet) Dial(ctx context.Context, addr string) (net.Conn, error) {
	host, _, _ := net.SplitHostPort(addr)
	n.reg.mu.Lock()
	real := n.reg.addrs[host]
	target := n.reg.nodes[host]
	selfUp := n.reg.nodes[n.ip].up
	n.reg.mu.Unlock()
	if real == "" || target == nil || !target.up || !selfUp {
		return nil, errors.New("unreachable")
	}
	var d net.Dialer
	return d.DialContext(ctx, "tcp", real)
}

func (n *fakeNet) Peers(context.Context) ([]NetPeer, error) {
	n.reg.mu.Lock()
	defer n.reg.mu.Unlock()
	if !n.reg.nodes[n.ip].up {
		return nil, errors.New("offline")
	}
	var out []NetPeer
	for ip, o := range n.reg.nodes {
		if ip != n.ip {
			out = append(out, NetPeer{IP: ip, Host: o.host, Online: o.up && n.reg.addrs[ip] != "", Direct: true})
		}
	}
	return out, nil
}

func (n *fakeNet) Self(context.Context) (string, string, error) {
	return n.ip, n.host + ".example.ts.net", nil
}

// fakeStore is a minimal PHP stand-in: an append-only op log per project.
type fakeStore struct {
	mu        sync.Mutex
	roster    Roster
	ops       map[string][]json.RawMessage
	seen      map[string]bool
	blobs     map[string][]byte
	put       map[string][]byte
	joinToken string
	joinAdd   Member
	joined    []int64
	failed    []string
	editors   []string
	events    int
}

func newStore(node, name string) *fakeStore {
	return &fakeStore{roster: Roster{Node: node, Name: name}, ops: map[string][]json.RawMessage{}, seen: map[string]bool{},
		blobs: map[string][]byte{}, put: map[string][]byte{}}
}

func op(origin string, seq int64, project string) json.RawMessage {
	return json.RawMessage(fmt.Sprintf(`{"o":%q,"s":%d,"p":%q}`, origin, seq, project))
}

func opKey(raw json.RawMessage) (string, string, int64) {
	var h struct {
		O string `json:"o"`
		S int64  `json:"s"`
	}
	_ = json.Unmarshal(raw, &h)
	return fmt.Sprintf("%s:%d", h.O, h.S), h.O, h.S
}

func (s *fakeStore) local(project string, ops ...json.RawMessage) {
	s.mu.Lock()
	defer s.mu.Unlock()
	for _, o := range ops {
		k, _, _ := opKey(o)
		s.seen[k] = true
		s.ops[project] = append(s.ops[project], o)
	}
}

func (s *fakeStore) count(project string) int {
	s.mu.Lock()
	defer s.mu.Unlock()
	return len(s.ops[project])
}

func (s *fakeStore) Roster(context.Context) (Roster, error) {
	s.mu.Lock()
	defer s.mu.Unlock()
	raw, _ := json.Marshal(s.roster)
	var r Roster
	_ = json.Unmarshal(raw, &r)
	return r, nil
}

func (s *fakeStore) Apply(_ context.Context, _, project string, ops []json.RawMessage) (ApplyResult, error) {
	s.mu.Lock()
	defer s.mu.Unlock()
	var res ApplyResult
	for i, o := range ops {
		k, _, _ := opKey(o)
		if s.seen[k] {
			continue
		}
		s.seen[k] = true
		s.ops[project] = append(s.ops[project], o)
		res.New = append(res.New, i)
	}
	if len(res.New) > 0 {
		s.events++
		res.Events = []json.RawMessage{json.RawMessage(`{"tbl":"codes"}`)}
	}
	return res, nil
}

func (s *fakeStore) Ops(_ context.Context, project string, vv map[string]int64, limit int) (OpsPage, error) {
	s.mu.Lock()
	defer s.mu.Unlock()
	var page OpsPage
	for _, o := range s.ops[project] {
		_, origin, seq := opKey(o)
		if seq <= vv[origin] {
			continue
		}
		if len(page.Ops) == limit {
			page.More = true
			break
		}
		page.Ops = append(page.Ops, o)
	}
	return page, nil
}

func (s *fakeStore) VV(_ context.Context, project string) (map[string]int64, error) {
	s.mu.Lock()
	defer s.mu.Unlock()
	have := map[string]map[int64]bool{}
	for _, o := range s.ops[project] {
		_, origin, seq := opKey(o)
		if have[origin] == nil {
			have[origin] = map[int64]bool{}
		}
		have[origin][seq] = true
	}
	vv := map[string]int64{}
	for origin, seqs := range have {
		var n int64
		for seqs[n+1] {
			n++
		}
		vv[origin] = n
	}
	return vv, nil
}

func (s *fakeStore) BlobGet(_ context.Context, sha, _ string, offset int64) (io.ReadCloser, int64, error) {
	s.mu.Lock()
	defer s.mu.Unlock()
	b, ok := s.blobs[sha]
	if !ok {
		return nil, 0, errors.New("missing")
	}
	return io.NopCloser(bytes.NewReader(b[offset:])), int64(len(b)), nil
}

func (s *fakeStore) BlobPut(_ context.Context, sha string, r io.Reader) error {
	b, err := io.ReadAll(r)
	if err != nil {
		return err
	}
	s.mu.Lock()
	defer s.mu.Unlock()
	s.put[sha] = b
	return nil
}

func (s *fakeStore) Join(_ context.Context, req JoinRequest) (json.RawMessage, error) {
	var inv struct {
		Token string `json:"token"`
	}
	_ = json.Unmarshal(req.Invite, &inv)
	s.mu.Lock()
	defer s.mu.Unlock()
	if inv.Token == "" || inv.Token != s.joinToken {
		return nil, fmt.Errorf("%w: this invitation is not valid", ErrRejected)
	}
	m := s.joinAdd
	m.Node, m.Pub = req.Node, req.Pub
	s.roster.Projects[0].Members = append(s.roster.Projects[0].Members, m)
	return json.RawMessage(`{"ok":true}`), nil
}

func (s *fakeStore) Joined(_ context.Context, id int64, _ json.RawMessage) error {
	s.mu.Lock()
	defer s.mu.Unlock()
	s.joined = append(s.joined, id)
	s.roster.Joins = nil
	return nil
}

func (s *fakeStore) JoinFailed(_ context.Context, id int64, msg string) error {
	s.mu.Lock()
	defer s.mu.Unlock()
	s.failed = append(s.failed, msg)
	s.roster.Joins = nil
	return nil
}

func (s *fakeStore) Doc(_ context.Context, key string) (DocInfo, error) {
	s.mu.Lock()
	defer s.mu.Unlock()
	return DocInfo{Project: "p1", Editors: s.editors}, nil
}

func (s *fakeStore) Flush(context.Context) ([]OutBatch, error) { return nil, nil }

// ---- harness -------------------------------------------------------------

type testNode struct {
	id    Identity
	store *fakeStore
	net   *fakeNet
	agent *Agent
	key   []byte
}

func newNode(t *testing.T, reg *fakeRegistry, ip, name string) *testNode {
	t.Helper()
	seed, _ := NewSeed()
	id, err := IdentityFromSeed(seed)
	if err != nil {
		t.Fatal(err)
	}
	st := newStore(id.Node, name)
	key := DeriveKey("token-"+name, "live-token-v1")
	a := NewAgent(Options{Identity: id, Store: st, DataDir: t.TempDir(), LiveKey: key, Logf: func(f string, v ...any) { t.Logf(name+": "+f, v...) },
		Heartbeat: 100 * time.Millisecond, Offline: 800 * time.Millisecond, Discover: 100 * time.Millisecond,
		FlushEvery: time.Hour, SyncEvery: time.Hour})
	return &testNode{id: id, store: st, net: reg.add(ip, "openqda-"+name), agent: a, key: key}
}

func (n *testNode) member(role string) Member {
	return Member{Node: n.id.Node, Pub: n.id.PubB64(), Name: n.store.roster.Name, Role: role, Addr: n.net.ip}
}

// share puts all nodes into project p1 on every node's roster.
func share(nodes ...*testNode) {
	var members []Member
	for i, n := range nodes {
		role := "assistant"
		if i == 0 {
			role = "primary"
		}
		members = append(members, n.member(role))
	}
	for _, n := range nodes {
		n.store.mu.Lock()
		own := append([]Member(nil), members...)
		n.store.roster.Projects = []RosterProject{{Gid: "p1", Name: "Interviews", Members: own}}
		n.store.mu.Unlock()
	}
}

func (n *testNode) start(t *testing.T) {
	t.Helper()
	n.agent.Start(context.Background())
	n.agent.SetNetwork(n.net)
	t.Cleanup(n.agent.Stop)
}

func eventually(t *testing.T, what string, cond func() bool) {
	t.Helper()
	deadline := time.Now().Add(10 * time.Second)
	for time.Now().Before(deadline) {
		if cond() {
			return
		}
		time.Sleep(25 * time.Millisecond)
	}
	t.Fatalf("timed out waiting for %s", what)
}

func linked(a *testNode, b *testNode) bool {
	for _, p := range a.agent.Status().Peers {
		if p.Node == b.id.Node && p.Linked {
			return true
		}
	}
	return false
}

// ---- tests ---------------------------------------------------------------

func TestIdentityIsStableAndSignaturesVerify(t *testing.T) {
	seed, _ := NewSeed()
	a, err := IdentityFromSeed(seed)
	if err != nil {
		t.Fatal(err)
	}
	b, _ := IdentityFromSeed(seed)
	if a.Node != b.Node || !strings.HasPrefix(a.Node, "n") || len(a.Node) != 21 {
		t.Fatalf("unexpected node id %q / %q", a.Node, b.Node)
	}
	msg := []byte("hello")
	if !VerifyB64(a.PubB64(), msg, a.Sign(msg)) || VerifyB64(a.PubB64(), []byte("other"), a.Sign(msg)) {
		t.Fatal("signature check is wrong")
	}
	if !NodeMatches(a.Node, a.PubB64()) || NodeMatches("nffff", a.PubB64()) {
		t.Fatal("node id check is wrong")
	}
	if _, err := IdentityFromSeed("not-base64!"); err == nil {
		t.Fatal("expected an error for a bad seed")
	}
}

func TestMembersLinkAndOutsidersAreRefused(t *testing.T) {
	reg := newRegistry()
	alice := newNode(t, reg, "100.64.0.1", "alice")
	bob := newNode(t, reg, "100.64.0.2", "bob")
	eve := newNode(t, reg, "100.64.0.3", "eve")
	share(alice, bob)
	// eve claims to be in the project on her own roster, but alice and bob do not list her
	eve.store.roster.Projects = []RosterProject{{Gid: "p1", Members: []Member{alice.member("primary"), eve.member("primary")}}}
	alice.start(t)
	bob.start(t)
	eve.start(t)

	eventually(t, "alice and bob to link", func() bool { return linked(alice, bob) && linked(bob, alice) })
	time.Sleep(500 * time.Millisecond)
	if linked(alice, eve) || linked(eve, alice) {
		t.Fatal("a node outside the roster must not link")
	}
	if n := len(alice.agent.Status().Peers); n != 1 {
		t.Fatalf("alice should list one peer, got %d", n)
	}
}

func TestRosterKeyMismatchIsRefused(t *testing.T) {
	reg := newRegistry()
	alice := newNode(t, reg, "100.64.0.1", "alice")
	bob := newNode(t, reg, "100.64.0.2", "bob")
	share(alice, bob)
	// alice's roster has a different key for bob's node id
	other, _ := NewSeed()
	oid, _ := IdentityFromSeed(other)
	alice.store.roster.Projects[0].Members[1].Pub = oid.PubB64()
	alice.start(t)
	bob.start(t)
	time.Sleep(time.Second)
	if linked(alice, bob) || linked(bob, alice) {
		t.Fatal("a key that differs from the roster must be refused")
	}
}

func TestChangesFanOutAndOfflinePeersCatchUp(t *testing.T) {
	reg := newRegistry()
	alice := newNode(t, reg, "100.64.0.1", "alice")
	bob := newNode(t, reg, "100.64.0.2", "bob")
	carol := newNode(t, reg, "100.64.0.3", "carol")
	share(alice, bob, carol)
	for _, n := range []*testNode{alice, bob, carol} {
		n.start(t)
	}
	eventually(t, "full mesh", func() bool { return linked(alice, bob) && linked(alice, carol) && linked(bob, carol) })

	// a live change reaches everyone right away
	o1 := op(alice.id.Node, 1, "p1")
	alice.store.local("p1", o1)
	alice.agent.Publish([]OutBatch{{Project: "p1", Ops: []json.RawMessage{o1}}})
	eventually(t, "fan-out", func() bool { return bob.store.count("p1") == 1 && carol.store.count("p1") == 1 })

	// carol goes offline; alice and bob keep working
	reg.setUp(carol.net.ip, false)
	carol.agent.SetNetwork(nil)
	eventually(t, "carol offline", func() bool { return !linked(alice, carol) })
	var batch []json.RawMessage
	for s := int64(2); s <= 1200; s++ { // more than one page
		batch = append(batch, op(alice.id.Node, s, "p1"))
	}
	alice.store.local("p1", batch...)
	alice.agent.Publish([]OutBatch{{Project: "p1", Ops: batch}})
	b1 := op(bob.id.Node, 1, "p1")
	bob.store.local("p1", b1)
	bob.agent.Publish([]OutBatch{{Project: "p1", Ops: []json.RawMessage{b1}}})
	eventually(t, "alice and bob in sync", func() bool { return alice.store.count("p1") == 1201 && bob.store.count("p1") == 1201 })
	if carol.store.count("p1") != 1 {
		t.Fatal("carol must not receive changes while offline")
	}

	// back online: carol catches up from version vectors
	reg.setUp(carol.net.ip, true)
	carol.agent.SetNetwork(carol.net)
	eventually(t, "carol catches up", func() bool { return carol.store.count("p1") == 1201 })
	vvA, _ := alice.store.VV(context.Background(), "p1")
	vvC, _ := carol.store.VV(context.Background(), "p1")
	if fmt.Sprint(vvA) != fmt.Sprint(vvC) {
		t.Fatalf("version vectors differ: %v vs %v", vvA, vvC)
	}
}

func TestChangesAreRelayedWhenTheOriginIsNotLinked(t *testing.T) {
	reg := newRegistry()
	alice := newNode(t, reg, "100.64.0.1", "alice")
	bob := newNode(t, reg, "100.64.0.2", "bob")
	carol := newNode(t, reg, "100.64.0.3", "carol")
	share(alice, bob, carol)
	// carol does not list alice's address, and alice does not list carol's: they only meet bob
	alice.store.roster.Projects[0].Members[2].Addr = "100.99.9.9"
	carol.store.roster.Projects[0].Members[0].Addr = "100.99.9.9"
	alice.net.host, carol.net.host = "alice-laptop", "carol-laptop" // not probed
	for _, n := range []*testNode{alice, bob, carol} {
		n.start(t)
	}
	eventually(t, "bob links both", func() bool { return linked(bob, alice) && linked(bob, carol) })
	o := op(alice.id.Node, 1, "p1")
	alice.store.local("p1", o)
	alice.agent.Publish([]OutBatch{{Project: "p1", Ops: []json.RawMessage{o}}})
	eventually(t, "relay through bob", func() bool { return carol.store.count("p1") == 1 })
}

func TestJoinWithInvite(t *testing.T) {
	reg := newRegistry()
	alice := newNode(t, reg, "100.64.0.1", "alice")
	bob := newNode(t, reg, "100.64.0.2", "bob")
	alice.store.roster.Projects = []RosterProject{{Gid: "p1", Members: []Member{alice.member("primary")}}}
	alice.store.joinToken = "secret"
	alice.store.joinAdd = Member{Name: "bob", Role: "assistant", Addr: bob.net.ip}
	bob.store.roster.Joins = []PendingJoin{{ID: 7, Project: "p1", Inviter: alice.member("primary"), Invite: json.RawMessage(`{"token":"secret"}`)}}
	alice.start(t)
	bob.start(t)
	eventually(t, "join", func() bool {
		bob.store.mu.Lock()
		defer bob.store.mu.Unlock()
		return len(bob.store.joined) == 1 && bob.store.joined[0] == 7
	})
	alice.store.mu.Lock()
	n := len(alice.store.roster.Projects[0].Members)
	alice.store.mu.Unlock()
	if n != 2 {
		t.Fatalf("inviter roster should have 2 members, got %d", n)
	}
}

func TestJoinWithWrongInviteIsReported(t *testing.T) {
	reg := newRegistry()
	alice := newNode(t, reg, "100.64.0.1", "alice")
	bob := newNode(t, reg, "100.64.0.2", "bob")
	alice.store.roster.Projects = []RosterProject{{Gid: "p1", Members: []Member{alice.member("primary")}}}
	alice.store.joinToken = "secret"
	bob.store.roster.Joins = []PendingJoin{{ID: 8, Project: "p1", Inviter: alice.member("primary"), Invite: json.RawMessage(`{"token":"guess"}`)}}
	alice.start(t)
	bob.start(t)
	eventually(t, "refusal", func() bool {
		bob.store.mu.Lock()
		defer bob.store.mu.Unlock()
		return len(bob.store.failed) == 1
	})
	if !strings.Contains(bob.store.failed[0], "not valid") {
		t.Fatalf("unexpected message %q", bob.store.failed[0])
	}
}

func sha(b []byte) string {
	s := sha256.Sum256(b)
	return hex.EncodeToString(s[:])
}

func TestFilesAreCopiedInCheckedChunks(t *testing.T) {
	reg := newRegistry()
	alice := newNode(t, reg, "100.64.0.1", "alice")
	bob := newNode(t, reg, "100.64.0.2", "bob")
	share(alice, bob)
	content := bytes.Repeat([]byte("interview transcript "), 40000) // ~800 KB, several chunks
	good := sha(content)
	alice.store.blobs[good] = content
	// a file whose bytes do not match its name must never be stored
	bad := sha([]byte("expected"))
	alice.store.blobs[bad] = []byte("tampered")
	alice.start(t)
	bob.start(t)
	eventually(t, "link", func() bool { return linked(bob, alice) })
	bob.agent.blobs.want(good, "p1", alice.id.Node)
	bob.agent.blobs.want(bad, "p1", alice.id.Node)
	eventually(t, "file copied", func() bool {
		bob.store.mu.Lock()
		defer bob.store.mu.Unlock()
		return bytes.Equal(bob.store.put[good], content)
	})
	time.Sleep(300 * time.Millisecond)
	bob.store.mu.Lock()
	_, stored := bob.store.put[bad]
	bob.store.mu.Unlock()
	if stored {
		t.Fatal("a file that fails its checksum must not be stored")
	}
}

// liveClient connects a pretend browser tab to a node's hub.
func liveClient(t *testing.T, n *testNode, user, project string) (*websocket.Conn, string) {
	t.Helper()
	srv := httptest.NewServer(n.agent.Hub())
	t.Cleanup(srv.Close)
	tok := SignToken(n.key, liveToken{Kind: "live", User: user, Name: user, Project: project, Exp: time.Now().Add(time.Minute).Unix()})
	ctx := context.Background()
	c, _, err := websocket.Dial(ctx, "ws"+strings.TrimPrefix(srv.URL, "http")+"/_live?token="+tok, nil)
	if err != nil {
		t.Fatal(err)
	}
	t.Cleanup(func() { c.Close(websocket.StatusNormalClosure, "") })
	var hello liveMsg
	if err := wsjson.Read(ctx, c, &hello); err != nil || hello.T != "hello" {
		t.Fatalf("expected hello, got %+v (%v)", hello, err)
	}
	return c, hello.Session
}

// readUntil reads messages until one matches.
func readUntil(t *testing.T, c *websocket.Conn, match func(liveMsg) bool) liveMsg {
	t.Helper()
	ctx, cancel := context.WithTimeout(context.Background(), 10*time.Second)
	defer cancel()
	for {
		var m liveMsg
		if err := wsjson.Read(ctx, c, &m); err != nil {
			t.Fatalf("no matching message: %v", err)
		}
		if match(m) {
			return m
		}
	}
}

func TestPresenceAndCoEditingAcrossNodes(t *testing.T) {
	reg := newRegistry()
	alice := newNode(t, reg, "100.64.0.1", "alice")
	bob := newNode(t, reg, "100.64.0.2", "bob")
	share(alice, bob)
	alice.store.editors = []string{alice.id.Node} // only alice may live-edit the memo
	bob.store.editors = []string{alice.id.Node}
	alice.start(t)
	bob.start(t)
	eventually(t, "link", func() bool { return linked(alice, bob) && linked(bob, alice) })

	ca, _ := liveClient(t, alice, "u-alice", "p1")
	cb, _ := liveClient(t, bob, "u-bob", "p1")
	ctx := context.Background()
	_ = wsjson.Write(ctx, ca, liveMsg{T: "view", View: "coding"})

	// bob's tab sees alice as online
	readUntil(t, cb, func(m liveMsg) bool {
		for _, u := range m.Users {
			if m.T == "presence" && u.User == "u-alice" && u.View == "coding" && u.Node == alice.id.Node {
				return true
			}
		}
		return false
	})

	doc := "notes:abc:content"
	subA := SignToken(alice.key, liveToken{Kind: "doc", User: "u-alice", Project: "p1", Doc: doc, Edit: true, Exp: time.Now().Add(time.Minute).Unix()})
	subB := SignToken(bob.key, liveToken{Kind: "doc", User: "u-bob", Project: "p1", Doc: doc, Edit: true, Exp: time.Now().Add(time.Minute).Unix()})
	_ = wsjson.Write(ctx, ca, liveMsg{T: "sub", Doc: doc, Token: subA})
	_ = wsjson.Write(ctx, cb, liveMsg{T: "sub", Doc: doc, Token: subB})
	time.Sleep(200 * time.Millisecond)

	// alice types: bob's tab gets the update
	_ = wsjson.Write(ctx, ca, liveMsg{T: "y", Doc: doc, U: []byte{1, 2, 3}})
	got := readUntil(t, cb, func(m liveMsg) bool { return m.T == "y" })
	if !bytes.Equal(got.U, []byte{1, 2, 3}) || !strings.HasPrefix(got.From, alice.id.Node+":") {
		t.Fatalf("unexpected update %+v", got)
	}

	// bob is not an editor of this memo on alice's side: his updates are dropped there
	_ = wsjson.Write(ctx, cb, liveMsg{T: "y", Doc: doc, U: []byte{9}})
	_ = wsjson.Write(ctx, cb, liveMsg{T: "aw", Doc: doc, U: []byte{7}})
	m := readUntil(t, ca, func(m liveMsg) bool { return m.T == "y" || m.T == "aw" })
	if m.T != "aw" {
		t.Fatalf("an update from a node that may not edit must be dropped, got %+v", m)
	}

	// bob closes his laptop: alice's tab sees him leave
	reg.setUp(bob.net.ip, false)
	bob.agent.SetNetwork(nil)
	readUntil(t, ca, func(m liveMsg) bool {
		if m.T != "presence" {
			return false
		}
		for _, u := range m.Users {
			if u.User == "u-bob" {
				return false
			}
		}
		return true
	})
}

func TestLiveHubRejectsBadTokens(t *testing.T) {
	reg := newRegistry()
	n := newNode(t, reg, "100.64.0.1", "alice")
	srv := httptest.NewServer(n.agent.Hub())
	defer srv.Close()
	expired := SignToken(n.key, liveToken{Kind: "live", User: "u", Project: "p1", Exp: time.Now().Add(-time.Minute).Unix()})
	forged := SignToken([]byte("other key"), liveToken{Kind: "live", User: "u", Project: "p1", Exp: time.Now().Add(time.Minute).Unix()})
	docKind := SignToken(n.key, liveToken{Kind: "doc", User: "u", Project: "p1", Exp: time.Now().Add(time.Minute).Unix()})
	for name, tok := range map[string]string{"expired": expired, "forged": forged, "wrong kind": docKind, "empty": ""} {
		resp, err := http.Get(srv.URL + "/_live?token=" + tok)
		if err != nil {
			t.Fatal(err)
		}
		resp.Body.Close()
		if resp.StatusCode != http.StatusForbidden {
			t.Fatalf("%s token: expected 403, got %d", name, resp.StatusCode)
		}
	}
}

func TestControlNeedsTheTokenAndPublishes(t *testing.T) {
	reg := newRegistry()
	alice := newNode(t, reg, "100.64.0.1", "alice")
	bob := newNode(t, reg, "100.64.0.2", "bob")
	share(alice, bob)
	alice.start(t)
	bob.start(t)
	eventually(t, "link", func() bool { return linked(alice, bob) })
	srv := httptest.NewServer((&Control{Agent: alice.agent, Token: "s3cret"}).Handler())
	defer srv.Close()

	body := `{"batches":[{"project":"p1","ops":[` + string(op(alice.id.Node, 1, "p1")) + `]}]}`
	resp, _ := http.Post(srv.URL+"/v1/ops", "application/json", strings.NewReader(body))
	resp.Body.Close()
	if resp.StatusCode != http.StatusForbidden {
		t.Fatalf("expected 403 without token, got %d", resp.StatusCode)
	}
	req, _ := http.NewRequest(http.MethodPost, srv.URL+"/v1/ops", strings.NewReader(body))
	req.Header.Set("Authorization", "Bearer s3cret")
	resp, err := http.DefaultClient.Do(req)
	if err != nil || resp.StatusCode != http.StatusOK {
		t.Fatalf("publish failed: %v %v", err, resp)
	}
	resp.Body.Close()
	eventually(t, "published op reaches bob", func() bool { return bob.store.count("p1") == 1 })

	req, _ = http.NewRequest(http.MethodGet, srv.URL+"/v1/status", nil)
	req.Header.Set("Authorization", "Bearer s3cret")
	resp, err = http.DefaultClient.Do(req)
	if err != nil {
		t.Fatal(err)
	}
	var st controlStatus
	_ = json.NewDecoder(resp.Body).Decode(&st)
	resp.Body.Close()
	if st.Cluster.Node != alice.id.Node || len(st.Cluster.Peers) != 1 || !st.Cluster.Peers[0].Online {
		t.Fatalf("unexpected status %+v", st.Cluster)
	}
}

func TestDuplicateLinksResolveToOne(t *testing.T) {
	if minNode("na", "nb") != "na" || minNode("nb", "na") != "na" {
		t.Fatal("minNode")
	}
	ops := make([]json.RawMessage, 450)
	chunks := chunkOps(ops, 200)
	sizes := []int{}
	for _, c := range chunks {
		sizes = append(sizes, len(c))
	}
	sort.Ints(sizes)
	if fmt.Sprint(sizes) != "[50 200 200]" {
		t.Fatalf("unexpected chunks %v", sizes)
	}
}
