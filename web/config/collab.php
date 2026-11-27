<?php

/*
|--------------------------------------------------------------------------
| Laptop collaboration (laptop edition)
|--------------------------------------------------------------------------
|
| Several laptop installations form a private cluster over the team's
| Tailscale network. The launcher's node agent (desktop/launcher/cluster)
| owns the network; this application captures, signs, merges and stores
| the changes. All values below are set by the launcher; on a server they
| stay empty and nothing here is active.
|
| See docs/development/backend/laptop-cluster.md.
|
*/

return [
    // node agent API (Tailscale sign-in, presence, handing over new changes)
    'control_url' => env('OPENQDA_CONTROL_URL'),

    // shared secret of the loopback APIs in both directions
    'control_token' => env('OPENQDA_CONTROL_TOKEN'),

    // Ed25519 seed of this installation's cluster identity (base64)
    'node_key' => env('OPENQDA_NODE_KEY'),

    // invitations and the Tailscale keys they carry are valid this long
    'invite_days' => 7,

    // browser tokens for the live channel
    'live_token_minutes' => 15,

    // operations stamped further in the future than this wait (clock skew)
    'future_skew_seconds' => 600,

    // the tag OpenQDA nodes get, and that the suggested policy refers to
    'tag' => 'tag:openqda',

    'tailscale_api' => env('OPENQDA_TAILSCALE_API', 'https://api.tailscale.com'),
];
