package cluster

import (
	"context"
	"crypto/sha256"
	"encoding/hex"
	"io"
	"os"
	"path/filepath"
	"regexp"
	"sync"
	"time"
)

// Files (original uploads and converted HTML) are content addressed by
// SHA-256 and copied between replicas in BlobChunk pieces, like blocks in a
// distributed file system: any online replica of the project can serve a
// file, an interrupted copy resumes from what is already on disk, and the
// result is checked against its hash before PHP places it.

var shaPattern = regexp.MustCompile(`^[0-9a-f]{64}$`)

const (
	blobStall    = 60 * time.Second
	blobMaxTries = 12
)

type blobWant struct {
	project   string
	preferred string
	tries     int
	next      time.Time
}

type blobXfer struct {
	link     *Link
	file     *os.File
	off      int64
	progress time.Time
}

type blobFetcher struct {
	a      *Agent
	mu     sync.Mutex
	wants  map[string]*blobWant
	active map[string]*blobXfer
}

func newBlobFetcher(a *Agent) *blobFetcher {
	return &blobFetcher{a: a, wants: map[string]*blobWant{}, active: map[string]*blobXfer{}}
}

func (b *blobFetcher) dir() string { return filepath.Join(b.a.opt.DataDir, "blobs") }

func (b *blobFetcher) want(sha, project, preferred string) {
	if !shaPattern.MatchString(sha) {
		return
	}
	b.mu.Lock()
	defer b.mu.Unlock()
	if w, ok := b.wants[sha]; ok {
		if preferred != "" {
			w.preferred = preferred
		}
		return
	}
	b.wants[sha] = &blobWant{project: project, preferred: preferred}
}

// wantMissing re-requests files PHP still lacks (after a restart).
func (b *blobFetcher) wantMissing(r Roster) {
	for _, m := range r.Missing {
		b.want(m.Sha, m.Project, "")
	}
}

func (b *blobFetcher) loop(ctx context.Context) {
	t := time.NewTicker(time.Second)
	defer t.Stop()
	for {
		select {
		case <-ctx.Done():
			b.mu.Lock()
			for sha, x := range b.active {
				x.file.Close()
				delete(b.active, sha)
			}
			b.mu.Unlock()
			return
		case <-t.C:
			b.tick()
		}
	}
}

func (b *blobFetcher) tick() {
	b.mu.Lock()
	defer b.mu.Unlock()
	now := time.Now()
	for sha, x := range b.active {
		select {
		case <-x.link.Done():
			b.dropLocked(sha, false)
			continue
		default:
		}
		if now.Sub(x.progress) > blobStall {
			b.dropLocked(sha, true)
		}
	}
	for sha, w := range b.wants {
		if b.active[sha] != nil || now.Before(w.next) {
			continue
		}
		if w.tries >= blobMaxTries {
			delete(b.wants, sha)
			b.a.logf("giving up on file %s after %d attempts", sha[:12], w.tries)
			continue
		}
		l := b.pickLink(w)
		if l == nil {
			continue
		}
		if err := os.MkdirAll(b.dir(), 0o700); err != nil {
			continue
		}
		f, err := os.OpenFile(filepath.Join(b.dir(), sha+".part"), os.O_CREATE|os.O_RDWR, 0o600)
		if err != nil {
			continue
		}
		off, _ := f.Seek(0, io.SeekEnd)
		x := &blobXfer{link: l, file: f, off: off, progress: now}
		if !l.Send(Frame{T: "bget", Sha: sha, Off: off}) {
			f.Close()
			continue
		}
		b.active[sha] = x
	}
}

func (b *blobFetcher) pickLink(w *blobWant) *Link {
	links := b.a.linksFor(w.project, "")
	for _, l := range links {
		if l.Peer.Node == w.preferred {
			return l
		}
	}
	if len(links) == 0 {
		return nil
	}
	return links[w.tries%len(links)]
}

// dropLocked ends a transfer; counted transfers back off before retrying.
func (b *blobFetcher) dropLocked(sha string, count bool) {
	if x := b.active[sha]; x != nil {
		x.file.Close()
		delete(b.active, sha)
	}
	if w := b.wants[sha]; w != nil && count {
		w.tries++
		w.preferred = ""
		w.next = time.Now().Add(time.Duration(w.tries) * 5 * time.Second)
	}
}

func (b *blobFetcher) onFrame(l *Link, f Frame) {
	b.mu.Lock()
	x := b.active[f.Sha]
	if x == nil || x.link != l {
		b.mu.Unlock()
		return
	}
	if f.T == "berr" {
		b.dropLocked(f.Sha, true)
		b.mu.Unlock()
		return
	}
	if f.Off != x.off {
		// out of step (for example the peer restarted the copy): start over
		_ = x.file.Truncate(0)
		b.dropLocked(f.Sha, true)
		b.mu.Unlock()
		return
	}
	if _, err := x.file.WriteAt(f.Data, x.off); err != nil {
		b.dropLocked(f.Sha, true)
		b.mu.Unlock()
		return
	}
	x.off += int64(len(f.Data))
	x.progress = time.Now()
	if !f.Last {
		b.mu.Unlock()
		return
	}
	// complete: verify, hand to PHP outside the lock
	delete(b.active, f.Sha)
	b.mu.Unlock()
	go b.finish(f.Sha, x.file)
}

func (b *blobFetcher) finish(sha string, file *os.File) {
	path := file.Name()
	defer file.Close()
	if _, err := file.Seek(0, io.SeekStart); err != nil {
		return
	}
	h := sha256.New()
	if _, err := io.Copy(h, file); err != nil || hex.EncodeToString(h.Sum(nil)) != sha {
		file.Close()
		os.Remove(path)
		b.mu.Lock()
		if w := b.wants[sha]; w != nil {
			w.tries++
			w.next = time.Now().Add(5 * time.Second)
		}
		b.mu.Unlock()
		b.a.logf("file %s failed its checksum, fetching again", sha[:12])
		return
	}
	if _, err := file.Seek(0, io.SeekStart); err != nil {
		return
	}
	ctx, cancel := context.WithTimeout(b.a.ctx, 10*time.Minute)
	err := b.a.opt.Store.BlobPut(ctx, sha, file)
	cancel()
	b.mu.Lock()
	defer b.mu.Unlock()
	if err != nil {
		if w := b.wants[sha]; w != nil {
			w.tries++
			w.next = time.Now().Add(30 * time.Second)
		}
		b.a.logf("storing file %s: %v", sha[:12], err)
		return
	}
	delete(b.wants, sha)
	file.Close()
	os.Remove(path)
}

// serveBlob streams a file to a peer; PHP checks the peer may have it.
func (a *Agent) serveBlob(l *Link, f Frame) {
	if !shaPattern.MatchString(f.Sha) || f.Off < 0 {
		l.Send(Frame{T: "berr", Sha: f.Sha, Err: "invalid request"})
		return
	}
	ctx, cancel := context.WithTimeout(a.ctx, 30*time.Minute)
	defer cancel()
	rc, size, err := a.opt.Store.BlobGet(ctx, f.Sha, l.Peer.Node, f.Off)
	if err != nil {
		l.Send(Frame{T: "berr", Sha: f.Sha, Err: "not available"})
		return
	}
	defer rc.Close()
	off := f.Off
	buf := make([]byte, BlobChunk)
	for {
		n, rerr := io.ReadFull(rc, buf)
		last := rerr == io.EOF || rerr == io.ErrUnexpectedEOF || (size > 0 && off+int64(n) >= size)
		if rerr != nil && !last {
			l.Send(Frame{T: "berr", Sha: f.Sha, Err: "read failed"})
			return
		}
		chunk := make([]byte, n)
		copy(chunk, buf[:n])
		if !l.SendWait(ctx, Frame{T: "bdat", Sha: f.Sha, Off: off, Size: size, Data: chunk, Last: last}) {
			return
		}
		off += int64(n)
		if last {
			return
		}
	}
}
