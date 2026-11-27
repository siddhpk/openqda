package cluster

import (
	"encoding/json"
	"time"
)

// Link protocol between two OpenQDA nodes (JSON text frames over one
// WebSocket on the tailnet, see link.go).
//
//	hello / auth / ready / err   handshake (mutual Ed25519, see handshake.go)
//	hb      heartbeat with the sender's presence, every HeartbeatEvery
//	vv      "this is what I have" for one project (version vector)
//	ops     signed operations (opaque to Go; PHP verifies and merges them)
//	bget    ask for a file by SHA-256 from an offset
//	bdat    one chunk of a file
//	berr    the file cannot be served
//	y       live co-editing update for one document (fast path, not stored)
//	aw      cursor / selection state for one document (never stored)
const (
	ProtocolVersion = 1
	LinkPath        = "/_cluster/v1/link"
	LinkPort        = "80"

	HeartbeatEvery = 3 * time.Second
	OfflineAfter   = 10 * time.Second
	BlobChunk      = 256 << 10
	opsPageSize    = 500
)

// Frame is one message on a link; unused fields stay empty.
type Frame struct {
	T string `json:"t"`

	// handshake
	V       int             `json:"v,omitempty"`
	Node    string          `json:"node,omitempty"`
	Pub     string          `json:"pub,omitempty"`
	Nonce   string          `json:"nonce,omitempty"`
	Name    string          `json:"name,omitempty"`
	Addr    string          `json:"addr,omitempty"`
	DNS     string          `json:"dns,omitempty"`
	Sig     []byte          `json:"sig,omitempty"`
	Invite  json.RawMessage `json:"invite,omitempty"`
	Welcome json.RawMessage `json:"welcome,omitempty"`
	Err     string          `json:"err,omitempty"`

	// replication
	Project string            `json:"project,omitempty"`
	VV      map[string]int64  `json:"vv,omitempty"`
	Ops     []json.RawMessage `json:"ops,omitempty"`

	// presence
	Presence []Presence `json:"presence,omitempty"`

	// files
	Sha  string `json:"sha,omitempty"`
	Off  int64  `json:"off,omitempty"`
	Size int64  `json:"size,omitempty"`
	Data []byte `json:"data,omitempty"`
	Last bool   `json:"last,omitempty"`

	// co-editing and cursors
	Doc  string `json:"doc,omitempty"`
	U    []byte `json:"u,omitempty"`
	From string `json:"from,omitempty"`
}

// Presence describes one person with OpenQDA open on some node.
type Presence struct {
	Node    string `json:"node"`
	Session string `json:"session"`
	User    string `json:"user"`
	Name    string `json:"name"`
	Color   string `json:"color,omitempty"`
	Project string `json:"project"`
	View    string `json:"view,omitempty"`
	Doc     string `json:"doc,omitempty"`
	Edit    bool   `json:"edit,omitempty"`
	Idle    bool   `json:"idle,omitempty"`
}

// handshake message signed by each side: binds the other side's nonce to
// both node ids so a signature cannot be replayed on another link.
func linkProof(otherNonce, signer, other string) []byte {
	return []byte("openqda-link-v1|" + otherNonce + "|" + signer + "|" + other)
}
