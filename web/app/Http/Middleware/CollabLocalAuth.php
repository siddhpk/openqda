<?php

namespace App\Http\Middleware;

use App\Services\Collab\Collab;
use App\Services\Desktop\Channel;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The collaboration storage API answers only the launcher's node agent:
 * requests must come through the loopback "collab" listener (a channel the
 * web server sets, never the client) and carry the installation's token.
 */
class CollabLocalAuth
{
    public function handle(Request $request, Closure $next): Response
    {
        $token = (string) config('collab.control_token');
        abort_unless(
            Channel::of($request) === Channel::COLLAB && Collab::enabled() && $token !== ''
                && hash_equals($token, (string) $request->header('X-OpenQDA-Gateway')),
            403
        );

        return $next($request);
    }
}
