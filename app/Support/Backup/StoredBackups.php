<?php

namespace App\Support\Backup;

use App\Models\Backup;
use App\Support\CloudDownload;
use Illuminate\Support\Facades\Storage;

/**
 * Where backups are kept (BACKUP_DISK). A backup bigger than partBytes() is
 * saved as parts — {path}.part001, .part002 … — because the bucket refuses
 * bigger objects (Supabase: 10 MB, docs/RENDER_SUPABASE.md). The Backup row
 * keeps the whole file's path; every method here joins the parts back.
 */
class StoredBackups
{
    public static function diskName(): string
    {
        return config('backup.disk', 'local');
    }

    public static function disk()
    {
        return Storage::disk(self::diskName());
    }

    /** Largest piece written: 90% of the limit, since a bucket's "10 MB" may mean 10,000,000 bytes. 0 = no limit. */
    public static function partBytes(): int
    {
        $limit = (int) config('backup.max_file_bytes');

        return $limit > 0 ? max(1, (int) ($limit * 0.9)) : PHP_INT_MAX;
    }

    public static function partPath(string $path, int $n): string
    {
        return sprintf('%s.part%03d', $path, $n);
    }

    /** The parts of a backup saved in parts, in order; [] for one saved whole. */
    public static function parts(string $path): array
    {
        $parts = [];
        while (self::disk()->exists($part = self::partPath($path, count($parts) + 1))) {
            $parts[] = $part;
        }

        return $parts;
    }

    /** Saves the local file $localFile as $path, in parts when it is over partBytes(). */
    public static function put(string $path, string $localFile): void
    {
        $size = filesize($localFile);
        $input = fopen($localFile, 'rb');
        if ($size === false || ! is_resource($input)) {
            throw new \RuntimeException('Unable to save the backup archive.');
        }
        try {
            if ($size <= self::partBytes()) {
                if (! self::disk()->put($path, $input, ['visibility' => 'private'])) {
                    throw new \RuntimeException('Unable to save the backup archive.');
                }

                return;
            }
            for ($n = 1, $left = $size; $left > 0; $n++, $left -= self::partBytes()) {
                $chunk = stream_get_contents($input, self::partBytes());
                if ($chunk === false || $chunk === '' || ! self::disk()->put(self::partPath($path, $n), $chunk, ['visibility' => 'private'])) {
                    throw new \RuntimeException("Unable to save part {$n} of the backup archive.");
                }
            }
        } finally {
            fclose($input);
        }
    }

    public static function exists(string $path): bool
    {
        return self::disk()->exists($path) || self::disk()->exists(self::partPath($path, 1));
    }

    public static function size(string $path): int
    {
        $parts = self::parts($path);

        return $parts ? array_sum(array_map(fn ($part) => self::disk()->size($part), $parts)) : self::disk()->size($path);
    }

    public static function delete(string $path): void
    {
        self::disk()->delete([$path, ...self::parts($path)]);
    }

    /** ZIP processing needs a local working file; the durable copy stays remote. */
    public static function withLocalFile(string $path, callable $callback): mixed
    {
        $parts = self::parts($path);
        if (! $parts && config('filesystems.disks.'.self::diskName().'.driver') === 'local') {
            return $callback(self::disk()->path($path));
        }
        $temporary = tempnam(sys_get_temp_dir(), 'drrms-backup-');
        if ($temporary === false) {
            throw new \RuntimeException('Unable to prepare the stored backup.');
        }
        $output = null;
        try {
            $output = fopen($temporary, 'wb');
            if (! is_resource($output)) {
                throw new \RuntimeException('Unable to read the stored backup.');
            }
            foreach ($parts ?: [$path] as $piece) {
                self::copyTo($piece, $output);
            }
            fclose($output);
            $output = null;

            return $callback($temporary);
        } finally {
            if (is_resource($output)) {
                fclose($output);
            }
            @unlink($temporary);
        }
    }

    public static function download(Backup $backup)
    {
        $type = str_ends_with($backup->filename, '.' . BackupArchive::EXTENSION) ? 'application/x-7z-compressed' : 'application/zip';
        $parts = self::parts($backup->storage_path);
        if ($parts) {
            // No single object to send the browser to: joined here, as it streams.
            return response()->streamDownload(function () use ($parts) {
                $output = fopen('php://output', 'wb');
                foreach ($parts as $part) {
                    self::copyTo($part, $output);
                }
                fclose($output);
            }, $backup->filename, ['Content-Type' => $type, 'Cache-Control' => 'private, no-store']);
        }

        return self::diskName() === 's3'
            ? CloudDownload::redirect('s3', $backup->storage_path, $backup->filename, $type)
            : response()->download(self::disk()->path($backup->storage_path), $backup->filename);
    }

    /** @param  resource  $output */
    protected static function copyTo(string $path, $output): void
    {
        $input = self::disk()->readStream($path);
        try {
            if (! is_resource($input) || stream_copy_to_stream($input, $output) === false) {
                throw new \RuntimeException('Unable to read the stored backup.');
            }
        } finally {
            if (is_resource($input)) {
                fclose($input);
            }
        }
    }
}
