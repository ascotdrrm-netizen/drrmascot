<?php

namespace Tests\Feature;

use App\Models\Campus;
use App\Models\Personnel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * The place-search proxy behind the "Pick on map" dialog (MapSearchController):
 * Nominatim's usage policy kept server-side, and only names and coordinates
 * returned. No real request ever leaves the test (Http::fake).
 */
class MapSearchTest extends TestCase
{
    use RefreshDatabase;

    protected Personnel $coordinator;

    protected function setUp(): void
    {
        parent::setUp();

        $campus = Campus::create(['campus_name' => 'ASCOT Zabali Campus']);
        $this->coordinator = Personnel::create(['campus_id' => $campus->campus_id, 'full_name' => 'Search Coordinator', 'role' => 'Coordinator', 'email' => 'search-coord@ascot-test.local', 'password_hash' => Hash::make('password')]);
        $this->travelTo(now()->startOfMinute());
    }

    /** A Nominatim-like answer: more places than we keep, with fields we must not pass on. */
    protected function fakeNominatim(int $places = 2): void
    {
        $body = [];
        for ($i = 1; $i <= $places; $i++) {
            $body[] = [
                'place_id' => 1000 + $i, 'osm_type' => 'relation', 'osm_id' => 2000 + $i, 'licence' => 'Data © OpenStreetMap contributors, ODbL 1.0.',
                'lat' => (string) (16.28 + $i / 100), 'lon' => (string) (122.12 + $i / 100), 'importance' => 0.5,
                'display_name' => "Casiguran {$i}, Aurora, Central Luzon, Philippines", 'boundingbox' => ['16.1', '16.4', '122.0', '122.3'],
            ];
        }
        Http::fake(['nominatim.openstreetmap.org/*' => Http::response($body)]);
    }

    protected function search(string $q)
    {
        return $this->actingAs($this->coordinator)->getJson(route('map.search', ['q' => $q]));
    }

    public function test_guests_cannot_search(): void
    {
        Http::fake();
        $this->getJson(route('map.search', ['q' => 'Baler']))->assertUnauthorized();
        Http::assertNothingSent();
    }

    public function test_it_asks_nominatim_politely_and_returns_only_names_and_coordinates(): void
    {
        config(['map.search.contact' => 'drrmo@ascot.edu.ph']);
        $this->fakeNominatim(7);

        $response = $this->search('Casiguran')->assertOk();

        // At most 5 results, each only name / lat / lng.
        $results = $response->json('results');
        $this->assertCount(5, $results);
        foreach ($results as $result) {
            $this->assertSame(['name', 'lat', 'lng'], array_keys($result));
        }
        $this->assertSame('Casiguran 1, Aurora, Central Luzon, Philippines', $results[0]['name']);
        $this->assertEqualsWithDelta(16.29, $results[0]['lat'], 0.0001);
        $this->assertEqualsWithDelta(122.13, $results[0]['lng'], 0.0001);

        Http::assertSent(function (HttpRequest $request) {
            parse_str(parse_url($request->url(), PHP_URL_QUERY), $query);

            return str_starts_with($request->url(), 'https://nominatim.openstreetmap.org/search?')
                && str_starts_with($request->header('User-Agent')[0], 'ASCOT-DRRMS/1.0')
                && str_contains($request->header('User-Agent')[0], 'drrmo@ascot.edu.ph')
                && $query['q'] === 'casiguran'
                && $query['countrycodes'] === 'ph'
                && $query['viewbox'] === '121.15,16.6,122.3,15.25' // Aurora: west,north,east,south
                && $query['bounded'] === '0'
                && $query['limit'] === '5'
                && $query['format'] === 'jsonv2';
        });
    }

    public function test_results_are_cached_so_a_repeat_search_sends_nothing(): void
    {
        $this->fakeNominatim();

        $first = $this->search('Baler')->assertOk()->json('results');
        $this->travel(5)->seconds();
        // Same place, different spacing and case: served from the cache.
        $this->assertSame($first, $this->search('  baler ')->assertOk()->json('results'));
        Http::assertSentCount(1);

        // Still cached after 29 days.
        $this->travel(29)->days();
        $this->search('BALER')->assertOk();
        Http::assertSentCount(1);
    }

    public function test_more_than_one_outbound_request_a_second_is_refused_not_queued(): void
    {
        $this->fakeNominatim();

        $this->search('Baler')->assertOk();
        $this->search('Dingalan')->assertStatus(429)->assertJson(['message' => 'Search is busy. Try again in a moment.']);
        Http::assertSentCount(1);

        // A second later it goes through.
        $this->travel(2)->seconds();
        $this->search('Dingalan')->assertOk();
        Http::assertSentCount(2);
    }

    public function test_the_query_length_is_capped_and_a_query_is_required(): void
    {
        Http::fake();

        $this->search(str_repeat('a', config('map.search.max_query_length') + 1))->assertStatus(422)->assertJsonValidationErrors('q');
        $this->search('a')->assertStatus(422)->assertJsonValidationErrors('q');
        $this->actingAs($this->coordinator)->getJson(route('map.search'))->assertStatus(422)->assertJsonValidationErrors('q');
        Http::assertNothingSent();
    }

    public function test_search_can_be_switched_off(): void
    {
        config(['map.search.enabled' => false]);
        Http::fake();

        $this->search('Baler')->assertStatus(503)->assertJsonStructure(['message']);
        Http::assertNothingSent();
    }

    public function test_an_upstream_failure_says_so_and_is_not_cached(): void
    {
        Http::fake(['nominatim.openstreetmap.org/*' => Http::sequence()->push('down', 500)->push([['display_name' => 'Baler, Aurora', 'lat' => '15.76', 'lon' => '121.56']])]);

        $this->search('Baler')->assertStatus(502)->assertJsonStructure(['message']);
        $this->travel(2)->seconds();
        $this->assertSame('Baler, Aurora', $this->search('Baler')->assertOk()->json('results.0.name'));
        Http::assertSentCount(2);
    }
}
