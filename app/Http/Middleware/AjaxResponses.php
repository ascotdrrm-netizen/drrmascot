<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\ViewErrorBag;
use Symfony\Component\HttpFoundation\Response;

/**
 * Lets every existing form and action work through AJAX
 * (resources/js/modules/ajax.js) without changing its controller.
 *
 * For an AJAX request that asks for JSON, the controller's usual answer —
 * a redirect with a flashed message, or back with errors — becomes:
 *
 *   back with errors  →  422 {message, errors: {field: [..]}}
 *   a redirect        →  200 {message, redirect, stay}
 *
 * "stay" is true when the redirect leads back to the page the request came
 * from: the page stays as it is and shows the message as a toast (so the
 * flashed message is dropped here, not shown again on the next page). Any
 * other redirect keeps its flash: the browser goes there and the page shows
 * it as usual. Ordinary (non-AJAX) requests are left exactly as they were.
 */
class AjaxResponses
{
    /** Flashed keys that hold the success message, in the order checked. */
    protected const MESSAGE_KEYS = ['status', 'success', 'message'];

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if (! ($request->ajax() && $request->wantsJson()) || ! $response instanceof RedirectResponse || ! $request->hasSession()) {
            return $response;
        }

        $session = $request->session();
        $errors = $session->get('errors');
        if ($errors instanceof ViewErrorBag && $errors->any()) {
            $session->forget('errors');
            $bag = $errors->getBag('default');

            return new JsonResponse(['message' => $bag->first(), 'errors' => $bag->toArray()], 422);
        }

        $message = null;
        foreach (self::MESSAGE_KEYS as $key) {
            if (is_string($session->get($key))) {
                $message = $session->get($key);
                break;
            }
        }

        $target = $response->getTargetUrl();
        $stay = $this->samePage($target, (string) $request->headers->get('referer'));
        if ($stay) {
            $session->forget(self::MESSAGE_KEYS);
        }

        return new JsonResponse(['ok' => true, 'message' => $message, 'redirect' => $target, 'stay' => $stay]);
    }

    protected function samePage(string $a, string $b): bool
    {
        if ($b === '') {
            return false;
        }
        $strip = fn (string $url) => rtrim((string) parse_url($url, PHP_URL_PATH), '/') . '?' . (string) parse_url($url, PHP_URL_QUERY);

        return $strip($a) === $strip($b);
    }
}
