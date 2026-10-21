<?php

namespace App\Support\Backup;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use ZipArchive;

/**
 * An ASCOT-DRRMS backup file: a .zip holding ONE password-protected entry,
 * INNER (ZipCrypto, so Windows' own "Extract All" opens it; see ZIP_PASSWORD).
 * A zip never hides its entry names, so the tables sit inside that one locked
 * entry: opening the backup shows a single name, and nothing more until the
 * password is given. INNER is a plain zip holding
 *
 *   manifest.json           what it is, when, from which database, and the
 *                           row count of every table (and the files' sizes)
 *   data/<table>.jsonl      one table each, one row per line (JSON)
 *   files/<disk>/<path>     uploaded record files (Clear All Records' copy only)
 *
 * The same on every database, so a backup taken on Supabase restores into the
 * test database and back. Older backups are still read: those entries locked
 * one by one at the top level (no INNER), and those made before the move to
 * Supabase (a .zip with one MySQL .sql file, or a plain .sql), whose INSERT
 * statements become rows the same way (App\Support\Backup\LegacySql).
 */
class BackupArchive
{
    /**
     * Fixed password on every backup zip, so an Admin can open one with no
     * extra software. ZipCrypto, not AES-256, on purpose: Windows' and macOS's
     * built-in extractors only open ZipCrypto. It is not the security boundary
     * (anyone with this source knows it): the Data Privacy Password, the typed
     * confirmation and the Admin-only routes are.
     */
    public const ZIP_PASSWORD = 'ASCOTDRRMS2026';

    /** Passwords earlier backups were written with; still tried when reading one. */
    public const OLD_ZIP_PASSWORDS = ['ASCOTDRRMS'];

    public const FORMAT = 'ascot-drrms-backup';

    public const VERSION = 2;

    public const MANIFEST = 'manifest.json';

    /** The one locked entry of a backup zip, holding all of its contents. */
    public const INNER = 'ascot-drrms-backup.zip';

    /**
     * Writes the archive to $absolutePath and returns its manifest.
     *
     * @param  list<array{0: string, 1: string, 2?: int}>  $files  [disk, path, bytes?] to carry under files/
     */
    public static function write(string $absolutePath, array $files = []): array
    {
        if (! defined(ZipArchive::class . '::EM_TRAD_PKWARE')) {
            throw new RuntimeException("This server's PHP zip extension does not support ZIP encryption.");
        }

        $innerPath = tempnam(sys_get_temp_dir(), 'drrms_backup_');
        try {
            $manifest = self::writeContents($innerPath, $files);
            self::lock($innerPath, $absolutePath);
        } catch (\Throwable $e) {
            if (is_file($absolutePath)) {
                @unlink($absolutePath);
            }
            throw $e;
        } finally {
            @unlink($innerPath);
        }

        return $manifest;
    }

    /** Writes $absolutePath as the outer zip: $innerPath as its one locked entry, INNER. */
    protected static function lock(string $innerPath, string $absolutePath): void
    {
        $zip = new ZipArchive();
        $open = $zip->open($absolutePath, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        if ($open !== true) {
            throw new RuntimeException("Unable to create the backup archive at {$absolutePath} (ZipArchive error code: {$open}).");
        }
        self::add($zip, self::INNER, fn () => $zip->addFile($innerPath, self::INNER));
        $zip->setCompressionName(self::INNER, ZipArchive::CM_STORE); // already compressed
        $zip->setPassword(self::ZIP_PASSWORD);
        if (! $zip->close()) {
            throw new RuntimeException('The backup archive could not be finished: ' . $zip->getStatusString());
        }
    }

    /**
     * Writes the backup's contents (manifest, tables, files) as a plain zip
     * at $absolutePath, the entry that lock() then protects.
     *
     * @param  list<array{0: string, 1: string, 2?: int}>  $files
     */
    protected static function writeContents(string $absolutePath, array $files): array
    {
        $exported = DataSet::exportToFiles();
        $zip = new ZipArchive();
        $open = $zip->open($absolutePath, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        if ($open !== true) {
            self::cleanup($exported);
            throw new RuntimeException("Unable to create the backup archive at {$absolutePath} (ZipArchive error code: {$open}).");
        }

        try {
            $manifest = [
                'format' => self::FORMAT,
                'version' => self::VERSION,
                'created_at' => now()->toIso8601String(),
                'database' => DB::getDriverName(),
                'app' => config('app.name'),
                'tables' => [],
                'files' => [],
            ];
            foreach ($exported as $table => [$path, $rows]) {
                self::put($zip, "data/{$table}.jsonl", fn () => $zip->addFile($path, "data/{$table}.jsonl"));
                $manifest['tables'][$table] = $rows;
            }
            foreach ($files as $file) {
                [$disk, $path] = $file;
                $bytes = Storage::disk($disk)->get($path);
                if ($bytes === null) {
                    throw new RuntimeException("Could not read {$path} to add it to the backup.");
                }
                self::put($zip, "files/{$disk}/{$path}", fn () => $zip->addFromString("files/{$disk}/{$path}", $bytes));
                $manifest['files'][] = ['disk' => $disk, 'path' => $path, 'bytes' => strlen($bytes)];
            }
            $json = json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            self::put($zip, self::MANIFEST, fn () => $zip->addFromString(self::MANIFEST, $json));

            if (! $zip->close()) {
                throw new RuntimeException('The backup archive could not be finished: ' . $zip->getStatusString());
            }
        } catch (\Throwable $e) {
            @$zip->close();
            throw $e;
        } finally {
            self::cleanup($exported);
        }

        return $manifest;
    }

    /**
     * Opens a backup for reading: the zip holding its entries (INNER, copied
     * to a temporary file, for a current backup; the file itself, for an older
     * one), with its password set. Null when it can't be opened or no known
     * password opens it. The caller closes the zip and deletes the temp file.
     *
     * @return array{0: ZipArchive, 1: ?string}|null [zip, temp file to delete]
     */
    protected static function openContents(string $absolutePath): ?array
    {
        $zip = new ZipArchive();
        if ($zip->open($absolutePath) !== true) {
            return null;
        }
        if (! self::unlock($zip)) {
            $zip->close();

            return null;
        }
        if ($zip->numFiles !== 1 || $zip->getNameIndex(0) !== self::INNER) {
            return [$zip, null];
        }

        $bytes = self::entry($zip, self::INNER);
        $zip->close();
        if ($bytes === null) {
            return null;
        }
        $temp = tempnam(sys_get_temp_dir(), 'drrms_restore_');
        file_put_contents($temp, $bytes);
        $inner = new ZipArchive();
        if ($inner->open($temp) !== true) {
            @unlink($temp);

            return null;
        }

        return [$inner, $temp];
    }

    /** Closes what openContents() opened. */
    protected static function closeContents(ZipArchive $zip, ?string $temp): void
    {
        $zip->close();
        if ($temp !== null) {
            @unlink($temp);
        }
    }

    /**
     * Reads a backup: a .zip (this format, or an old one with a MySQL .sql
     * inside). Null when it can't be opened, holds neither, or the password
     * or a checksum is wrong.
     *
     * @return array{manifest: ?array, rows: array<string, list<array<string, mixed>>>, legacy: bool}|null
     */
    public static function read(string $absolutePath): ?array
    {
        $opened = self::openContents($absolutePath);
        if ($opened === null) {
            return null;
        }
        [$zip, $temp] = $opened;

        try {
            if ($zip->locateName(self::MANIFEST) !== false) {
                $manifest = json_decode((string) self::entry($zip, self::MANIFEST), true);
                if (! is_array($manifest) || ($manifest['format'] ?? null) !== self::FORMAT) {
                    return null;
                }
                $rows = [];
                foreach (array_keys($manifest['tables'] ?? []) as $table) {
                    $text = self::entry($zip, "data/{$table}.jsonl");
                    if ($text === null) {
                        return null;
                    }
                    $rows[$table] = DataSet::parseLines($text, $table);
                }

                return ['manifest' => $manifest, 'rows' => $rows, 'legacy' => false];
            }

            // Before Supabase: one MySQL dump inside the zip.
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $name = (string) $zip->getNameIndex($i);
                if (str_ends_with(strtolower($name), '.sql')) {
                    $sql = self::entry($zip, $name);

                    return $sql === null || trim($sql) === '' ? null : self::readLegacySql($sql);
                }
            }

            return null;
        } finally {
            self::closeContents($zip, $temp);
        }
    }

    /** An old MySQL .sql dump, as rows. @return array{manifest: null, rows: array, legacy: true}|null */
    public static function readLegacySql(string $sql): ?array
    {
        if (! LegacySql::looksLikeSql($sql)) {
            return null;
        }
        try {
            $rows = LegacySql::rows($sql);
        } catch (RuntimeException) {
            return null; // damaged: a row with the wrong number of values
        }

        return ['manifest' => null, 'rows' => $rows, 'legacy' => true];
    }

    /**
     * Proves a stored backup is a usable copy: it opens with the password,
     * every entry passes its checksum, it has every table in $expected with
     * that many rows (or, for a range [low, high], a count within it), and
     * every file in $files is inside at the same size. Throws naming the
     * first problem.
     *
     * @param  array<string, int|array{0: int, 1: int}>  $expected
     * @param  list<array{0: string, 1: string, 2: int}>  $files
     */
    public static function verify(string $absolutePath, array $expected, array $files = []): array
    {
        if (! is_file($absolutePath)) {
            throw new RuntimeException('the backup file ' . basename($absolutePath) . ' was not written to disk.');
        }
        $read = self::read($absolutePath);
        if ($read === null || $read['legacy']) {
            throw new RuntimeException(basename($absolutePath) . ' could not be opened, or holds no data.');
        }

        $missing = array_diff(array_keys($expected), array_keys($read['rows']));
        if ($missing) {
            throw new RuntimeException('the backup is missing ' . count($missing) . ' of ' . count($expected) . ' tables (' . implode(', ', $missing) . ').');
        }
        foreach ($expected as $table => $count) {
            [$low, $high] = is_array($count) ? $count : [$count, $count];
            $got = count($read['rows'][$table]);
            if ($got < $low || $got > $high) {
                throw new RuntimeException($low === $high
                    ? "table {$table} has {$got} rows in the backup but {$low} in the database."
                    : "table {$table} has {$got} rows in the backup, outside the {$low}–{$high} it had while the backup was made.");
            }
        }

        if ($files) {
            $sizes = collect($read['manifest']['files'] ?? [])->mapWithKeys(fn ($f) => [$f['disk'] . ':' . $f['path'] => $f['bytes']]);
            [$zip, $temp] = self::openContents($absolutePath) ?? throw new RuntimeException(basename($absolutePath) . ' could not be opened.');
            try {
                foreach ($files as [$disk, $path, $bytes]) {
                    $stat = $zip->statName("files/{$disk}/{$path}");
                    if ($stat === false || $stat['size'] !== $bytes || ($sizes[$disk . ':' . $path] ?? null) !== $bytes) {
                        throw new RuntimeException("the file {$path} is " . ($stat === false ? 'missing from the backup.' : "{$stat['size']} bytes in the backup but {$bytes} on disk."));
                    }
                }
            } finally {
                self::closeContents($zip, $temp);
            }
        }

        return $read;
    }

    /**
     * Writes a backup's files/<disk>/<path> entries back to storage — only
     * where $target says (the disk to write to, or null to refuse), never
     * anywhere else.
     *
     * @param  callable(string $disk, string $path): ?string  $target
     * @return array{restored: int, refused: list<string>}
     */
    public static function restoreFiles(string $absolutePath, callable $target): array
    {
        $result = ['restored' => 0, 'refused' => []];
        $opened = self::openContents($absolutePath);
        if ($opened === null) {
            return $result;
        }
        [$zip, $temp] = $opened;

        try {
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $name = (string) $zip->getNameIndex($i);
                if (! preg_match('#^files/([a-z0-9_-]+)/(.+[^/])$#', $name, $m)) {
                    if (str_starts_with($name, 'files/') && ! str_ends_with($name, '/')) {
                        $result['refused'][] = $name;
                    }

                    continue;
                }
                $disk = $target($m[1], $m[2]);
                if ($disk === null) {
                    $result['refused'][] = $name;

                    continue;
                }
                $bytes = self::entry($zip, $name);
                if ($bytes !== null && Storage::disk($disk)->put($m[2], $bytes)) {
                    $result['restored']++;
                }
            }
        } finally {
            self::closeContents($zip, $temp);
        }

        return $result;
    }

    /**
     * Sets the password the archive was written with: ZIP_PASSWORD, or one of
     * OLD_ZIP_PASSWORDS for a backup made before it changed. Tried on the
     * manifest of an older backup, else the first entry (INNER, or an old
     * MySQL zip's dump); entry()'s CRC check tells a right password from a
     * wrong one. False when none opens it.
     */
    protected static function unlock(ZipArchive $zip): bool
    {
        $probe = $zip->locateName(self::MANIFEST) !== false ? self::MANIFEST : null;
        for ($i = 0; $probe === null && $i < $zip->numFiles; $i++) {
            $name = (string) $zip->getNameIndex($i);
            $probe = str_ends_with($name, '/') ? null : $name;
        }
        if ($probe === null) {
            return false;
        }
        foreach ([self::ZIP_PASSWORD, ...self::OLD_ZIP_PASSWORDS] as $password) {
            $zip->setPassword($password);
            if (self::entry($zip, $probe) !== null) {
                return true;
            }
        }

        return false;
    }

    /**
     * One entry's bytes, checked against its own CRC and size. ZipCrypto
     * checks a wrong password against a single header byte, so a wrong
     * password slips through about 1 read in 100 and returns '' — the CRC
     * catches that, and a file damaged on disk.
     */
    protected static function entry(ZipArchive $zip, string $name): ?string
    {
        $contents = $zip->getFromName($name);
        $stat = $zip->statName($name);
        if ($contents === false || $stat === false) {
            return null;
        }
        if (strlen($contents) !== $stat['size'] || crc32($contents) !== $stat['crc']) {
            return null;
        }

        return $contents;
    }

    protected static function add(ZipArchive $zip, string $entry, callable $adder): void
    {
        if (! $adder() || ! $zip->setEncryptionName($entry, ZipArchive::EM_TRAD_PKWARE)) {
            throw new RuntimeException("Could not add {$entry} to the backup archive with encryption.");
        }
    }

    /** Adds an entry to the (unencrypted) contents zip. */
    protected static function put(ZipArchive $zip, string $entry, callable $adder): void
    {
        if (! $adder()) {
            throw new RuntimeException("Could not add {$entry} to the backup archive.");
        }
    }

    /** @param array<string, array{0: string, 1: int}> $exported */
    protected static function cleanup(array $exported): void
    {
        foreach ($exported as [$path]) {
            if (is_file($path)) {
                @unlink($path);
            }
        }
    }
}
