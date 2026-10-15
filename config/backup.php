<?php

/*
|--------------------------------------------------------------------------
| Automatic snapshots (php artisan backup:snapshot)
|--------------------------------------------------------------------------
|
| A verified copy of the whole database (the same password-protected zip as
| Download Backup) plus a copy of the uploaded files, run by Windows Task
| Scheduler every 3 hours while someone is signed in to this PC (see
| App\Support\Backup\Snapshot). Separate from Backup & Restore, which makes
| zips on demand.
|
| Off unless BACKUP_SNAPSHOT_DIR is set: on a host without it the command
| refuses to run and no dashboard banner is shown.
|
*/

return [

    // Outside the project and outside the web root, e.g.
    // C:\Users\<you>\ASCOT-DRRMS-Backups\snapshots
    'dir' => env('BACKUP_SNAPSHOT_DIR'),

    // Optional second copy with PostgreSQL's own tool, e.g.
    // C:\pgsql\bin\pg_dump.exe (pg_restore.exe must sit beside it). Empty: off.
    'pg_dump' => env('BACKUP_PG_DUMP'),

    // The task fires every hour (so a failed run is retried within the hour)
    // but a copy is taken only once the newest verified one is this old:
    // a copy every 3 hours, and the sign-in trigger doesn't duplicate it.
    'min_interval_minutes' => 170,

    // Rotation: every copy from the last 48 hours, plus the last copy of
    // each of the 14 calendar days before today.
    'keep_all_hours' => 48,
    'keep_daily_days' => 14,

    // Dashboard banner once the newest verified copy (or file copy) is older.
    'stale_after_hours' => 26,

    // A copy under half the size of the previous one is recorded as a
    // warning — never a failure (the row counts already prove it matches).
    'shrink_warning_ratio' => 0.5,

    // Uploaded files copied after each verified copy: folder name => Storage
    // disk (config/filesystems.php). On Supabase the record files live on the
    // "s3" disk (Supabase Storage); stored backup zips stay on "local".
    'mirror' => array_merge([
        'private' => 'local',
        'public' => 'public',
    ], env('FILES_DISK', 'records') === 's3' ? ['records' => 's3'] : []),

    // Files that disappear from the source are set aside (not deleted) for
    // this many days before the copy forgets them.
    'removed_files_days' => 14,

    // The largest single file this server can write. Some hosts cap every
    // file (InfinityFree: 10 MB), so the Clear All Records copy (one zip: the
    // data plus the record photos) can't be written past it — clearing then
    // stops before anything is deleted. The Clear screen warns once the copy
    // is estimated at 90% of this. Raise it on a host without such a limit.
    'max_file_bytes' => (int) env('BACKUP_MAX_FILE_BYTES', 10 * 1024 * 1024),

];
