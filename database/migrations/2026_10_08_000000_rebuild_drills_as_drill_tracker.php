<?php

use App\Support\LegacyDrillExport;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

/**
 * Drill & Evaluation (approval workflow) -> Drill Tracker.
 *
 * A drill becomes a plain schedule record with two separate after-the-drill
 * submissions, one each per drill, never merged:
 *   drill_documentations           what happened: date held, narrative,
 *                                  approximate participant count, notes
 *   drill_after_activity_reports   ASCOT's "After Activity Report"
 *                                  (field set provisional — agreed with the client)
 *   drill_attachments              photos / PDFs for one of the two, kept apart
 *                                  by `section`; files on the PRIVATE disk
 *
 * Removed: the status workflow and every *_by / *_by_name / *_at column,
 * return_reason, the responsible school, participants and their scores
 * (drill_evaluations) and drill_photos.
 *
 * DATA. up() refuses to run unless `php artisan drills:export-legacy` has
 * written the evaluations, workflow columns and photo rows to
 * storage/app/private/exports and those files match the database row for row.
 * Then:
 *   - each drill with an actual date becomes a documentation record: date and
 *     time held, observations as the narrative, the number of participants
 *     recorded, submitted by whoever submitted it;
 *   - each drill photo moves from the public disk to the private disk as an
 *     attachment of that documentation;
 *   - drills get created_by (scheduled_by, else the activity log's 'created').
 * down() rebuilds the old schema and re-imports the export files: statuses,
 * schools, conduct records, workflow names and dates, participants and scores,
 * and the photos (copied back to the public disk). Drills created after the
 * rebuild come back as Scheduled, or Completed when documented.
 */
return new class extends Migration
{
    private const STEPS = ['scheduled', 'acknowledged', 'submitted', 'reviewed'];

    private const NO_NARRATIVE = 'Recorded before the Drill Tracker; no narrative was written at the time.';

    public function up(): void
    {
        LegacyDrillExport::assertComplete();

        $this->createNewTables();

        // ---- drills.created_by -----------------------------------------------
        Schema::table('drills', function (Blueprint $t) {
            $t->foreignId('created_by')->nullable()->after('campus_id')->constrained('personnel', 'personnel_id')->nullOnDelete();
            $t->string('created_by_name', 150)->nullable()->after('created_by');
        });
        DB::table('drills')->whereNotNull('scheduled_by_name')->update([
            'created_by' => DB::raw('scheduled_by'),
            'created_by_name' => DB::raw('scheduled_by_name'),
        ]);
        $creators = DB::table('activity_logs as l')
            ->join('personnel as p', 'p.personnel_id', '=', 'l.personnel_id')
            ->where('l.subject_type', 'Drill')->where('l.action', 'created')->whereNotNull('l.subject_id')
            ->orderBy('l.activity_log_id')
            ->get(['l.subject_id', 'p.personnel_id', 'p.full_name'])
            ->unique('subject_id');
        foreach ($creators as $row) {
            DB::table('drills')->where('drill_id', $row->subject_id)->whereNull('created_by_name')
                ->update(['created_by' => $row->personnel_id, 'created_by_name' => $row->full_name]);
        }

        // ---- conduct records -> documentation --------------------------------
        $counts = DB::table('drill_evaluations')->selectRaw('drill_id, COUNT(*) AS n')->groupBy('drill_id')->pluck('n', 'drill_id');
        $documentationFor = function (object $drill) use ($counts): int {
            $existing = DB::table('drill_documentations')->where('drill_id', $drill->drill_id)->value('documentation_id');
            if ($existing) {
                return $existing;
            }

            return DB::table('drill_documentations')->insertGetId([
                'drill_id' => $drill->drill_id,
                'date_conducted' => $drill->date_conducted ?? $drill->scheduled_date,
                'time_conducted' => $drill->date_conducted ? $drill->time_conducted : null,
                'narrative' => filled($drill->observations) ? $drill->observations : self::NO_NARRATIVE,
                'participant_count' => ($counts[$drill->drill_id] ?? 0) ?: null,
                'notes' => "Converted from the earlier drill record (status: {$drill->status}). Participant names and scores were exported to storage/app/private/exports.",
                'submitted_by' => $drill->submitted_by,
                'submitted_by_name' => $drill->submitted_by_name,
                'created_at' => $drill->submitted_at ?? now(),
                'updated_at' => now(),
            ], 'documentation_id'); // PostgreSQL returns the key by name ("id" otherwise).
        };

        foreach (DB::table('drills')->whereNotNull('date_conducted')->get() as $drill) {
            $documentationFor($drill);
        }

        // ---- drill photos -> private documentation attachments --------------
        $public = Storage::disk('public');
        $private = Storage::disk('local');
        foreach (DB::table('drill_photos')->orderBy('photo_id')->get() as $photo) {
            $drill = DB::table('drills')->where('drill_id', $photo->drill_id)->first();
            $documentationFor($drill);
            $target = "drill-attachments/{$photo->drill_id}/documentation/" . basename($photo->path);
            if ($public->exists($photo->path)) {
                $private->put($target, $public->get($photo->path));
                $public->delete($photo->path);
            }
            DB::table('drill_attachments')->insert([
                'drill_id' => $photo->drill_id,
                'section' => 'documentation',
                'path' => $target,
                'original_name' => $photo->caption ? $photo->caption . '.' . pathinfo($photo->path, PATHINFO_EXTENSION) : basename($photo->path),
                'mime_type' => $this->mimeFor($photo->path),
                'size_bytes' => $private->exists($target) ? $private->size($target) : 0,
                'uploaded_by' => $drill->submitted_by,
                'uploaded_by_name' => $drill->submitted_by_name,
                'created_at' => $photo->created_at ?? now(),
            ]);
        }

        // ---- drop the old workflow -------------------------------------------
        Schema::dropIfExists('drill_evaluations');
        Schema::dropIfExists('drill_photos');

        Schema::table('drills', function (Blueprint $t) {
            // SQLite (the test database) can only drop a foreign key by its columns.
            $t->dropForeign(DB::getDriverName() === 'sqlite' ? ['campus_id', 'school_id'] : 'drills_campus_school_foreign');
        });
        // MariaDB lets campus_id's own foreign key lean on the composite index;
        // give it its own index before that one goes.
        if (! Schema::hasIndex('drills', 'drills_campus_id_index')) {
            Schema::table('drills', fn (Blueprint $t) => $t->index('campus_id', 'drills_campus_id_index'));
        }
        Schema::table('drills', function (Blueprint $t) {
            if (Schema::hasIndex('drills', 'drills_campus_school_foreign')) {
                $t->dropIndex('drills_campus_school_foreign');
            }
            foreach (self::STEPS as $step) {
                $t->dropConstrainedForeignId("{$step}_by");
                $t->dropColumn(["{$step}_by_name", "{$step}_at"]);
            }
            $t->dropIndex(['status']);
            $t->dropColumn(['school_id', 'status', 'date_conducted', 'time_conducted', 'observations', 'return_reason']);
        });
    }

    public function down(): void
    {
        // ---- old drill columns back ------------------------------------------
        Schema::table('drills', function (Blueprint $t) {
            $t->unsignedBigInteger('school_id')->nullable()->after('campus_id');
            $t->string('status', 20)->default('Scheduled')->after('school_id')->index();
            $t->date('date_conducted')->nullable()->after('scheduled_time');
            $t->time('time_conducted')->nullable()->after('date_conducted');
            $t->text('observations')->nullable()->after('partner_agency');
            $t->string('return_reason', 500)->nullable()->after('observations');
            foreach (self::STEPS as $step) {
                $t->foreignId("{$step}_by")->nullable()->constrained('personnel', 'personnel_id')->nullOnDelete();
                $t->string("{$step}_by_name", 255)->nullable();
                $t->timestamp("{$step}_at")->nullable();
            }
            $t->foreign(['campus_id', 'school_id'], 'drills_campus_school_foreign')
                ->references(['campus_id', 'school_id'])->on('schools')
                ->restrictOnDelete()->restrictOnUpdate();
        });
        // up() added a stand-alone campus index for campus_id's foreign key; the
        // composite index serves it again, as it did before.
        if (Schema::hasIndex('drills', 'drills_campus_id_index')) {
            Schema::table('drills', fn (Blueprint $t) => $t->dropIndex('drills_campus_id_index'));
        }

        Schema::create('drill_evaluations', function (Blueprint $t) {
            $t->id('evaluation_id');
            $t->foreignId('drill_id')->constrained('drills', 'drill_id')->cascadeOnDelete();
            $t->foreignId('personnel_id')->nullable()->constrained('personnel', 'personnel_id')->cascadeOnDelete();
            $t->string('participant_name', 150)->nullable();
            $t->decimal('pre_score', 5, 2)->nullable();
            $t->decimal('post_score', 5, 2)->nullable();
            $t->unique(['drill_id', 'personnel_id'], 'drill_evaluations_drill_personnel_unique');
            $t->unique(['drill_id', 'participant_name'], 'drill_evaluations_drill_name_unique');
        });
        if (DB::getDriverName() !== 'sqlite') DB::statement('ALTER TABLE drill_evaluations ADD CONSTRAINT drill_evaluations_one_participant_check
            CHECK ((personnel_id IS NULL) <> (participant_name IS NULL))');

        Schema::create('drill_photos', function (Blueprint $t) {
            $t->id('photo_id');
            $t->foreignId('drill_id')->constrained('drills', 'drill_id')->cascadeOnDelete();
            $t->string('path', 255);
            $t->string('caption', 150)->nullable();
            $t->timestamp('created_at')->nullable();
        });

        // ---- re-import the export --------------------------------------------
        $drillIds = DB::table('drills')->pluck('drill_id')->flip();
        $exported = collect(LegacyDrillExport::read(LegacyDrillExport::WORKFLOW))->keyBy('drill_id');

        foreach (DB::table('drills')->get(['drill_id']) as $drill) {
            $row = $exported->get((string) $drill->drill_id);
            if ($row) {
                DB::table('drills')->where('drill_id', $drill->drill_id)->update(
                    collect(LegacyDrillExport::WORKFLOW_COLUMNS)->mapWithKeys(fn ($c) => [$c => $row[$c]])->all()
                );
                continue;
            }
            // Created after the rebuild: documented = held.
            $doc = DB::table('drill_documentations')->where('drill_id', $drill->drill_id)->first();
            DB::table('drills')->where('drill_id', $drill->drill_id)->update($doc ? [
                'status' => 'Completed',
                'date_conducted' => $doc->date_conducted,
                'time_conducted' => $doc->time_conducted,
                'observations' => $doc->narrative,
            ] : ['status' => 'Scheduled']);
        }

        $personnelIds = DB::table('personnel')->pluck('personnel_id')->flip();
        foreach (LegacyDrillExport::read(LegacyDrillExport::EVALUATIONS) as $row) {
            if (! $drillIds->has((int) $row['drill_id'])) {
                continue;
            }
            // An account deleted since the export comes back as a typed name.
            $accountExists = $row['personnel_id'] !== null && $personnelIds->has((int) $row['personnel_id']);
            DB::table('drill_evaluations')->insert([
                'evaluation_id' => $row['evaluation_id'],
                'drill_id' => $row['drill_id'],
                'personnel_id' => $accountExists ? $row['personnel_id'] : null,
                'participant_name' => $accountExists ? null : ($row['participant_name'] ?? $row['personnel_full_name'] ?? 'Unknown participant'),
                'pre_score' => $row['pre_score'],
                'post_score' => $row['post_score'],
            ]);
        }

        $public = Storage::disk('public');
        $private = Storage::disk('local');
        foreach (LegacyDrillExport::read(LegacyDrillExport::PHOTOS) as $row) {
            if (! $drillIds->has((int) $row['drill_id'])) {
                continue;
            }
            $source = "drill-attachments/{$row['drill_id']}/documentation/" . basename($row['path']);
            if (! $public->exists($row['path']) && $private->exists($source)) {
                $public->put($row['path'], $private->get($source));
            }
            DB::table('drill_photos')->insert($row);
        }

        // ---- new structure out ------------------------------------------------
        // Uploaded files stay on the private disk (drill-attachments/); only the rows go.
        Schema::dropIfExists('drill_attachments');
        Schema::dropIfExists('drill_after_activity_reports');
        Schema::dropIfExists('drill_documentations');
        Schema::table('drills', function (Blueprint $t) {
            $t->dropConstrainedForeignId('created_by');
            $t->dropColumn('created_by_name');
        });
    }

    private function createNewTables(): void
    {
        Schema::create('drill_documentations', function (Blueprint $t) {
            $t->id('documentation_id');
            $t->foreignId('drill_id')->unique()->constrained('drills', 'drill_id')->cascadeOnDelete();
            $t->date('date_conducted');
            $t->time('time_conducted')->nullable();
            $t->text('narrative');
            $t->unsignedInteger('participant_count')->nullable(); // approximate; no names
            $t->text('notes')->nullable();
            $t->foreignId('submitted_by')->nullable()->constrained('personnel', 'personnel_id')->nullOnDelete();
            $t->string('submitted_by_name', 150)->nullable();
            $t->timestamps();
        });

        // ASCOT's "After Activity Report". PROVISIONAL field set (agreed with the
        // client); nothing outside the form, PDFs and tests depends on it.
        Schema::create('drill_after_activity_reports', function (Blueprint $t) {
            $t->id('report_id');
            $t->foreignId('drill_id')->unique()->constrained('drills', 'drill_id')->cascadeOnDelete();
            $t->string('activity_title', 200);
            $t->date('date_conducted');
            $t->time('start_time')->nullable();
            $t->time('end_time')->nullable();
            $t->text('objectives')->nullable();
            $t->text('activities_conducted');
            $t->text('participating_offices')->nullable();
            $t->unsignedInteger('participants_students')->nullable();
            $t->unsignedInteger('participants_employees')->nullable(); // faculty and staff
            $t->unsignedInteger('participants_others')->nullable();
            $t->decimal('response_time_minutes', 6, 1)->nullable(); // evacuation / response time
            $t->text('results')->nullable();                     // against the objectives
            $t->text('problems_encountered')->nullable();
            $t->text('recommendations')->nullable();
            $t->foreignId('submitted_by')->nullable()->constrained('personnel', 'personnel_id')->nullOnDelete();
            $t->string('submitted_by_name', 150)->nullable();
            $t->timestamps();
        });

        // One table for both submissions' files; `section` keeps them apart.
        Schema::create('drill_attachments', function (Blueprint $t) {
            $t->id('attachment_id');
            $t->foreignId('drill_id')->constrained('drills', 'drill_id')->cascadeOnDelete();
            $t->string('section', 20); // 'documentation' | 'after_activity'
            $t->string('path', 255);   // on the private ("local") disk
            $t->string('original_name', 255);
            $t->string('mime_type', 100);
            $t->unsignedInteger('size_bytes');
            $t->foreignId('uploaded_by')->nullable()->constrained('personnel', 'personnel_id')->nullOnDelete();
            $t->string('uploaded_by_name', 150)->nullable();
            $t->timestamp('created_at')->nullable();
            $t->index(['drill_id', 'section']);
        });
    }

    private function mimeFor(string $path): string
    {
        return match (strtolower(pathinfo($path, PATHINFO_EXTENSION))) {
            'jpg', 'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            'webp' => 'image/webp',
            'pdf' => 'application/pdf',
            default => 'application/octet-stream',
        };
    }
};
