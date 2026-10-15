<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Thesis panel (Engineering): every photo can say what it shows.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['incidents', 'buildings', 'hazard_inspections'] as $table) {
            Schema::table($table, function (Blueprint $table) {
                $table->string('photo_caption', 200)->nullable()->after('photo_path');
            });
        }
    }

    public function down(): void
    {
        foreach (['incidents', 'buildings', 'hazard_inspections'] as $table) {
            Schema::table($table, function (Blueprint $table) {
                $table->dropColumn('photo_caption');
            });
        }
    }
};
