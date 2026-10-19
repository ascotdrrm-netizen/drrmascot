<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;

/**
 * A change sent twice is saved once. resources/js/modules/ajax.js gives each
 * submission one Idempotency-Key and keeps it when it retries after a lost
 * answer (a slow connection: the server saved the drill, the reply never
 * arrived, the browser tried again). The first try runs; a repeat of the
 * same key gets the first try's JSON answer, or 409 {pending} while the first
 * is still running. Requests without the header are untouched.
 */
class IdempotentRequests
{
    /** How long a finished request's answer is replayed, in seconds. */
    public const TTL = 600;

    /** How long a key stays "pending" if its request never finishes (a worker that died). */
    public const PENDING_TTL = 120;

    public function handle(Request $request, Closure $next): Response
    {
        $key = $request->headers->get('Idempotency-Key');
        if ($request->isMethodSafe() || ! is_string($key) || ! preg_match('/^[A-Za-z0-9-]{8,80}$/', $key)) {
            return $next($request);
        }

        $owner = $request->user()?->getAuthIdentifier() ?? ($request->hasSession() ? $request->session()->getId() : $request->ip());
        $cacheKey = 'idempotency:' . sha1($owner . '|' . $request->method() . '|' . $request->path() . '|' . $key);

        if (! Cache::add($cacheKey, ['state' => 'pending'], self::PENDING_TTL)) {
            $saved = Cache::get($cacheKey);
            if (is_array($saved) && ($saved['state'] ?? null) === 'done') {
                return new JsonResponse($saved['body'], $saved['status']);
            }

            return new JsonResponse(['message' => 'Still saving your first try. Please wait.', 'pending' => true], 409);
        }

        try {
            $response = $next($request);
        } catch (\Throwable $e) {
            Cache::forget($cacheKey);
            throw $e;
        }

        // Only a successful JSON answer is replayed; anything else (field
        // errors, a redirect for a non-AJAX form) may be sent again.
        if ($response instanceof JsonResponse && $response->isSuccessful()) {
            Cache::put($cacheKey, ['state' => 'done', 'status' => $response->getStatusCode(), 'body' => $response->getData(true)], self::TTL);
        } else {
            Cache::forget($cacheKey);
        }

        return $response;
    }
}
