<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('drills', function (Blueprint $table) {
            $table->id('drill_id');
            $table->foreignId('campus_id')->constrained('campuses', 'campus_id')->onDelete('cascade');
            $table->string('drill_type', 50);
            $table->date('date_conducted');
            $table->string('partner_agency', 100)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('drills');
    }
};
