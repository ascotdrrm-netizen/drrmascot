<?php

namespace App\Support;

use App\Support\Backup\DataSet;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * Restore → "Records only": replaces exactly the tables Clear All Records
 * clears (RecordsClear::TABLES, Trash included) with a backup's rows, and
 * leaves accounts, campuses, schools, the activity log and backups alone.
 *
 * Restore → "Everything": the same, plus every other table the backup holds
 * (the activity log, settings, hotlines) — except accounts, which a restore
 * never touches (a backup doesn't hold them: DataSet::EXCLUDED_TABLES), and
 * the campuses and schools those accounts belong to (KEPT_TABLES).
 *
 * The backup's rows (App\Support\Backup\BackupArchive::read(), new format or
 * an old MySQL one) are checked and fixed BEFORE anything changes:
 *   - an account (created_by / submitted_by / uploaded_by) that no longer
 *     exists is set to NULL — the *_by_name text keeps who it was; so is a
 *     Reporter's (reporter_id), shown as "Former reporter", the same as when
 *     the account is deleted;
 *   - a staff notification for an account that no longer exists is left
 *     out, as deleting the account deletes its notifications;
 *   - a campus or school that no longer exists (or a school now on another
 *     campus) refuses the whole restore, naming what's missing.
 * Then one transaction, foreign keys ON (DataSet::replace): delete children
 * first, insert parents first. Anything still wrong makes the database
 * refuse, and nothing changes.
 *
 * Restore → "Merge": the record tables again, but nothing current is deleted
 * or changed: only the backup's records that are no longer here are added
 * (missing()), with the same checks and fixes (DataSet::insert).
 */
class RecordsRestore
{
    /** Added after the first portable backup format; older backups have no rows for them. */
    public const OPTIONAL_NEW_TABLES = ['disaster_advisories', 'broadcasts', 'broadcast_deliveries', 'staff_notifications', 'report_events', 'report_photos', 'citizen_reports', 'sos_events', 'campus_statuses',
        'assignments', 'responders', 'roll_calls', 'roll_call_responses', 'alert_recipients'];

    /**
     * Account columns that are nulled when the account is gone: every foreign
     * key from a record table to personnel, read from the database so a new
     * one can't be missed. Not staff_notifications.personnel_id: those rows
     * go with their account (apply()).
     *
     * @return array<string, list<string>> table => columns
     */
    public static function accountColumns(?array $tables = null): array
    {
        $columns = [];
        foreach ($tables ?? array_keys(RecordsClear::TABLES) as $table) {
            foreach (Schema::getForeignKeys($table) as $key) {
                if ($key['foreign_table'] === 'personnel' && $table !== 'staff_notifications') {
                    $columns[$table] = array_merge($columns[$table] ?? [], $key['columns']);
                }
            }
        }

        return $columns;
    }

    /**
     * Never replaced, even by "Everything": accounts belong to a campus and a
     * school, and deleting a campus deletes its accounts (personnel.campus_id
     * cascades).
     */
    public const KEPT_TABLES = ['campuses', 'schools'];

    /** Tables whose campus_id must still exist when set (an advisory's is empty for every campus). */
    public const CAMPUS_TABLES = ['disaster_advisories', 'incidents', 'buildings', 'drills', 'citizen_reports', 'sos_events', 'campus_statuses', 'staff_notifications',
        'roll_calls', 'responders', 'assignments', 'broadcasts'];

    /** Tables whose school_id (nullable) must still exist when set. */
    public const SCHOOL_TABLES = ['incidents', 'hazard_inspections'];

    /**
     * The record tables' rows from a whole backup's rows. Refuses a file that
     * doesn't hold the record tables at all (an unrelated file must not
     * "restore" nothing over every current record), and one from an older
     * version with columns that no longer exist.
     *
     * $everything adds every other table the backup holds, except
     * KEPT_TABLES (and accounts, which no backup holds).
     *
     * @param  array<string, list<array<string, mixed>>>  $backupRows  table => rows
     * @return array<string, list<array<string, mixed>>>
     */
    public static function fromRows(array $backupRows, bool $everything = false): array
    {
        $absent = array_diff(array_keys(RecordsClear::TABLES), array_keys($backupRows), self::OPTIONAL_NEW_TABLES);
        if ($absent) {
            throw new RuntimeException('This file is not an ASCOT-DRRMS backup of the records: it has no ' . implode(', ', $absent) . ' table' . (count($absent) === 1 ? '' : 's') . '.');
        }

        $rows = array_intersect_key($backupRows, RecordsClear::TABLES) + array_fill_keys(array_keys(RecordsClear::TABLES), []);
        if ($everything) {
            // Only what the backup holds: an older backup without, say, hotlines leaves them as they are.
            $rows += array_intersect_key($backupRows, array_flip(array_diff(DataSet::tables(), self::KEPT_TABLES)));
        }
        $unknown = DataSet::unknownColumns($rows);
        if ($unknown) {
            throw new RuntimeException('This backup is from an older version of ASCOT-DRRMS: it has columns that no longer exist (' . implode(', ', $unknown) . '). Restore a newer backup.');
        }

        return $rows;
    }

    /**
     * Restore → "Merge": the rows of $rows (from fromRows()) that are no longer
     * here. A row whose key is taken stays as it is now: the same record,
     * edited or moved to Trash since. A key taken by a DIFFERENT record
     * (created at another time; for a table without created_at, under another
     * parent) means the backup and the current records number their records
     * differently, as after Clear All Records, and no merge is safe: that
     * refuses the whole merge, naming how many. A table keyed by its parent
     * (campus_statuses: one row per campus) keeps the current row.
     *
     * @param  array<string, list<array<string, mixed>>>  $rows
     * @return array{rows: array<string, list<array<string, mixed>>>, kept: int}
     */
    public static function missing(array $rows): array
    {
        $missing = [];
        $kept = 0;
        $clashes = [];
        foreach ($rows as $table => $tableRows) {
            $missing[$table] = [];
            if ($tableRows === []) {
                continue;
            }
            $key = DataSet::primaryKey($table);
            if ($key === null) {
                throw new RuntimeException("The $table table has no key to tell its records apart, so it can't be merged.");
            }
            $parents = collect(DataSet::foreignKeys($table))->filter(fn ($fk) => isset($rows[$fk['table']]))->pluck('column')->unique()->all();
            $identity = match (true) {
                in_array($key, collect(DataSet::foreignKeys($table))->pluck('column')->all(), true) => [],
                Schema::hasColumn($table, 'created_at') => ['created_at'],
                default => array_values($parents),
            };
            $current = DB::table($table)->get([$key, ...$identity])->keyBy(fn ($r) => (string) $r->{$key});

            foreach ($tableRows as $row) {
                $here = $current[(string) $row[$key]] ?? null;
                if ($here === null) {
                    $missing[$table][] = $row;

                    continue;
                }
                foreach ($identity as $column) {
                    if (! self::sameValue($column, $here->{$column}, $row[$column] ?? null)) {
                        $clashes[$table] = ($clashes[$table] ?? 0) + 1;

                        continue 2;
                    }
                }
                $kept++;
            }
        }

        if ($clashes) {
            $named = implode(', ', array_map(fn ($table, $n) => $n . ' ' . (RecordsClear::TABLES[$table][$n === 1 ? 0 : 1] ?? $table), array_keys($clashes), $clashes));
            throw new RuntimeException("The backup and the current records use the same numbers for different records ($named). "
                . 'This happens when records were cleared and new ones added since the backup. Choose Records only to bring the backup back as it was.');
        }

        return ['rows' => $missing, 'kept' => $kept];
    }

    /**
     * Where a merge writes a backup's files/<disk>/<path> entry back: only a
     * file of a record it adds (in $rows, from missing()) that isn't already
     * on the records disk. Others are left out quietly (false); paths outside
     * the record folders are refused as in every restore (null).
     */
    public static function mergeFileTarget(array $rows): callable
    {
        $wanted = [];
        foreach (RecordsClear::FILES as $table => [$column]) {
            foreach ($rows[$table] ?? [] as $row) {
                if (($row[$column] ?? null) !== null) {
                    $wanted[$row[$column]] = true;
                }
            }
        }

        return function (string $disk, string $path) use ($wanted): string|false|null {
            $target = RecordsClear::restoreTarget($disk, $path);
            if ($target === null) {
                return null;
            }

            return isset($wanted[$path]) && ! Storage::disk($target)->exists($path) ? $target : false;
        };
    }

    /** Whether a current value and a backup's are the same (timestamps by the moment, whatever their format). */
    protected static function sameValue(string $column, mixed $current, mixed $backup): bool
    {
        if ($current === null || $backup === null) {
            return $current === $backup;
        }

        return $column === 'created_at'
            ? strtotime((string) $current) === strtotime((string) $backup)
            : (string) $current === (string) $backup;
    }

    /**
     * Problems that refuse the whole restore. Empty means it can go ahead.
     *
     * @return list<string>
     */
    public static function problems(array $rows): array
    {
        $campuses = DB::table('campuses')->pluck('campus_id')->map(fn ($id) => (string) $id)->flip();
        $schools = DB::table('schools')->pluck('campus_id', 'school_id')->mapWithKeys(fn ($campus, $id) => [(string) $id => (string) $campus]);
        $missingCampus = [];
        $missingSchool = [];
        $movedSchool = [];

        foreach (self::CAMPUS_TABLES as $table) {
            foreach ($rows[$table] as $row) {
                if ($row['campus_id'] !== null && ! isset($campuses[(string) $row['campus_id']])) {
                    $missingCampus[(string) $row['campus_id']][$table] = ($missingCampus[(string) $row['campus_id']][$table] ?? 0) + 1;
                }
            }
        }
        foreach (self::SCHOOL_TABLES as $table) {
            foreach ($rows[$table] as $row) {
                $school = $row['school_id'] ?? null;
                if ($school === null) {
                    continue;
                }
                if (! isset($schools[(string) $school])) {
                    $missingSchool[(string) $school][$table] = ($missingSchool[(string) $school][$table] ?? 0) + 1;
                } elseif ($table === 'incidents' && $schools[(string) $school] !== (string) $row['campus_id'] && isset($campuses[(string) $row['campus_id']])) {
                    $movedSchool[(string) $school] = ($movedSchool[(string) $school] ?? 0) + 1;
                }
            }
        }

        $describe = function (array $byTable): string {
            return implode(', ', array_map(
                fn ($table, $n) => $n . ' ' . RecordsClear::TABLES[$table][$n === 1 ? 0 : 1],
                array_keys($byTable), $byTable
            ));
        };
        $problems = [];
        foreach ($missingCampus as $id => $byTable) {
            $problems[] = "This backup has records for a campus that no longer exists (id $id): " . $describe($byTable) . '.';
        }
        foreach ($missingSchool as $id => $byTable) {
            $problems[] = "This backup has records for a school that no longer exists (id $id): " . $describe($byTable) . '.';
        }
        foreach ($movedSchool as $id => $n) {
            $problems[] = "This backup has $n " . ($n === 1 ? 'incident' : 'incidents') . " for school id $id on a campus that school no longer belongs to.";
        }
        return $problems;
    }

    /**
     * Replace the tables in $rows (from fromRows()) with them, all or nothing;
     * with $merge, add them instead (the rows from missing()). Returns the counts
     * restored per table, how many account references were set to NULL, and
     * how many notifications were left out with their account.
     *
     * @return array{tables: array<string, int>, accounts_cleared: int, notifications_skipped: int}
     */
    public static function apply(array $rows, bool $merge = false): array
    {
        $accounts = DB::table('personnel')->pluck('personnel_id')->map(fn ($id) => (string) $id)->flip();
        $notifications = count($rows['staff_notifications']);
        $rows['staff_notifications'] = array_values(array_filter($rows['staff_notifications'],
            fn ($row) => isset($accounts[(string) $row['personnel_id']])));
        $cleared = 0;
        foreach (self::accountColumns(array_keys($rows)) as $table => $columns) {
            foreach ($rows[$table] as $i => $row) {
                foreach ($columns as $column) {
                    if (($row[$column] ?? null) !== null && ! isset($accounts[(string) $row[$column]])) {
                        $rows[$table][$i][$column] = null;
                        $cleared++;
                    }
                }
            }
        }

        $merge ? DataSet::insert($rows, array_keys($rows)) : DataSet::replace($rows, array_keys($rows));

        return ['tables' => array_map('count', $rows), 'accounts_cleared' => $cleared,
            'notifications_skipped' => $notifications - count($rows['staff_notifications'])];
    }
}
