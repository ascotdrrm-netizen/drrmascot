<?php

namespace App\Support;

use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\HeaderUtils;

class CloudDownload
{
    public static function redirect(string $disk, string $path, string $name, string $type = 'application/octet-stream', bool $inline = false)
    {
        $name = str_replace(["\r", "\n", '/', '\\'], '_', $name);
        $extension = pathinfo($name, PATHINFO_EXTENSION);
        $fallback = 'download.'.(preg_match('/\A[a-zA-Z0-9]{1,12}\z/', $extension) ? $extension : 'bin');
        $disposition = HeaderUtils::makeDisposition($inline ? 'inline' : 'attachment', $name, $fallback);

        return redirect()->away(Storage::disk($disk)->temporaryUrl($path, now()->addMinute(), [
            'ResponseContentType' => $type, 'ResponseContentDisposition' => $disposition,
            'ResponseCacheControl' => 'private, no-store',
        ]))->withHeaders(['Cache-Control' => 'private, no-store', 'Referrer-Policy' => 'no-referrer']);
    }
}
