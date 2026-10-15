<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('alerts', function (Blueprint $table) {
            $table->id('alert_id');
            $table->foreignId('incident_id')->constrained('incidents', 'incident_id')->onDelete('cascade');
            $table->text('message');
            $table->dateTime('sent_at');
            $table->integer('recipient_count')->default(0);
            $table->enum('delivery_status', ['Sent', 'Failed', 'Partial'])->default('Sent');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('alerts');
    }
};
