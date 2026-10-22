<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Who an SMS alert went to: name, role and number as they were when it was
 * sent, so the list still reads right after an account changes or is deleted
 * (personnel_id then becomes null, the rest stays). One row per recipient,
 * with whether the gateway took their text.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('alert_recipients', function (Blueprint $table) {
            $table->id('alert_recipient_id');
            $table->foreignId('alert_id')->constrained('alerts', 'alert_id')->cascadeOnDelete();
            $table->foreignId('personnel_id')->nullable()->constrained('personnel', 'personnel_id')->nullOnDelete();
            $table->string('full_name', 150);
            $table->string('role', 30);
            $table->string('contact_number', 20);
            $table->string('status', 10); // Sent, Failed, Skipped (not a mobile number)
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('alert_recipients');
    }
};
