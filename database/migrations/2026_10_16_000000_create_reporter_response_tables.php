<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // PostgreSQL's enum() is a varchar with a check constraint. Drop the
        // original two-role check before widening the column for Reporters.
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE personnel DROP CONSTRAINT IF EXISTS personnel_role_check');
        }
        Schema::table('personnel', function (Blueprint $table) {
            $table->string('role', 30)->change();
            $table->string('reporter_kind', 20)->nullable();
            $table->string('id_number', 40)->nullable();
            $table->string('recovery_email', 100)->nullable();
            $table->timestamp('email_verified_at')->nullable();
            $table->timestamp('validated_by_coordinator_at')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('privacy_consented_at')->nullable();
        });
        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE personnel ADD CONSTRAINT personnel_role_check CHECK (role IN ('Coordinator', 'DRRMO Admin', 'Reporter'))");
        }

        Schema::create('citizen_reports', function (Blueprint $table) {
            $table->id('report_id');
            $table->foreignId('reporter_id')->constrained('personnel', 'personnel_id')->cascadeOnDelete();
            $table->foreignId('campus_id')->constrained('campuses', 'campus_id')->restrictOnDelete();
            $table->string('client_key', 64);
            $table->string('category', 30);
            $table->string('place', 255)->nullable();
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->unsignedInteger('accuracy_m')->nullable();
            $table->text('description');
            $table->unsignedInteger('people_injured')->nullable();
            $table->boolean('urgent')->default(false);
            $table->string('status', 20)->default('New');
            $table->string('proof_path')->nullable();
            $table->timestamps();
            $table->unique(['reporter_id', 'client_key']);
            $table->index(['campus_id', 'status', 'created_at']);
        });

        Schema::create('report_photos', function (Blueprint $table) {
            $table->id('photo_id');
            $table->foreignId('report_id')->constrained('citizen_reports', 'report_id')->cascadeOnDelete();
            $table->string('path');
            $table->string('caption', 150)->nullable();
        });

        Schema::create('report_events', function (Blueprint $table) {
            $table->id('event_id');
            $table->foreignId('report_id')->constrained('citizen_reports', 'report_id')->cascadeOnDelete();
            $table->foreignId('actor_id')->nullable()->constrained('personnel', 'personnel_id')->nullOnDelete();
            $table->string('status', 20);
            $table->string('note', 500);
            $table->timestamp('created_at');
        });

        Schema::create('sos_events', function (Blueprint $table) {
            $table->id('sos_id');
            $table->foreignId('reporter_id')->constrained('personnel', 'personnel_id')->cascadeOnDelete();
            $table->foreignId('campus_id')->constrained('campuses', 'campus_id')->restrictOnDelete();
            $table->string('client_key', 64);
            $table->string('type', 20)->nullable();
            $table->string('place', 255)->nullable();
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->unsignedInteger('accuracy_m')->nullable();
            $table->string('status', 20)->default('New');
            $table->foreignId('acknowledged_by')->nullable()->constrained('personnel', 'personnel_id')->nullOnDelete();
            $table->timestamp('acknowledged_at')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();
            $table->unique(['reporter_id', 'client_key']);
            $table->index(['campus_id', 'status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sos_events');
        Schema::dropIfExists('report_events');
        Schema::dropIfExists('report_photos');
        Schema::dropIfExists('citizen_reports');
        Schema::table('personnel', function (Blueprint $table) {
            $table->dropColumn(['reporter_kind', 'id_number', 'recovery_email', 'email_verified_at',
                'validated_by_coordinator_at', 'approved_at', 'privacy_consented_at']);
        });
    }
};
