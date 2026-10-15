<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * "Clear All Records" (Backup & Restore): what it removes, counted live.
 *
 * Removes every operational record — Trash included — and the uploaded files
 * behind them. Keeps personnel accounts, campuses, schools, the activity log,
 * stored backups, exports, the snapshot folder and migrations.
 *
 * Tables are listed in foreign-key-safe delete order (children first).
 */
class RecordsClear
{
    /** table => [label (singular, plural), has Trash] — in delete order. */
    public const TABLES = [
        'staff_notifications' => ['staff notification', 'staff notifications', false],
        'broadcast_deliveries' => ['broadcast delivery', 'broadcast deliveries', false],
        'broadcasts' => ['campus broadcast', 'campus broadcasts', false],
        'assignments' => ['dispatch assignment', 'dispatch assignments', false],
        'responders' => ['campus responder', 'campus responders', false],
        'roll_call_responses' => ['safety check-in roster entry', 'safety check-in roster entries', false],
        'roll_calls' => ['roll call', 'roll calls', false],
        'report_events' => ['report status event', 'report status events', false],
        'report_photos' => ['report photo', 'report photos', false],
        'citizen_reports' => ['Reporter submission', 'Reporter submissions', false],
        'sos_events' => ['SOS event', 'SOS events', false],
        'campus_statuses' => ['campus status', 'campus statuses', false],
        'alerts' => ['SMS & broadcast alert', 'SMS & broadcast alerts', false],
        'damage_reports' => ['damage report', 'damage reports', false],
        'incidents' => ['incident', 'incidents', true],
        'hazard_inspections' => ['hazard inspection', 'hazard inspections', false],
        'buildings' => ['building/area', 'buildings/areas', true],
        'drill_attachments' => ['drill attachment', 'drill attachments', false],
        'drill_documentations' => ['drill documentation record', 'drill documentation records', false],
        'drill_after_activity_reports' => ['After Activity Report', 'After Activity Reports', false],
        'drills' => ['drill', 'drills', true],
    ];

    /**
     * Where each record's uploaded file lives: table => [column, disk, folder,
     * label]. "records" stands for the records disk (App\Support\Files); use
     * files(), which puts in its real name.
     */
    public const FILES = [
        'report_photos' => ['path', 'records', 'reports', 'report photo'],
        'citizen_reports' => ['proof_path', 'records', 'attachments', 'report proof'],
        'buildings' => ['photo_path', 'records', 'buildings', 'building photo'],
        'hazard_inspections' => ['photo_path', 'records', 'hazard-inspections', 'inspection photo'],
        'incidents' => ['photo_path', 'records', 'incidents', 'incident photo'],
        'drill_attachments' => ['path', 'records', 'drill-attachments', 'drill attachment'],
    ];

    public const PHRASE = 'CLEAR ALL RECORDS';

    /**
     * Live counts of everything that would be removed.
     *
     * @return array{tables: array<string, array{label: string, count: int, trashed: int}>, total: int,
     *               files: array<string, array{label: string, count: int, bytes: int, missing: int}>,
     *               file_count: int, file_bytes: int, missing: int, stray: array{count: int, bytes: int}}
     */
    public static function inventory(): array
    {
        $tables = [];
        foreach (self::TABLES as $table => [$one, $many, $hasTrash]) {
            $count = DB::table($table)->count();
            $tables[$table] = [
                'label' => $count === 1 ? $one : $many,
                'count' => $count,
                'trashed' => $hasTrash ? DB::table($table)->whereNotNull('deleted_at')->count() : 0,
            ];
        }

        $files = [];
        $referenced = [];
        foreach (self::files() as $table => [$column, $disk, $folder, $label]) {
            $entry = ['label' => $label, 'count' => 0, 'bytes' => 0, 'missing' => 0];
            foreach (DB::table($table)->whereNotNull($column)->pluck($column) as $path) {
                $referenced[$disk . ':' . $path] = true;
                if (Storage::disk($disk)->exists($path)) {
                    $entry['count']++;
                    $entry['bytes'] += Storage::disk($disk)->size($path);
                } else {
                    $entry['missing']++;
                }
            }
            $files[$table] = $entry;
        }

        // Anything else already sitting in those folders (left by an earlier
        // delete) goes too, so the clear leaves no orphans behind.
        $stray = ['count' => 0, 'bytes' => 0];
        foreach (self::files() as [, $disk, $folder]) {
            foreach (Storage::disk($disk)->allFiles($folder) as $path) {
                if (! isset($referenced[$disk . ':' . $path])) {
                    $stray['count']++;
                    $stray['bytes'] += Storage::disk($disk)->size($path);
                }
            }
        }

        return [
            'tables' => $tables,
            'total' => array_sum(array_column($tables, 'count')),
            'files' => $files,
            'file_count' => array_sum(array_column($files, 'count')) + $stray['count'],
            'file_bytes' => array_sum(array_column($files, 'bytes')) + $stray['bytes'],
            'missing' => array_sum(array_column($files, 'missing')),
            'stray' => $stray,
        ];
    }

    /**
     * Every file in the record-file folders right now (named on a record or
     * not), as the pre-clear backup carries them.
     *
     * @return list<array{0: string, 1: string, 2: int}> [disk, path, bytes]
     */
    public static function currentFiles(): array
    {
        $files = [];
        foreach (self::files() as [, $disk, $folder]) {
            foreach (Storage::disk($disk)->allFiles($folder) as $path) {
                $files[] = [$disk, $path, Storage::disk($disk)->size($path)];
            }
        }

        return $files;
    }

    /**
     * About how big the pre-clear copy will be: the record files (photos
     * barely compress) plus the database's table data. An estimate, for the
     * file-size warning only.
     */
    public static function estimatedCopyBytes(): int
    {
        $files = array_sum(array_column(self::currentFiles(), 2));

        return $files + \App\Support\Backup\DataSet::approximateBytes();
    }

    /** FILES with the records disk's real name (App\Support\Files::diskName()). */
    public static function files(): array
    {
        return array_map(function (array $entry) {
            $entry[1] = \App\Support\Files::diskName();

            return $entry;
        }, self::FILES);
    }

    /**
     * Where a backup's files/<disk>/<path> entry is written back: the records
     * disk, and only into the record-file folders; null refuses it. Backups
     * made before Supabase name their files "public" or "local" — those land
     * on the records disk too.
     */
    public static function restoreTarget(string $disk, string $path): ?string
    {
        $current = \App\Support\Files::diskName();
        if (! in_array($disk, ['public', 'local', $current], true) || str_contains($path, '..') || str_contains($path, '\\') || str_starts_with($path, '/')) {
            return null;
        }
        foreach (self::FILES as [, , $folder]) {
            if (str_starts_with($path, $folder . '/')) {
                return $current;
            }
        }

        return null;
    }

    /** Whether a backup's files/<disk>/<path> entry may be written back. */
    public static function restorablePath(string $disk, string $path): bool
    {
        return self::restoreTarget($disk, $path) !== null;
    }

    /**
     * What is kept, counted live, so the screen can say so with numbers.
     *
     * @return list<string> e.g. "9 personnel accounts", "1 campus"
     */
    public static function kept(): array
    {
        $line = fn (int $n, string $one, string $many) => number_format($n) . ' ' . ($n === 1 ? $one : $many);

        return [
            $line(DB::table('personnel')->count(), 'personnel account', 'personnel accounts'),
            $line(DB::table('campuses')->count(), 'campus', 'campuses'),
            $line(DB::table('schools')->count(), 'school', 'schools'),
            $line(DB::table('activity_logs')->count(), 'activity log entry', 'activity log entries'),
            $line(DB::table('backups')->count(), 'stored backup', 'stored backups'),
        ];
    }

    /** "11 incidents, 17 buildings/areas, …, 8 files (2.5 MB)" in delete-screen order. */
    public static function summary(array $inventory): string
    {
        $order = ['incidents', 'buildings', 'hazard_inspections', 'drills', 'alerts', 'damage_reports', 'drill_documentations', 'drill_after_activity_reports', 'drill_attachments'];
        $parts = array_map(fn ($t) => $inventory['tables'][$t]['count'] . ' ' . $inventory['tables'][$t]['label'], $order);
        $parts[] = $inventory['file_count'] . ' uploaded ' . ($inventory['file_count'] === 1 ? 'file' : 'files') . ' (' . self::size($inventory['file_bytes']) . ')';

        return implode(', ', $parts);
    }

    // ================================ the wipe ================================

    /**
     * Clears everything listed in TABLES (Trash included) and the files behind
     * it. Call only after the pre-clear backup has been verified.
     *
     *  1. If BACKUP_SNAPSHOT_DIR is set, writes pre-clear-<stamp>\ there with a
     *     copy of the backup zip and a frozen copy of the record-file folders.
     *  2. Writes the manifest (status "started") beside the backup.
     *  3. One transaction, children first. The rows are locked and counted
     *     first; if they differ from what the backup holds (someone added or
     *     removed a record since), it aborts and nothing is deleted.
     *  4. After the commit, deletes the files: those named on the records plus
     *     any others in the same folders. Files can't join a database
     *     transaction, so any that fail are listed in the manifest.
     *  5. Manifest status "completed" (or "aborted"), with every detail.
     *
     * @param  array<string, int>  $verifiedCounts  row counts the backup was verified against
     * @return array the manifest, plus 'manifest_path' and 'folder'
     *
     * @throws RecordsChangedException when the rows changed after the backup
     */
    public static function run(\App\Models\Backup $backup, array $verifiedCounts, \App\Models\Personnel $by): array
    {
        $now = now();
        $manifestPath = 'backups/' . pathinfo($backup->filename, PATHINFO_FILENAME) . '.manifest.json';
        $manifest = [
            'status' => 'started',
            'started_at' => $now->toIso8601String(),
            'cleared_by' => ['personnel_id' => $by->getKey(), 'name' => $by->full_name],
            'pre_clear_backup' => [
                'backup_id' => $backup->getKey(), 'filename' => $backup->filename,
                'path' => (\App\Support\Backup\StoredBackups::diskName() === 'local' ? 'storage/app/private/' : \App\Support\Backup\StoredBackups::diskName().':') . $backup->storage_path, 'bytes' => $backup->file_size,
                'verified_row_counts' => $verifiedCounts,
            ],
            'extra_copy' => null,
            'tables' => [],
            'files' => ['deleted' => [], 'missing_before' => [], 'not_deleted' => []],
        ];

        $folder = self::writeExtraCopy($backup, $now, $manifest);
        self::writeManifest($manifestPath, $folder, $manifest);

        // 3. The rows, all or nothing.
        try {
            $files = DB::transaction(function () use ($verifiedCounts, &$manifest) {
                $changed = [];
                foreach (array_keys(self::TABLES) as $table) {
                    // Lock the rows, then count them (PostgreSQL refuses FOR
                    // UPDATE on COUNT(*) itself).
                    $now = DB::table($table)->lockForUpdate()->get()->count();
                    if ($now !== ($verifiedCounts[$table] ?? 0)) {
                        $changed[] = "$table: " . ($verifiedCounts[$table] ?? 0) . " in the backup, $now now";
                    }
                }
                if ($changed) {
                    throw new RecordsChangedException('Records changed while the backup was being made (' . implode('; ', $changed) . ').');
                }

                $files = [];
                foreach (self::files() as $table => [$column, $disk]) {
                    foreach (DB::table($table)->whereNotNull($column)->pluck($column) as $path) {
                        $files[] = [$disk, $path];
                    }
                }
                foreach (self::TABLES as $table => [, , $hasTrash]) {
                    $manifest['tables'][$table] = [
                        'deleted' => DB::table($table)->count(),
                        'of_which_in_trash' => $hasTrash ? DB::table($table)->whereNotNull('deleted_at')->count() : 0,
                    ];
                    DB::table($table)->delete();
                }

                return $files;
            });
        } catch (RecordsChangedException $e) {
            $manifest['status'] = 'aborted';
            $manifest['aborted_reason'] = $e->getMessage() . ' Nothing was deleted.';
            $manifest['tables'] = [];
            self::writeManifest($manifestPath, $folder, $manifest);

            throw $e;
        }

        // 4. The files, after the commit.
        $seen = [];
        foreach ($files as [$disk, $path]) {
            $seen[$disk . ':' . $path] = true;
            self::deleteFile($disk, $path, $manifest);
        }
        foreach (self::files() as [, $disk, $folderName]) {
            foreach (Storage::disk($disk)->allFiles($folderName) as $path) {
                if (! isset($seen[$disk . ':' . $path])) {
                    self::deleteFile($disk, $path, $manifest, stray: true);
                }
            }
            foreach (array_reverse(Storage::disk($disk)->allDirectories($folderName)) as $dir) {
                if (Storage::disk($disk)->allFiles($dir) === []) {
                    Storage::disk($disk)->deleteDirectory($dir);
                }
            }
        }

        // 5.
        $manifest['status'] = 'completed';
        $manifest['completed_at'] = now()->toIso8601String();
        $manifest['totals'] = [
            'records_deleted' => array_sum(array_column($manifest['tables'], 'deleted')),
            'files_deleted' => count($manifest['files']['deleted']),
            'bytes_deleted' => array_sum(array_column($manifest['files']['deleted'], 'bytes')),
            'files_not_deleted' => count($manifest['files']['not_deleted']),
        ];
        self::writeManifest($manifestPath, $folder, $manifest);

        return $manifest + ['manifest_path' => 'storage/app/private/' . $manifestPath, 'folder' => $folder];
    }

    protected static function deleteFile(string $disk, string $path, array &$manifest, bool $stray = false): void
    {
        $store = Storage::disk($disk);
        if (! $store->exists($path)) {
            $manifest['files']['missing_before'][] = ['disk' => $disk, 'path' => $path];

            return;
        }
        $bytes = $store->size($path);
        $error = null;
        try {
            if (! $store->delete($path) || $store->exists($path)) {
                $error = 'the file could not be deleted';
            }
        } catch (\Throwable $e) {
            $error = $e->getMessage();
        }
        $entry = ['disk' => $disk, 'path' => $path, 'bytes' => $bytes] + ($stray ? ['not_on_any_record' => true] : []);
        if ($error === null) {
            $manifest['files']['deleted'][] = $entry;
        } else {
            $manifest['files']['not_deleted'][] = $entry + ['error' => $error];
        }
    }

    /**
     * The extra copy, only when BACKUP_SNAPSHOT_DIR is set: pre-clear-<stamp>\
     * holding the backup zip and the record-file folders as they are now (the
     * regular file mirror forgets removed files after 14 days). Not a gate.
     */
    protected static function writeExtraCopy(\App\Models\Backup $backup, $now, array &$manifest): ?string
    {
        $dir = \App\Support\Backup\Snapshot::dir();
        if ($dir === null) {
            return null;
        }
        // Same separator as the configured folder, so the path shown reads cleanly.
        $folder = $dir . (str_contains($dir, '/') ? '/' : DIRECTORY_SEPARATOR) . 'pre-clear-' . $now->format('Y-m-d-His');
        try {
            self::ensureDir($folder);
            if (! \App\Support\Backup\StoredBackups::withLocalFile($backup->storage_path, fn ($path) => copy($path, $folder . DIRECTORY_SEPARATOR . $backup->filename))) {
                throw new \RuntimeException('could not copy the backup zip');
            }
            $copied = 0;
            foreach (self::files() as [, $disk, $folderName]) {
                foreach (Storage::disk($disk)->allFiles($folderName) as $path) {
                    $target = $folder . DIRECTORY_SEPARATOR . 'files' . DIRECTORY_SEPARATOR . $disk . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $path);
                    self::ensureDir(dirname($target));
                    // Through Storage, so it works on Supabase Storage too.
                    $bytes = Storage::disk($disk)->get($path);
                    if ($bytes === null || file_put_contents($target, $bytes) === false) {
                        throw new \RuntimeException("could not copy $path");
                    }
                    $copied++;
                }
            }
            $manifest['extra_copy'] = ['folder' => $folder, 'files_copied' => $copied];

            return $folder;
        } catch (\Throwable $e) {
            $manifest['extra_copy'] = ['folder' => $folder, 'error' => $e->getMessage()];

            return null;
        }
    }

    protected static function writeManifest(string $path, ?string $folder, array $manifest): void
    {
        $json = json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (! \App\Support\Backup\StoredBackups::disk()->put($path, $json)) {
            throw new \RuntimeException('Could not save the records-clear manifest.');
        }
        if ($folder) {
            @file_put_contents($folder . DIRECTORY_SEPARATOR . basename($path), $json);
        }
    }

    protected static function ensureDir(string $dir): void
    {
        if (! is_dir($dir) && ! mkdir($dir, 0775, true) && ! is_dir($dir)) {
            throw new \RuntimeException("could not create $dir");
        }
    }

    public static function size(int $bytes): string
    {
        return match (true) {
            $bytes >= 1048576 => number_format($bytes / 1048576, 1) . ' MB',
            $bytes >= 1024 => number_format($bytes / 1024) . ' KB',
            default => $bytes . ' bytes',
        };
    }
}
