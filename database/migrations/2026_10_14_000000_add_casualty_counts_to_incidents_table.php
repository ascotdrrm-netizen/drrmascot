<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Thesis panel (Arts & Sciences): how many people an incident affected,
 * injured, left missing or killed. Every count is optional: blank means not
 * known, which is different from 0.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('incidents', function (Blueprint $table) {
            $table->unsignedSmallInteger('people_affected')->nullable()->after('description');
            $table->unsignedSmallInteger('people_injured')->nullable()->after('people_affected');
            $table->unsignedSmallInteger('people_missing')->nullable()->after('people_injured');
            $table->unsignedSmallInteger('people_dead')->nullable()->after('people_missing');
        });
    }

    public function down(): void
    {
        Schema::table('incidents', function (Blueprint $table) {
            $table->dropColumn(['people_affected', 'people_injured', 'people_missing', 'people_dead']);
        });
    }
};
