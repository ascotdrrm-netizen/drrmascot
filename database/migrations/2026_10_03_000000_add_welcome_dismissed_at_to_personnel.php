<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Remembers, per account, that the dashboard's first-run welcome panel was
 * dismissed. Null = show it (every existing account sees it once). Set by the
 * panel's "Got it" button and cleared by "Show help again" on the profile
 * page. Presentation state only: nothing else reads it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('personnel', function (Blueprint $table) {
            $table->timestamp('welcome_dismissed_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('personnel', function (Blueprint $table) {
            $table->dropColumn('welcome_dismissed_at');
        });
    }
};
