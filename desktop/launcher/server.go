package main

import (
	"context"
	"errors"
	"fmt"
	"io"
	"net/http"
	"os"
	"os/exec"
	"path/filepath"
	"strings"
	"time"
)

// Server runs FrankenPHP with the generated Caddyfile.
type Server struct {
	Layout Layout
	Config Config
	Env    []string
	Log    io.Writer

	cmd  *exec.Cmd
	done chan error
	lan  bool
}

func (s *Server) caddyfilePath() string { return s.Layout.DataFile("Caddyfile") }

func (s *Server) Start(lan bool) error {
	if err := writeFileAtomic(s.caddyfilePath(), []byte(Caddyfile(s.Layout, s.Config, lan)), 0o600); err != nil {
		return err
	}
	cmd := exec.Command(s.Layout.Binary("frankenphp"), "run", "--config", s.caddyfilePath(), "--adapter", "caddyfile")
	cmd.Dir = s.Layout.App
	cmd.Env = s.Env
	cmd.Stdout = s.Log
	cmd.Stderr = s.Log
	hideWindow(cmd)
	if err := cmd.Start(); err != nil {
		return fmt.Errorf("could not start the web server: %w", err)
	}
	s.cmd = cmd
	s.lan = lan
	s.done = make(chan error, 1)
	go func() { s.done <- cmd.Wait() }()
	return nil
}

func (s *Server) LAN() bool { return s.lan }

// Exited returns a channel that receives when the server process ends.
func (s *Server) Exited() <-chan error { return s.done }

func (s *Server) LocalURL() string {
	return fmt.Sprintf("http://127.0.0.1:%d", s.Config.LocalPort)
}

// WaitHealthy polls the local listener until it answers.
func (s *Server) WaitHealthy(timeout time.Duration) error {
	deadline := time.Now().Add(timeout)
	client := &http.Client{Timeout: 3 * time.Second, CheckRedirect: func(*http.Request, []*http.Request) error { return http.ErrUseLastResponse }}
	for time.Now().Before(deadline) {
		select {
		case err := <-s.done:
			s.done <- err
			return fmt.Errorf("the web server stopped during start-up (see %s)", s.Layout.DataFile("storage", "logs", "launcher.log"))
		default:
		}
		if resp, err := client.Get(s.LocalURL() + "/"); err == nil {
			resp.Body.Close()
			if resp.StatusCode < 500 {
				return nil
			}
		}
		time.Sleep(300 * time.Millisecond)
	}
	return errors.New("the web server did not answer in time")
}

// Stop asks FrankenPHP to shut down gracefully, then kills it if needed.
func (s *Server) Stop() {
	if s.cmd == nil || s.cmd.Process == nil {
		return
	}
	ctx, cancel := context.WithTimeout(context.Background(), 5*time.Second)
	defer cancel()
	req, _ := http.NewRequestWithContext(ctx, http.MethodPost, fmt.Sprintf("http://127.0.0.1:%d/stop", s.Config.AdminPort), nil)
	if resp, err := http.DefaultClient.Do(req); err == nil {
		resp.Body.Close()
	}
	select {
	case err := <-s.done:
		s.done <- err
	case <-time.After(8 * time.Second):
		_ = s.cmd.Process.Kill()
		<-s.done
		s.done <- nil
	}
	s.cmd = nil
}

// Artisan runs a Laravel command with the app environment.
func Artisan(l Layout, env []string, log io.Writer, args ...string) error {
	var cmd *exec.Cmd
	artisan := filepath.Join(l.App, "artisan")
	if php := l.Binary("php"); isFile(php) {
		// Windows: the official PHP build ships php.exe next to frankenphp.exe
		cmd = exec.Command(php, append([]string{artisan}, args...)...)
	} else {
		cmd = exec.Command(l.Binary("frankenphp"), append([]string{"php-cli", artisan}, args...)...)
	}
	cmd.Dir = l.App
	cmd.Env = env
	var out strings.Builder
	cmd.Stdout = io.MultiWriter(log, &out)
	cmd.Stderr = io.MultiWriter(log, &out)
	hideWindow(cmd)
	if err := cmd.Run(); err != nil {
		tail := out.String()
		if len(tail) > 800 {
			tail = tail[len(tail)-800:]
		}
		return fmt.Errorf("artisan %s failed: %w\n%s", strings.Join(args, " "), err, tail)
	}
	return nil
}

// AlreadyRunning reports whether another launcher serves this data folder.
func AlreadyRunning(cfg Config) bool {
	client := &http.Client{Timeout: 2 * time.Second, CheckRedirect: func(*http.Request, []*http.Request) error { return http.ErrUseLastResponse }}
	resp, err := client.Get(fmt.Sprintf("http://127.0.0.1:%d/", cfg.LocalPort))
	if err != nil {
		return false
	}
	resp.Body.Close()
	return true
}

func openLog(path string) *os.File {
	f, err := os.OpenFile(path, os.O_CREATE|os.O_WRONLY|os.O_APPEND, 0o600)
	if err != nil {
		return os.Stderr
	}
	return f
}
