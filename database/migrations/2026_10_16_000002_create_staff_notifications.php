<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('staff_notifications', function (Blueprint $table) {
            $table->id('notification_id');
            $table->foreignId('personnel_id')->constrained('personnel', 'personnel_id')->cascadeOnDelete();
            $table->foreignId('campus_id')->constrained('campuses', 'campus_id')->cascadeOnDelete();
            $table->string('kind', 20);
            $table->unsignedBigInteger('subject_id');
            $table->string('message', 255);
            $table->timestamp('read_at')->nullable();
            $table->timestamps();
            $table->index(['personnel_id', 'read_at', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('staff_notifications');
    }
};
