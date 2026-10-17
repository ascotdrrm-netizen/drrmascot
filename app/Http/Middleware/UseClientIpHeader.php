<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Behind Render (which sits behind Cloudflare) the connecting address is a
 * proxy's, so the Activity Log and login throttling would see one shared IP.
 * Cloudflare overwrites CF-Connecting-IP with the visitor's real address on
 * every request, so when config('hosting.client_ip_header') names it, that
 * address becomes the request IP. Off by default (local, tests).
 */
class UseClientIpHeader
{
    public function handle(Request $request, Closure $next): Response
    {
        $header = config('hosting.client_ip_header');
        $ip = $header ? trim((string) $request->headers->get($header)) : '';
        if ($ip !== '' && filter_var($ip, FILTER_VALIDATE_IP)) {
            // "Is this HTTPS?" comes from the trusted proxy's header, which only
            // counts while REMOTE_ADDR is that proxy. Keep the answer before
            // replacing the address, or every URL (CSS, JS) becomes http://.
            $secure = $request->isSecure();
            $request->server->set('REMOTE_ADDR', $ip);
            if ($secure) {
                $request->server->set('HTTPS', 'on');
            }
        }

        return $next($request);
    }
}
