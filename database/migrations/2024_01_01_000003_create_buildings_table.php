<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('buildings', function (Blueprint $table) {
            $table->id('building_id');
            $table->foreignId('campus_id')->constrained('campuses', 'campus_id')->onDelete('cascade');
            $table->string('building_name', 100);
            $table->string('hazard_type', 50)->nullable();
            $table->enum('risk_level', ['Low', 'Medium', 'High'])->default('Low');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('buildings');
    }
};
