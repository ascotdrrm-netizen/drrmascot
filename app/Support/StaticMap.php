<?php

namespace App\Support;

use Illuminate\Http\Client\Pool;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * A still map image for a PDF (dompdf can't draw the web map): the street
 * tiles around a point (config map.pdf, OpenStreetMap by default) joined with
 * GD, a red pin on the point, returned as a JPEG data URI. Tiles are cached
 * (OpenStreetMap's tile policy: identify the app, cache, no bulk use), and
 * fetched together with a short timeout. Null when it is switched off or any
 * tile can't be had: the PDF then shows the coordinates alone, as before.
 */
final class StaticMap
{
    private const TILE = 256;

    public static function dataUri(float $latitude, float $longitude): ?string
    {
        $config = config('map.pdf');
        if (! ($config['enabled'] ?? false) || ! function_exists('imagecreatetruecolor')
            || abs($latitude) > 85 || abs($longitude) > 180) {
            return null;
        }

        $zoom = (int) $config['zoom'];
        $width = (int) $config['width'];
        $height = (int) $config['height'];

        // The point in world pixels at this zoom (Web Mercator), and the frame around it.
        $scale = self::TILE * (2 ** $zoom);
        $rad = deg2rad($latitude);
        $px = ($longitude + 180) / 360 * $scale;
        $py = (1 - log(tan($rad) + 1 / cos($rad)) / M_PI) / 2 * $scale;
        $left = (int) floor($px - $width / 2);
        $top = (int) floor($py - $height / 2);

        $tiles = [];
        $count = 2 ** $zoom;
        for ($ty = intdiv($top, self::TILE); $ty <= intdiv($top + $height - 1, self::TILE); $ty++) {
            for ($tx = intdiv($left, self::TILE); $tx <= intdiv($left + $width - 1, self::TILE); $tx++) {
                if ($ty < 0 || $ty >= $count) {
                    return null;
                }
                $tiles["{$zoom}/" . (($tx % $count + $count) % $count) . "/{$ty}"] = [$tx, $ty];
            }
        }

        $bytes = self::tiles(array_keys($tiles), $config);
        if ($bytes === null) {
            return null;
        }

        $canvas = imagecreatetruecolor($width, $height);
        imagefill($canvas, 0, 0, imagecolorallocate($canvas, 242, 239, 233));
        foreach ($tiles as $key => [$tx, $ty]) {
            $tile = @imagecreatefromstring($bytes[$key]);
            if ($tile === false) {
                imagedestroy($canvas);

                return null;
            }
            imagecopy($canvas, $tile, $tx * self::TILE - $left, $ty * self::TILE - $top, 0, 0, self::TILE, self::TILE);
            imagedestroy($tile);
        }
        self::pin($canvas, (int) round($px - $left), (int) round($py - $top));

        ob_start();
        imagejpeg($canvas, null, 82);
        imagedestroy($canvas);

        return 'data:image/jpeg;base64,' . base64_encode((string) ob_get_clean());
    }

    /** The tile images by "z/x/y", from the cache or fetched together; null if any is missing. */
    private static function tiles(array $keys, array $config): ?array
    {
        $bytes = [];
        $missing = [];
        foreach ($keys as $key) {
            $cached = Cache::get('pdf-map-tile:' . $key);
            $cached === null ? $missing[] = $key : $bytes[$key] = base64_decode($cached);
        }
        if ($missing === []) {
            return $bytes;
        }

        $url = fn (string $key) => strtr($config['tile_url'], array_combine(['{z}', '{x}', '{y}'], explode('/', $key)));
        try {
            $responses = Http::pool(fn (Pool $pool) => array_map(
                fn ($key) => $pool->as($key)->withHeaders(['User-Agent' => $config['user_agent']])
                    ->connectTimeout(3)->timeout((int) $config['timeout_seconds'])->get($url($key)),
                $missing
            ));
        } catch (\Throwable $e) {
            Log::warning('PDF map tiles could not be fetched', ['error' => $e->getMessage()]);

            return null;
        }

        foreach ($missing as $key) {
            $response = $responses[$key] ?? null;
            if (! $response instanceof \Illuminate\Http\Client\Response || ! $response->successful() || $response->body() === '') {
                Log::warning('PDF map tile could not be fetched', ['tile' => $key,
                    'status' => $response instanceof \Illuminate\Http\Client\Response ? $response->status() : null]);

                return null;
            }
            $bytes[$key] = $response->body();
            Cache::put('pdf-map-tile:' . $key, base64_encode($bytes[$key]), now()->addDays((int) $config['cache_days']));
        }

        return $bytes;
    }

    /** A red map pin whose tip is on ($x, $y). */
    private static function pin(\GdImage $canvas, int $x, int $y): void
    {
        imageantialias($canvas, true);
        $red = imagecolorallocate($canvas, 220, 38, 38);
        $white = imagecolorallocate($canvas, 255, 255, 255);
        $shadow = imagecolorallocatealpha($canvas, 0, 0, 0, 90);

        imagefilledellipse($canvas, $x, $y, 14, 6, $shadow);
        imagefilledpolygon($canvas, [$x - 9, $y - 20, $x + 9, $y - 20, $x, $y], $red);
        imagefilledellipse($canvas, $x, $y - 24, 26, 26, $white);
        imagefilledellipse($canvas, $x, $y - 24, 22, 22, $red);
        imagefilledellipse($canvas, $x, $y - 24, 8, 8, $white);
    }
}
