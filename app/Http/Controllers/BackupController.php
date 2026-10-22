<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\Backup;
use App\Support\Backup\BackupArchive;
use App\Support\Backup\DataSet;
use App\Support\Backup\StoredBackups;
use App\Support\DataPrivacyPassword;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use RuntimeException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

/**
 * Backup & Restore (DRRMO Admin only).
 *
 * A backup is an App\Support\Backup\BackupArchive: a password-protected zip
 * of every app table as plain rows, the same on any database (Supabase in
 * use, SQLite in the tests). Restore reads it — or an old MySQL backup from
 * before the move to Supabase — and writes the rows back through
 * App\Support\Backup\DataSet, all or nothing, with foreign keys on.
 */
class BackupController extends Controller
{
    /**
     * Backups created within this many days count as "Recent" in the Stored Backups
     * panel, versus "Older". Purely a display grouping.
     */
    protected const RECENT_BACKUPS_DAYS = 7;

    /**
     * Retention cap: after every new backup, anything beyond the most recent N
     * (by creation order) is deleted — both its DB row and its file on disk.
     */
    protected const MAX_STORED_BACKUPS = 20;

    /** The zip password; see BackupArchive::ZIP_PASSWORD for why it is fixed. */
    protected const ZIP_PASSWORD = BackupArchive::ZIP_PASSWORD;

    /** Filename prefix of the copy Clear All Records takes first; never pruned. */
    public const PRE_CLEAR_PREFIX = 'ascot-drrms-pre-clear-backup-';

    public function index(): View
    {
        $backups = Backup::with('creator')->orderByDesc('backup_id')->get();
        $recentCutoff = now()->subDays(self::RECENT_BACKUPS_DAYS);

        return view('backup.index', [
            'dataPrivacyPasswordConfigured' => $this->isDataPrivacyPasswordConfigured(),
            'recentBackups' => $backups->filter(
                fn ($backup) => $backup->created_at && $backup->created_at->greaterThanOrEqualTo($recentCutoff)
            )->values(),
            'oldBackups' => $backups->filter(
                fn ($backup) => ! $backup->created_at || $backup->created_at->lessThan($recentCutoff)
            )->values(),
            'recentBackupsDays' => self::RECENT_BACKUPS_DAYS,
            'maxStoredBackups' => self::MAX_STORED_BACKUPS,
            'snapshots' => \App\Support\Backup\Snapshot::summary(),
        ]);
    }

    /**
     * Clear All Records — the confirmation screen. Everything it lists is
     * counted live (App\Support\RecordsClear::inventory()).
     */
    public function clearRecordsForm(): View
    {
        return view('backup.clear-records', [
            'inventory' => \App\Support\RecordsClear::inventory(),
            'kept' => \App\Support\RecordsClear::kept(),
            'snapshotDir' => \App\Support\Backup\Snapshot::dir(),
            'dataPrivacyPasswordConfigured' => $this->isDataPrivacyPasswordConfigured(),
            'zipSupported' => extension_loaded('zip') && BackupArchive::sevenZipAvailable(),
            'copyEstimate' => \App\Support\RecordsClear::estimatedCopyBytes(),
            // Only a local disk can't take the copy: on cloud storage it is saved in parts (StoredBackups).
            'maxFileBytes' => config('filesystems.disks.' . StoredBackups::diskName() . '.driver') === 'local' ? (int) config('backup.max_file_bytes') : 0,
        ]);
    }

    /**
     * Clear All Records. Gates, in order: DRRMO Admin (route middleware), the
     * Data Privacy Password (throttled; failures logged as data_privacy_denied),
     * the exact phrase CLEAR ALL RECORDS, then a verified pre-clear backup made
     * with the same routine as Download Backup. If that backup doesn't verify,
     * nothing is deleted. The wipe itself is App\Support\RecordsClear::run().
     */
    public function clearRecords(Request $request): RedirectResponse
    {
        $this->verifyDataPrivacyPassword($request);

        if ($request->input('confirmation') !== \App\Support\RecordsClear::PHRASE) {
            throw ValidationException::withMessages([
                'confirmation' => 'You must type ' . \App\Support\RecordsClear::PHRASE . ' exactly to confirm this action.',
            ]);
        }

        $this->assertZipEncryptionSupported('clear');
        $back = redirect()->route('admin.backup.clear-records');

        // The pre-clear backup. Counts and rows are read in one transaction, so
        // they come from the same snapshot and must match exactly.
        $backup = null;
        try {
            DB::beginTransaction();
            try {
                $expected = DataSet::rowCounts();
                $files = \App\Support\RecordsClear::currentFiles();
                $backup = $this->createStoredBackup(preClear: true, files: $files);
                DB::commit();
            } catch (Throwable $e) {
                DB::rollBack();
                throw $e;
            }
            $this->verifyStoredBackup($backup, $expected, $files);
        } catch (Throwable $e) {
            if ($backup) {
                StoredBackups::delete($backup->storage_path);
                $backup->delete();
            }
            Log::warning('Clear All Records: pre-clear backup failed', ['error' => $e->getMessage()]);

            return $back->withErrors(['clear' => 'Nothing was deleted. The backup taken first could not be verified: ' . $e->getMessage()]);
        }

        try {
            $result = \App\Support\RecordsClear::run($backup, $expected, Auth::user());
        } catch (\App\Support\RecordsChangedException $e) {
            return $back->withErrors(['clear' => $e->getMessage() . ' Nothing was deleted; the backup ' . $backup->filename . ' was kept. Try again.']);
        }

        // After the delete, so it survives: the only in-system record of the clear.
        ActivityLog::create([
            'personnel_id' => Auth::id(),
            'action' => 'records_cleared',
            'subject_type' => 'backup',
            'subject_id' => $backup->getKey(),
            'description' => mb_substr(sprintf(
                '%s cleared all records: %d records and %d files deleted. Details: %s',
                Auth::user()->full_name, $result['totals']['records_deleted'], $result['totals']['files_deleted'], basename($result['manifest_path'])
            ), 0, 255),
            'ip_address' => $request->ip(),
        ]);

        return $back->with('cleared', [
            'backup' => $backup->filename,
            'manifest' => $result['manifest_path'],
            'folder' => $result['folder'],
            'records' => $result['totals']['records_deleted'],
            'files' => $result['totals']['files_deleted'],
            'not_deleted' => $result['files']['not_deleted'],
        ]);
    }

    protected function isDataPrivacyPasswordConfigured(): bool
    {
        return DataPrivacyPassword::isConfigured();
    }

    /**
     * Set the Data Privacy Password from the Backup & Restore page. The first
     * time, the Admin confirms with their OWN login password; after that, a
     * change needs the current Data Privacy Password (same throttle and
     * data_privacy_denied log as every other use of it).
     */
    public function setDataPrivacyPassword(Request $request): RedirectResponse
    {
        $changing = $this->isDataPrivacyPasswordConfigured();
        if ($changing) {
            $this->verifyDataPrivacyPassword($request);
        } else {
            $request->validate(['account_password' => ['required', 'string']]);
            $throttleKey = 'data-privacy-password:' . Auth::id();
            if (RateLimiter::tooManyAttempts($throttleKey, 5)) {
                throw ValidationException::withMessages(['account_password' => 'Too many attempts. Please try again in ' . RateLimiter::availableIn($throttleKey) . ' seconds.']);
            }
            if (! Hash::check($request->input('account_password'), Auth::user()->getAuthPassword())) {
                RateLimiter::hit($throttleKey, 60);
                throw ValidationException::withMessages(['account_password' => 'That is not your login password.']);
            }
            RateLimiter::clear($throttleKey);
        }

        $data = $request->validate([
            'new_data_privacy_password' => ['required', 'string', 'min:8', 'max:72', 'confirmed'],
        ], [
            'new_data_privacy_password.confirmed' => 'The two new passwords do not match.',
        ]);
        if (Hash::check($data['new_data_privacy_password'], Auth::user()->getAuthPassword())) {
            throw ValidationException::withMessages(['new_data_privacy_password' => 'Use a different password from your own login: this one is a second lock.']);
        }

        DataPrivacyPassword::set($data['new_data_privacy_password']);
        ActivityLog::create([
            'personnel_id' => Auth::id(), 'action' => 'updated', 'subject_type' => null, 'subject_id' => null,
            'description' => Auth::user()->full_name . ($changing ? ' changed' : ' set') . ' the Data Privacy Password',
            'ip_address' => $request->ip(),
        ]);

        return redirect()->route('admin.backup.index')->with('status', $changing
            ? 'Data Privacy Password changed. Use the new one from now on.'
            : 'Data Privacy Password set. Download Backup, Restore and Clear All Records are now available.');
    }

    /**
     * Guards every code path that builds or reads a ZIP archive. Throws (redirecting
     * back with a field error) rather than letting a missing extension surface as a
     * fatal "Class not found" error.
     */
    protected function assertZipSupported(string $field): void
    {
        if (! extension_loaded('zip')) {
            throw ValidationException::withMessages([
                $field => "This server's PHP build does not support ZIP archives. Contact your system administrator.",
            ]);
        }
    }

    /**
     * Before WRITING or reading a locked .7z backup: the zip extension (the
     * contents are put together as a zip) AND the 7-Zip program. Never
     * silently falls back to an unlocked archive.
     */
    protected function assertZipEncryptionSupported(string $field): void
    {
        $this->assertZipSupported($field);

        if (! BackupArchive::sevenZipAvailable()) {
            throw ValidationException::withMessages([
                $field => 'The 7-Zip program, which locks the backup with its password, is not installed on this server. Contact your system administrator.',
            ]);
        }
    }

    /**
     * Independent second gate for Download Backup / Restore / Clear, separate
     * from the requester's own DRRMO Admin login. Throws on any failure —
     * missing config, rate-limited, or wrong password.
     */
    protected function verifyDataPrivacyPassword(Request $request): void
    {
        if (! $this->isDataPrivacyPasswordConfigured()) {
            throw ValidationException::withMessages([
                'data_privacy_password' => 'Set the Data Privacy Password first (Backup & Restore page).',
            ]);
        }

        $request->validate([
            'data_privacy_password' => ['required', 'string'],
        ]);

        $throttleKey = 'data-privacy-password:' . Auth::id();

        if (RateLimiter::tooManyAttempts($throttleKey, 5)) {
            $seconds = RateLimiter::availableIn($throttleKey);

            throw ValidationException::withMessages([
                'data_privacy_password' => "Too many attempts. Please try again in {$seconds} seconds.",
            ]);
        }

        if (! DataPrivacyPassword::check($request->input('data_privacy_password'))) {
            RateLimiter::hit($throttleKey, 60);

            ActivityLog::create([
                'personnel_id' => Auth::id(),
                'action' => 'data_privacy_denied',
                'subject_type' => null,
                'subject_id' => null,
                'description' => Auth::user()->full_name . ' entered an incorrect data privacy password',
                'ip_address' => $request->ip(),
            ]);

            throw ValidationException::withMessages([
                'data_privacy_password' => 'Incorrect data privacy password.',
            ]);
        }

        RateLimiter::clear($throttleKey);
    }

    public function download(Request $request): BinaryFileResponse|StreamedResponse|RedirectResponse
    {
        $this->verifyDataPrivacyPassword($request);

        $this->assertZipEncryptionSupported('backup_zip');

        $backup = $this->createStoredBackup();

        $this->logBackupDownload($backup, $request);

        // The zip IS the permanent stored copy (see Recent/Old Backups), so it
        // is not deleted after sending.
        return StoredBackups::download($backup);
    }

    /**
     * Builds a password-protected backup zip in storage/app/private/backups/,
     * records it as a stored Backup, and applies the retention cap. Shared by
     * Download Backup and Clear All Records (whose copy is named PRE_CLEAR_PREFIX,
     * is never pruned, and carries $files — list of [disk, path, bytes] — under files/).
     */
    protected function createStoredBackup(bool $preClear = false, array $files = []): Backup
    {
        $timestamp = now()->format('Y-m-d_His');
        $base = ($preClear ? self::PRE_CLEAR_PREFIX : 'ascot-drrms-backup-') . $timestamp;

        // A backup file name is never reused: names are stamped to the second,
        // so a second backup in the same second gets -2, -3 …
        $extension = BackupArchive::EXTENSION;
        for ($name = $base, $n = 2; StoredBackups::exists("backups/{$name}.{$extension}"); $n++) {
            $name = "{$base}-{$n}";
        }
        $zipFilename = "{$name}.{$extension}";
        $storagePath = "backups/{$zipFilename}";

        $temporary = tempnam(sys_get_temp_dir(), 'drrms-backup-');
        if ($temporary === false) {
            throw new RuntimeException('Unable to prepare a backup archive.');
        }
        try {
            $this->writeArchive($temporary, $files);
            StoredBackups::put($storagePath, $temporary);
            $backup = Backup::create([
                'filename' => $zipFilename,
                'storage_path' => $storagePath,
                'created_by' => Auth::id(),
                'file_size' => StoredBackups::size($storagePath),
            ]);
        } catch (Throwable $e) {
            StoredBackups::delete($storagePath);
            throw $e;
        } finally {
            @unlink($temporary);
        }

        $this->pruneOldBackups();

        return $backup;
    }

    /** Writes the archive itself (a seam the tests replace to simulate a bad copy). */
    protected function writeArchive(string $absolutePath, array $files): array
    {
        return BackupArchive::write($absolutePath, $files);
    }

    /**
     * Retention: keep only the MAX_STORED_BACKUPS most recently created backups.
     * Pre-clear copies (Clear All Records) are never pruned and don't count
     * toward the cap: they are the way back from a wipe.
     */
    protected function pruneOldBackups(): void
    {
        $stale = Backup::where('filename', 'not like', self::PRE_CLEAR_PREFIX . '%')
            ->orderByDesc('backup_id')->get()->slice(self::MAX_STORED_BACKUPS);

        foreach ($stale as $backup) {
            StoredBackups::delete($backup->storage_path);
            $backup->delete();
        }
    }

    public function restore(Request $request): RedirectResponse
    {
        $request->validate([
            'backup_file' => ['required', 'file'],
            'confirmation' => ['required', 'string'],
            'data_privacy_password' => ['required', 'string'],
            'mode' => ['nullable', 'in:records,everything'],
        ]);

        $this->assertConfirmedForRestore($request);

        $file = $request->file('backup_file');
        $extension = strtolower($file->getClientOriginalExtension());

        if (! in_array($extension, ['sql', 'zip', BackupArchive::EXTENSION], true)) {
            return back()->withErrors([
                'backup_file' => 'The uploaded file must have a .7z extension (or .zip / .sql for an older backup).',
            ]);
        }

        if ($extension !== 'sql') {
            $extension === BackupArchive::EXTENSION
                ? $this->assertZipEncryptionSupported('backup_file')
                : $this->assertZipSupported('backup_file');
            $read = BackupArchive::read($file->getRealPath());

            if ($read === null) {
                return back()->withErrors([
                    'backup_file' => 'This backup file is invalid or unrecognized. Check that it\'s a genuine '
                        . 'ASCOT-DRRMS backup — this also rejects archives protected with the wrong password '
                        . 'or damaged on disk.',
                ]);
            }
        } else {
            $read = BackupArchive::readLegacySql((string) file_get_contents($file->getRealPath()));

            if ($read === null) {
                return back()->withErrors([
                    'backup_file' => 'This file does not look like a valid SQL dump.',
                ]);
            }
        }

        return $this->performRestore($read, $file->getClientOriginalName(), $this->restoreMode($request), $extension !== 'sql' ? $file->getRealPath() : null);
    }

    /**
     * "Restore from this" for a server-stored backup — the same gates as
     * restore(), just reading the archive from disk instead of an upload.
     */
    public function restoreStored(Request $request, Backup $backup): RedirectResponse
    {
        $request->validate([
            'confirmation' => ['required', 'string'],
            'data_privacy_password' => ['required', 'string'],
            'mode' => ['nullable', 'in:records,everything'],
        ]);

        $this->assertConfirmedForRestore($request);
        str_ends_with($backup->storage_path, '.' . BackupArchive::EXTENSION)
            ? $this->assertZipEncryptionSupported('backup_file')
            : $this->assertZipSupported('backup_file');

        if (! StoredBackups::exists($backup->storage_path)) {
            return back()->withErrors([
                'backup_file' => 'This stored backup file could no longer be found on disk.',
            ]);
        }

        return StoredBackups::withLocalFile($backup->storage_path, function ($path) use ($backup, $request) {
            $read = BackupArchive::read($path);
            if ($read === null) {
                return back()->withErrors(['backup_file' => 'This stored backup file is invalid or unrecognized and could not be read.']);
            }
            return $this->performRestore($read, $backup->filename, $this->restoreMode($request), $path);
        });
    }

    /**
     * Re-download a server-stored backup. No extra gate beyond the Admin-only
     * route: the file is already a password-protected zip.
     */
    public function downloadStored(Request $request, Backup $backup): BinaryFileResponse|StreamedResponse|RedirectResponse
    {
        if (! StoredBackups::exists($backup->storage_path)) {
            return back()->withErrors([
                'backup_file' => 'This stored backup file could no longer be found on disk.',
            ]);
        }

        $this->logBackupDownload($backup, $request);

        return StoredBackups::download($backup);
    }

    protected function logBackupDownload(Backup $backup, Request $request): void
    {
        ActivityLog::create([
            'personnel_id' => Auth::id(), 'action' => 'backup_downloaded',
            'subject_type' => 'backup', 'subject_id' => $backup->getKey(),
            'description' => mb_substr(Auth::user()->full_name . ' downloaded database backup ' . $backup->filename, 0, 255),
            'ip_address' => $request->ip(),
        ]);
    }

    /**
     * Independent gate for BOTH restore entry points, checked before any file
     * content is looked at: the Data Privacy Password, then "RESTORE" typed exactly.
     */
    protected function assertConfirmedForRestore(Request $request): void
    {
        $this->verifyDataPrivacyPassword($request);

        if ($request->input('confirmation') !== 'RESTORE') {
            throw ValidationException::withMessages([
                'confirmation' => 'You must type RESTORE exactly to confirm this action.',
            ]);
        }
    }

    /**
     * Shared tail end of both restore entry points: check, apply, log, redirect.
     *
     * $mode "records" (the default) replaces only the record tables, after
     * checking every campus/school they point at still exists (App\Support\
     * RecordsRestore); "everything" replaces every app table. In both, a zip
     * with a files/ directory (only pre-clear copies have one) also gets its
     * record files written back.
     *
     * @param  array{manifest: ?array, rows: array, legacy: bool}  $read
     */
    protected function performRestore(array $read, string $sourceLabel, string $mode = 'records', ?string $zipPath = null): RedirectResponse
    {
        $read['rows'] = \App\Support\RiskMatrix::normaliseRows($read['rows']);

        if ($mode === 'records') {
            try {
                $rows = \App\Support\RecordsRestore::fromRows($read['rows']);
            } catch (RuntimeException $e) {
                return back()->withErrors(['backup_file' => 'Nothing was restored. ' . $e->getMessage()]);
            }
            $problems = \App\Support\RecordsRestore::problems($rows);
            if ($problems) {
                return back()->withErrors(['backup_file' => 'Nothing was restored. ' . implode(' ', $problems)]);
            }
        } else {
            $tables = DataSet::tables();
            $rows = array_intersect_key($read['rows'], array_flip($tables));
            $unknown = DataSet::unknownColumns($rows);
            if ($unknown) {
                return back()->withErrors(['backup_file' => 'Nothing was restored. This backup has columns the database no longer has (' . implode(', ', $unknown) . ').']);
            }
        }

        try {
            $result = $mode === 'records'
                ? \App\Support\RecordsRestore::apply($rows)
                : ['tables' => DataSet::replace($rows, $tables)];
        } catch (Throwable $e) {
            Log::error('Database restore failed', [
                'user_id' => Auth::id(),
                'user_email' => Auth::user()->email,
                'filename' => $sourceLabel,
                'error' => $e->getMessage(),
                'at' => now()->toDateTimeString(),
            ]);

            return back()->withErrors([
                'backup_file' => 'Restore failed: ' . $e->getMessage(),
            ]);
        }

        // Trashed rows are back with their original deleted_at: pause Trash
        // retention so the next purge can't undo this restore overnight.
        \App\Support\TrashPurge::pauseAfterRestore($sourceLabel);

        // Record files carried by a pre-clear copy (after the database commit:
        // files can't join the transaction). Ordinary backups have none.
        $files = $zipPath
            ? BackupArchive::restoreFiles($zipPath, fn (string $disk, string $path) => \App\Support\RecordsClear::restoreTarget($disk, $path))
            : ['restored' => 0, 'refused' => []];

        Log::info('Database restore completed', [
            'user_id' => Auth::id(),
            'user_email' => Auth::user()->email,
            'filename' => $sourceLabel,
            'mode' => $mode,
            'legacy_format' => $read['legacy'],
            'files_restored' => $files['restored'],
            'at' => now()->toDateTimeString(),
        ]);

        $message = $mode === 'records'
            ? sprintf('Records restored from %s: %d records%s. Accounts, campuses, schools and the activity log were left as they were.',
                $sourceLabel, array_sum($result['tables']),
                $result['accounts_cleared'] ? sprintf(' (%d by accounts that no longer exist, kept under their names)', $result['accounts_cleared']) : '')
            : "Database restored successfully from {$sourceLabel}.";
        if ($result['notifications_skipped'] ?? 0) {
            $n = $result['notifications_skipped'];
            $message .= " {$n} staff " . ($n === 1 ? 'notification' : 'notifications') . ' for accounts that no longer exist were left out.';
        }
        if ($files['restored']) {
            $message .= " {$files['restored']} uploaded " . ($files['restored'] === 1 ? 'file' : 'files') . ' restored.';
        }
        if ($files['refused']) {
            $message .= ' ' . count($files['refused']) . ' file entries outside the record folders were ignored.';
        }
        $message .= ' Trash retention is paused until someone resumes it.';

        return redirect()
            ->route('admin.backup.index')
            ->with('status', $message);
    }

    /** "records" unless the form explicitly chose "everything" (a request naming no mode gets the safer one). */
    protected function restoreMode(Request $request): string
    {
        return $request->input('mode') === 'everything' ? 'everything' : 'records';
    }

    /**
     * Proves a stored backup is a usable copy: it opens, every table in
     * $expected is there with exactly that many rows (counted in the same
     * snapshot), and every file in $files is inside at the same size.
     *
     * @param  array<string, int>  $expected
     */
    protected function verifyStoredBackup(Backup $backup, array $expected, array $files = []): void
    {
        StoredBackups::withLocalFile($backup->storage_path, fn ($path) => BackupArchive::verify($path, $expected, $files));
    }
}
