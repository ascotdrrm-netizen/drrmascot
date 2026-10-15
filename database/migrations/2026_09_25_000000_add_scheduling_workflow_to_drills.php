<?php

use Carbon\CarbonImmutable;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Drill & Evaluation Tracker -> scheduled workflow
 * (Scheduled -> Acknowledged -> Submitted -> Completed, Returned back to editable).
 *
 * drills.date_conducted / time_conducted keep their meaning — the ACTUAL
 * date and time — and become nullable, because a scheduled drill hasn't
 * happened yet. The planned date/time move to new scheduled_* columns.
 *
 * Pre-existing drills (they predate statuses):
 *   - dated before today (Asia/Manila)  -> Completed, actual date kept, and
 *     the scheduled date/time set to the same values (no separate schedule
 *     was ever recorded);
 *   - dated today or later              -> Scheduled; the date/time move to
 *     scheduled_*, the actual date/time are cleared (not conducted yet).
 * Evaluations are kept in both cases; trashed drills stay trashed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('drills', function (Blueprint $table) {
            $table->string('status', 20)->default('Scheduled')->after('campus_id')->index();
            $table->date('scheduled_date')->nullable()->after('drill_type');
            $table->time('scheduled_time')->nullable()->after('scheduled_date');
            $table->text('observations')->nullable()->after('partner_agency');
            $table->string('return_reason', 500)->nullable()->after('observations');

            // Who/when for each transition. The FK is nulled if the account is
            // hard-deleted; the *_name copy keeps the report's "Prepared by"
            // and the detail page's history readable after that.
            foreach (['scheduled', 'acknowledged', 'submitted', 'reviewed'] as $step) {
                $table->foreignId("{$step}_by")->nullable()->constrained('personnel', 'personnel_id')->nullOnDelete();
                $table->string("{$step}_by_name", 255)->nullable();
                $table->timestamp("{$step}_at")->nullable();
            }
        });

        $today = CarbonImmutable::now('Asia/Manila')->toDateString();

        DB::table('drills')->update([
            'scheduled_date' => DB::raw('date_conducted'),
            'scheduled_time' => DB::raw('time_conducted'),
        ]);
        DB::table('drills')->where('date_conducted', '<', $today)->update(['status' => 'Completed']);

        Schema::table('drills', function (Blueprint $table) {
            $table->date('scheduled_date')->nullable(false)->change();
            $table->time('scheduled_time')->nullable(false)->change();
            $table->date('date_conducted')->nullable()->change();
            $table->time('time_conducted')->nullable()->change();
        });

        DB::table('drills')->where('scheduled_date', '>=', $today)->update([
            'status' => 'Scheduled',
            'date_conducted' => null,
            'time_conducted' => null,
        ]);

        // Participants: a typed name (students/staff without accounts) OR a
        // campus personnel account — exactly one of the two.
        Schema::table('drill_evaluations', function (Blueprint $table) {
            $table->string('participant_name', 150)->nullable()->after('personnel_id');
        });
        Schema::table('drill_evaluations', function (Blueprint $table) {
            $table->unsignedBigInteger('personnel_id')->nullable()->change();
            // Per-drill uniqueness for both kinds. NULLs don't collide, and the
            // table's utf8mb4_unicode_ci collation makes the name case-insensitive.
            $table->unique(['drill_id', 'personnel_id'], 'drill_evaluations_drill_personnel_unique');
            $table->unique(['drill_id', 'participant_name'], 'drill_evaluations_drill_name_unique');
        });
        // Older SQLite (the test database) can't add a CHECK to an existing table.
        if (DB::getDriverName() !== 'sqlite') {
            DB::statement('ALTER TABLE drill_evaluations ADD CONSTRAINT drill_evaluations_one_participant_check
                CHECK ((personnel_id IS NULL) <> (participant_name IS NULL))');
        }

        Schema::create('drill_photos', function (Blueprint $table) {
            $table->id('photo_id');
            $table->foreignId('drill_id')->constrained('drills', 'drill_id')->cascadeOnDelete();
            $table->string('path', 255);
            $table->string('caption', 150)->nullable();
            $table->timestamp('created_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('drill_photos');

        if (DB::getDriverName() !== 'sqlite') {
            DB::statement('ALTER TABLE drill_evaluations DROP CONSTRAINT drill_evaluations_one_participant_check');
        }
        // Typed-name participants can't exist without an account column value.
        DB::table('drill_evaluations')->whereNull('personnel_id')->delete();
        // MariaDB may be using one of the uniques to back the drill_id
        // foreign key; give the FK its own index before dropping them.
        if (! Schema::hasIndex('drill_evaluations', 'drill_evaluations_drill_id_index')) {
            Schema::table('drill_evaluations', function (Blueprint $table) {
                $table->index('drill_id', 'drill_evaluations_drill_id_index');
            });
        }
        Schema::table('drill_evaluations', function (Blueprint $table) {
            $table->dropUnique('drill_evaluations_drill_personnel_unique');
            $table->dropUnique('drill_evaluations_drill_name_unique');
            $table->unsignedBigInteger('personnel_id')->nullable(false)->change();
            $table->dropColumn('participant_name');
        });

        // Not-yet-conducted drills go back to carrying their planned date.
        DB::table('drills')->whereNull('date_conducted')->update([
            'date_conducted' => DB::raw('scheduled_date'),
            'time_conducted' => DB::raw('scheduled_time'),
        ]);

        Schema::table('drills', function (Blueprint $table) {
            $table->date('date_conducted')->nullable(false)->change();
            $table->time('time_conducted')->nullable(false)->change();

            foreach (['scheduled', 'acknowledged', 'submitted', 'reviewed'] as $step) {
                $table->dropConstrainedForeignId("{$step}_by");
                $table->dropColumn(["{$step}_by_name", "{$step}_at"]);
            }

            $table->dropIndex(['status']);
            $table->dropColumn(['status', 'scheduled_date', 'scheduled_time', 'observations', 'return_reason']);
        });
    }
};
