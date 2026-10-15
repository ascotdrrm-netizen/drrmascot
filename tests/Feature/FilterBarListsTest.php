<?php

namespace Tests\Feature;

use App\Models\Advisory\DisasterAdvisory;
use App\Models\Building\Building;
use App\Models\Campus;
use App\Models\Drill\Drill;
use App\Models\Incident\Incident;
use App\Models\Personnel;
use App\Models\School;
use DOMDocument;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * The shared filter bar on each list page, per role: which controls it
 * declares (a coordinator never gets a Campus control), Apply vs submit on
 * change, and a summary line that never describes more than the viewer can
 * see ("your" where a coordinator's list is per person).
 */
class FilterBarListsTest extends TestCase
{
    use RefreshDatabase;

    protected Campus $zabali;

    protected Campus $bazal;

    protected School $engineering;

    protected Personnel $admin;

    protected Personnel $alpha;   // coordinator, Zabali

    protected Personnel $bravo;   // coordinator, Zabali

    protected function setUp(): void
    {
        parent::setUp();

        $this->zabali = Campus::create(['campus_name' => 'ASCOT Zabali Campus']);
        $this->bazal = Campus::create(['campus_name' => 'ASCOT Bazal Campus']);
        $this->engineering = School::create(['campus_id' => $this->zabali->campus_id, 'school_name' => 'School of Engineering']);
        $make = fn (Campus $campus, string $name, string $role) => Personnel::create(['campus_id' => $campus->campus_id, 'full_name' => $name, 'role' => $role,
            'email' => strtolower(str_replace(' ', '-', $name)) . '@ascot-test.local', 'password_hash' => Hash::make('password')]);
        $this->admin = $make($this->zabali, 'Lists Admin', 'DRRMO Admin');
        $this->alpha = $make($this->zabali, 'Coordinator Alpha', 'Coordinator');
        $this->bravo = $make($this->zabali, 'Coordinator Bravo', 'Coordinator');
    }

    // ============================= Buildings/Areas ============================

    public function test_buildings_admin_gets_one_campus_control_that_submits_on_change(): void
    {
        Building::forceCreate(['campus_id' => $this->zabali->campus_id, 'building_name' => 'Gym', 'risk_level' => 'Low']);
        Building::forceCreate(['campus_id' => $this->bazal->campus_id, 'building_name' => 'Barn', 'risk_level' => 'Low']);

        [$x, $r] = $this->page($this->admin, route('admin.buildings.index'));
        $this->assertCount(2, $r->viewData('buildings'));
        $this->assertSame(['campus'], $this->controls($x));
        $this->assertSame('auto', $this->form($x));
        $this->assertSame(0, $x->query('//button[@data-filter-apply]')->length);
        $this->assertSame('Showing all buildings/areas for all campuses.', $this->summary($x));
        // The grid/table switch sits in the bar, and isn't a filter.
        $this->assertSame(2, $x->query('//*[@data-filter-bar]//*[@data-filter-aside]//button[@data-view-toggle="buildings"]')->length, 'grid + table');

        [$x, $r] = $this->page($this->admin, route('admin.buildings.index', ['campus_id' => $this->bazal->campus_id]));
        $this->assertSame(['Barn'], $r->viewData('buildings')->pluck('building_name')->all());
        $this->assertSame('Showing all buildings/areas for ASCOT Bazal Campus.', $this->summary($x));
        $this->assertSame(1, $x->query('//a[@data-filter-clear]')->length);

        // An unknown campus is ignored, not matched literally.
        [$x, $r] = $this->page($this->admin, route('admin.buildings.index', ['campus_id' => '999999']));
        $this->assertCount(2, $r->viewData('buildings'));
        $this->assertSame('Showing all buildings/areas for all campuses.', $this->summary($x));
    }

    public function test_buildings_coordinator_gets_no_controls_just_the_summary_and_the_switch(): void
    {
        Building::forceCreate(['campus_id' => $this->zabali->campus_id, 'building_name' => 'Gym', 'risk_level' => 'Low']);
        Building::forceCreate(['campus_id' => $this->bazal->campus_id, 'building_name' => 'Barn', 'risk_level' => 'Low']);

        foreach ([[], ['campus_id' => $this->bazal->campus_id]] as $query) {
            [$x, $r] = $this->page($this->alpha, route('coordinator.buildings.index', $query));
            $this->assertSame(['Gym'], $r->viewData('buildings')->pluck('building_name')->all());
            $this->assertSame([], $this->controls($x));
            $this->assertSame(0, $x->query('//*[@data-filter-bar]//form')->length);
            $this->assertSame('Showing all buildings/areas for ASCOT Zabali Campus.', $this->summary($x));
            $this->assertSame(2, $x->query('//*[@data-filter-aside]//button[@data-view-toggle="buildings"]')->length, 'grid + table');
        }
    }

    // ================================ Trash ===================================

    public function test_buildings_trash(): void
    {
        [$x] = $this->page($this->admin, route('admin.buildings.trash'));
        $this->assertSame(['campus'], $this->controls($x));
        $this->assertSame('Showing all buildings/areas in Trash for all campuses.', $this->summary($x));

        [$x] = $this->page($this->alpha, route('coordinator.buildings.trash', ['campus_id' => $this->bazal->campus_id]));
        $this->assertSame([], $this->controls($x));
        $this->assertSame('Showing all buildings/areas in Trash for ASCOT Zabali Campus.', $this->summary($x));
    }

    public function test_incidents_trash_says_your_for_a_coordinator_and_offers_school_only(): void
    {
        $mine = $this->trashedIncident($this->alpha, $this->engineering);
        $theirs = $this->trashedIncident($this->bravo, $this->engineering);
        $this->trashedIncident($this->alpha, null);

        // Admin: Campus + School, so Apply.
        [$x, $r] = $this->page($this->admin, route('admin.incidents.trash'));
        $this->assertCount(3, $r->viewData('incidents'));
        $this->assertSame(['campus', 'school'], $this->controls($x));
        $this->assertSame('apply', $this->form($x));
        $this->assertSame('Showing all incidents in Trash for all campuses.', $this->summary($x));

        [$x] = $this->page($this->admin, route('admin.incidents.trash', ['school_id' => $this->engineering->school_id]));
        $this->assertSame('Showing all incidents from School of Engineering in Trash for ASCOT Zabali Campus.', $this->summary($x));

        // Coordinator: School only, submitting on change; only their own incidents, and the line says so.
        [$x, $r] = $this->page($this->alpha, route('coordinator.incidents.trash', ['campus_id' => $this->bazal->campus_id]));
        $this->assertCount(2, $r->viewData('incidents'));
        $this->assertNotContains($theirs->incident_id, $r->viewData('incidents')->pluck('incident_id')->all());
        $this->assertSame(['school'], $this->controls($x));
        $this->assertSame('auto', $this->form($x));
        $this->assertSame('Showing your incidents in Trash for ASCOT Zabali Campus.', $this->summary($x));

        [$x, $r] = $this->page($this->alpha, route('coordinator.incidents.trash', ['school_id' => $this->engineering->school_id]));
        $this->assertSame([$mine->incident_id], $r->viewData('incidents')->pluck('incident_id')->all());
        $this->assertSame('Showing your incidents from School of Engineering in Trash for ASCOT Zabali Campus.', $this->summary($x));

        [$x] = $this->page($this->alpha, route('coordinator.incidents.trash', ['school_id' => 'none']));
        $this->assertSame('Showing your incidents with no school set in Trash for ASCOT Zabali Campus.', $this->summary($x));
    }

    public function test_drills_trash(): void
    {
        [$x] = $this->page($this->admin, route('admin.drills.trash'));
        $this->assertSame(['campus'], $this->controls($x));
        $this->assertSame('Showing all drills in Trash for all campuses.', $this->summary($x));

        [$x] = $this->page($this->alpha, route('coordinator.drills.trash'));
        $this->assertSame([], $this->controls($x));
        $this->assertSame('Showing your drills in Trash for ASCOT Zabali Campus.', $this->summary($x));
        // Drills carry no school.
        $this->assertSame(0, $x->query('//select[@name="school_id"]')->length);
    }

    public function test_advisories_trash_has_no_controls_for_anyone(): void
    {
        [$x] = $this->page($this->admin, route('admin.advisories.trash'));
        $this->assertSame([], $this->controls($x));
        $this->assertSame(0, $x->query('//*[@data-filter-bar]//form')->length);
        $this->assertSame('Showing all advisories in Trash for all campuses.', $this->summary($x));

        [$x] = $this->page($this->alpha, route('coordinator.advisories.trash'));
        $this->assertSame([], $this->controls($x));
        $this->assertSame('Showing your advisories in Trash for ASCOT Zabali Campus.', $this->summary($x));
    }

    public function test_no_coordinator_page_in_this_step_offers_a_campus_control(): void
    {
        foreach (['buildings.index', 'buildings.trash', 'incidents.trash', 'drills.trash', 'advisories.trash'] as $page) {
            [$x] = $this->page($this->alpha, route("coordinator.{$page}"));
            $this->assertSame(0, $x->query('//select[@name="campus_id"]')->length, $page);
        }
    }

    // ================================ helpers =================================

    protected function trashedIncident(Personnel $by, ?School $school): Incident
    {
        $incident = Incident::forceCreate(['campus_id' => $by->campus_id, 'school_id' => $school?->school_id, 'hazard_type' => 'Fire',
            'date_occurred' => '2026-09-01', 'status' => 'Active', 'created_by' => $by->personnel_id]);
        $incident->delete();

        return $incident;
    }

    protected function page(Personnel $as, string $url): array
    {
        $response = $this->actingAs($as)->get($url)->assertOk();
        $dom = new DOMDocument;
        libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="UTF-8">' . $response->getContent());
        libxml_clear_errors();

        return [new DOMXPath($dom), $response];
    }

    protected function summary(DOMXPath $x): string
    {
        return trim(preg_replace('/\s+/', ' ', $x->query('//*[@data-filter-summary]')->item(0)->textContent));
    }

    protected function controls(DOMXPath $x): array
    {
        return array_map(fn ($n) => $n->getAttribute('data-filter-control'), iterator_to_array($x->query('//*[@data-filter-bar]//*[@data-filter-control]')));
    }

    protected function form(DOMXPath $x): string
    {
        return $x->query('//*[@data-filter-bar]//form')->item(0)->getAttribute('data-filter-form');
    }
}
