<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Indexes for the columns every dashboard, list and report filters on.
 * PostgreSQL (Supabase) does not index foreign keys by itself, so these
 * lookups were full-table scans. Each is skipped when it already exists.
 */
return new class extends Migration
{
    /** table => [index name => columns] */
    private const INDEXES = [
        'incidents' => [
            'incidents_campus_date_index' => ['campus_id', 'date_occurred'],
            'incidents_status_index' => ['status'],
            'incidents_created_by_index' => ['created_by'],
        ],
        'hazard_inspections' => [
            'hazard_inspections_building_date_index' => ['building_id', 'inspection_date'],
        ],
        'buildings' => [
            'buildings_campus_risk_index' => ['campus_id', 'risk_level'],
        ],
        'damage_reports' => [
            'damage_reports_incident_index' => ['incident_id'],
            'damage_reports_building_index' => ['building_id'],
        ],
        'alerts' => [
            'alerts_incident_index' => ['incident_id'],
        ],
        'activity_logs' => [
            'activity_logs_personnel_created_index' => ['personnel_id', 'created_at'],
            'activity_logs_created_index' => ['created_at'],
        ],
        'personnel' => [
            'personnel_role_campus_index' => ['role', 'campus_id'],
        ],
        'drills' => [
            'drills_campus_date_index' => ['campus_id', 'scheduled_date'],
        ],
    ];

    public function up(): void
    {
        foreach (self::INDEXES as $table => $indexes) {
            foreach ($indexes as $name => $columns) {
                if (! Schema::hasTable($table) || ! Schema::hasColumns($table, $columns) || Schema::hasIndex($table, $name)) {
                    continue;
                }
                Schema::table($table, fn (Blueprint $t) => $t->index($columns, $name));
            }
        }
    }

    public function down(): void
    {
        foreach (self::INDEXES as $table => $indexes) {
            foreach (array_keys($indexes) as $name) {
                if (Schema::hasTable($table) && Schema::hasIndex($table, $name)) {
                    Schema::table($table, fn (Blueprint $t) => $t->dropIndex($name));
                }
            }
        }
    }
};
