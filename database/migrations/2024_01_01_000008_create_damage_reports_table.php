<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('damage_reports', function (Blueprint $table) {
            $table->id('report_id');
            $table->foreignId('incident_id')->constrained('incidents', 'incident_id')->onDelete('cascade');
            $table->foreignId('building_id')->constrained('buildings', 'building_id')->onDelete('cascade');
            $table->enum('damage_level', ['Minor', 'Major', 'Total']);
            $table->integer('casualty_count')->default(0);
            $table->decimal('est_cost', 12, 2)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('damage_reports');
    }
};
