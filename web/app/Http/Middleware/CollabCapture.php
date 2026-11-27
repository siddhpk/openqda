<?php

namespace App\Http\Middleware;

use App\Services\Collab\ChangeCapture;
use App\Services\Collab\Collab;
use App\Services\Collab\ControlClient;
use App\Services\Collab\Docs;
use App\Services\Collab\IdMap;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Laptop collaboration: after each request, the rows it changed in
 * collaborative projects become signed operations and go to the node
 * agent, which sends them to the online collaborators right away.
 *
 * A co-editor's save names the shared document it comes from
 * (X-OpenQDA-YDoc: "<global key>@<generation>"); that is recorded with the
 * saved text so the next editor continues the same document.
 */
class CollabCapture
{
    public function handle(Request $request, Closure $next): Response
    {
        if (($doc = $request->header('X-OpenQDA-YDoc')) && Collab::enabled()) {
            [$key, $gen] = array_pad(explode('@', $doc, 2), 2, '');
            if (($parts = Docs::parse($key)) && $gen !== '' && ($local = IdMap::local($parts[0], $parts[1]))) {
                ChangeCapture::virtual($parts[0], $local, ['_ygen' => mb_substr($gen, 0, 80)]);
            }
        }

        return $next($request);
    }

    public function terminate(Request $request, Response $response): void
    {
        if (! Collab::enabled()) {
            return;
        }
        try {
            ControlClient::publish(ChangeCapture::flush());
        } catch (\Throwable $e) {
            // the next flush (every 30 s by the agent) picks the changes up
            report($e);
        }
    }
}
