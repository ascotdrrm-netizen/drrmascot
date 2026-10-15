<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Moving to Supabase (PostgreSQL):
 *  - incidents' people counts become plain integers. PostgreSQL has no
 *    unsigned types, so unsignedSmallInteger became smallint (max 32767),
 *    below the 60000 the incident form allows.
 *  - e-mail addresses are stored lower-case (NormalizeEmails, Personnel), and
 *    on PostgreSQL a unique index on lower(email) keeps "A@x" and "a@x" from
 *    ever being two accounts; school names likewise, per campus.
 */
return new class extends Migration
{
    private const COUNTS = ['people_affected', 'people_injured', 'people_missing', 'people_dead'];

    public function up(): void
    {
        Schema::table('incidents', function (Blueprint $table) {
            foreach (self::COUNTS as $column) {
                $table->integer($column)->nullable()->change();
            }
        });

        DB::table('personnel')->orderBy('personnel_id')->each(function (object $row) {
            $lower = mb_strtolower(trim((string) $row->email));
            if ($lower !== $row->email) {
                DB::table('personnel')->where('personnel_id', $row->personnel_id)->update(['email' => $lower]);
            }
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('create unique index personnel_email_lower_unique on personnel (lower(email))');
            DB::statement('create unique index schools_campus_name_lower_unique on schools (campus_id, lower(school_name))');
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('drop index if exists personnel_email_lower_unique');
            DB::statement('drop index if exists schools_campus_name_lower_unique');
        }

        Schema::table('incidents', function (Blueprint $table) {
            foreach (self::COUNTS as $column) {
                $table->unsignedSmallInteger($column)->nullable()->change();
            }
        });
    }
};
