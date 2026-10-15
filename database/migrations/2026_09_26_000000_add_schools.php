<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Schools (academic units) — ATTRIBUTION ONLY. Campus scoping is unchanged:
 * school_id records which school a person belongs to / which school
 * recorded a drill, incident or inspection. It never grants or limits access.
 *
 * Existing rows get school_id = null ("—"); nothing is guessed and no
 * campus_id is read-modified.
 *
 * personnel, incidents and drills carry campus_id themselves, so their school
 * is tied to it with a composite foreign key (campus_id, school_id) ->
 * schools(campus_id, school_id): the database rejects a school from another
 * campus. hazard_inspections take their campus from the building, so they get
 * a plain foreign key (and keep their school if the building is moved — the
 * school records who did the inspection).
 */
return new class extends Migration
{
    /** Snapshot of the schools at the time of this migration (campus name keyword => schools). */
    private const SCHOOLS = [
        'Zabali' => [
            'School of Engineering',
            'School of Arts and Sciences',
            'School of Information Technology',
            'School of Forestry and Environmental Sciences',
            'School of Education',
            'School of Industrial Technology',
        ],
        'Bazal' => ['School of Agricultural and Science'],
        'Casiguran' => ['School of Fisheries and Ocean Sciences'],
    ];

    private const COMPOSITE = ['personnel', 'incidents', 'drills'];

    public function up(): void
    {
        Schema::create('schools', function (Blueprint $table) {
            $table->id('school_id');
            $table->foreignId('campus_id')->constrained('campuses', 'campus_id')->restrictOnDelete();
            $table->string('school_name', 150);
            $table->timestamps();

            $table->unique(['campus_id', 'school_name']);
            // Target of the composite foreign keys below.
            $table->unique(['campus_id', 'school_id'], 'schools_campus_school_unique');
        });

        // Seed only for campuses that exist (a fresh/test database has none yet;
        // SchoolSeeder covers fresh installs).
        foreach (self::SCHOOLS as $keyword => $names) {
            // Case-insensitive on every database (ilike on PostgreSQL).
            $campusId = DB::table('campuses')->whereLike('campus_name', "%{$keyword}%")->value('campus_id');
            if ($campusId === null) {
                continue;
            }
            foreach ($names as $name) {
                DB::table('schools')->insert(['campus_id' => $campusId, 'school_name' => $name, 'created_at' => now(), 'updated_at' => now()]);
            }
        }

        foreach (self::COMPOSITE as $tableName) {
            Schema::table($tableName, function (Blueprint $table) use ($tableName) {
                $table->unsignedBigInteger('school_id')->nullable()->after('campus_id');
                $table->foreign(['campus_id', 'school_id'], "{$tableName}_campus_school_foreign")
                    ->references(['campus_id', 'school_id'])->on('schools')
                    ->restrictOnDelete()->restrictOnUpdate();
            });
        }

        Schema::table('hazard_inspections', function (Blueprint $table) {
            $table->foreignId('school_id')->nullable()->after('building_id')
                ->constrained('schools', 'school_id')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('hazard_inspections', function (Blueprint $table) {
            $table->dropConstrainedForeignId('school_id');
        });

        foreach (self::COMPOSITE as $tableName) {
            Schema::table($tableName, function (Blueprint $table) use ($tableName) {
                // SQLite (the test database) can only drop a foreign key by its columns.
                $table->dropForeign(DB::getDriverName() === 'sqlite' ? ['campus_id', 'school_id'] : "{$tableName}_campus_school_foreign");
            });
            // MariaDB lets the existing campus_id foreign key lean on the
            // composite index; give it its own index before dropping that one.
            if (! Schema::hasIndex($tableName, "{$tableName}_campus_id_index")) {
                Schema::table($tableName, function (Blueprint $table) use ($tableName) {
                    $table->index('campus_id', "{$tableName}_campus_id_index");
                });
            }
            Schema::table($tableName, function (Blueprint $table) use ($tableName) {
                if (Schema::hasIndex($tableName, "{$tableName}_campus_school_foreign")) {
                    $table->dropIndex("{$tableName}_campus_school_foreign");
                }
                $table->dropColumn('school_id');
            });
        }

        Schema::dropIfExists('schools');
    }
};
