package cluster

import (
	"context"
	"net"
	"net/http"
	"time"
)

// NetPeer is another device in the tailnet as Tailscale reports it.
type NetPeer struct {
	IP     string   `json:"ip"`
	DNS    string   `json:"dns"`
	Host   string   `json:"host"`
	Online bool     `json:"online"`
	Direct bool     `json:"direct"` // a direct WireGuard path (not relayed through DERP)
	Tags   []string `json:"tags,omitempty"`
}

// Network is the private network the cluster runs on: the embedded
// Tailscale node (Tailnet) in production, loopback sockets in tests.
type Network interface {
	// Listen accepts links from peers on LinkPort.
	Listen() (net.Listener, error)
	// Dial connects to a peer address "ip:port" through the private network.
	Dial(ctx context.Context, addr string) (net.Conn, error)
	// Peers lists the devices in the network (only meaningful while running).
	Peers(ctx context.Context) ([]NetPeer, error)
	// Self returns this node's address and DNS name.
	Self(ctx context.Context) (ip, dns string, err error)
}

// httpClientVia returns an HTTP client whose connections go through n.
func httpClientVia(n Network) *http.Client {
	return &http.Client{
		Timeout: 0, // the WebSocket lives long; dial timeouts come from the context
		Transport: &http.Transport{
			DialContext: func(ctx context.Context, _, addr string) (net.Conn, error) {
				return n.Dial(ctx, addr)
			},
			ResponseHeaderTimeout: 15 * time.Second,
		},
	}
}
