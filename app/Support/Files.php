<?php

namespace App\Support;

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;

/**
 * Where every uploaded record file lives: record photos, inspection photos,
 * personnel photos, drill attachments, report photos and proof files — one
 * private disk (config('filesystems.records_disk')):
 *
 *   - Supabase Storage (FILES_DISK=s3): the private "drrms" bucket;
 *   - otherwise ("records"): storage/app/private on this server.
 *
 * Nothing is public. A page that may show a file asks Files::url() for a
 * signed link that works for SIGNED_MINUTES and only for a signed-in user
     * (FileController). The link is bound to the viewer that requested it,
     * so another signed-in user cannot reuse a copied link.
 */
class Files
{
    public const SIGNED_MINUTES = 60;

    /** Folders a file may live in (and be restored into). */
    public const FOLDERS = [
        'buildings', 'hazard-inspections', 'incidents', 'personnel', 'drill-attachments',
        'reports', 'sos', 'attachments', 'documents', 'evacuation-sites', 'hazard-layers',
        'recovery', 'trainings', 'requisition-letters', 'static-maps', 'thumbnails',
    ];

    public static function diskName(): string
    {
        return (string) config('filesystems.records_disk', 'records');
    }

    public static function disk(): Filesystem
    {
        return Storage::disk(self::diskName());
    }

    /** Stores an upload under $folder with a random name; returns its path. */
    public static function store(UploadedFile $file, string $folder, ?string $name = null): string
    {
        $extension = strtolower($file->getClientOriginalExtension() ?: $file->extension() ?: 'bin');
        $name ??= Str::random(32) . '.' . $extension;

        $path = $file->storeAs(trim($folder, '/'), $name, self::diskName());
        if ($path === false) {
            throw new \RuntimeException("The file {$file->getClientOriginalName()} could not be stored.");
        }

        return $path;
    }

    /** A signed link to view the file inline (null when there is no file). */
    public static function url(?string $path, ?string $downloadName = null): ?string
    {
        if (! filled($path) || ! Auth::check()) {
            return null;
        }

        return URL::temporarySignedRoute('files.show', now()->addMinutes(self::SIGNED_MINUTES), array_filter([
            'path' => $path,
            'name' => $downloadName,
            'viewer' => Auth::id(),
        ]));
    }

    public static function exists(?string $path): bool
    {
        return filled($path) && self::disk()->exists($path);
    }

    /** @param string|list<string>|null $paths */
    public static function delete(string|array|null $paths): void
    {
        $paths = array_values(array_filter((array) $paths));
        if ($paths) {
            self::disk()->delete($paths);
        }
    }

    /** The file's bytes, or null when it isn't there. */
    public static function get(?string $path): ?string
    {
        return self::exists($path) ? self::disk()->get($path) : null;
    }

    /** True when $path is a plain relative path inside one of FOLDERS. */
    public static function isAllowedPath(string $path): bool
    {
        if ($path === '' || str_contains($path, '..') || str_contains($path, '\\') || str_starts_with($path, '/') || str_contains($path, "\0")) {
            return false;
        }

        return in_array(explode('/', $path, 2)[0], self::FOLDERS, true) && str_contains($path, '/');
    }
}
