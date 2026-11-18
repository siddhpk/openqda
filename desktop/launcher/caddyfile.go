package main

import (
	"fmt"
	"path/filepath"
	"strings"
)

// Caddyfile renders the FrankenPHP configuration.
//
// Three listeners, each telling PHP which way a request came in
// (OPENQDA_CHANNEL, set here and never taken from the request):
//
//	local   127.0.0.1:<local>   the person at this computer; Host must be
//	                            127.0.0.1 or localhost (blocks DNS rebinding)
//	shared  127.0.0.1:<shared>  only reachable through Tailscale Funnel
//	lan     0.0.0.0:<lan>       only while "share on this network" is on
//
// Visitors on shared/lan must enter the access code (app/Http/Middleware/DesktopGate).
func Caddyfile(l Layout, cfg Config, lan bool) string {
	slash := func(p string) string { return filepath.ToSlash(p) }
	var b strings.Builder
	fmt.Fprintf(&b, `{
	admin 127.0.0.1:%d
	auto_https off
	persist_config off
	storage file_system "%s"
	frankenphp
	log {
		output file "%s" {
			roll_size 10MiB
			roll_keep 3
		}
		level WARN
	}
}

(openqda) {
	root * "%s"
	encode zstd br gzip
	header -Server
	handle_path /storage/* {
		root * "%s"
		file_server
	}
	handle {
		php_server {
			env OPENQDA_CHANNEL {args[0]}
		}
	}
}

http://127.0.0.1:%d, http://localhost:%d {
	bind 127.0.0.1
	import openqda local
}

http://:%d {
	bind 127.0.0.1
	import openqda shared
}
`, cfg.AdminPort, slash(l.DataFile("caddy")), slash(l.DataFile("storage", "logs", "server.log")),
		slash(filepath.Join(l.App, "public")), slash(l.DataFile("storage", "app", "public")),
		cfg.LocalPort, cfg.LocalPort, cfg.SharedPort)
	if lan {
		fmt.Fprintf(&b, `
http://:%d {
	bind 0.0.0.0
	import openqda lan
}
`, cfg.LanPort)
	}
	return b.String()
}
