<?php

namespace Tests\Feature;

use App\Models\Building\Building;
use App\Models\Campus;
use App\Models\Incident\Incident;
use App\Models\Personnel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * The "Pick on map" dialog on the building, inspection and incident forms.
 * The map is JavaScript, so what is tested here is what the page promises
 * before any script runs: tiles come only from config/map.php, every layer
 * carries its attribution, the latitude/longitude fields live outside the
 * map and stay editable, and the form never depends on a tile layer.
 */
class MapPickerTest extends TestCase
{
    use RefreshDatabase;

    protected Campus $zabali;

    protected Personnel $coordinator;

    protected Building $building;

    protected function setUp(): void
    {
        parent::setUp();

        $this->zabali = Campus::create(['campus_name' => 'ASCOT Zabali Campus']);
        $this->coordinator = Personnel::create(['campus_id' => $this->zabali->campus_id, 'full_name' => 'Picker Coordinator', 'role' => 'Coordinator', 'email' => 'picker-coord@ascot-test.local', 'password_hash' => Hash::make('password')]);
        $this->building = Building::create(['campus_id' => $this->zabali->campus_id, 'building_name' => 'Picker Hall', 'hazard_type' => 'Fire', 'risk_level' => 'Minimal Risk']);
    }

    /** Every form that includes the location fields: create and edit. */
    protected function pages(): array
    {
        $incident = Incident::forceCreate(['campus_id' => $this->zabali->campus_id, 'hazard_type' => 'Fire', 'date_occurred' => '2026-09-20', 'status' => 'Active', 'created_by' => $this->coordinator->personnel_id]);

        return [
            route('coordinator.buildings.create'), route('coordinator.buildings.edit', $this->building),
            route('coordinator.buildings.inspections.create', $this->building),
            route('coordinator.incidents.create'), route('coordinator.incidents.edit', $incident),
        ];
    }

    protected function html(string $url): string
    {
        return $this->actingAs($this->coordinator)->get($url)->assertOk()->getContent();
    }

    /** The settings the dialog's script reads. */
    protected function mapConfig(string $html): array
    {
        $this->assertSame(1, preg_match("/data-map-config='([^']*)'/", $html, $m), 'no map settings on the page');

        return json_decode(html_entity_decode($m[1]), true);
    }

    /** The <dialog>…</dialog> markup. */
    protected function dialog(string $html): string
    {
        $this->assertSame(1, preg_match('#<dialog data-map-picker .*?</dialog>#s', $html, $m), 'no map dialog');

        return $m[0];
    }

    public function test_the_map_layers_come_from_config_only(): void
    {
        $layers = $this->mapConfig($this->html(route('coordinator.buildings.create')))['layers'];

        // Satellite (three stacked Esri layers) first, then Street, exactly as configured.
        $this->assertSame(['Satellite', 'Street'], array_column($layers, 'label'));
        foreach (array_values(config('map.layers')) as $i => $layer) {
            foreach ($layer['tiles'] as $j => $tile) {
                $this->assertSame($tile['url'], $layers[$i]['tiles'][$j]['url']);
                $this->assertSame($tile['attribution'], $layers[$i]['tiles'][$j]['attribution']);
            }
        }
        $this->assertCount(3, $layers[0]['tiles']);
        $this->assertSame(18, $layers[0]['tiles'][0]['maxNativeZoom']);

        // Swapping the provider is a config change: the page follows it.
        config(['map.layers' => ['plain' => ['label' => 'Plain', 'tiles' => [['url' => 'https://tiles.example.test/{z}/{x}/{y}.png', 'attribution' => 'Example Tiles']]]]]);
        $html = $this->html(route('coordinator.buildings.create'));
        $layers = $this->mapConfig($html)['layers'];
        $this->assertSame('https://tiles.example.test/{z}/{x}/{y}.png', $layers[0]['tiles'][0]['url']);
        $this->assertSame('Example Tiles', $layers[0]['tiles'][0]['attribution']);
        $this->assertStringNotContainsString('arcgisonline', $html);
        $this->assertStringNotContainsString('tile.openstreetmap.org', $html);
    }

    public function test_every_configured_tile_layer_has_a_url_and_an_attribution(): void
    {
        $this->assertNotEmpty(config('map.layers'));
        foreach (config('map.layers') as $key => $layer) {
            $this->assertNotEmpty($layer['tiles'] ?? [], "$key has no tiles");
            foreach ($layer['tiles'] as $i => $tile) {
                $this->assertNotEmpty($tile['url'] ?? null, "$key tile $i has no URL");
                $this->assertNotEmpty(trim(strip_tags($tile['attribution'] ?? '')), "$key tile $i has no attribution");
            }
        }
    }

    public function test_the_coordinate_fields_sit_outside_the_map_and_stay_editable(): void
    {
        foreach ($this->pages() as $url) {
            $html = $this->html($url);
            $dialog = $this->dialog($html);

            foreach (['latitude', 'longitude'] as $field) {
                $this->assertSame(1, preg_match('/<input type="number" id="' . $field . '" name="' . $field . '"[^>]*>/', $html, $input), "$url: $field");
                $this->assertStringNotContainsString('readonly', $input[0], "$url: $field");
                $this->assertStringNotContainsString('disabled', $input[0], "$url: $field");
                $this->assertStringNotContainsString('name="' . $field . '"', $dialog, "$url: $field is inside the map");
            }
            // Nothing in the dialog is a form field (it moves to <body> while open),
            // and its buttons never submit.
            $this->assertDoesNotMatchRegularExpression('/\sname="/', $dialog, $url);
            $this->assertSame(substr_count($dialog, '<button'), substr_count($dialog, '<button type="button"'), $url);
            // The button starts hidden (shown only if Leaflet loads) and never submits.
            $this->assertMatchesRegularExpression('/<button type="button" data-map-picker-open hidden/', $html, $url);
        }
    }

    public function test_where_the_map_opens_comes_from_the_page(): void
    {
        $config = $this->mapConfig($this->html(route('coordinator.buildings.create')));

        // A record's campus, else Aurora province (a saved pin is read from the fields).
        $this->assertSame($this->zabali->campus_id, $config['campusId']);
        $this->assertSame(config('campus_map.centres.Zabali'), $config['centres'][$this->zabali->campus_id]);
        $this->assertSame(config('map.province_bounds'), $config['provinceBounds']);

        // A campus not in campus_map has no centre, so the province is used.
        $unlisted = Campus::create(['campus_name' => 'ASCOT Satellite Annex']);
        $config = $this->mapConfig($this->html(route('coordinator.buildings.create')));
        $this->assertArrayNotHasKey($unlisted->campus_id, $config['centres']);
    }

    public function test_place_search_and_its_attribution_appear_only_when_enabled(): void
    {
        $html = $this->html(route('coordinator.buildings.create'));
        $this->assertSame(route('map.search'), $this->mapConfig($html)['searchUrl']);
        $this->assertStringContainsString('data-map-search-input', $this->dialog($html));
        $this->assertStringContainsString(config('map.search.attribution'), $this->dialog($html));
        $this->assertStringContainsString('maxlength="' . config('map.search.max_query_length') . '"', $this->dialog($html));

        config(['map.search.enabled' => false]);
        $html = $this->html(route('coordinator.buildings.create'));
        $this->assertNull($this->mapConfig($html)['searchUrl']);
        $this->assertStringNotContainsString('data-map-search-input', $this->dialog($html));
        $this->assertStringNotContainsString(config('map.search.attribution'), $html);
    }

    public function test_the_form_renders_and_saves_with_a_layer_removed(): void
    {
        $layers = config('map.layers');
        unset($layers['street']);
        config(['map.layers' => $layers]);

        foreach ($this->pages() as $url) {
            $this->assertSame(['Satellite'], array_column($this->mapConfig($this->html($url))['layers'], 'label'), $url);
        }
        $this->assertSavesTypedCoordinates('Hall With One Layer');
    }

    public function test_the_form_renders_and_saves_with_no_layers_at_all(): void
    {
        foreach ([[], null] as $empty) {
            config(['map.layers' => $empty]);

            foreach ($this->pages() as $url) {
                $html = $this->html($url);
                // No map at all: no dialog, no button, no settings — just the fields.
                $this->assertStringNotContainsString('<dialog', $html, $url);
                $this->assertStringNotContainsString('data-map-picker-open hidden', $html, $url);
                $this->assertStringNotContainsString("data-map-config='", $html, $url);
                $this->assertStringNotContainsString('Pick on map', $html, $url);
                $this->assertMatchesRegularExpression('/<input type="number" id="latitude" name="latitude"/', $html, $url);
                $this->assertMatchesRegularExpression('/<input type="number" id="longitude" name="longitude"/', $html, $url);
                $this->assertStringContainsString('Use my current location', $html, $url);
            }
        }
        $this->assertSavesTypedCoordinates('Hall With No Layers');
    }

    protected function assertSavesTypedCoordinates(string $name): void
    {
        $this->actingAs($this->coordinator)->post(route('coordinator.buildings.store'), [
            'building_name' => $name, 'hazard_type' => 'Fire', 'risk_level' => 'Minimal Risk',
            'latitude' => '15.7440842', 'longitude' => '121.5773613',
        ])->assertSessionHasNoErrors()->assertRedirect();

        $building = Building::where('building_name', $name)->firstOrFail();
        $this->assertEqualsWithDelta(15.7440842, (float) $building->latitude, 0.0000001);
        $this->assertEqualsWithDelta(121.5773613, (float) $building->longitude, 0.0000001);

        $this->actingAs($this->coordinator)->put(route('coordinator.buildings.update', $building), [
            'building_name' => $name, 'hazard_type' => 'Fire', 'risk_level' => 'Minimal Risk',
            'latitude' => '16.2836', 'longitude' => '122.1236',
        ])->assertSessionHasNoErrors()->assertRedirect();
        $this->assertEqualsWithDelta(16.2836, (float) $building->fresh()->latitude, 0.0000001);
    }
}
