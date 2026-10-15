<?php

namespace App\Support;

use App\Support\Backup\DataSet;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Restore → "Records only": replaces exactly the tables Clear All Records
 * clears (RecordsClear::TABLES, Trash included) with a backup's rows, and
 * leaves accounts, campuses, schools, the activity log and backups alone.
 *
 * The backup's rows (App\Support\Backup\BackupArchive::read(), new format or
 * an old MySQL one) are checked and fixed BEFORE anything changes:
 *   - an account (created_by / submitted_by / uploaded_by) that no longer
 *     exists is set to NULL — the *_by_name text keeps who it was;
 *   - a campus or school that no longer exists (or a school now on another
 *     campus) refuses the whole restore, naming what's missing.
 * Then one transaction, foreign keys ON (DataSet::replace): delete children
 * first, insert parents first. Anything still wrong makes the database
 * refuse, and nothing changes.
 */
class RecordsRestore
{
    /** Added after the first portable backup format; older backups have no rows for them. */
    public const OPTIONAL_NEW_TABLES = ['broadcasts', 'broadcast_deliveries', 'staff_notifications', 'report_events', 'report_photos', 'citizen_reports', 'sos_events', 'campus_statuses',
        'assignments', 'responders', 'roll_calls', 'roll_call_responses'];

    /** Account columns that are nulled when the account is gone. */
    public const ACCOUNT_COLUMNS = [
        'broadcasts' => ['created_by', 'published_by'], 'broadcast_deliveries' => 'personnel_id',
        'roll_calls' => ['created_by', 'closed_by'], 'roll_call_responses' => 'reporter_id', 'assignments' => 'assigned_by',
        'report_events' => 'actor_id', 'campus_statuses' => 'updated_by',
        'alerts' => 'created_by', 'damage_reports' => 'created_by', 'incidents' => 'created_by',
        'hazard_inspections' => 'created_by', 'buildings' => 'created_by', 'drills' => 'created_by',
        'drill_attachments' => 'uploaded_by', 'drill_documentations' => 'submitted_by',
        'drill_after_activity_reports' => 'submitted_by',
    ];

    /** Tables whose campus_id (NOT NULL) must still exist. */
    public const CAMPUS_TABLES = ['incidents', 'buildings', 'drills', 'citizen_reports', 'sos_events', 'campus_statuses', 'staff_notifications',
        'roll_calls', 'responders', 'assignments', 'broadcasts'];

    /** Tables whose school_id (nullable) must still exist when set. */
    public const SCHOOL_TABLES = ['incidents', 'hazard_inspections'];

    /**
     * The record tables' rows from a whole backup's rows. Refuses a file that
     * doesn't hold the record tables at all (an unrelated file must not
     * "restore" nothing over every current record), and one from an older
     * version with columns that no longer exist.
     *
     * @param  array<string, list<array<string, mixed>>>  $backupRows  table => rows
     * @return array<string, list<array<string, mixed>>>
     */
    public static function fromRows(array $backupRows): array
    {
        $absent = array_diff(array_keys(RecordsClear::TABLES), array_keys($backupRows), self::OPTIONAL_NEW_TABLES);
        if ($absent) {
            throw new RuntimeException('This file is not an ASCOT-DRRMS backup of the records: it has no ' . implode(', ', $absent) . ' table' . (count($absent) === 1 ? '' : 's') . '.');
        }

        $rows = array_intersect_key($backupRows, RecordsClear::TABLES) + array_fill_keys(array_keys(RecordsClear::TABLES), []);
        $unknown = DataSet::unknownColumns($rows);
        if ($unknown) {
            throw new RuntimeException('This backup is from an older version of ASCOT-DRRMS: it has columns that no longer exist (' . implode(', ', $unknown) . '). Use "Everything in the backup" only if you mean to replace the whole database, or restore a newer backup.');
        }

        return $rows;
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
        $missingReporter = [];
        $missingNoticeRecipient = [];
        $reporters = DB::table('personnel')->pluck('personnel_id')
            ->map(fn ($id) => (string) $id)->flip();

        foreach ($rows['staff_notifications'] as $row) {
            if (! isset($reporters[(string) $row['personnel_id']])) {
                $missingNoticeRecipient[(string) $row['personnel_id']]['staff_notifications'] =
                    ($missingNoticeRecipient[(string) $row['personnel_id']]['staff_notifications'] ?? 0) + 1;
            }
        }

        foreach (['citizen_reports', 'sos_events'] as $table) {
            foreach ($rows[$table] as $row) {
                if (! isset($reporters[(string) $row['reporter_id']])) {
                    $missingReporter[(string) $row['reporter_id']][$table] = ($missingReporter[(string) $row['reporter_id']][$table] ?? 0) + 1;
                }
            }
        }

        foreach (self::CAMPUS_TABLES as $table) {
            foreach ($rows[$table] as $row) {
                if (! isset($campuses[(string) $row['campus_id']])) {
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
        foreach ($missingReporter as $id => $byTable) {
            $problems[] = "This backup has records for a Reporter account that no longer exists (id $id): " . $describe($byTable) . '.';
        }
        foreach ($missingNoticeRecipient as $id => $byTable) {
            $problems[] = "This backup has notifications for an account that no longer exists (id $id): " . $describe($byTable) . '.';
        }

        return $problems;
    }

    /**
     * Replace the record tables with $rows, all or nothing. Returns the counts
     * restored per table and how many account references were set to NULL.
     *
     * @return array{tables: array<string, int>, accounts_cleared: int}
     */
    public static function apply(array $rows): array
    {
        $accounts = DB::table('personnel')->pluck('personnel_id')->map(fn ($id) => (string) $id)->flip();
        $cleared = 0;
        foreach (self::ACCOUNT_COLUMNS as $table => $columns) {
            foreach ($rows[$table] as $i => $row) {
                foreach ((array) $columns as $column) {
                    if (($row[$column] ?? null) !== null && ! isset($accounts[(string) $row[$column]])) {
                        $rows[$table][$i][$column] = null;
                        $cleared++;
                    }
                }
            }
        }

        DataSet::replace($rows, array_keys(RecordsClear::TABLES));

        return ['tables' => array_map('count', $rows), 'accounts_cleared' => $cleared];
    }
}
