package cluster

import (
	"bytes"
	"context"
	"encoding/json"
	"errors"
	"fmt"
	"io"
	"net/http"
	"net/url"
	"strconv"
	"time"
)

// Member is one node in a project's roster, as PHP reports it.
type Member struct {
	Node    string `json:"node"`
	Pub     string `json:"pub"`
	Name    string `json:"name"`
	Role    string `json:"role"`
	Addr    string `json:"addr,omitempty"`
	DNS     string `json:"dns,omitempty"`
	Removed bool   `json:"removed,omitempty"`
}

// RosterProject is a collaborative project and who belongs to it.
type RosterProject struct {
	Gid     string   `json:"gid"`
	Name    string   `json:"name"`
	Members []Member `json:"members"`
}

// PendingJoin is an invite this node accepted and still has to present to
// the inviter (who may be offline right now).
type PendingJoin struct {
	ID      int64           `json:"id"`
	Project string          `json:"project"`
	Inviter Member          `json:"inviter"`
	Invite  json.RawMessage `json:"invite"`
}

// MissingBlob is a file PHP needs but does not have yet.
type MissingBlob struct {
	Sha     string `json:"sha"`
	Project string `json:"project"`
}

// Roster is everything the agent needs to know about projects and peers.
type Roster struct {
	Node     string          `json:"node"`
	Name     string          `json:"name"`
	Projects []RosterProject `json:"projects"`
	Joins    []PendingJoin   `json:"joins"`
	Missing  []MissingBlob   `json:"missing"`
}

// ApplyResult is PHP's answer to a batch of incoming operations.
type ApplyResult struct {
	New       []int             `json:"new"` // indices of ops that were new here (forwarded to other peers)
	Events    []json.RawMessage `json:"events"`
	NeedBlobs []string          `json:"need_blobs"`
	Roster    bool              `json:"roster"` // the roster changed
}

// OpsPage is a page of operations a peer lacks.
type OpsPage struct {
	Ops  []json.RawMessage `json:"ops"`
	More bool              `json:"more"`
}

// OutBatch is a set of new local operations (and the matching browser
// events) for one project.
type OutBatch struct {
	Project string            `json:"project"`
	Ops     []json.RawMessage `json:"ops"`
	Events  []json.RawMessage `json:"events"`
}

// DocInfo says which nodes may live-edit a co-edited text.
type DocInfo struct {
	Project string   `json:"project"`
	Editors []string `json:"editors"`
}

// JoinRequest is what a joining node presents to the inviter.
type JoinRequest struct {
	Node   string          `json:"node"`
	Pub    string          `json:"pub"`
	Name   string          `json:"name"`
	Addr   string          `json:"addr"`
	DNS    string          `json:"dns"`
	Invite json.RawMessage `json:"invite"`
}

// Store is the data side of a node, implemented by PHP (HTTPStore) and by
// fakes in tests.
type Store interface {
	Roster(ctx context.Context) (Roster, error)
	Apply(ctx context.Context, from, project string, ops []json.RawMessage) (ApplyResult, error)
	Ops(ctx context.Context, project string, vv map[string]int64, limit int) (OpsPage, error)
	VV(ctx context.Context, project string) (map[string]int64, error)
	BlobGet(ctx context.Context, sha, node string, offset int64) (io.ReadCloser, int64, error)
	BlobPut(ctx context.Context, sha string, r io.Reader) error
	Join(ctx context.Context, req JoinRequest) (json.RawMessage, error)
	Joined(ctx context.Context, joinID int64, welcome json.RawMessage) error
	JoinFailed(ctx context.Context, joinID int64, message string) error
	Doc(ctx context.Context, key string) (DocInfo, error)
	Flush(ctx context.Context) ([]OutBatch, error)
}

// ErrRejected is returned when PHP refuses a request (for example an
// invalid invite).
var ErrRejected = errors.New("rejected")

// HTTPStore talks to the PHP side on the loopback "collab" listener. Every
// request carries the gateway token; PHP refuses requests without it.
type HTTPStore struct {
	Base   string // http://127.0.0.1:<collab port>
	Token  string
	Client *http.Client
}

func NewHTTPStore(base, token string) *HTTPStore {
	return &HTTPStore{Base: base, Token: token, Client: &http.Client{Timeout: 120 * time.Second}}
}

func (s *HTTPStore) do(ctx context.Context, method, path string, body any, out any) error {
	var rd io.Reader
	if body != nil {
		raw, err := json.Marshal(body)
		if err != nil {
			return err
		}
		rd = bytes.NewReader(raw)
	}
	req, err := http.NewRequestWithContext(ctx, method, s.Base+"/_collab/local"+path, rd)
	if err != nil {
		return err
	}
	req.Header.Set("X-OpenQDA-Gateway", s.Token)
	req.Header.Set("Accept", "application/json")
	if body != nil {
		req.Header.Set("Content-Type", "application/json")
	}
	resp, err := s.Client.Do(req)
	if err != nil {
		return err
	}
	defer resp.Body.Close()
	if resp.StatusCode == http.StatusForbidden || resp.StatusCode == http.StatusUnprocessableEntity {
		msg, _ := io.ReadAll(io.LimitReader(resp.Body, 2048))
		return fmt.Errorf("%w: %s", ErrRejected, bytes.TrimSpace(msg))
	}
	if resp.StatusCode >= 300 {
		msg, _ := io.ReadAll(io.LimitReader(resp.Body, 2048))
		return fmt.Errorf("%s %s: %s %s", method, path, resp.Status, bytes.TrimSpace(msg))
	}
	if out == nil {
		return nil
	}
	return json.NewDecoder(resp.Body).Decode(out)
}

func (s *HTTPStore) Roster(ctx context.Context) (Roster, error) {
	var r Roster
	err := s.do(ctx, http.MethodGet, "/roster", nil, &r)
	return r, err
}

func (s *HTTPStore) Apply(ctx context.Context, from, project string, ops []json.RawMessage) (ApplyResult, error) {
	var r ApplyResult
	err := s.do(ctx, http.MethodPost, "/apply", map[string]any{"from": from, "project": project, "ops": ops}, &r)
	return r, err
}

func (s *HTTPStore) Ops(ctx context.Context, project string, vv map[string]int64, limit int) (OpsPage, error) {
	var r OpsPage
	if vv == nil {
		vv = map[string]int64{}
	}
	err := s.do(ctx, http.MethodPost, "/ops", map[string]any{"project": project, "vv": vv, "limit": limit}, &r)
	return r, err
}

func (s *HTTPStore) VV(ctx context.Context, project string) (map[string]int64, error) {
	var r struct {
		VV map[string]int64 `json:"vv"`
	}
	err := s.do(ctx, http.MethodGet, "/vv?project="+url.QueryEscape(project), nil, &r)
	if r.VV == nil {
		r.VV = map[string]int64{}
	}
	return r.VV, err
}

func (s *HTTPStore) BlobGet(ctx context.Context, sha, node string, offset int64) (io.ReadCloser, int64, error) {
	q := url.Values{"node": {node}, "offset": {strconv.FormatInt(offset, 10)}}
	req, err := http.NewRequestWithContext(ctx, http.MethodGet, s.Base+"/_collab/local/blob/"+url.PathEscape(sha)+"?"+q.Encode(), nil)
	if err != nil {
		return nil, 0, err
	}
	req.Header.Set("X-OpenQDA-Gateway", s.Token)
	resp, err := s.Client.Do(req)
	if err != nil {
		return nil, 0, err
	}
	if resp.StatusCode != http.StatusOK {
		resp.Body.Close()
		return nil, 0, fmt.Errorf("blob %s: %s", sha, resp.Status)
	}
	size, _ := strconv.ParseInt(resp.Header.Get("X-Blob-Size"), 10, 64)
	return resp.Body, size, nil
}

func (s *HTTPStore) BlobPut(ctx context.Context, sha string, r io.Reader) error {
	req, err := http.NewRequestWithContext(ctx, http.MethodPut, s.Base+"/_collab/local/blob/"+url.PathEscape(sha), r)
	if err != nil {
		return err
	}
	req.Header.Set("X-OpenQDA-Gateway", s.Token)
	req.Header.Set("Content-Type", "application/octet-stream")
	resp, err := s.Client.Do(req)
	if err != nil {
		return err
	}
	defer resp.Body.Close()
	if resp.StatusCode >= 300 {
		msg, _ := io.ReadAll(io.LimitReader(resp.Body, 1024))
		return fmt.Errorf("blob put %s: %s %s", sha, resp.Status, msg)
	}
	return nil
}

func (s *HTTPStore) Join(ctx context.Context, req JoinRequest) (json.RawMessage, error) {
	var r struct {
		Welcome json.RawMessage `json:"welcome"`
	}
	err := s.do(ctx, http.MethodPost, "/join", req, &r)
	return r.Welcome, err
}

func (s *HTTPStore) Joined(ctx context.Context, joinID int64, welcome json.RawMessage) error {
	return s.do(ctx, http.MethodPost, "/joined", map[string]any{"join": joinID, "welcome": welcome}, nil)
}

func (s *HTTPStore) JoinFailed(ctx context.Context, joinID int64, message string) error {
	return s.do(ctx, http.MethodPost, "/join-failed", map[string]any{"join": joinID, "message": message}, nil)
}

func (s *HTTPStore) Doc(ctx context.Context, key string) (DocInfo, error) {
	var r DocInfo
	err := s.do(ctx, http.MethodGet, "/doc?key="+url.QueryEscape(key), nil, &r)
	return r, err
}

func (s *HTTPStore) Flush(ctx context.Context) ([]OutBatch, error) {
	var r struct {
		Batches []OutBatch `json:"batches"`
	}
	err := s.do(ctx, http.MethodPost, "/flush", map[string]any{}, &r)
	return r.Batches, err
}
