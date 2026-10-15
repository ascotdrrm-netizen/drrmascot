<?php

namespace App\Http\Middleware;

use App\Support\CloudDownload;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/** Generated PDFs can also exceed the function's 4.5 MB response limit. */
class OffloadLargeDownloads
{
    public function handle(Request $request, Closure $next)
    {
        $response = $next($request);
        if (! config('hosting.serverless') || ! $request->user() || $response->getStatusCode() !== 200) {
            return $response;
        }
        $bytes = $response->getContent();
        if (! is_string($bytes) || strlen($bytes) < 3 * 1024 * 1024
            || ! str_contains((string) $response->headers->get('Content-Type'), 'application/pdf')
            || ! str_contains((string) $response->headers->get('Content-Disposition'), 'attachment')) {
            return $response;
        }
        $path = '_exports/'.Str::uuid().'.pdf';
        Storage::disk('s3')->put($path, $bytes, ['visibility' => 'private', 'ContentType' => 'application/pdf']);
        $name = 'ASCOT-DRRMS-report.pdf';
        $disposition = $response->headers->get('Content-Disposition');
        if (preg_match('/filename="?([^";]+)"?/', $disposition, $match)) {
            $name = $match[1];
        }

        return CloudDownload::redirect('s3', $path, $name, 'application/pdf');
    }
}
