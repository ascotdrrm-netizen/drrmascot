<?php

namespace App\Support;

/**
 * Images for dompdf: shrunk with GD to at most $maxEdge pixels on the long
 * side and re-encoded as JPEG, then embedded as a data URI. A phone photo of
 * 4000px / 5 MB becomes ~150 KB, so a PDF with many photos stays small and
 * dompdf doesn't run out of memory. Falls back to the original bytes when GD
 * can't read the file; returns null when the file is missing.
 */
final class PdfImage
{
    public static function dataUri(string $absolutePath, int $maxEdge = 1200, int $quality = 75): ?string
    {
        return is_file($absolutePath) ? self::fromBytes((string) file_get_contents($absolutePath), $maxEdge, $quality) : null;
    }

    /** An uploaded record file (App\Support\Files, e.g. Supabase Storage), or null when missing. */
    public static function fromStoredFile(?string $path, int $maxEdge = 1200, int $quality = 75): ?string
    {
        $bytes = Files::get($path);

        return $bytes === null ? null : self::fromBytes($bytes, $maxEdge, $quality);
    }

    public static function fromBytes(string $bytes, int $maxEdge = 1200, int $quality = 75): ?string
    {
        if ($bytes === '') {
            return null;
        }
        $image = function_exists('imagecreatefromstring') ? @imagecreatefromstring($bytes) : false;

        if ($image === false) {
            $mime = (new \finfo(FILEINFO_MIME_TYPE))->buffer($bytes) ?: 'image/jpeg';

            return 'data:' . $mime . ';base64,' . base64_encode($bytes);
        }

        $width = imagesx($image);
        $height = imagesy($image);
        $scale = min(1, $maxEdge / max($width, $height));

        // Always onto a white canvas: transparent PNG/WebP areas would turn
        // black in a JPEG.
        $canvas = imagecreatetruecolor(max(1, (int) round($width * $scale)), max(1, (int) round($height * $scale)));
        imagefill($canvas, 0, 0, imagecolorallocate($canvas, 255, 255, 255));
        imagecopyresampled($canvas, $image, 0, 0, 0, 0, imagesx($canvas), imagesy($canvas), $width, $height);
        imagedestroy($image);
        $image = $canvas;

        ob_start();
        imagejpeg($image, null, $quality);
        $jpeg = ob_get_clean();
        imagedestroy($image);

        return 'data:image/jpeg;base64,' . base64_encode($jpeg);
    }
}
