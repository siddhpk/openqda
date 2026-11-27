package main

import (
	"context"
	"errors"
	"fmt"
	"net"
	"net/http"
	"time"

	"github.com/openqda/openqda/desktop/launcher/cluster"
)

// collabRuntime runs the laptop collaboration next to the web server: the
// node agent with its embedded Tailscale node, the loopback control API for
// PHP and the browsers' WebSocket (/_live, proxied by Caddy).
type collabRuntime struct {
	agent   *cluster.Agent
	tailnet *cluster.Tailnet
	servers []*http.Server
	logf    func(string, ...any)
}

func newCollabRuntime(layout Layout, cfg Config, logf func(string, ...any)) (*collabRuntime, error) {
	id, err := cluster.IdentityFromSeed(cfg.NodeKey)
	if err != nil {
		return nil, fmt.Errorf("config.json: %w", err)
	}
	tn := &cluster.Tailnet{Dir: layout.DataFile("tailscale"), Hostname: cluster.DefaultHostname(), Logf: logf}
	agent := cluster.NewAgent(cluster.Options{
		Identity: id,
		Store:    cluster.NewHTTPStore(fmt.Sprintf("http://127.0.0.1:%d", cfg.CollabPort), cfg.ControlToken),
		Logf:     logf,
		DataDir:  layout.DataFile("collab"),
		LiveKey:  cluster.DeriveKey(cfg.ControlToken, "live-token-v1"),
	})
	rt := &collabRuntime{agent: agent, tailnet: tn, logf: logf}
	control := &cluster.Control{Agent: agent, Tailnet: tn, Token: cfg.ControlToken}
	for _, s := range []struct {
		port    int
		handler http.Handler
	}{{cfg.ControlPort, control.Handler()}, {cfg.LivePort, agent.Hub()}} {
		ln, err := net.Listen("tcp", fmt.Sprintf("127.0.0.1:%d", s.port))
		if err != nil {
			rt.closeServers()
			return nil, fmt.Errorf("port %d is in use: %w", s.port, err)
		}
		srv := &http.Server{Handler: s.handler, ReadHeaderTimeout: 15 * time.Second}
		rt.servers = append(rt.servers, srv)
		go func() {
			if err := srv.Serve(ln); err != nil && !errors.Is(err, http.ErrServerClosed) {
				logf("collaboration listener: %v", err)
			}
		}()
	}
	return rt, nil
}

// start begins replication once PHP answers; a computer that joined a team
// network before reconnects to it.
func (rt *collabRuntime) start() {
	rt.agent.Start(context.Background())
	if rt.tailnet.Configured() {
		go func() {
			if err := rt.tailnet.Start(); err != nil {
				rt.logf("tailscale: %v", err)
				return
			}
			rt.agent.SetNetwork(rt.tailnet)
		}()
	}
}

func (rt *collabRuntime) stop() {
	rt.agent.Stop()
	rt.tailnet.Close()
	rt.closeServers()
}

func (rt *collabRuntime) closeServers() {
	for _, s := range rt.servers {
		_ = s.Close()
	}
}
