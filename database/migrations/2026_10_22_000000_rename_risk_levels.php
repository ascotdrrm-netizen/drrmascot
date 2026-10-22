<?php

use App\Support\RiskMatrix;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The risk levels are renamed (App\Support\RiskMatrix), same score bands:
 *   Low → Minimal Risk · Moderate → Moderate Risk · High → High Risk ·
 *   Very High → Critical Risk (and any pre-matrix Medium → Moderate Risk).
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['hazard_inspections', 'buildings'] as $table) {
            foreach (RiskMatrix::OLD_NAMES as $old => $new) {
                DB::table($table)->where('risk_level', $old)->update(['risk_level' => $new]);
            }
        }
    }

    public function down(): void
    {
        $back = ['Minimal Risk' => 'Low', 'Moderate Risk' => 'Moderate', 'High Risk' => 'High', 'Critical Risk' => 'Very High'];
        foreach (['hazard_inspections', 'buildings'] as $table) {
            foreach ($back as $new => $old) {
                DB::table($table)->where('risk_level', $new)->update(['risk_level' => $old]);
            }
        }
    }
};
