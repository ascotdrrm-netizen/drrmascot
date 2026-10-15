<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Incidents record the same measurements an advisory does, for their
 * Disaster-related type — as OBSERVED, not forecast — keyed as in
 * config/hazard_measurements.php (one shared definition). Same shape as
 * disaster_advisories.measurements: nullable JSON, cast to an array.
 *
 * Additive only: every existing incident gets NULL ("none entered"), and a
 * records-only restore of a backup taken before this column existed still
 * works (RecordsRestore only refuses columns that no longer exist).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('incidents', function (Blueprint $table) {
            $table->json('measurements')->nullable()->after('hazard_type');
        });
    }

    public function down(): void
    {
        Schema::table('incidents', function (Blueprint $table) {
            $table->dropColumn('measurements');
        });
    }
};
