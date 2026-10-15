<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('roll_calls', function (Blueprint $table) {
            $table->id('roll_call_id');
            $table->foreignId('campus_id')->constrained('campuses', 'campus_id')->restrictOnDelete();
            $table->string('title', 150);
            $table->text('instructions')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('personnel', 'personnel_id')->nullOnDelete();
            $table->foreignId('closed_by')->nullable()->constrained('personnel', 'personnel_id')->nullOnDelete();
            $table->timestamp('started_at');
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();
        });
        DB::statement('CREATE UNIQUE INDEX roll_calls_one_open_per_campus ON roll_calls (campus_id) WHERE closed_at IS NULL');

        Schema::create('roll_call_responses', function (Blueprint $table) {
            $table->id('response_id');
            $table->foreignId('roll_call_id')->constrained('roll_calls', 'roll_call_id')->cascadeOnDelete();
            $table->foreignId('reporter_id')->nullable()->constrained('personnel', 'personnel_id')->nullOnDelete();
            $table->string('full_name', 150);
            $table->string('contact_number', 20)->nullable();
            $table->string('answer', 10)->nullable();
            $table->string('note', 1000)->nullable();
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->unsignedInteger('accuracy_m')->nullable();
            $table->timestamp('responded_at')->nullable();
            $table->timestamps();
            $table->unique(['roll_call_id', 'reporter_id']);
            $table->index(['roll_call_id', 'answer']);
        });

        Schema::create('responders', function (Blueprint $table) {
            $table->id('responder_id');
            $table->foreignId('campus_id')->constrained('campuses', 'campus_id')->restrictOnDelete();
            $table->string('full_name', 150);
            $table->string('team', 100);
            $table->string('contact_number', 20);
            $table->boolean('is_on_duty')->default(true);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
        Schema::create('assignments', function (Blueprint $table) {
            $table->id('assignment_id');
            $table->foreignId('campus_id')->constrained('campuses', 'campus_id')->restrictOnDelete();
            $table->foreignId('responder_id')->constrained('responders', 'responder_id')->restrictOnDelete();
            $table->foreignId('report_id')->nullable()->constrained('citizen_reports', 'report_id')->cascadeOnDelete();
            $table->foreignId('sos_id')->nullable()->constrained('sos_events', 'sos_id')->cascadeOnDelete();
            $table->foreignId('assigned_by')->nullable()->constrained('personnel', 'personnel_id')->nullOnDelete();
            $table->string('status', 20)->default('Assigned');
            $table->string('notes', 500)->nullable();
            $table->timestamp('assigned_at');
            $table->timestamp('arrived_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();
            $table->index(['campus_id', 'status']);
        });
        DB::statement("CREATE UNIQUE INDEX assignments_one_active_per_responder ON assignments (responder_id) WHERE status IN ('Assigned', 'On scene')");
    }

    public function down(): void
    {
        Schema::dropIfExists('assignments');
        Schema::dropIfExists('responders');
        Schema::dropIfExists('roll_call_responses');
        Schema::dropIfExists('roll_calls');
    }
};
