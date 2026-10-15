<?php

namespace App\Support;

use App\Models\ActivityLog;
use App\Models\Advisory\DisasterAdvisory;
use App\Models\Building\Building;
use App\Models\Drill\Drill;
use App\Models\Drill\DrillAttachment;
use App\Models\Incident\DamageReport;
use App\Models\Incident\Incident;
use App\Models\Personnel;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use LogicException;
use Throwable;

/**
 * Trash retention: permanently deleting records that have been in Trash
 * longer than config('trash.retention_days').
 *
 * purge() deletes ONE trashed record safely — the scheduled trash:purge
 * command calls it for each expired record, and an Admin button can call it
 * later. It refuses anything not in Trash and keeps a building that damage
 * reports point at (they belong to incidents; never cascade into another
 * module's records unsupervised). The declared database cascades delete the
 * children; each model collects its own file paths in forceDeleting, before
 * those rows are gone, and its files are deleted only once the delete has
 * committed. The permanently_deleted log entry is written in the same
 * transaction: activity_logs has no foreign key to the record, so the entry
 * outlives it, and a rollback takes the entry with it.
 *
 * run() is the command's loop; it records when it last succeeded, which the
 * Trash pages read before promising any countdown.
 *
 * AFTER A BACKUP RESTORE. Restoring a backup brings trashed rows back with
 * their original deleted_at. Any of them already past the retention window
 * would be permanently deleted on the next purge run — a restore undone
 * overnight. So every restore pauses retention (pauseAfterRestore(), naming
 * the restore), and it stays paused until someone runs
 * `php artisan trash:purge --resume`, which first lists what it will delete.
 */
class TrashPurge
{
    public const MODELS = [Building::class, Incident::class, Drill::class, DisasterAdvisory::class];

    /** Held for the length of a real (not dry) run, so two runs never overlap. */
    public const LOCK = 'trash:purge';

    public const STATUS_DISK = 'local';

    public const STATUS_PATH = 'trash-purge/status.json';

    // ============================== the window ================================

    public static function retentionDays(): int
    {
        return max(1, (int) config('trash.retention_days', 30));
    }

    /** Anything deleted at or before this is past the window. */
    public static function cutoff(): Carbon
    {
        return now()->subDays(self::retentionDays());
    }

    public static function removesAt(Model $record): Carbon
    {
        return $record->deleted_at->copy()->addDays(self::retentionDays());
    }

    // ============================== what goes =================================

    /**
     * Why this record must stay in Trash, or null. A building that any damage
     * report points at — including reports on incidents in Trash — is kept:
     * deleting it would cascade into those incidents' damage reports. Reads a
     * loaded damage_reports_count when the list has one.
     */
    public static function blockReason(Model $record): ?string
    {
        if (! $record instanceof Building) {
            return null;
        }

        $count = $record->damage_reports_count ?? DamageReport::where('building_id', $record->building_id)->count();

        return $count > 0
            ? 'Kept: ' . $count . ' ' . Str::plural('damage report', $count) . ' ' . ($count === 1 ? 'depends' : 'depend') . ' on this.'
            : null;
    }

    /**
     * What a permanent delete takes with it, counted before anything is
     * deleted: child rows by kind, the files (and how many are already missing
     * from disk), and for an advisory the incidents that only lose their link.
     */
    public static function contents(Model $record): array
    {
        [$children, $disk, $files, $unlinked] = match (true) {
            $record instanceof Building => [['inspection' => $record->hazardInspections()->count()], \App\Support\Files::diskName(), $record->storedFiles(), 0],
            $record instanceof Incident => [['damage report' => $record->damageReports()->count(), 'SMS alert record' => $record->alerts()->count()], \App\Support\Files::diskName(), $record->storedFiles(), 0],
            $record instanceof Drill => [[
                'documentation record' => $record->documentation()->count(),
                'After Activity Report' => $record->afterActivityReport()->count(),
                'attachment' => $record->attachments()->count(),
            ], \App\Support\Files::diskName(), $record->storedFiles(), 0],
            $record instanceof DisasterAdvisory => [[], null, [], Incident::withTrashed()->where('advisory_id', $record->advisory_id)->count()],
        };

        $missing = $disk ? count(array_filter($files, fn ($path) => ! Storage::disk($disk)->exists($path))) : 0;

        return ['children' => array_filter($children), 'files' => count($files), 'missing_files' => $missing, 'unlinked_incidents' => $unlinked];
    }

    /** "Building 'Gym' (in Trash since Sep 6, 2026): 2 inspections, 3 files (1 already missing)" */
    public static function describe(Model $record, array $contents): string
    {
        $parts = [];
        foreach ($contents['children'] as $noun => $count) {
            $parts[] = $count . ' ' . Str::plural($noun, $count);
        }
        if ($contents['files'] > 0) {
            $parts[] = $contents['files'] . ' ' . Str::plural('file', $contents['files'])
                . ($contents['missing_files'] > 0 ? " ({$contents['missing_files']} already missing)" : '');
        }
        if ($contents['unlinked_incidents'] > 0) {
            $n = $contents['unlinked_incidents'];
            $parts[] = $n . ' linked ' . Str::plural('incident', $n) . ' kept, unlinked';
        }

        return sprintf('%s \'%s\' (in Trash since %s): %s',
            Str::headline(class_basename($record)), $record->getActivityLogDescriptor(),
            $record->deleted_at->format('M j, Y'), $parts ? implode(', ', $parts) : 'nothing attached');
    }

    // ============================ one record ==================================

    /**
     * Permanently delete one record that is in Trash. $by is the Admin who
     * asked, or null for the automatic purge ("System (automatic)" in the log).
     * $trashedBefore, when given, must still hold once the row is locked —
     * a record restored and deleted again in the meantime is not touched.
     *
     * Returns ['outcome' => 'deleted'|'kept'|'skipped', 'description' => ..., 'reason' => ...].
     */
    public static function purge(Model $record, ?Personnel $by = null, ?CarbonInterface $trashedBefore = null): array
    {
        if (! in_array($record::class, self::MODELS, true)) {
            throw new LogicException(class_basename($record) . ' records have no Trash to delete from.');
        }
        if (! $record->trashed()) {
            throw new LogicException('Only a record already in Trash can be permanently deleted.');
        }

        return DB::transaction(function () use ($record, $by, $trashedBefore) {
            // Re-read under a row lock: another run, a restore or a new damage
            // report since $record was loaded all change the answer.
            $fresh = $record::onlyTrashed()->whereKey($record->getKey())->lockForUpdate()->first();
            if (! $fresh || ($trashedBefore && $fresh->deleted_at->gt($trashedBefore))) {
                return ['outcome' => 'skipped', 'reason' => 'No longer in Trash past the window.'];
            }
            if ($reason = self::blockReason($fresh)) {
                return ['outcome' => 'kept', 'reason' => $reason, 'description' => self::describe($fresh, self::contents($fresh))];
            }

            $description = self::describe($fresh, self::contents($fresh));

            // Its own entry below, not a second "deleted" that reads like a trashing.
            $fresh::withoutActivityLog(fn () => $fresh->forceDelete());

            ActivityLog::create([
                'personnel_id' => $by?->getKey(),
                'actor_name' => $by ? null : ActivityLog::SYSTEM_ACTOR,
                'action' => 'permanently_deleted',
                'subject_type' => class_basename($fresh),
                'subject_id' => $fresh->getKey(),
                'description' => mb_substr('Permanently deleted ' . $description, 0, 255),
                'ip_address' => $by ? request()?->ip() : null,
            ]);

            return ['outcome' => 'deleted', 'description' => $description];
        });
    }

    /**
     * Files of a permanently deleted record: deleted once the surrounding
     * transaction commits (at once outside one). A file already missing is
     * fine; one that can't be removed is logged, never thrown — the record is
     * already gone by then. $folder, when given, is removed if left empty.
     */
    public static function deleteFilesAfterCommit(string $disk, array $paths, ?string $folder = null): void
    {
        if (! $paths && ! $folder) {
            return;
        }

        DB::afterCommit(function () use ($disk, $paths, $folder) {
            try {
                $storage = Storage::disk($disk);
                $storage->delete($paths);
                $left = array_values(array_filter($paths, fn ($path) => $storage->exists($path)));
                if ($left) {
                    Log::warning('Permanent delete: files could not be removed', ['disk' => $disk, 'paths' => $left]);
                }
                if ($folder && $storage->directoryExists($folder) && ! $storage->allFiles($folder)) {
                    $storage->deleteDirectory($folder);
                }
            } catch (Throwable $e) {
                Log::warning('Permanent delete: files could not be removed', ['disk' => $disk, 'paths' => $paths, 'error' => $e->getMessage()]);
            }
        });
    }

    // ============================== the run ===================================

    /**
     * One scheduled run. Paused (after a backup restore): a real run deletes
     * nothing and records no successful run — paused is not failed — and a
     * dry run says so, then lists what a run would remove once resumed.
     * Otherwise see preview() and purgeExpired(). $say receives each line.
     */
    public static function run(bool $dryRun, callable $say): array
    {
        if ($pause = self::pause()) {
            $say(self::pauseNotice($pause) . ($dryRun
                ? ' Once it is resumed, a run would do this:'
                : ' Nothing was deleted. See what a run would remove with --dry-run, then resume with --resume.'));
            if (! $dryRun) {
                return self::summary(paused: true);
            }
        }

        return $dryRun ? self::preview($say) : self::purgeExpired($say);
    }

    /**
     * Clear the pause a restore set, deliberately: first the same lines a dry
     * run prints, the count, and which restore's pause is being cleared; then
     * a real run. Null (and nothing changed) when there is no pause.
     */
    public static function resume(callable $say): ?array
    {
        $pause = self::pause();
        if (! $pause) {
            return null;
        }

        $preview = self::preview($say);
        $say(sprintf('%d would be deleted, %d kept.', $preview['deleted'], $preview['kept']));
        $say('Clearing the pause set by the restore of ' . $pause['source'] . ' on ' . self::pausedAt($pause)->format('M j, Y g:i A') . '.');

        $status = self::status();
        unset($status['paused']);
        self::writeStatus($status);

        return self::purgeExpired($say);
    }

    /** What a run would do, deleting nothing: one "[would delete]" or "[would keep]" line per record. */
    public static function preview(callable $say): array
    {
        $summary = self::summary();
        foreach (self::expired(self::cutoff()) as $record) {
            $reason = self::blockReason($record);
            $summary[$reason ? 'kept' : 'deleted']++;
            $say(($reason ? '[would keep]   ' : '[would delete] ') . self::describe($record, self::contents($record)) . ($reason ? " — {$reason}" : ''));
        }

        return $summary;
    }

    /**
     * Purge everything past the window, oldest first, one record (and one
     * transaction, one log entry) at a time — so a run that is months late
     * catches up completely, and a failure on one record doesn't stop the
     * rest. A restore that pauses retention mid-run stops it before the next
     * record. Records the run unless it failed somewhere or was paused.
     */
    protected static function purgeExpired(callable $say): array
    {
        $cutoff = self::cutoff();
        $summary = self::summary();

        foreach (self::expired($cutoff) as $record) {
            if (self::pause()) {
                $summary['paused'] = true;
                $say('[stopped]      A backup restore paused retention during this run; nothing more is deleted.');
                break;
            }

            try {
                $result = self::purge($record, null, $cutoff);
                $summary[$result['outcome']]++;
                $say(match ($result['outcome']) {
                    'deleted' => '[deleted]      ' . $result['description'],
                    'kept' => '[kept]         ' . $result['description'] . ' — ' . $result['reason'],
                    default => '[skipped]      ' . class_basename($record) . " #{$record->getKey()}: {$result['reason']}",
                });
            } catch (Throwable $e) {
                $summary['failed']++;
                Log::error('trash:purge failed on a record', ['model' => $record::class, 'id' => $record->getKey(), 'error' => $e->getMessage()]);
                $say('[failed]       ' . class_basename($record) . " #{$record->getKey()}: " . $e->getMessage());
            }
        }

        if (! $summary['paused']) {
            self::recordRun($summary);
        }

        return $summary;
    }

    /** Every record in Trash since $cutoff or earlier, model by model, oldest first, read fresh one at a time. */
    protected static function expired(CarbonInterface $cutoff): \Generator
    {
        foreach (self::MODELS as $class) {
            $ids = $class::onlyTrashed()->where('deleted_at', '<=', $cutoff)->orderBy('deleted_at')->pluck((new $class)->getKeyName());
            foreach ($ids as $id) {
                if ($record = $class::onlyTrashed()->find($id)) {
                    yield $record;
                }
            }
        }
    }

    protected static function summary(bool $paused = false): array
    {
        return ['deleted' => 0, 'kept' => 0, 'skipped' => 0, 'failed' => 0, 'paused' => $paused];
    }

    // =========================== last run (status) ============================

    public static function status(): array
    {
        try {
            $json = Storage::disk(self::STATUS_DISK)->get(self::STATUS_PATH);
        } catch (Throwable) {
            $json = null;
        }
        $status = $json ? json_decode($json, true) : null;

        return is_array($status) ? $status : [];
    }

    /** When a real run last finished without a failure, or null if never. */
    public static function lastSuccess(): ?Carbon
    {
        $at = self::status()['last_success_at'] ?? null;

        return $at ? Carbon::parse($at)->timezone(config('app.timezone')) : null;
    }

    /**
     * Whether the countdown can be trusted: not paused, and the purge has
     * succeeded within config('trash.stale_after_hours'). Otherwise nothing
     * is removing anything, whatever the window says.
     */
    public static function isRunning(): bool
    {
        if (self::pause()) {
            return false;
        }

        $last = self::lastSuccess();

        return $last !== null && $last->gt(now()->subHours((int) config('trash.stale_after_hours', 48)));
    }

    protected static function recordRun(array $summary): void
    {
        $status = self::status();
        $now = now()->toIso8601String();
        $status['last_attempt_at'] = $now;
        $status['last_summary'] = $summary;
        if ($summary['failed'] === 0) {
            $status['last_success_at'] = $now;
        }

        self::writeStatus($status);
    }

    protected static function writeStatus(array $status): void
    {
        Storage::disk(self::STATUS_DISK)->put(self::STATUS_PATH, json_encode($status, JSON_PRETTY_PRINT));
    }

    // ======================= the pause after a restore ========================

    /**
     * Called by every backup restore (BackupController::performRestore, the
     * one place both restore entry points go through), once it has succeeded.
     * Trashed rows come back with their original deleted_at, so a run right
     * after could permanently delete what was just restored; retention stops
     * until someone runs trash:purge --resume. $source names the restore: the
     * stored backup's filename or the uploaded file's name.
     */
    public static function pauseAfterRestore(string $source): void
    {
        $status = self::status();
        $status['paused'] = ['at' => now()->toIso8601String(), 'source' => $source];
        self::writeStatus($status);
    }

    /** ['at' => ISO time, 'source' => restore label] while paused, else null. */
    public static function pause(): ?array
    {
        $pause = self::status()['paused'] ?? null;

        return is_array($pause) && isset($pause['at'], $pause['source']) ? $pause : null;
    }

    public static function pausedAt(array $pause): Carbon
    {
        return Carbon::parse($pause['at'])->timezone(config('app.timezone'));
    }

    /** "Retention is paused after the restore of X on Oct 11, 2026." */
    public static function pauseNotice(array $pause): string
    {
        return 'Retention is paused after the restore of ' . $pause['source'] . ' on ' . self::pausedAt($pause)->format('M j, Y') . '.';
    }
}
