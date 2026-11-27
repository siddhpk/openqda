// Package cluster connects OpenQDA laptops of a team into a private cluster
// over the team's Tailscale network (tailnet).
//
// The Go side owns the network: the embedded Tailscale node, one
// authenticated WebSocket link per peer, heartbeats with presence, the
// fan-out of changes, catch-up after a peer was offline, file transfer and
// the live channel to the browsers on this computer. PHP owns the data: it
// captures changes, signs them as operations, merges them and writes SQLite.
// The two talk over loopback HTTP only (see store.go and control.go).
package cluster

import (
	"crypto/ed25519"
	"crypto/hmac"
	"crypto/rand"
	"crypto/sha256"
	"encoding/base64"
	"encoding/hex"
	"errors"
)

// Identity is this installation's node key. The private part never leaves
// the computer; the public part is shared with collaborators through the
// project roster and proves who signed a link handshake or an operation.
type Identity struct {
	Priv ed25519.PrivateKey
	Pub  ed25519.PublicKey
	Node string
}

// NewSeed returns a fresh Ed25519 seed, base64 encoded (stored in config.json).
func NewSeed() (string, error) {
	seed := make([]byte, ed25519.SeedSize)
	if _, err := rand.Read(seed); err != nil {
		return "", err
	}
	return base64.StdEncoding.EncodeToString(seed), nil
}

// IdentityFromSeed restores the node identity from its base64 seed.
func IdentityFromSeed(b64 string) (Identity, error) {
	seed, err := base64.StdEncoding.DecodeString(b64)
	if err != nil || len(seed) != ed25519.SeedSize {
		return Identity{}, errors.New("invalid node key")
	}
	priv := ed25519.NewKeyFromSeed(seed)
	pub := priv.Public().(ed25519.PublicKey)
	return Identity{Priv: priv, Pub: pub, Node: NodeID(pub)}, nil
}

// NodeID derives the short node name from a public key; PHP derives the
// same value ('n' . bin2hex(substr(hash('sha256', $pub, true), 0, 10))).
func NodeID(pub []byte) string {
	sum := sha256.Sum256(pub)
	return "n" + hex.EncodeToString(sum[:10])
}

// PubB64 is the public key as stored in rosters.
func (id Identity) PubB64() string { return base64.StdEncoding.EncodeToString(id.Pub) }

func (id Identity) Sign(msg []byte) []byte { return ed25519.Sign(id.Priv, msg) }

// VerifyB64 checks a signature against a base64 public key.
func VerifyB64(pubB64 string, msg, sig []byte) bool {
	pub, err := base64.StdEncoding.DecodeString(pubB64)
	if err != nil || len(pub) != ed25519.PublicKeySize {
		return false
	}
	return ed25519.Verify(ed25519.PublicKey(pub), msg, sig)
}

// NodeMatches reports whether node is the id belonging to the base64 key.
func NodeMatches(node, pubB64 string) bool {
	pub, err := base64.StdEncoding.DecodeString(pubB64)
	return err == nil && len(pub) == ed25519.PublicKeySize && NodeID(pub) == node
}

// DeriveKey derives a purpose-bound key from the shared loopback token;
// PHP computes hash_hmac('sha256', purpose, token, true).
func DeriveKey(token, purpose string) []byte {
	m := hmac.New(sha256.New, []byte(token))
	m.Write([]byte(purpose))
	return m.Sum(nil)
}

func randomNonce() string {
	b := make([]byte, 24)
	_, _ = rand.Read(b)
	return base64.RawURLEncoding.EncodeToString(b)
}

func decodeStd(s string) ([]byte, error) { return base64.StdEncoding.DecodeString(s) }
