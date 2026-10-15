<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Self-registered accounts wait for DRRMO approval before they can sign in.
 *
 * The column defaults to approved, so every existing account (and any the
 * Admin or a seeder creates) keeps working — nobody is locked out by this
 * migration. Only public self-registration sets it to false.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('personnel', function (Blueprint $table) {
            $table->boolean('is_approved')->default(true)->after('role');
        });
    }

    public function down(): void
    {
        Schema::table('personnel', function (Blueprint $table) {
            $table->dropColumn('is_approved');
        });
    }
};
