<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Disaster Advisories: a warning typed in from a PAGASA / PHIVOLCS / DRRMO
 * bulletin before or during an event. campus_id NULL means every campus.
 * measurements holds the hazard-specific figures (config/hazard_measurements.php).
 *
 * incidents.advisory_id links the incident recorded afterwards ("Record what
 * happened") to the advisory that preceded it. Nulled if the advisory is ever
 * permanently deleted; moving it to Trash keeps the link.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('disaster_advisories', function (Blueprint $table) {
            $table->id('advisory_id');
            $table->string('hazard_type', 30);
            $table->string('hazard_type_other', 100)->nullable();
            $table->foreignId('campus_id')->nullable()->constrained('campuses', 'campus_id')->nullOnDelete();
            $table->string('headline', 150);
            $table->json('measurements')->nullable();
            $table->string('source', 40);
            $table->string('source_other', 100)->nullable();
            $table->string('bulletin_reference', 60)->nullable();
            $table->dateTime('issued_at');
            $table->dateTime('expected_at')->nullable();
            $table->dateTime('valid_until');
            $table->string('status', 20)->default('Active');
            $table->text('instructions');
            $table->foreignId('created_by')->nullable()->constrained('personnel', 'personnel_id')->nullOnDelete();
            $table->string('created_by_name', 150)->nullable();
            $table->softDeletes();
            $table->timestamps();

            $table->index(['status', 'valid_until']);
        });

        Schema::table('incidents', function (Blueprint $table) {
            $table->foreignId('advisory_id')->nullable()->after('school_id')
                ->constrained('disaster_advisories', 'advisory_id')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('incidents', function (Blueprint $table) {
            $table->dropConstrainedForeignId('advisory_id');
        });

        Schema::dropIfExists('disaster_advisories');
    }
};
