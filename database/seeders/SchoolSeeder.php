<?php

namespace Database\Seeders;

use App\Models\Campus;
use App\Models\School;
use Illuminate\Database\Seeder;

/** Seeds the official schools for each campus that exists (idempotent). */
class SchoolSeeder extends Seeder
{
    public function run(): void
    {
        foreach (School::CATALOG as $keyword => $names) {
            // Case-insensitive on every database (ilike on PostgreSQL).
            $campus = Campus::whereLike('campus_name', "%{$keyword}%")->first();
            if (! $campus) {
                continue;
            }
            foreach ($names as $name) {
                School::firstOrCreate(['campus_id' => $campus->campus_id, 'school_name' => $name]);
            }
        }
    }
}
