<?php

namespace Tests\Concerns;

use App\Support\Backup\BackupArchive;
use RuntimeException;
use ZipArchive;

/**
 * Reads and edits a backup the way the app writes it: a .7z locked with the
 * backup password, opened into a plain zip of its manifest, tables and files
 * (BackupArchive::contentsZip) and locked again (BackupArchive::seal). Static
 * so a stand-in controller in a test can use it too.
 */
class BackupZips
{
    /** Every entry of the backup at $path: name => contents. */
    public static function entries(string $path): array
    {
        return self::withContents($path, function (ZipArchive $zip) {
            $entries = [];
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $entries[$zip->getNameIndex($i)] = $zip->getFromIndex($i);
            }

            return $entries;
        });
    }

    /** Lets $change edit the backup's contents zip, then locks it back into $path. */
    public static function rewrite(string $path, callable $change): void
    {
        self::withContents($path, $change, true);
    }

    private static function withContents(string $path, callable $use, bool $save = false): mixed
    {
        $temp = BackupArchive::contentsZip($path) ?? throw new RuntimeException("{$path} did not open with the backup password.");
        try {
            $zip = new ZipArchive();
            $zip->open($temp);
            try {
                $result = $use($zip);
            } finally {
                $zip->close();
            }
            if ($save) {
                BackupArchive::seal($temp, $path);
            }
        } finally {
            @unlink($temp);
        }

        return $result;
    }
}
