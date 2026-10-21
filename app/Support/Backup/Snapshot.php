<?php

namespace App\Support\Backup;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;
use RuntimeException;
use Throwable;

/**
 * php artisan backup:snapshot — a verified copy of the whole database plus a
 * copy of the uploaded files, into config('backup.dir').
 *
 *  1. Skips if a verified copy is newer than min_interval_minutes.
 *  2. If the database (Supabase) doesn't answer, the run fails (exit 1, and
 *     Task Scheduler retries later).
 *  3. Counts every table's rows, writes a BackupArchive (the same format as
 *     Download Backup, so it restores from the Backup page) to a ".partial"
 *     file, counts again.
 *  4. Verifies: the archive opens, has every table, and each table's row
 *     count is within the before/after counts (exactly equal when nothing
 *     changed). Only then is it renamed to ascot_drrms-YYYY-MM-DD-HHmm.7z.
 *  5. On PostgreSQL with BACKUP_PG_DUMP set, also a pg_dump (custom format)
 *     beside it, checked with pg_restore --list — a second, independent copy
 *     restorable with PostgreSQL's own tools. Its failure is a warning only.
 *  6. Rotates (only after a verified copy): every copy from the last 48
 *     hours, plus the last copy of each of the 14 days before today.
 *  7. Copies the uploaded files (FileMirror), from wherever they are stored.
 *  8. Writes status.json, which the Admin dashboard banner reads.
 */
class Snapshot
{
    /** Verified copies; .zip ones are older, .sql ones from before the move to Supabase. */
    public const PATTERN = '/^ascot_drrms-(\d{4}-\d{2}-\d{2}-\d{4})\.(7z|zip|sql)$/';

    public const STATUS_FILE = 'status.json';

    public const FAILED_FILE = 'last-failed.partial';

    protected CarbonImmutable $now;

    public function __construct(?CarbonImmutable $now = null)
    {
        $this->now = $now ?? CarbonImmutable::now(config('app.timezone'));
    }

    /**
     * @return array{outcome: string, message: string}  outcome: success | skipped | failed
     */
    public function run(bool $force = false): array
    {
        $dir = self::dir();
        if ($dir === null) {
            return ['outcome' => 'failed', 'message' => 'BACKUP_SNAPSHOT_DIR is not set; snapshots are off on this server.'];
        }
        if (! is_dir($dir) && ! @mkdir($dir, 0775, true) && ! is_dir($dir)) {
            return ['outcome' => 'failed', 'message' => "Could not create $dir."];
        }

        $status = self::readStatus($dir);
        $status['last_attempt_at'] = $this->now->toIso8601String();
        $status['warnings'] = [];

        $newest = self::dumps($dir)->last();
        if (! $force && $newest && $newest['at']->gt($this->now->subMinutes(config('backup.min_interval_minutes')))) {
            $status['last_outcome'] = 'skipped';
            $this->writeStatus($dir, $status);

            return ['outcome' => 'skipped', 'message' => "A verified copy from {$newest['at']->format('M j, g:i A')} is recent enough."];
        }

        $partial = $dir . DIRECTORY_SEPARATOR . '.partial-' . $this->now->format('YmdHis') . '.' . BackupArchive::EXTENSION;
        try {
            $dump = $this->copyAndVerify($partial, $newest);
        } catch (Throwable $e) {
            if (is_file($partial)) {
                @rename($partial, $dir . DIRECTORY_SEPARATOR . self::FAILED_FILE);
            }
            $status['last_outcome'] = 'failed';
            $status['last_error'] = $e->getMessage();
            $this->writeStatus($dir, $status);

            return ['outcome' => 'failed', 'message' => $e->getMessage()];
        }

        $status['warnings'] = $dump['warnings'];
        $status['last_success_at'] = $this->now->toIso8601String();
        $status['last_dump'] = ['file' => $dump['file'], 'bytes' => $dump['bytes'], 'tables' => $dump['tables'], 'rows' => $dump['rows'], 'idle' => $dump['idle'], 'pg_dump' => $dump['pg_dump']];
        $status['last_error'] = null;
        $status['rotated_out'] = $this->rotate($dir);

        // The copy is good whatever happens to the file copy; a file-copy
        // failure is still a failed run (exit 1) so the task retries it.
        try {
            $status['files'] = $this->mirrorFiles($dir);
            $status['files_error'] = null;
            $status['last_outcome'] = 'success';
            $this->writeStatus($dir, $status);

            return ['outcome' => 'success', 'message' => sprintf(
                '%s: %d tables, %d rows, %s. Files: %d (%s), %d copied, %d set aside.',
                $dump['file'], $dump['tables'], $dump['rows'], self::size($dump['bytes']),
                $status['files']['files'], self::size($status['files']['bytes']), $status['files']['copied'], $status['files']['set_aside'],
            )];
        } catch (Throwable $e) {
            $status['files_error'] = $e->getMessage();
            $status['last_outcome'] = 'failed';
            $status['last_error'] = 'The database copy succeeded, but copying the uploaded files failed: ' . $e->getMessage();
            $this->writeStatus($dir, $status);

            return ['outcome' => 'failed', 'message' => $status['last_error']];
        }
    }

    // ============================== copy + verify =============================

    protected function copyAndVerify(string $partial, ?array $previous): array
    {
        try {
            DB::select('select 1');
        } catch (Throwable $e) {
            throw new RuntimeException('The database (Supabase) did not answer, so no copy was taken. Check the internet connection and that the Supabase project is not paused; the next run will catch up.');
        }

        $before = DataSet::rowCounts();
        $this->writeArchive($partial);
        $after = DataSet::rowCounts();

        // Idle database: counts must match exactly. Busy: each table's count
        // in the copy must fall between its before and after counts.
        $ranges = [];
        foreach (array_keys($after + $before) as $table) {
            $ranges[$table] = [min($before[$table] ?? 0, $after[$table] ?? 0), max($before[$table] ?? 0, $after[$table] ?? 0)];
        }
        $read = BackupArchive::verify($partial, $ranges);

        $file = 'ascot_drrms-' . $this->now->format('Y-m-d-Hi') . '.' . BackupArchive::EXTENSION;
        $final = dirname($partial) . DIRECTORY_SEPARATOR . $file;
        if (! @rename($partial, $final) && ! (copy($partial, $final) && unlink($partial))) {
            throw new RuntimeException("Could not rename the verified copy to $file.");
        }
        $bytes = filesize($final);

        $warnings = [];
        if ($previous && $bytes < $previous['bytes'] * config('backup.shrink_warning_ratio')) {
            $warnings[] = sprintf('This copy (%s) is under half the size of the previous one (%s). The row counts match the database, so the data itself got smaller.',
                self::size($bytes), self::size($previous['bytes']));
        }

        $pgDump = $this->pgDump(dirname($final), $warnings);

        return [
            'file' => $file, 'bytes' => $bytes, 'tables' => count($read['rows']),
            'rows' => array_sum(array_map('count', $read['rows'])), 'idle' => $before == $after,
            'warnings' => $warnings, 'pg_dump' => $pgDump,
        ];
    }

    /** The archive itself (a seam the tests replace to simulate a bad copy). */
    protected function writeArchive(string $path): void
    {
        BackupArchive::write($path);
    }

    /**
     * A second copy with PostgreSQL's own pg_dump, when configured. A failure
     * is recorded as a warning: the verified archive above is the copy.
     */
    protected function pgDump(string $dir, array &$warnings): ?string
    {
        $binary = config('backup.pg_dump');
        if (! filled($binary) || DB::getDriverName() !== 'pgsql') {
            return null;
        }

        $settings = config('database.connections.' . config('database.default'));
        $file = 'ascot_drrms-' . $this->now->format('Y-m-d-Hi') . '-pg.dump';
        $path = $dir . DIRECTORY_SEPARATOR . $file;
        $env = ['PGPASSWORD' => (string) ($settings['password'] ?? ''), 'PGSSLMODE' => (string) ($settings['sslmode'] ?? 'require')];
        $args = [$binary, '--format=custom', '--no-owner', '--no-privileges', '--schema=public', '--file=' . $path];
        $args = array_merge($args, filled($settings['url'] ?? null)
            ? ['--dbname=' . $settings['url']]
            : ['--host=' . $settings['host'], '--port=' . $settings['port'], '--username=' . $settings['username'], '--dbname=' . $settings['database']]);

        $result = Process::timeout(900)->env($env)->run($args);
        if (! $result->successful() || ! is_file($path)) {
            @unlink($path);
            $warnings[] = 'The extra pg_dump copy failed (exit ' . $result->exitCode() . '): ' . trim($result->errorOutput() ?: $result->output());

            return null;
        }

        $restore = preg_replace('/pg_dump(\.exe)?$/i', 'pg_restore$1', $binary);
        $list = Process::timeout(120)->run([$restore, '--list', $path]);
        if (! $list->successful()) {
            @unlink($path);
            $warnings[] = 'The extra pg_dump copy could not be read back by pg_restore, so it was removed.';

            return null;
        }

        return $file;
    }

    // ================================ rotation ================================

    /** Deletes copies outside the rotation (and their pg_dump twins); returns their names. */
    protected function rotate(string $dir): array
    {
        $dumps = self::dumps($dir);
        $keep = self::toKeep($dumps->all(), $this->now);
        $deleted = [];
        foreach ($dumps as $name => $dump) {
            if (! isset($keep[$name])) {
                @unlink($dir . DIRECTORY_SEPARATOR . $name);
                @unlink($dir . DIRECTORY_SEPARATOR . preg_replace('/\.(7z|zip|sql)$/', '-pg.dump', $name));
                $deleted[] = $name;
            }
        }

        return $deleted;
    }

    /**
     * @param  array<string, array{at: CarbonImmutable}>  $dumps
     * @return array<string, true>
     */
    public static function toKeep(array $dumps, CarbonImmutable $now): array
    {
        $keep = [];
        $recent = $now->subHours(config('backup.keep_all_hours'));
        $firstDay = $now->startOfDay()->subDays(config('backup.keep_daily_days'));
        $lastOfDay = [];

        foreach ($dumps as $name => $dump) {
            if ($dump['at']->gte($recent)) {
                $keep[$name] = true;
            }
            // The day's last copy is chosen among ALL of that day's copies, so a
            // day whose last copy is already in the 48 hours adds nothing.
            if ($dump['at']->gte($firstDay) && $dump['at']->lt($now->startOfDay())) {
                $day = $dump['at']->format('Y-m-d');
                if (! isset($lastOfDay[$day]) || $dump['at']->gt($dumps[$lastOfDay[$day]]['at'])) {
                    $lastOfDay[$day] = $name;
                }
            }
        }

        return $keep + array_fill_keys($lastOfDay, true);
    }

    // ================================== files =================================

    protected function mirrorFiles(string $dir): array
    {
        $total = ['files' => 0, 'bytes' => 0, 'copied' => 0, 'copied_bytes' => 0, 'set_aside' => 0];
        $removed = $dir . DIRECTORY_SEPARATOR . 'files-removed';
        foreach (config('backup.mirror') as $name => $disk) {
            $part = FileMirror::sync(
                $disk,
                $dir . DIRECTORY_SEPARATOR . 'files' . DIRECTORY_SEPARATOR . $name,
                $removed . DIRECTORY_SEPARATOR . $this->now->format('Y-m-d') . DIRECTORY_SEPARATOR . $name,
            );
            foreach ($total as $key => $value) {
                $total[$key] = $value + $part[$key];
            }
        }
        FileMirror::pruneRemoved($removed, config('backup.removed_files_days'), $this->now);

        return $total + ['at' => $this->now->toIso8601String()];
    }

    // ============================= status (read) ==============================

    public static function dir(): ?string
    {
        $dir = config('backup.dir');

        return filled($dir) ? rtrim($dir, '\\/') : null;
    }

    /**
     * Verified copies in the folder, oldest first. Only files that passed every
     * check ever get this name, so the folder itself is the source of truth.
     *
     * @return \Illuminate\Support\Collection<string, array{at: CarbonImmutable, bytes: int}>
     */
    public static function dumps(string $dir): \Illuminate\Support\Collection
    {
        $dumps = [];
        foreach (is_dir($dir) ? scandir($dir) : [] as $name) {
            if (preg_match(self::PATTERN, $name, $m)) {
                $dumps[$name] = [
                    'at' => CarbonImmutable::createFromFormat('Y-m-d-Hi', $m[1], config('app.timezone'))->startOfMinute(),
                    'bytes' => filesize($dir . DIRECTORY_SEPARATOR . $name),
                ];
            }
        }

        return collect($dumps)->sortBy(fn ($d) => $d['at']->getTimestamp());
    }

    public static function readStatus(string $dir): array
    {
        $path = $dir . DIRECTORY_SEPARATOR . self::STATUS_FILE;
        $status = is_file($path) ? json_decode((string) file_get_contents($path), true) : null;

        return is_array($status) ? $status : [];
    }

    protected function writeStatus(string $dir, array $status): void
    {
        $path = $dir . DIRECTORY_SEPARATOR . self::STATUS_FILE;
        file_put_contents($path . '.tmp', json_encode($status, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        @rename($path . '.tmp', $path);
    }

    /**
     * What the Admin dashboard banner and the Backup page show. The alarm is
     * the ABSENCE of a recent verified copy, so it also fires if the task
     * never runs at all (deleted, PC off for days, folder lost).
     *
     * @return array{configured: bool, alarm: bool, newest: ?CarbonImmutable, files_at: ?CarbonImmutable, status: array, dumps: \Illuminate\Support\Collection}
     */
    public static function summary(?CarbonImmutable $now = null): array
    {
        $dir = self::dir();
        if ($dir === null) {
            return ['configured' => false, 'alarm' => false, 'newest' => null, 'files_at' => null, 'status' => [], 'dumps' => collect()];
        }
        $now ??= CarbonImmutable::now(config('app.timezone'));
        $dumps = self::dumps($dir);
        $status = self::readStatus($dir);
        $newest = $dumps->last()['at'] ?? null;
        $filesAt = isset($status['files']['at']) ? CarbonImmutable::parse($status['files']['at'])->setTimezone(config('app.timezone')) : null;
        $stale = $now->subHours(config('backup.stale_after_hours'));

        return [
            'configured' => true,
            'alarm' => ! $newest || $newest->lt($stale) || ! $filesAt || $filesAt->lt($stale),
            'newest' => $newest,
            'files_at' => $filesAt,
            'status' => $status,
            'dumps' => $dumps->reverse(),
        ];
    }

    public static function size(int $bytes): string
    {
        return $bytes >= 1048576 ? number_format($bytes / 1048576, 1) . ' MB' : number_format($bytes / 1024, 1) . ' KB';
    }
}
