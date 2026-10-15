<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hazard_inspections', function (Blueprint $table) {
            $table->id('inspection_id');
            $table->foreignId('building_id')->constrained('buildings', 'building_id')->onDelete('cascade');
            $table->date('inspection_date');
            $table->text('findings')->nullable();
            $table->enum('risk_level', ['Low', 'Medium', 'High']);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hazard_inspections');
    }
};
