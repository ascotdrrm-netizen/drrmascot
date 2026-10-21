<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// A damage report's building/area is typed, not picked: area_name holds what
// was typed. building_id is set only when it matches a building on the
// incident's campus (so that building's history still lists it), else null.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('damage_reports', function (Blueprint $table) {
            $table->string('area_name', 150)->nullable()->after('building_id');
        });
        Schema::table('damage_reports', function (Blueprint $table) {
            $table->dropForeign(['building_id']);
        });
        Schema::table('damage_reports', function (Blueprint $table) {
            $table->foreignId('building_id')->nullable()->change();
            $table->foreign('building_id')->references('building_id')->on('buildings')->cascadeOnDelete();
        });

        // Existing reports keep their building's name as the typed area.
        foreach (DB::table('buildings')->get(['building_id', 'building_name']) as $building) {
            DB::table('damage_reports')->where('building_id', $building->building_id)->whereNull('area_name')
                ->update(['area_name' => $building->building_name]);
        }
    }

    public function down(): void
    {
        DB::table('damage_reports')->whereNull('building_id')->delete();
        Schema::table('damage_reports', function (Blueprint $table) {
            $table->dropForeign(['building_id']);
        });
        Schema::table('damage_reports', function (Blueprint $table) {
            $table->foreignId('building_id')->nullable(false)->change();
            $table->foreign('building_id')->references('building_id')->on('buildings')->cascadeOnDelete();
            $table->dropColumn('area_name');
        });
    }
};
