<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Per-person visibility: who created each record.
 *
 * created_by -> personnel (nulled if the account is hard-deleted) and
 * created_by_name (kept, so attribution stays readable after that) — the same
 * pattern as the drill workflow's *_by / *_by_name columns. Drills need no new
 * column: their owner is acknowledged_by / submitted_by.
 *
 * Existing rows: filled ONLY from the app's own audit trail — the earliest
 * 'created' activity-log entry for that record, when it names a person who
 * still exists. That entry was written with the signed-in user at the moment
 * of creation, so it's a record, not a guess. Every other row stays null and
 * is therefore visible to the DRRMO Admin only (see App\Support\RecordAccess).
 * Alerts never had 'created' entries, so they all stay null.
 *
 * Down drops the columns (the backfill is derived data and is simply lost).
 */
return new class extends Migration
{
    /** table => [primary key, activity_logs.subject_type or null] */
    private const TABLES = [
        'buildings' => ['building_id', 'Building'],
        'hazard_inspections' => ['inspection_id', 'HazardInspection'],
        'incidents' => ['incident_id', 'Incident'],
        'damage_reports' => ['report_id', 'DamageReport'],
        'alerts' => ['alert_id', null],
    ];

    public function up(): void
    {
        foreach (self::TABLES as $table => [$key, $subjectType]) {
            Schema::table($table, function (Blueprint $t) {
                $t->foreignId('created_by')->nullable()->constrained('personnel', 'personnel_id')->nullOnDelete();
                $t->string('created_by_name', 150)->nullable();
            });

            if ($subjectType === null) {
                continue;
            }

            // Earliest 'created' entry per record, joined to a still-existing person.
            $creators = DB::table('activity_logs as l')
                ->join('personnel as p', 'p.personnel_id', '=', 'l.personnel_id')
                ->where('l.subject_type', $subjectType)
                ->where('l.action', 'created')
                ->whereNotNull('l.subject_id')
                ->orderBy('l.activity_log_id')
                ->get(['l.subject_id', 'p.personnel_id', 'p.full_name'])
                ->unique('subject_id');

            foreach ($creators as $row) {
                DB::table($table)->where($key, $row->subject_id)->whereNull('created_by')->update([
                    'created_by' => $row->personnel_id,
                    'created_by_name' => $row->full_name,
                ]);
            }
        }
    }

    public function down(): void
    {
        foreach (array_keys(self::TABLES) as $table) {
            Schema::table($table, function (Blueprint $t) {
                $t->dropConstrainedForeignId('created_by');
                $t->dropColumn('created_by_name');
            });
        }
    }
};
