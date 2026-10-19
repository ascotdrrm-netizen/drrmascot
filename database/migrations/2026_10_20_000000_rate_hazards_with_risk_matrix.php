<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The 5×5 risk matrix (App\Support\RiskMatrix) replaces the hand-picked
 * Low / Medium / High:
 *   hazard_inspections  + likelihood, impact (1–5) and risk_score (1–25);
 *                       risk_level becomes Low / Moderate / High / Very High.
 *                       Older inspections keep their level (Medium → Moderate)
 *                       and have no score.
 *   buildings           risk_level is now the level of the building's latest
 *                       inspection (kept up to date by HazardInspection);
 *                       empty = not yet assessed. A building never inspected
 *                       keeps the level it was given by hand.
 */
return new class extends Migration
{
    public function up(): void
    {
        // PostgreSQL's enum() is a varchar with a check constraint.
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE hazard_inspections DROP CONSTRAINT IF EXISTS hazard_inspections_risk_level_check');
            DB::statement('ALTER TABLE buildings DROP CONSTRAINT IF EXISTS buildings_risk_level_check');
        }

        Schema::table('hazard_inspections', function (Blueprint $table) {
            $table->string('risk_level', 20)->change();
            $table->unsignedTinyInteger('likelihood')->nullable();
            $table->unsignedTinyInteger('impact')->nullable();
            $table->unsignedTinyInteger('risk_score')->nullable();
        });
        Schema::table('buildings', function (Blueprint $table) {
            $table->string('risk_level', 20)->nullable()->default(null)->change();
        });

        DB::table('hazard_inspections')->where('risk_level', 'Medium')->update(['risk_level' => 'Moderate']);
        DB::table('buildings')->where('risk_level', 'Medium')->update(['risk_level' => 'Moderate']);

        foreach (DB::table('hazard_inspections')->distinct()->pluck('building_id') as $buildingId) {
            $latest = DB::table('hazard_inspections')->where('building_id', $buildingId)
                ->orderByDesc('inspection_date')->orderByDesc('inspection_id')->value('risk_level');
            DB::table('buildings')->where('building_id', $buildingId)->update(['risk_level' => $latest]);
        }
    }

    public function down(): void
    {
        foreach (['hazard_inspections', 'buildings'] as $table) {
            DB::table($table)->where('risk_level', 'Moderate')->update(['risk_level' => 'Medium']);
            DB::table($table)->where('risk_level', 'Very High')->update(['risk_level' => 'High']);
        }
        DB::table('buildings')->whereNull('risk_level')->update(['risk_level' => 'Low']);

        Schema::table('hazard_inspections', function (Blueprint $table) {
            $table->dropColumn(['likelihood', 'impact', 'risk_score']);
        });
    }
};
