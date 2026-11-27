package cluster

import "testing"

// Values produced by the PHP side with the same inputs
// (web/tests/Feature/Collab/CollabApiTest::test_cross_language_vectors):
// both sides must derive the same node id, accept each other's Ed25519
// signatures and the browser tokens PHP issues.
func TestCrossLanguageVectors(t *testing.T) {
	const (
		seed  = "BwcHBwcHBwcHBwcHBwcHBwcHBwcHBwcHBwcHBwcHBwc="
		node  = "nfe812c12f3ab4ce6ac5d"
		pub   = "6kpsY+KcUgq+9VB7Ey7F+ZVHdq6+vnuSQh7qaRRG0iw="
		sig   = "vpDcqRzM5+Z1uFg5ITxqKOH9Og7er83qM39fKtWAX6KLMpZrmcoN09LWVkHLHPrFNcttHoFhTGZfIYiPRGodBg=="
		token = "eyJrIjoibGl2ZSIsInUiOiJ1MSIsInAiOiJwMSIsImUiOjQxMDI0NDQ4MDB9.CAlLEGyrc8P8mYxaVLnHJlFTLmQUXcLo2Zhe2cHVLQY"
	)
	id, err := IdentityFromSeed(seed)
	if err != nil {
		t.Fatal(err)
	}
	if id.Node != node || id.PubB64() != pub {
		t.Fatalf("identity differs from PHP: %s %s", id.Node, id.PubB64())
	}
	if !VerifyB64(pub, []byte("openqda-vector"), mustB64(t, sig)) {
		t.Fatal("a PHP (sodium) signature must verify in Go")
	}
	hub := newLiveHub(&Agent{}, DeriveKey("vector-token", "live-token-v1"))
	got, err := hub.verify(token, "live")
	if err != nil || got.User != "u1" || got.Project != "p1" {
		t.Fatalf("a PHP token must verify in Go: %+v %v", got, err)
	}
}

func mustB64(t *testing.T, s string) []byte {
	t.Helper()
	b, err := decodeStd(s)
	if err != nil {
		t.Fatal(err)
	}
	return b
}
