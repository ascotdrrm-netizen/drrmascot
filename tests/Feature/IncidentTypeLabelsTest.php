<?php

namespace Tests\Feature;

use App\Models\Advisory\DisasterAdvisory;
use App\Models\Building\Building;
use App\Models\Campus;
use App\Models\Incident\Incident;
use App\Models\Personnel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Tests\Concerns\ReadsReportHtml;
use Tests\TestCase;

/**
 * An incident's type is labelled "Incident type" everywhere it is shown —
 * pages, PDFs, chart titles, Help — so it no longer reads as the same field
 * as a disaster advisory's hazard. A building's "Hazard type" (the hazard it
 * is exposed to) is a different field and keeps its name.
 */
class IncidentTypeLabelsTest extends TestCase
{
    use RefreshDatabase, ReadsReportHtml;

    protected Campus $campus;

    protected Personnel $admin;

    protected Personnel $coordinator;

    protected Incident $incident;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::parse('2026-10-12 09:00:00', 'Asia/Manila'));
        $this->campus = Campus::create(['campus_name' => 'ASCOT Zabali Campus']);
        $make = fn (string $name, string $role) => Personnel::create(['campus_id' => $this->campus->campus_id, 'full_name' => $name, 'role' => $role,
            'email' => strtolower(str_replace(' ', '-', $name)) . '@ascot-test.local', 'password_hash' => Hash::make('password')]);
        $this->admin = $make('Labels Admin', 'DRRMO Admin');
        $this->coordinator = $make('Labels Coordinator', 'Coordinator');
        $this->incident = Incident::forceCreate(['campus_id' => $this->campus->campus_id, 'hazard_type' => 'Vehicular accident', 'date_occurred' => '2026-10-05',
            'status' => 'Active', 'created_by' => $this->coordinator->personnel_id]);
    }

    public function test_incident_pages_say_incident_type_and_never_hazard_type(): void
    {
        $trashed = Incident::forceCreate(['campus_id' => $this->campus->campus_id, 'hazard_type' => 'Fire', 'date_occurred' => '2026-10-01',
            'status' => 'Resolved', 'created_by' => $this->coordinator->personnel_id]);
        $trashed->delete();

        foreach ([[$this->admin, 'admin'], [$this->coordinator, 'coordinator']] as [$user, $prefix]) {
            foreach ([route("{$prefix}.incidents.index"), route("{$prefix}.incidents.trash"), route("{$prefix}.incidents.show", $this->incident),
                      route("{$prefix}.incidents.create"), route("{$prefix}.incidents.edit", $this->incident)] as $url) {
                $this->actingAs($user)->get($url)->assertOk()->assertSee('Incident type')->assertDontSee('Hazard type')->assertDontSee('hazard type');
            }
        }
    }

    public function test_the_incident_page_title_reads_incident_colon_type(): void
    {
        $this->actingAs($this->admin)->get(route('admin.incidents.show', $this->incident))
            ->assertSee('<title>Incident: Vehicular accident - ASCOT DRRMS</title>', false);
    }

    public function test_the_pdfs_say_incident_type(): void
    {
        $record = $this->reportText($this->capturePdf($this->admin, 'reports.records.incident', route('admin.incidents.report', $this->incident))['html']);
        $module = $this->reportText($this->periodPdfHtml($this->admin, 'incidents', ['period_type' => 'quarterly', 'quarter' => 4, 'year' => 2026]));
        $consolidated = $this->reportText($this->consolidatedPdfHtml($this->admin, ['period_type' => 'quarterly', 'quarter' => 4, 'year' => 2026]));

        $this->assertStringContainsString('Incident type', $record);
        $this->assertStringContainsString('Incident type', $module);
        $this->assertStringContainsString('By incident type', $consolidated);
        foreach ([$record, $module, $consolidated] as $text) {
            $this->assertStringNotContainsStringIgnoringCase('hazard type', $text);
        }
    }

    public function test_the_charts_help_and_glossary_say_type(): void
    {
        $this->actingAs($this->admin)->get(route('admin.dashboard'))->assertSee('Incidents by type')->assertDontSee('Incidents by hazard type');
        $this->actingAs($this->admin)->get(route('reports.consolidated.index'))->assertSee('Incidents by type')->assertDontSee('Incidents by Hazard Type');
        $this->actingAs($this->admin)->get(route('help'))->assertSee('incident type')->assertDontSee('hazard event that happened');
        $this->assertStringContainsString('campus emergency', config('glossary.incident_hazard_type'));
    }

    public function test_the_advisorys_what_happened_column_says_incident_type(): void
    {
        $advisory = DisasterAdvisory::forceCreate([
            'campus_id' => $this->campus->campus_id, 'hazard_type' => 'Typhoon', 'headline' => 'Typhoon Egay', 'measurements' => ['wind_signal' => '2'],
            'source' => 'PAGASA', 'issued_at' => now(), 'valid_until' => now()->addDay(), 'status' => 'Active',
            'instructions' => 'Stay indoors.', 'created_by' => $this->admin->personnel_id,
        ]);

        $this->actingAs($this->admin)->get(route('admin.advisories.show', $advisory))
            ->assertSee('<th class="table-th">Incident type</th>', false)
            ->assertDontSee('<th class="table-th">Hazard</th>', false);
    }

    public function test_a_buildings_hazard_type_keeps_its_name(): void
    {
        $building = Building::forceCreate(['campus_id' => $this->campus->campus_id, 'building_name' => 'Gym', 'hazard_type' => 'Fire', 'risk_level' => 'Minimal Risk']);

        foreach ([route('admin.buildings.index'), route('admin.buildings.show', $building), route('admin.buildings.edit', $building)] as $url) {
            $this->actingAs($this->admin)->get($url)->assertOk()->assertSee('Hazard type');
        }
    }
}
