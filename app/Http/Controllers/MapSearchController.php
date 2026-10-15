<?php

namespace App\Http\Controllers;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

/**
 * Place search for the "Pick on map" dialog, proxied to OpenStreetMap's
 * Nominatim so its usage policy is kept from one place (config/map.php):
 * an identifying User-Agent, results cached, and at most ONE outbound
 * request per second for the whole app. A request over that limit is
 * refused ("busy, try again"), never queued. Signed-in users only; the
 * response carries only each result's name, latitude and longitude.
 */
class MapSearchController extends Controller
{
    public const OUTBOUND_LIMITER = 'map-search:nominatim';

    public function __invoke(Request $request): JsonResponse
    {
        $config = config('map.search');

        if (! ($config['enabled'] ?? false)) {
            return response()->json(['message' => 'Place search is switched off. Pan the map, or type the coordinates.'], 503);
        }

        $data = $request->validate([
            'q' => ['required', 'string', 'min:2', 'max:' . $config['max_query_length']],
        ]);
        $query = Str::of($data['q'])->squish()->lower()->value();
        $cacheKey = 'map-search:' . sha1($query);

        if (($cached = Cache::get($cacheKey)) !== null) {
            return response()->json(['results' => $cached]);
        }

        // One outbound request per second, app-wide. Refuse rather than wait.
        if (RateLimiter::tooManyAttempts(self::OUTBOUND_LIMITER, 1)) {
            return response()->json(['message' => 'Search is busy. Try again in a moment.'], 429);
        }
        RateLimiter::hit(self::OUTBOUND_LIMITER, 1);

        try {
            $response = Http::withUserAgent($this->userAgent($config))
                ->timeout($config['timeout_seconds'])
                ->acceptJson()
                ->get($config['url'], [
                    'q' => $query,
                    'format' => 'jsonv2',
                    'countrycodes' => $config['country_codes'],
                    'viewbox' => $this->viewbox(),
                    'bounded' => 0, // prefer Aurora, but still find places outside it
                    'limit' => $config['limit'],
                    'accept-language' => 'en',
                ]);
        } catch (ConnectionException) {
            return $this->unavailable();
        }

        if (! $response->successful() || ! is_array($response->json())) {
            return $this->unavailable();
        }

        $results = collect($response->json())
            ->filter(fn ($place) => is_array($place) && is_numeric($place['lat'] ?? null) && is_numeric($place['lon'] ?? null))
            ->take($config['limit'])
            ->map(fn ($place) => [
                'name' => Str::limit((string) ($place['display_name'] ?? $place['name'] ?? ''), 200),
                'lat' => (float) $place['lat'],
                'lng' => (float) $place['lon'],
            ])
            ->values()
            ->all();

        Cache::put($cacheKey, $results, now()->addDays($config['cache_days']));

        return response()->json(['results' => $results]);
    }

    protected function userAgent(array $config): string
    {
        return filled($config['contact'] ?? null)
            ? "{$config['user_agent']} contact: {$config['contact']}"
            : $config['user_agent'];
    }

    /** Nominatim's viewbox, "west,north,east,south", from the province bounds. */
    protected function viewbox(): string
    {
        [[$south, $west], [$north, $east]] = config('map.province_bounds');

        return "{$west},{$north},{$east},{$south}";
    }

    protected function unavailable(): JsonResponse
    {
        return response()->json(['message' => 'Place search isn\'t available right now. Pan the map, or type the coordinates.'], 502);
    }
}
