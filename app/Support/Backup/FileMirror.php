<?php

namespace App\Support\Backup;

use Carbon\CarbonImmutable;
use FilesystemIterator;
use Illuminate\Support\Facades\Storage;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;

/**
 * Keeps a local copy of a Storage disk's uploaded files (the server's own
 * folder, or Supabase Storage). New and changed files are copied; files that
 * disappeared from the source are not deleted from the copy but moved to a
 * dated "removed" folder, which is cleared after a set number of days. So the
 * copy matches the source, yet an accidental deletion (or a deletion followed
 * by a bad sync) can still be recovered for a while.
 */
class FileMirror
{
    /**
     * @param  string  $disk  a Storage disk name (config/filesystems.php)
     * @return array{files: int, bytes: int, copied: int, copied_bytes: int, set_aside: int}
     */
    public static function sync(string $disk, string $target, string $asideDir): array
    {
        $result = ['files' => 0, 'bytes' => 0, 'copied' => 0, 'copied_bytes' => 0, 'set_aside' => 0];
        self::ensureDir($target);
        $store = Storage::disk($disk);

        $seen = [];
        foreach ($store->allFiles() as $relative) {
            $local = str_replace('/', DIRECTORY_SEPARATOR, $relative);
            $seen[$local] = true;
            $size = $store->size($relative);
            $mtime = $store->lastModified($relative);
            $copy = $target . DIRECTORY_SEPARATOR . $local;

            if (! is_file($copy) || filesize($copy) !== $size || filemtime($copy) < $mtime) {
                self::ensureDir(dirname($copy));
                $in = $store->readStream($relative);
                $out = $in ? fopen($copy, 'wb') : false;
                if (! $in || ! $out || stream_copy_to_stream($in, $out) === false) {
                    throw new RuntimeException("Could not copy $relative");
                }
                fclose($out);
                fclose($in);
                touch($copy, $mtime);
                $result['copied']++;
                $result['copied_bytes'] += $size;
            }
            $result['files']++;
            $result['bytes'] += $size;
        }

        // Gone from the source: set aside, never deleted here.
        foreach (self::files($target) as $relative => $path) {
            if (isset($seen[$relative])) {
                continue;
            }
            $aside = $asideDir . DIRECTORY_SEPARATOR . $relative;
            self::ensureDir(dirname($aside));
            if (! rename($path, $aside)) {
                throw new RuntimeException("Could not set aside $relative");
            }
            $result['set_aside']++;
        }

        return $result;
    }

    /** Forget set-aside files older than $days (dated folders under $removedRoot). */
    public static function pruneRemoved(string $removedRoot, int $days, CarbonImmutable $now): int
    {
        if (! is_dir($removedRoot)) {
            return 0;
        }
        $cutoff = $now->subDays($days)->format('Y-m-d');
        $pruned = 0;
        foreach (scandir($removedRoot) as $day) {
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $day) && $day < $cutoff) {
                self::deleteTree($removedRoot . DIRECTORY_SEPARATOR . $day);
                $pruned++;
            }
        }

        return $pruned;
    }

    /** @return \Generator<string, string> relative path => absolute path */
    protected static function files(string $root): \Generator
    {
        if (! is_dir($root)) {
            return;
        }
        $root = rtrim($root, '\\/');
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if ($file->isFile()) {
                yield substr($file->getPathname(), strlen($root) + 1) => $file->getPathname();
            }
        }
    }

    protected static function ensureDir(string $dir): void
    {
        if (! is_dir($dir) && ! mkdir($dir, 0775, true) && ! is_dir($dir)) {
            throw new RuntimeException("Could not create $dir");
        }
    }

    protected static function deleteTree(string $dir): void
    {
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($iterator as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($dir);
    }
}
