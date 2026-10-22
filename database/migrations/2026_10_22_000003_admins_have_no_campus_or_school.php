<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * A DRRMO Admin covers every campus, so their account has no campus and no
 * school (Personnel::booted clears both whenever an Admin is saved). Before,
 * a Coordinator made Admin kept their old campus and school, and every
 * incident or inspection they recorded was still "Recorded by school" that
 * school. campus_id becomes optional for that; everyone else keeps one.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('personnel', function (Blueprint $table) {
            $table->unsignedBigInteger('campus_id')->nullable()->change();
        });

        DB::table('personnel')->where('role', 'DRRMO Admin')->update(['campus_id' => null, 'school_id' => null]);
    }

    public function down(): void
    {
        // Admins get a campus back (the first one) so the column can be required again.
        $first = DB::table('campuses')->orderBy('campus_id')->value('campus_id');
        DB::table('personnel')->whereNull('campus_id')->update(['campus_id' => $first]);

        Schema::table('personnel', function (Blueprint $table) {
            $table->unsignedBigInteger('campus_id')->nullable(false)->change();
        });
    }
};
