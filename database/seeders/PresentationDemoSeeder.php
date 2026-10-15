<?php

namespace Database\Seeders;

use App\Models\Building\Building;
use App\Models\Campus;
use App\Models\Incident\DamageReport;
use App\Models\Incident\Incident;
use App\Models\Personnel;
use App\Models\School;
use App\Support\Files;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class PresentationDemoSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new \RuntimeException('Presentation demo records are for local/testing databases only.');
        }
        $campus = Campus::where('campus_name', 'like', '%Bazal%')->first() ?? Campus::firstOrFail();
        $actor = Personnel::where('campus_id', $campus->getKey())->where('role', 'Coordinator')->where('is_approved', true)->first()
            ?? Personnel::where('role', 'DRRMO Admin')->firstOrFail();
        $photo = 'incidents/presentation-demo-flood.png';
        if (! Files::disk()->put($photo, file_get_contents(resource_path('demo/flood-gym-synthetic.png')))) {
            throw new \RuntimeException('The demonstration photo could not be stored.');
        }
        Model::unguarded(fn () => DB::transaction(function () use ($campus, $actor, $photo) {
            $by = ['created_by' => $actor->getKey(), 'created_by_name' => $actor->full_name];
            $building = Building::firstOrCreate(['campus_id' => $campus->getKey(), 'building_name' => 'Gymnasium entrance (demo)'], $by + [
                'description' => '[DEMO] Covered gymnasium used for PE classes and campus assemblies. Main entrance faces the concrete walkway; a side entrance provides alternate access.',
                'hazard_type' => 'Flood', 'risk_level' => 'High', 'latitude' => 15.7905, 'longitude' => 121.4751,
            ]);
            $incident = Incident::firstOrCreate(['campus_id' => $campus->getKey(), 'photo_path' => $photo], $by + [
                'school_id' => School::attributedTo($actor, (int) $campus->getKey()), 'hazard_type' => 'Flood', 'status' => 'Under Response', 'date_occurred' => today(),
                'description' => '[DEMO] At 9:20 AM, a campus officer reported approximately 15–20 cm of water across the gymnasium entrance following sustained rain. Twenty-four students were redirected to the side entrance. Security cordoned off the submerged walkway, maintenance began clearing the drainage grate, and the clinic confirmed no injuries. The area remains under observation until the water recedes and electrical access is checked. This is a fictional presentation scenario.',
                'people_affected' => 24, 'people_injured' => 0, 'people_missing' => 0, 'people_dead' => 0,
                'latitude' => 15.7905, 'longitude' => 121.4751,
                'photo_caption' => 'DEMO: synthetic illustration of flooding at a generic campus gymnasium; not a photograph of an actual ASCOT incident.',
            ]);
            DamageReport::firstOrCreate(['incident_id' => $incident->getKey(), 'building_id' => $building->getKey()], $by + [
                'damage_level' => 'Minor', 'casualty_count' => 0, 'est_cost' => 3000,
            ]);
        }));
    }
}
