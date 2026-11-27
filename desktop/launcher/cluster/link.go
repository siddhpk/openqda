package cluster

import (
	"context"
	"sync"
	"sync/atomic"
	"time"

	"github.com/coder/websocket"
	"github.com/coder/websocket/wsjson"
)

const (
	linkQueue     = 1024
	linkReadLimit = 16 << 20
	writeTimeout  = 20 * time.Second
)

// Link is one authenticated connection to a peer node.
type Link struct {
	Peer   helloInfo
	Dialer string // node id of the side that dialed (decides duplicates)

	conn      *websocket.Conn
	out       chan Frame
	done      chan struct{}
	closeOnce sync.Once
	lastRecv  atomic.Int64
	since     time.Time
}

func newLink(conn *websocket.Conn, peer helloInfo, dialer string) *Link {
	conn.SetReadLimit(linkReadLimit)
	l := &Link{Peer: peer, Dialer: dialer, conn: conn, out: make(chan Frame, linkQueue), done: make(chan struct{}), since: time.Now()}
	l.lastRecv.Store(time.Now().UnixNano())
	return l
}

// Send queues a frame without blocking. A peer that cannot keep up is
// disconnected; it catches up from version vectors when it reconnects.
func (l *Link) Send(f Frame) bool {
	select {
	case <-l.done:
		return false
	default:
	}
	select {
	case l.out <- f:
		return true
	default:
		l.Close("send queue full")
		return false
	}
}

// SendWait queues a frame, waiting for room (used for file chunks).
func (l *Link) SendWait(ctx context.Context, f Frame) bool {
	select {
	case l.out <- f:
		return true
	case <-l.done:
		return false
	case <-ctx.Done():
		return false
	}
}

func (l *Link) Close(reason string) {
	l.closeOnce.Do(func() {
		close(l.done)
		_ = l.conn.Close(websocket.StatusNormalClosure, reason)
	})
}

func (l *Link) Done() <-chan struct{} { return l.done }

// run pumps frames until the link closes. handle is called on the reading
// goroutine and must not block for long.
func (l *Link) run(ctx context.Context, heartbeat, offline time.Duration, hb func() Frame, handle func(*Link, Frame)) {
	ctx, cancel := context.WithCancel(ctx)
	defer cancel()
	defer l.Close("closed")

	go func() { // writer
		for {
			select {
			case f := <-l.out:
				wctx, wcancel := context.WithTimeout(ctx, writeTimeout)
				err := wsjson.Write(wctx, l.conn, f)
				wcancel()
				if err != nil {
					l.Close("write failed")
					return
				}
			case <-l.done:
				return
			case <-ctx.Done():
				return
			}
		}
	}()

	go func() { // heartbeat and liveness
		t := time.NewTicker(heartbeat)
		defer t.Stop()
		l.Send(hb())
		for {
			select {
			case <-t.C:
				if time.Since(time.Unix(0, l.lastRecv.Load())) > offline {
					l.Close("peer silent")
					return
				}
				l.Send(hb())
			case <-l.done:
				return
			case <-ctx.Done():
				return
			}
		}
	}()

	for {
		var f Frame
		if err := wsjson.Read(ctx, l.conn, &f); err != nil {
			return
		}
		l.lastRecv.Store(time.Now().UnixNano())
		if f.T == "bye" {
			return
		}
		handle(l, f)
	}
}
