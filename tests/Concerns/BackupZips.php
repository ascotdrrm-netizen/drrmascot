<?php

namespace Tests\Concerns;

use App\Support\Backup\BackupArchive;
use RuntimeException;
use ZipArchive;

/**
 * Reads and edits a backup zip the way the app writes it: one entry,
 * BackupArchive::INNER, locked with the zip password, holding the manifest,
 * tables and files. Static so a stand-in controller in a test can use it too.
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
        $outer = new ZipArchive();
        if ($outer->open($path) !== true || $outer->numFiles !== 1 || $outer->getNameIndex(0) !== BackupArchive::INNER) {
            throw new RuntimeException("{$path} is not a backup zip with one locked entry.");
        }
        $outer->setPassword(BackupArchive::ZIP_PASSWORD);
        $bytes = $outer->getFromName(BackupArchive::INNER);
        $outer->close();
        if ($bytes === false) {
            throw new RuntimeException("{$path} did not open with the zip password.");
        }

        $temp = tempnam(sys_get_temp_dir(), 'test_backup_inner_');
        file_put_contents($temp, $bytes);
        try {
            $inner = new ZipArchive();
            $inner->open($temp);
            try {
                $result = $use($inner);
            } finally {
                $inner->close();
            }
            if ($save) {
                $outer = new ZipArchive();
                $outer->open($path, ZipArchive::OVERWRITE);
                $outer->addFile($temp, BackupArchive::INNER);
                $outer->setEncryptionName(BackupArchive::INNER, ZipArchive::EM_TRAD_PKWARE);
                $outer->setPassword(BackupArchive::ZIP_PASSWORD);
                $outer->close();
            }
        } finally {
            @unlink($temp);
        }

        return $result;
    }
}
