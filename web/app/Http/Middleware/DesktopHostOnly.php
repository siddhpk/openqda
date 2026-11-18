<?php

namespace App\Http\Middleware;

use App\Services\Desktop\Channel;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Only the person at this computer (local listener of the laptop edition)
 * may manage sharing or shut OpenQDA down. Everyone else gets a 404, so the
 * routes are invisible through the sharing link and on servers.
 */
class DesktopHostOnly
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless(Channel::isHost($request), 404);

        return $next($request);
    }
}
