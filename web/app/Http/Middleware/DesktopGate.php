<?php

namespace App\Http\Middleware;

use App\Services\Desktop\Channel;
use App\Services\Desktop\SharingManager;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;
use Symfony\Component\HttpFoundation\Response;

/**
 * Laptop edition: visitors who arrive through the sharing link or the
 * network address must enter the host's access code first. Requests on
 * the local listener (the host) and all requests outside the laptop
 * edition pass through untouched.
 */
class DesktopGate
{
    public function __construct(private SharingManager $sharing) {}

    public function handle(Request $request, Closure $next): Response
    {
        // the collaboration listener serves the node agent's API, never pages
        abort_if(Channel::of($request) === Channel::COLLAB, 404);

        if (! Channel::isGuest($request)) {
            return $next($request);
        }

        if (! $this->sharing->channelOpen($request)) {
            return response()->view('desktop.closed', [], 503);
        }

        $current = $this->sharing->current();
        if (Channel::of($request) === Channel::SHARED && ! empty($current['url'])) {
            // behind Tailscale Funnel: the visitor uses https://<name>.ts.net
            URL::forceRootUrl($current['url']);
            URL::forceScheme('https');
            config(['session.secure' => true]);
        }

        if ($request->is('share/enter') || $this->sharing->admitted($request)) {
            return $next($request);
        }

        if ($request->expectsJson()) {
            return response()->json(['message' => 'Enter the access code first.'], 401);
        }

        return redirect('/share/enter');
    }
}
