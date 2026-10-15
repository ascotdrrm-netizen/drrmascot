<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Run before the Drill Tracker migration (2026_10_08_000000), which refuses to
// run without these files. See App\Support\LegacyDrillExport.
Artisan::command('drills:export-legacy', function () {
    foreach (\App\Support\LegacyDrillExport::export() as $file => $rows) {
        $this->info(sprintf('%-32s %3d rows  storage/app/private/%s', $file, $rows, \App\Support\LegacyDrillExport::path($file)));
    }
})->purpose('Export drill participants, scores, workflow columns and photo rows to CSV before the Drill Tracker rebuild');

// Verified copy of the database + copy of the uploaded files, run by Windows
// Task Scheduler every 3 hours while signed in. Exit code 1 on failure so the
// task retries. See App\Support\Backup\Snapshot and config/backup.php.
// Resolved from the container so the tests can swap in a failing copy.
Artisan::command('backup:snapshot {--force : Run even if a recent verified copy exists}', function () {
    $result = app(\App\Support\Backup\Snapshot::class)->run((bool) $this->option('force'));
    $line = '[' . now()->format('Y-m-d H:i:s') . '] ' . strtoupper($result['outcome']) . ': ' . $result['message'];
    $result['outcome'] === 'failed' ? $this->error($line) : $this->info($line);

    return $result['outcome'] === 'failed' ? 1 : 0;
})->purpose('Take a verified copy of the database and the uploaded files into BACKUP_SNAPSHOT_DIR');

// Trash retention: permanently delete records in Trash longer than
// config('trash.retention_days'), with their photos and files, one at a time,
// each logged as "System (automatic)". See App\Support\TrashPurge. Run daily by
// Windows Task Scheduler (scripts/windows/register-purge-task.ps1); the
// schedule entry below is for a server with a cron entry for schedule:run.
// A real run holds an atomic lock; a second run started meanwhile does nothing.
// Every backup restore pauses it (a paused run deletes nothing and exits 0);
// --resume lists what it will delete, clears the pause and runs.
Artisan::command('trash:purge
    {--dry-run : List what would be deleted and why, and delete nothing}
    {--resume : Clear the pause a backup restore set, after listing what will be deleted, then run}', function () {
    $dryRun = (bool) $this->option('dry-run');
    $resume = (bool) $this->option('resume');
    $days = \App\Support\TrashPurge::retentionDays();

    if ($dryRun && $resume) {
        $this->error('Use --dry-run or --resume, not both. Nothing was changed.');

        return 1;
    }
    if ($resume && ! \App\Support\TrashPurge::pause()) {
        $this->error('Retention is not paused: there is no pause to clear. Nothing was changed.');

        return 1;
    }

    $lock = $dryRun ? null : Cache::lock(\App\Support\TrashPurge::LOCK, 3600);
    if ($lock && ! $lock->get()) {
        $this->info('Another trash:purge run is in progress; this one did nothing.');

        return 0;
    }

    try {
        $this->line(($dryRun ? 'DRY RUN, nothing is deleted. ' : '') . "Removing records in Trash for more than {$days} days (deleted on or before "
            . \App\Support\TrashPurge::cutoff()->format('M j, Y g:i A') . ').');
        $say = fn (string $line) => $this->line($line);
        $summary = $resume ? \App\Support\TrashPurge::resume($say) : \App\Support\TrashPurge::run($dryRun, $say);
    } finally {
        $lock?->release();
    }

    if ($summary === null) {   // the pause was cleared by someone else meanwhile
        $this->error('Retention is not paused: there is no pause to clear. Nothing was changed.');

        return 1;
    }

    $this->info(sprintf('%s%d deleted, %d kept, %d skipped, %d failed%s.', $dryRun ? 'Would be: ' : '',
        $summary['deleted'], $summary['kept'], $summary['skipped'], $summary['failed'], $summary['paused'] ? ', paused' : ''));

    return $summary['failed'] > 0 ? 1 : 0;
})->purpose('Permanently delete records that have been in Trash longer than the retention period');

Schedule::command('trash:purge')->daily();

Artisan::command('broadcasts:resume', function () {
    $stale = \App\Models\BroadcastDelivery::where('status', 'Processing')->where('started_at', '<', now()->subMinutes(10))
        ->update(['status' => 'Unknown', 'error' => 'Worker stopped before confirming the provider result. Check provider history.']);
    $sender = app(\App\Services\BroadcastSender::class);
    \App\Models\Broadcast::whereNotNull('published_at')->where('status', 'Queued')->each(function ($broadcast) use ($sender) {
        $sender->refreshStatus($broadcast->getKey());
        if ($sender->hasPending($broadcast->getKey())) \App\Jobs\DeliverBroadcast::dispatch($broadcast->getKey());
    });
    $this->info("Resumed pending broadcasts; {$stale} interrupted deliveries require review.");
})->purpose('Resume pending broadcast delivery without repeating unconfirmed requests');
Schedule::command('broadcasts:resume')->everyMinute()->withoutOverlapping();

// Uploaded files kept from before the move to Supabase (the "public" folder
// for photos, the "local" folder for drill attachments) copied into the
// records disk (App\Support\Files: Supabase Storage when FILES_DISK=s3).
// Safe to run again: a file already there with the same size is skipped.
// The originals are left where they were.
Artisan::command('files:move-to-records {--dry-run : List what would be copied, and copy nothing}', function () {
    $target = \App\Support\Files::disk();
    $copied = $skipped = $failed = 0;
    foreach (['public', 'local'] as $source) {
        $disk = \Illuminate\Support\Facades\Storage::disk($source);
        foreach (\App\Support\Files::FOLDERS as $folder) {
            foreach ($disk->allFiles($folder) as $path) {
                if ($target->exists($path)) {
                    if ($target->size($path) === $disk->size($path)
                        && hash('sha256', $target->get($path)) === hash('sha256', $disk->get($path))) {
                        $skipped++;

                        continue;
                    }
                    $failed++;
                    $this->error("{$source}:{$path} — the destination has different content; it was left untouched.");

                    continue;
                }
                if ($this->option('dry-run')) {
                    $this->line("would copy {$source}:{$path}");
                    $copied++;

                    continue;
                }
                try {
                    $stream = $disk->readStream($path);
                    if (! is_resource($stream)) {
                        throw new \RuntimeException('Could not read the source file.');
                    }
                    try {
                        if (! $target->writeStream($path, $stream)) {
                            throw new \RuntimeException('Could not write the destination file.');
                        }
                    } finally {
                        fclose($stream);
                    }
                    $copied++;
                } catch (\Throwable $e) {
                    $failed++;
                    $this->error("{$source}:{$path} — {$e->getMessage()}");
                }
            }
        }
    }
    $this->info(sprintf('%s%d copied, %d already there, %d failed (to the "%s" disk).',
        $this->option('dry-run') ? 'Would be: ' : '', $copied, $skipped, $failed, \App\Support\Files::diskName()));

    return $failed ? 1 : 0;
})->purpose('Copy uploaded files from before the move to Supabase into the records disk');
