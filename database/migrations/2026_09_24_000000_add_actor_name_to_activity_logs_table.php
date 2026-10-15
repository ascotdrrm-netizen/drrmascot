<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * personnel_id is nullOnDelete, so deleting an account used to leave its
     * audit rows anonymous. actor_name keeps the name as it was when the row
     * was written (filled by ActivityLog's creating hook from now on).
     */
    public function up(): void
    {
        Schema::table('activity_logs', function (Blueprint $table) {
            $table->string('actor_name', 150)->nullable()->after('personnel_id');
        });

        // Backfill only where the account still exists. Rows whose personnel
        // was already deleted have no recoverable name and stay NULL.
        DB::statement('
            UPDATE activity_logs
            SET actor_name = (SELECT p.full_name FROM personnel p WHERE p.personnel_id = activity_logs.personnel_id)
            WHERE personnel_id IS NOT NULL AND actor_name IS NULL
        ');
    }

    public function down(): void
    {
        Schema::table('activity_logs', function (Blueprint $table) {
            $table->dropColumn('actor_name');
        });
    }
};
