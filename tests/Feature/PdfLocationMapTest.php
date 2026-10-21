<?php

namespace Tests\Feature;

use App\Models\Building\Building;
use App\Models\Building\HazardInspection;
use App\Models\Campus;
use App\Models\Incident\Incident;
use App\Models\Personnel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\ReadsReportHtml;
use Tests\TestCase;

/**
 * UAT 2026-10-21: under "Geo-tagged location" the incident, building and
 * inspection PDFs show a map of the spot with a pin (App\Support\StaticMap),
 * made from OpenStreetMap tiles, with its credit. No map when there is no
 * location or the tiles can't be had: the coordinates stay, the PDF downloads.
 */
class PdfLocationMapTest extends TestCase
{
    use RefreshDatabase, ReadsReportHtml;

    private Personnel $admin;

    private Campus $campus;

    protected function setUp(): void
    {
        parent::setUp();
        config(['map.pdf.enabled' => true]);
        $this->campus = Campus::create(['campus_name' => 'ASCOT Zabali Campus']);
        $this->admin = Personnel::create(['campus_id' => $this->campus->campus_id, 'full_name' => 'Map Admin', 'role' => 'DRRMO Admin',
            'email' => 'map-admin@ascot-test.local', 'password_hash' => Hash::make('password')]);
    }

    private function tile(): string
    {
        $image = imagecreatetruecolor(256, 256);
        imagefill($image, 0, 0, imagecolorallocate($image, 200, 220, 200));
        ob_start();
        imagepng($image);
        imagedestroy($image);

        return (string) ob_get_clean();
    }

    public function test_the_incident_pdf_shows_a_map_of_the_geo_tagged_location(): void
    {
        Http::fake(['tile.openstreetmap.org/*' => Http::response($this->tile(), 200, ['Content-Type' => 'image/png'])]);
        $incident = Incident::create(['campus_id' => $this->campus->campus_id, 'hazard_type' => 'Fire', 'date_occurred' => '2026-10-21',
            'status' => 'Active', 'description' => 'Fire near the gym', 'latitude' => 15.7378551, 'longitude' => 121.5493429]);

        $html = $this->capturePdf($this->admin, 'reports.records.incident', route('admin.incidents.report', $incident))['html'];

        $this->assertStringContainsString('15.7378551, 121.5493429', $html);
        $this->assertStringContainsString('data-location-map', $html);
        $this->assertStringContainsString('src="data:image/jpeg;base64,', $html);
        $this->assertStringContainsString('OpenStreetMap contributors', $html);
        Http::assertSent(fn (HttpRequest $request) => str_starts_with($request->url(), 'https://tile.openstreetmap.org/16/')
            && str_contains($request->header('User-Agent')[0] ?? '', 'ASCOT-DRRMS'));

        // Tiles are cached: a second PDF of the same spot fetches nothing.
        $sent = count(Http::recorded());
        $this->capturePdf($this->admin, 'reports.records.incident', route('admin.incidents.report', $incident));
        $this->assertCount($sent, Http::recorded());
    }

    public function test_building_and_inspection_pdfs_show_the_map_too(): void
    {
        Http::fake(['tile.openstreetmap.org/*' => Http::response($this->tile())]);
        $building = Building::create(['campus_id' => $this->campus->campus_id, 'building_name' => 'Gym', 'hazard_type' => 'Fire',
            'risk_level' => 'High', 'latitude' => 15.7378551, 'longitude' => 121.5493429]);
        $inspection = HazardInspection::create(['building_id' => $building->building_id, 'inspection_date' => '2026-10-21',
            'findings' => 'Cracked wall', 'risk_level' => 'High', 'latitude' => 15.7379, 'longitude' => 121.5494]);

        $this->assertStringContainsString('data-location-map',
            $this->capturePdf($this->admin, 'reports.records.building', route('admin.buildings.report', $building))['html']);
        $this->assertStringContainsString('data-location-map',
            $this->capturePdf($this->admin, 'reports.records.hazard-inspection', route('admin.buildings.inspections.report', [$building, $inspection]))['html']);
    }

    public function test_no_location_or_no_tiles_means_no_map_and_the_pdf_still_downloads(): void
    {
        Http::fake(['tile.openstreetmap.org/*' => Http::response('', 503)]);
        $placed = Incident::create(['campus_id' => $this->campus->campus_id, 'hazard_type' => 'Fire', 'date_occurred' => '2026-10-21',
            'status' => 'Active', 'description' => 'Tiles down', 'latitude' => 15.7378551, 'longitude' => 121.5493429]);
        $html = $this->capturePdf($this->admin, 'reports.records.incident', route('admin.incidents.report', $placed))['html'];
        $this->assertStringContainsString('15.7378551, 121.5493429', $html);
        $this->assertStringNotContainsString('data-location-map', $html);

        Http::fake();
        $unplaced = Incident::create(['campus_id' => $this->campus->campus_id, 'hazard_type' => 'Fire', 'date_occurred' => '2026-10-21',
            'status' => 'Active', 'description' => 'No pin']);
        $html = $this->capturePdf($this->admin, 'reports.records.incident', route('admin.incidents.report', $unplaced))['html'];
        $this->assertStringNotContainsString('data-location-map', $html);
        Http::assertNothingSent();
    }
}
