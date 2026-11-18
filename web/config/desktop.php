<?php

/*
|--------------------------------------------------------------------------
| Laptop edition (desktop installer)
|--------------------------------------------------------------------------
|
| These values are set by the OpenQDA launcher that ships with the Windows
| and macOS installers (see desktop/README.md). On a server or in Docker
| they stay unset and none of the desktop features are active.
|
| The launcher serves the app on two listeners and tells PHP which one a
| request came through ($_SERVER['OPENQDA_CHANNEL'], set by the web server,
| never by a request header):
|   local  - 127.0.0.1, the person sitting at this computer (host)
|   shared - 127.0.0.1 behind Tailscale Funnel (internet link)
|   lan    - this computer's network address (same network, no internet)
| Requests through "shared" or "lan" must pass the access-code gate.
|
*/

return [
    'enabled' => (bool) env('OPENQDA_DESKTOP', false),

    // per-user data folder (database, uploads, logs); set by the launcher
    'data_path' => env('OPENQDA_DATA'),

    // state shared with the launcher (sharing mode, autostart, shutdown request)
    'state_file' => env('OPENQDA_STATE_FILE'),

    // ports of the gated listeners
    'shared_port' => (int) env('OPENQDA_SHARED_PORT', 0),
    'lan_port' => (int) env('OPENQDA_LAN_PORT', 0),

    'sharing' => [
        // sharing stops by itself after this many hours unless "keep on" is chosen
        'max_hours' => (int) env('OPENQDA_SHARING_MAX_HOURS', 8),
        // wrong access codes allowed per minute, and in total before sharing stops
        'attempts_per_minute' => 5,
        'max_failures' => 20,
    ],

    'tailscale' => [
        // path to the Tailscale command line tool; found automatically when empty
        'cli' => env('TAILSCALE_CLI'),
        'timeout' => 20,
    ],

    // pandoc binary for document conversion without the converter container
    'pandoc' => env('PANDOC_PATH'),
];
