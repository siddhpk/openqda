<?php

/*
|--------------------------------------------------------------------------
| Trusted proxies
|--------------------------------------------------------------------------
|
| Set TRUSTED_PROXIES when OpenQDA runs behind a tunnel or reverse proxy
| that terminates HTTPS (e.g. Cloudflare Tunnel, Caddy, nginx), so that
| links, redirects and assets use https:// and the visitor's address is
| logged correctly. Values: "*" (trust the proxy that calls the app) or a
| comma-separated list of IP addresses / CIDR ranges. Unset: trust none.
|
| Only use "*" when the app port is not reachable from other machines,
| e.g. published on 127.0.0.1 only, because forwarded headers can be forged.
|
*/

return [
    'proxies' => env('TRUSTED_PROXIES'),
];
