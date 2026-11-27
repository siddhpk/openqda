package cluster

import (
	"context"
	"encoding/json"
	"errors"
	"fmt"
	"time"

	"github.com/coder/websocket"
	"github.com/coder/websocket/wsjson"
)

// Handshake (both sides prove they hold the private key of their node id):
//
//	dialer   -> hello {node, pub, nonce Nd, name, addr, invite?}
//	acceptor -> hello {node, pub, nonce Na, name, addr, sig(Nd, acceptor, dialer)}
//	dialer   -> auth  {sig(Na, dialer, acceptor)}
//	acceptor -> ready {welcome?}   or   err {err}
//
// The transport is already encrypted and authenticated by WireGuard; the
// handshake binds the link to the cluster identities in the roster, which
// is what authorizes a peer (Tailscale identities are not trusted for that).

const handshakeTimeout = 20 * time.Second

// errPeer is a refusal sent by the other side (not a network failure).
type errPeer struct{ msg string }

func (e errPeer) Error() string { return "refused by peer: " + e.msg }

// IsRefusal reports whether err is a refusal by the peer (bad invite, not
// a member) rather than a connection problem.
func IsRefusal(err error) bool {
	var p errPeer
	return errors.As(err, &p)
}

type helloInfo struct {
	Node, Pub, Name, Addr, DNS string
	Invite                     json.RawMessage
}

func readFrame(ctx context.Context, c *websocket.Conn) (Frame, error) {
	var f Frame
	err := wsjson.Read(ctx, c, &f)
	return f, err
}

func checkHello(f Frame) error {
	if f.T == "err" {
		return errPeer{f.Err}
	}
	if f.T != "hello" || f.V != ProtocolVersion {
		return fmt.Errorf("unexpected handshake message %q (version %d)", f.T, f.V)
	}
	if !NodeMatches(f.Node, f.Pub) {
		return errors.New("node id does not match its key")
	}
	return nil
}

// dialHandshake runs the dialer's side. expectPub, when set, is the key the
// peer must have (from the roster or from an invite).
func dialHandshake(ctx context.Context, c *websocket.Conn, id Identity, self helloInfo, expectPub string) (helloInfo, json.RawMessage, error) {
	ctx, cancel := context.WithTimeout(ctx, handshakeTimeout)
	defer cancel()
	nonce := randomNonce()
	if err := wsjson.Write(ctx, c, Frame{T: "hello", V: ProtocolVersion, Node: id.Node, Pub: id.PubB64(), Nonce: nonce,
		Name: self.Name, Addr: self.Addr, DNS: self.DNS, Invite: self.Invite}); err != nil {
		return helloInfo{}, nil, err
	}
	f, err := readFrame(ctx, c)
	if err != nil {
		return helloInfo{}, nil, err
	}
	if err := checkHello(f); err != nil {
		return helloInfo{}, nil, err
	}
	if expectPub != "" && f.Pub != expectPub {
		return helloInfo{}, nil, errors.New("peer key differs from the roster")
	}
	if !VerifyB64(f.Pub, linkProof(nonce, f.Node, id.Node), f.Sig) {
		return helloInfo{}, nil, errors.New("peer signature is invalid")
	}
	if err := wsjson.Write(ctx, c, Frame{T: "auth", Sig: id.Sign(linkProof(f.Nonce, id.Node, f.Node))}); err != nil {
		return helloInfo{}, nil, err
	}
	r, err := readFrame(ctx, c)
	if err != nil {
		return helloInfo{}, nil, err
	}
	if r.T == "err" {
		return helloInfo{}, nil, errPeer{r.Err}
	}
	if r.T != "ready" {
		return helloInfo{}, nil, fmt.Errorf("unexpected handshake message %q", r.T)
	}
	return helloInfo{Node: f.Node, Pub: f.Pub, Name: f.Name, Addr: f.Addr, DNS: f.DNS}, r.Welcome, nil
}

// acceptHandshake runs the acceptor's side; authorize decides whether the
// authenticated peer may link and may return a welcome (after a join).
func acceptHandshake(ctx context.Context, c *websocket.Conn, id Identity, self helloInfo,
	authorize func(context.Context, helloInfo) (json.RawMessage, error)) (helloInfo, error) {
	ctx, cancel := context.WithTimeout(ctx, handshakeTimeout)
	defer cancel()
	f, err := readFrame(ctx, c)
	if err != nil {
		return helloInfo{}, err
	}
	if err := checkHello(f); err != nil {
		_ = wsjson.Write(ctx, c, Frame{T: "err", Err: err.Error()})
		return helloInfo{}, err
	}
	nonce := randomNonce()
	if err := wsjson.Write(ctx, c, Frame{T: "hello", V: ProtocolVersion, Node: id.Node, Pub: id.PubB64(), Nonce: nonce,
		Name: self.Name, Addr: self.Addr, DNS: self.DNS, Sig: id.Sign(linkProof(f.Nonce, id.Node, f.Node))}); err != nil {
		return helloInfo{}, err
	}
	a, err := readFrame(ctx, c)
	if err != nil {
		return helloInfo{}, err
	}
	if a.T != "auth" || !VerifyB64(f.Pub, linkProof(nonce, f.Node, id.Node), a.Sig) {
		_ = wsjson.Write(ctx, c, Frame{T: "err", Err: "authentication failed"})
		return helloInfo{}, errors.New("peer authentication failed")
	}
	peer := helloInfo{Node: f.Node, Pub: f.Pub, Name: f.Name, Addr: f.Addr, DNS: f.DNS, Invite: f.Invite}
	welcome, err := authorize(ctx, peer)
	if err != nil {
		_ = wsjson.Write(ctx, c, Frame{T: "err", Err: err.Error()})
		return helloInfo{}, err
	}
	if err := wsjson.Write(ctx, c, Frame{T: "ready", Welcome: welcome}); err != nil {
		return helloInfo{}, err
	}
	return peer, nil
}
