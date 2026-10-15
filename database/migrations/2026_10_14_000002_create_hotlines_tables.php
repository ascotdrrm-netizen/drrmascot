<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Thesis panel (Engineering): the Admin and the coordinators edit the
 * emergency hotlines, so they move from config/hotlines.php into the
 * database. The config's groups are copied in here, so every page shows the
 * same numbers as before; a group that "Serves <X> Campus" belongs to that
 * campus (its coordinator may edit it).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hotline_groups', function (Blueprint $table) {
            $table->id('hotline_group_id');
            $table->string('title', 80);
            $table->string('subtitle', 120)->nullable();
            $table->string('note', 300)->nullable();
            $table->unsignedBigInteger('campus_id')->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
            $table->foreign('campus_id')->references('campus_id')->on('campuses')->nullOnDelete();
        });

        Schema::create('hotlines', function (Blueprint $table) {
            $table->id('hotline_id');
            $table->unsignedBigInteger('hotline_group_id');
            $table->string('label', 100);
            $table->json('numbers')->nullable();
            $table->string('radio', 40)->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
            $table->foreign('hotline_group_id')->references('hotline_group_id')->on('hotline_groups')->cascadeOnDelete();
        });

        $campuses = DB::table('campuses')->get(['campus_id', 'campus_name']);
        foreach (config('hotlines.groups', []) as $g => $group) {
            $campusId = null;
            if (preg_match('/Serves (\w+) Campus/', $group['subtitle'] ?? '', $m)) {
                $campusId = $campuses->first(fn ($c) => str_contains($c->campus_name, $m[1]))?->campus_id;
            }
            $groupId = DB::table('hotline_groups')->insertGetId([
                'title' => $group['title'], 'subtitle' => $group['subtitle'] ?? null, 'note' => $group['note'] ?? null,
                'campus_id' => $campusId, 'sort_order' => $g, 'created_at' => now(), 'updated_at' => now(),
            ], 'hotline_group_id');
            foreach ($group['entries'] as $e => $entry) {
                DB::table('hotlines')->insert([
                    'hotline_group_id' => $groupId, 'label' => $entry['label'],
                    'numbers' => isset($entry['radio']) ? null : json_encode(array_values($entry['numbers'] ?? [])),
                    'radio' => $entry['radio'] ?? null, 'sort_order' => $e, 'created_at' => now(), 'updated_at' => now(),
                ]);
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('hotlines');
        Schema::dropIfExists('hotline_groups');
    }
};
