<?php

namespace Tests\Feature;

use App\Models\Building\Building;
use App\Models\Building\HazardInspection;
use App\Models\Campus;
use App\Models\Incident\Incident;
use App\Models\Personnel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * The map location picker on the building, hazard inspection and incident
 * forms. The map is input help only: coordinates are still the two plain
 * fields, optional, and validated server-side exactly as before.
 */
class LocationMapTest extends TestCase
{
    use RefreshDatabase;

    protected Campus $zabali;

    protected Campus $casiguran;

    protected Personnel $coordinator;

    protected Personnel $admin;

    protected Building $building;

    protected function setUp(): void
    {
        parent::setUp();

        $this->zabali = Campus::create(['campus_name' => 'ASCOT Zabali Campus']);
        $this->casiguran = Campus::create(['campus_name' => 'ASCOT Casiguran Campus']);
        $this->admin = Personnel::create(['campus_id' => $this->zabali->campus_id, 'full_name' => 'Map Admin', 'role' => 'DRRMO Admin', 'email' => 'map-admin@ascot-test.local', 'password_hash' => Hash::make('password')]);
        $this->coordinator = Personnel::create(['campus_id' => $this->zabali->campus_id, 'full_name' => 'Map Coordinator', 'role' => 'Coordinator', 'email' => 'map-coord@ascot-test.local', 'password_hash' => Hash::make('password')]);
        $this->building = Building::create(['campus_id' => $this->zabali->campus_id, 'building_name' => 'Map Hall', 'hazard_type' => 'Fire', 'risk_level' => 'Low']);
    }

    /** [store route, payload without coordinates, model class, how to find the saved record] per form. */
    protected function forms(): array
    {
        return [
            'building' => [route('coordinator.buildings.store'), ['building_name' => 'Pinned Hall', 'hazard_type' => 'Fire', 'risk_level' => 'Low'], fn () => Building::where('building_name', 'Pinned Hall')->first()],
            'inspection' => [route('coordinator.buildings.inspections.store', $this->building), ['inspection_date' => '2026-09-20', 'risk_level' => 'Low', 'findings' => 'Pinned inspection'], fn () => HazardInspection::where('findings', 'Pinned inspection')->first()],
            'incident' => [route('coordinator.incidents.store'), ['hazard_type' => 'Fire', 'date_occurred' => '2026-09-20', 'status' => 'Active', 'description' => 'Pinned incident'], fn () => Incident::where('description', 'Pinned incident')->first()],
        ];
    }

    public function test_each_form_saves_coordinates_from_the_fields(): void
    {
        foreach ($this->forms() as $name => [$url, $payload, $find]) {
            $this->actingAs($this->coordinator)->post($url, $payload + ['latitude' => '15.7440842', 'longitude' => '121.5773613'])
                ->assertSessionHasNoErrors()->assertRedirect();

            $record = $find();
            $this->assertNotNull($record, $name);
            $this->assertEqualsWithDelta(15.7440842, (float) $record->latitude, 0.0000001, $name);
            $this->assertEqualsWithDelta(121.5773613, (float) $record->longitude, 0.0000001, $name);
        }
    }

    public function test_coordinates_stay_optional(): void
    {
        foreach ($this->forms() as $name => [$url, $payload, $find]) {
            $this->actingAs($this->coordinator)->post($url, $payload + ['latitude' => '', 'longitude' => ''])
                ->assertSessionHasNoErrors()->assertRedirect();

            $record = $find();
            $this->assertNull($record->latitude, $name);
            $this->assertNull($record->longitude, $name);
        }
    }

    public function test_invalid_coordinates_are_still_rejected_server_side(): void
    {
        $bad = [
            [['latitude' => '95', 'longitude' => '121.5'], 'latitude'],          // out of range
            [['latitude' => '15.7', 'longitude' => '200'], 'longitude'],         // out of range
            [['latitude' => 'north', 'longitude' => '121.5'], 'latitude'],       // not a number
            [['latitude' => '15.7'], 'longitude'],                                // one without the other
            [['longitude' => '121.5'], 'latitude'],
        ];

        foreach ($this->forms() as $name => [$url, $payload, $find]) {
            foreach ($bad as [$coords, $field]) {
                $this->actingAs($this->coordinator)->from('/coordinator/dashboard')->post($url, $payload + $coords)
                    ->assertSessionHasErrors($field);
                $this->assertNull($find(), "$name saved with " . json_encode($coords));
            }
        }
    }

    public function test_edit_forms_update_coordinates(): void
    {
        $inspection = HazardInspection::forceCreate(['building_id' => $this->building->building_id, 'inspection_date' => '2026-09-20', 'risk_level' => 'Low', 'created_by' => $this->coordinator->personnel_id]);
        $incident = Incident::forceCreate(['campus_id' => $this->zabali->campus_id, 'hazard_type' => 'Fire', 'date_occurred' => '2026-09-20', 'status' => 'Active', 'created_by' => $this->coordinator->personnel_id]);
        $coords = ['latitude' => '15.7376192', 'longitude' => '121.5494202'];

        $this->actingAs($this->coordinator)->put(route('coordinator.buildings.update', $this->building), ['building_name' => 'Map Hall', 'hazard_type' => 'Fire', 'risk_level' => 'Low'] + $coords)->assertSessionHasNoErrors();
        $this->actingAs($this->coordinator)->put(route('coordinator.buildings.inspections.update', [$this->building, $inspection]), ['inspection_date' => '2026-09-20', 'risk_level' => 'Low'] + $coords)->assertSessionHasNoErrors();
        $this->actingAs($this->coordinator)->put(route('coordinator.incidents.update', $incident), ['hazard_type' => 'Fire', 'date_occurred' => '2026-09-20', 'status' => 'Active'] + $coords)->assertSessionHasNoErrors();

        foreach ([$this->building->fresh(), $inspection->fresh(), $incident->fresh()] as $record) {
            $this->assertEqualsWithDelta(15.7376192, (float) $record->latitude, 0.0000001);
            $this->assertEqualsWithDelta(121.5494202, (float) $record->longitude, 0.0000001);
        }
    }

    public function test_the_map_renders_on_every_form_with_coordinates(): void
    {
        $inspection = HazardInspection::forceCreate(['building_id' => $this->building->building_id, 'inspection_date' => '2026-09-20', 'risk_level' => 'Low', 'latitude' => 15.7376192, 'longitude' => 121.5494202, 'created_by' => $this->coordinator->personnel_id]);
        $incident = Incident::forceCreate(['campus_id' => $this->zabali->campus_id, 'hazard_type' => 'Fire', 'date_occurred' => '2026-09-20', 'status' => 'Active', 'created_by' => $this->coordinator->personnel_id]);

        $pages = [
            route('coordinator.buildings.create'), route('coordinator.buildings.edit', $this->building),
            route('coordinator.buildings.inspections.create', $this->building), route('coordinator.buildings.inspections.edit', [$this->building, $inspection]),
            route('coordinator.incidents.create'), route('coordinator.incidents.edit', $incident),
        ];

        foreach ($pages as $url) {
            $html = $this->actingAs($this->coordinator)->get($url)->assertOk()->getContent();

            $this->assertSame(1, substr_count($html, '<dialog data-map-picker '), $url);
            // Leaflet from our own host (scripts/copy-assets.mjs), loaded once; never a CDN.
            $this->assertSame(1, substr_count($html, asset('vendor/leaflet/leaflet.js')), $url);
            $this->assertSame(1, substr_count($html, asset('vendor/leaflet/leaflet.css')), $url);
            $this->assertStringNotContainsString('cdn.jsdelivr.net', $html, $url);
            // The map button starts hidden (shown only if Leaflet loads); the plain inputs are outside the map.
            $this->assertMatchesRegularExpression('/<button type="button" data-map-picker-open hidden/', $html);
            $this->assertMatchesRegularExpression('/<input type="number" id="latitude" name="latitude"/', $html);
            $this->assertMatchesRegularExpression('/<input type="number" id="longitude" name="longitude"/', $html);
            $this->assertStringContainsString('Use my current location', $html);
        }

        // Editing shows the saved pin in the fields (the map reads it from there).
        $html = $this->actingAs($this->coordinator)->get(route('coordinator.buildings.inspections.edit', [$this->building, $inspection]))->getContent();
        $this->assertMatchesRegularExpression('/name="latitude"[^>]*value="15\.7376192"/', $html);
        $this->assertMatchesRegularExpression('/name="longitude"[^>]*value="121\.5494202"/', $html);
    }

    public function test_a_new_record_centres_on_the_campus(): void
    {
        $config = function (string $html): array {
            $this->assertSame(1, preg_match("/data-map-config='([^']*)'/", $html, $m));

            return json_decode(html_entity_decode($m[1]), true);
        };

        // Coordinator: their own campus.
        $c = $config($this->actingAs($this->coordinator)->get(route('coordinator.incidents.create'))->getContent());
        $this->assertSame($this->zabali->campus_id, $c['campusId']);
        $this->assertSame(config('campus_map.centres.Zabali'), $c['centres'][$this->zabali->campus_id]);
        $this->assertSame(config('campus_map.centres.Casiguran'), $c['centres'][$this->casiguran->campus_id]);

        // An inspection: its building's campus, whoever is signed in.
        $casiguranBuilding = Building::create(['campus_id' => $this->casiguran->campus_id, 'building_name' => 'Cas Hall', 'hazard_type' => 'Fire', 'risk_level' => 'Low']);
        $c = $config($this->actingAs($this->admin)->get(route('admin.buildings.inspections.create', $casiguranBuilding))->getContent());
        $this->assertSame($this->casiguran->campus_id, $c['campusId']);
    }
}
