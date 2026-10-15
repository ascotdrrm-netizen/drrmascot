<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('incidents', function (Blueprint $table) {
            $table->id('incident_id');
            $table->foreignId('campus_id')->constrained('campuses', 'campus_id')->onDelete('cascade');
            $table->string('hazard_type', 50);
            $table->date('date_occurred');
            $table->enum('status', ['Active', 'Under Response', 'Resolved'])->default('Active');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('incidents');
    }
};
