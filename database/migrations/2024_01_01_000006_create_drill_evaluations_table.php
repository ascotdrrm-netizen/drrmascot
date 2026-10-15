<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('drill_evaluations', function (Blueprint $table) {
            $table->id('evaluation_id');
            $table->foreignId('drill_id')->constrained('drills', 'drill_id')->onDelete('cascade');
            $table->foreignId('personnel_id')->constrained('personnel', 'personnel_id')->onDelete('cascade');
            $table->decimal('pre_score', 5, 2)->nullable();
            $table->decimal('post_score', 5, 2)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('drill_evaluations');
    }
};
