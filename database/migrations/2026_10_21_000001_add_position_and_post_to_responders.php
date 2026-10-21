<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Dispatch shows who each responder is (position) and where they are posted
// on campus, so the DRRMO knows whom to send.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('responders', function (Blueprint $table) {
            $table->string('position', 100)->nullable()->after('full_name');
            $table->string('assigned_post', 150)->nullable()->after('team');
        });
    }

    public function down(): void
    {
        Schema::table('responders', function (Blueprint $table) {
            $table->dropColumn(['position', 'assigned_post']);
        });
    }
};
