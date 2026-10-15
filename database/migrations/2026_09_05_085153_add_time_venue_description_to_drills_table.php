<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('drills', function (Blueprint $table) {
            $table->time('time_conducted')->after('date_conducted');
            $table->string('venue', 150)->after('time_conducted');
            $table->text('description')->nullable()->after('venue');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('drills', function (Blueprint $table) {
            $table->dropColumn(['time_conducted', 'venue', 'description']);
        });
    }
};
