<?php

namespace Database\Seeders;

use App\Models\Advisory\DisasterAdvisory;
use App\Models\Building\Building;
use App\Models\Building\HazardInspection;
use App\Models\Campus;
use App\Models\Incident\Incident;
use App\Models\Personnel;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use RuntimeException;

/**
 * Sample records for a local demo (dashboard charts, lists, maps) — never for
 * a real server. Run after DatabaseSeeder:
 *
 *     php artisan db:seed --class=DemoDataSeeder
 *
 * Everything here is fictitious; delete it with Backup & Restore → Clear All
 * Records before entering real data.
 */
class DemoDataSeeder extends Seeder
{
    public function run(): void
    {
        if (app()->environment('production')) {
            throw new RuntimeException('DemoDataSeeder is for local demos only.');
        }

        $admin = Personnel::where('role', 'DRRMO Admin')->firstOrFail();
        $campuses = Campus::orderBy('campus_id')->get()->values();
        [$zabali, $bazal, $casiguran] = [$campuses[0], $campuses[1], $campuses[2]];
        $by = ['created_by' => $admin->personnel_id, 'created_by_name' => $admin->full_name];

        // Buildings and areas, near each campus centre, each rated on the
        // 5×5 risk matrix (likelihood, impact) by one inspection; the building
        // takes its inspection's level.
        $buildings = [
            [$zabali, 'Engineering Building', 'Earthquake', 2, 4, 15.7362, 121.5489],
            [$zabali, 'General Education Building', 'Fire', 1, 3, 15.7369, 121.5496],
            [$zabali, 'Ermita Hill Access Road', 'Landslide', 4, 5, 15.7381, 121.5512],
            [$zabali, "Men's Dormitory", 'Typhoon', 3, 3, 15.7355, 121.5478],
            [$bazal, 'Agriculture Laboratory', 'Flood', 4, 3, 15.7898, 121.4743],
            [$bazal, 'Bazal Gymnasium', 'Typhoon', 2, 2, 15.7905, 121.4751],
            [$casiguran, 'Fisheries Hatchery', 'Storm Surge', 4, 4, 16.2849, 122.1196],
            [$casiguran, 'Casiguran Admin Building', 'Earthquake', 1, 4, 16.2856, 122.1189],
        ];

        foreach ($buildings as $i => [$campus, $name, $hazard, $likelihood, $impact, $lat, $lng]) {
            $building = Building::forceCreate($by + [
                'campus_id' => $campus->campus_id, 'building_name' => $name, 'hazard_type' => $hazard,
                'latitude' => $lat, 'longitude' => $lng,
            ]);

            (new HazardInspection)->forceFill($by + [
                'building_id' => $building->building_id,
                'inspection_date' => Carbon::today()->subDays(3 + $i * 4),
                'findings' => "Routine inspection of the {$name}.",
                'latitude' => $lat, 'longitude' => $lng,
            ])->rate($likelihood, $impact)->save();
        }

        // Incidents over the last two months.
        $incidents = [
            [$zabali, 'Typhoon', 'Resolved', 52, 'Strong winds tore roofing sheets off the covered walk.'],
            [$bazal, 'Flood', 'Resolved', 47, 'Knee-deep water at the Agriculture Laboratory road.'],
            [$zabali, 'Electrical or power failure', 'Resolved', 39, 'Power out in the Engineering Building for three hours.'],
            [$casiguran, 'Storm Surge', 'Resolved', 33, 'Waves reached the hatchery perimeter fence.'],
            [$zabali, 'Earthquake', 'Under Response', 21, 'Hairline cracks found on the second-floor stair landing.'],
            [$zabali, 'Landslide', 'Active', 12, 'Soil slipped onto the Ermita Hill access road after heavy rain.'],
            [$bazal, 'Injury or medical emergency', 'Resolved', 9, 'A student sprained an ankle during PE class.'],
            [$zabali, 'Typhoon', 'Active', 4, 'Fallen tree blocking the main gate.'],
            [$bazal, 'Flood', 'Active', 2, 'Flooding at the gymnasium entrance.'],
            [$casiguran, 'Vehicular accident', 'Under Response', 1, 'Tricycle collision at the campus entrance.'],
        ];

        // One advisory in effect, so the dashboards and the landing page show a warning.
        DisasterAdvisory::forceCreate($by + [
            'hazard_type' => 'Typhoon', 'campus_id' => $zabali->campus_id,
            'headline' => 'Typhoon Pepito: Signal No. 1 over Aurora',
            'measurements' => ['storm_name' => 'Pepito', 'wind_signal' => '1'],
            'source' => 'PAGASA', 'issued_at' => Carbon::now()->subHours(3),
            'valid_until' => Carbon::now()->addDays(2), 'status' => 'Active',
            'instructions' => 'Secure loose objects, charge phones, and watch for class-suspension notices.',
        ]);

        foreach ($incidents as [$campus, $type, $status, $daysAgo, $description]) {
            Incident::forceCreate($by + [
                'campus_id' => $campus->campus_id, 'hazard_type' => $type, 'status' => $status,
                'date_occurred' => Carbon::today()->subDays($daysAgo), 'description' => $description,
            ]);
        }
    }
}
