<?php

namespace App\Http\Controllers;

use App\Support\Files;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Serves an uploaded file from the private records disk (App\Support\Files):
 * only through a signed, expiring link that a page the user was allowed to
 * see created, and only to a signed-in user (routes: auth + signed).
 *
 * Photos and PDFs open inline; anything else is always downloaded, so an
 * uploaded HTML or SVG file can never run in the app's own origin.
 */
class FileController extends Controller
{
    /** Shown in the browser; every other type is forced to download. */
    protected const INLINE_TYPES = ['image/jpeg', 'image/png', 'image/webp', 'image/gif', 'application/pdf'];

    public function show(Request $request, string $path): StreamedResponse
    {
        abort_unless((string) $request->query('viewer') === (string) $request->user()->getAuthIdentifier(), 403);
        abort_unless(Files::isAllowedPath($path) && Files::exists($path), 404);

        $disk = Files::disk();
        $type = $disk->mimeType($path) ?: 'application/octet-stream';
        $name = $request->query('name') ?: basename($path);
        $headers = [
            'Content-Type' => $type,
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ];

        return in_array($type, self::INLINE_TYPES, true) && ! $request->boolean('download')
            ? $disk->response($path, $name, $headers)
            : $disk->download($path, $name, $headers);
    }
}
