<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Deleting a Reporter's account must not erase the DRRMO's incident history:
// their reports and SOS records stay, shown as "Former reporter".
return new class extends Migration
{
    public function up(): void
    {
        foreach (['citizen_reports', 'sos_events'] as $table) {
            Schema::table($table, function (Blueprint $t) {
                $t->dropForeign(['reporter_id']);
            });
            Schema::table($table, function (Blueprint $t) {
                $t->foreignId('reporter_id')->nullable()->change();
                $t->foreign('reporter_id')->references('personnel_id')->on('personnel')->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        foreach (['citizen_reports', 'sos_events'] as $table) {
            Schema::table($table, function (Blueprint $t) {
                $t->dropForeign(['reporter_id']);
                $t->foreign('reporter_id')->references('personnel_id')->on('personnel')->cascadeOnDelete();
            });
        }
    }
};
