<?php

namespace Tests\Feature;

use App\Models\Campus;
use App\Models\Incident\Incident;
use App\Models\Personnel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Thesis panel (Engineering): a map under the geotag coordinates on a
 * record's page (components/location-map), and none without a location.
 */
class LocationPreviewTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_incident_with_a_location_shows_a_map_under_its_coordinates(): void
    {
        $campus = Campus::create(['campus_name' => 'ASCOT Zabali Campus']);
        $admin = Personnel::create(['campus_id' => $campus->campus_id, 'full_name' => 'Map Admin', 'role' => 'DRRMO Admin',
            'email' => 'map-admin@ascot-test.local', 'password_hash' => Hash::make('password')]);
        $pinned = Incident::forceCreate(['campus_id' => $campus->campus_id, 'hazard_type' => 'Fire', 'date_occurred' => '2026-10-10', 'status' => 'Active',
            'latitude' => 15.7376192, 'longitude' => 121.5494202, 'created_by' => $admin->personnel_id]);
        $unpinned = Incident::forceCreate(['campus_id' => $campus->campus_id, 'hazard_type' => 'Fire', 'date_occurred' => '2026-10-10', 'status' => 'Active',
            'created_by' => $admin->personnel_id]);

        $html = $this->actingAs($admin)->get(route('admin.incidents.show', $pinned))->assertOk()->getContent();
        $this->assertStringContainsString('data-location-map data-lat="15.7376192" data-lng="121.5494202"', $html);
        $this->assertStringContainsString('15.73762, 121.54942', $html);
        $this->assertSame(1, substr_count($html, asset('vendor/leaflet/leaflet.js')), 'Leaflet once, from our own host');

        $this->get(route('admin.incidents.show', $unpinned))->assertOk()->assertDontSee('data-location-map', false);
    }
}
