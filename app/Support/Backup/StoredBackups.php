<?php

namespace App\Support\Backup;

use App\Models\Backup;
use App\Support\CloudDownload;
use Illuminate\Support\Facades\Storage;

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

    /** ZIP processing needs a local working file; the durable copy stays remote. */
    public static function withLocalFile(string $path, callable $callback): mixed
    {
        if (config('filesystems.disks.'.self::diskName().'.driver') === 'local') {
            return $callback(self::disk()->path($path));
        }
        $temporary = tempnam(sys_get_temp_dir(), 'drrms-backup-');
        if ($temporary === false) {
            throw new \RuntimeException('Unable to prepare the stored backup.');
        }
        $input = $output = null;
        try {
            $input = self::disk()->readStream($path);
            $output = fopen($temporary, 'wb');
            if (! is_resource($input) || ! is_resource($output) || stream_copy_to_stream($input, $output) === false) {
                throw new \RuntimeException('Unable to read the stored backup.');
            }
            fclose($output);
            $output = null;

            return $callback($temporary);
        } finally {
            if (is_resource($input)) {
                fclose($input);
            }
            if (is_resource($output)) {
                fclose($output);
            }
            @unlink($temporary);
        }
    }

    public static function download(Backup $backup)
    {
        return self::diskName() === 's3'
            ? CloudDownload::redirect('s3', $backup->storage_path, $backup->filename,
                str_ends_with($backup->filename, '.' . BackupArchive::EXTENSION) ? 'application/x-7z-compressed' : 'application/zip')
            : response()->download(self::disk()->path($backup->storage_path), $backup->filename);
    }
}
