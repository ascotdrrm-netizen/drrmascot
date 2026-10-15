<?php

namespace Tests\Feature;

use App\Models\Building\Building;
use App\Models\Campus;
use App\Models\Drill\Drill;
use App\Models\Incident\Incident;
use App\Models\Personnel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Guidance for first-time users: the Help page, empty states that say what
 * belongs there, delete confirmations that say whether it can be undone, and
 * the drill workflow's current step and next action.
 */
class NewcomerGuidanceTest extends TestCase
{
    use RefreshDatabase;

    protected Campus $campus;

    protected Personnel $admin;

    protected Personnel $coordinator;

    protected function setUp(): void
    {
        parent::setUp();

        $this->campus = Campus::create(['campus_name' => 'ASCOT Zabali Campus']);
        $this->admin = Personnel::create(['campus_id' => $this->campus->campus_id, 'full_name' => 'Guide Admin', 'role' => 'DRRMO Admin', 'email' => 'guide-admin@ascot-test.local', 'password_hash' => Hash::make('password')]);
        $this->coordinator = Personnel::create(['campus_id' => $this->campus->campus_id, 'full_name' => 'Guide Coordinator', 'role' => 'Coordinator', 'email' => 'guide-coord@ascot-test.local', 'password_hash' => Hash::make('password')]);
    }

    /** @return array<string, array{Personnel, string}> */
    protected function roles(): array
    {
        return ['admin' => [$this->admin, 'admin'], 'coordinator' => [$this->coordinator, 'coordinator']];
    }

    // ------------------------------------------------------------- access

    public function test_guests_cannot_use_the_help_route(): void
    {
        $this->get(route('help'))->assertRedirect(route('login'));
        // The dashboard welcome panel and its dismiss / show-again URLs are gone.
        $this->post('/welcome/dismiss')->assertNotFound();
        $this->post('/welcome/show')->assertNotFound();
    }

    // ---------------------------------------------------------------- help page

    public function test_help_page_loads_for_both_roles_with_the_parts_relevant_to_each(): void
    {
        foreach ($this->roles() as $prefix => [$user]) {
            $html = $this->actingAs($user)->get(route('help'))->assertOk()->getContent();

            foreach (['start', 'roles', 'modules', 'drills', 'terms', 'reports', 'trash'] as $section) {
                $this->assertStringContainsString('data-help-section="' . $section . '"', $html, "$prefix: $section");
            }
            $this->assertStringContainsString('data-risk-placeholder', $html);
            // Every status is explained.
            foreach (['Under Response', 'Resolved', 'Minor', 'Major', 'Total', 'Partial', 'Not assigned', 'After Activity Report'] as $term) {
                $this->assertStringContainsString($term, $html, "$prefix: $term");
            }

            if ($prefix === 'admin') {
                $this->assertStringContainsString('User Management</dt>', $html);
                $this->assertStringContainsString('Backup &amp; Restore</dt>', $html);
                $this->assertStringContainsString('You can pick several campuses at once', $html);
            } else {
                $this->assertStringNotContainsString('User Management</dt>', $html);
                $this->assertStringNotContainsString('Backup &amp; Restore</dt>', $html);
                $this->assertStringContainsString('It is for your campus.', $html);
            }
        }
    }

    public function test_sidebar_links_to_help_for_both_roles(): void
    {
        foreach ($this->roles() as $prefix => [$user]) {
            $html = $this->actingAs($user)->get(route("$prefix.dashboard"))->getContent();
            preg_match('/<nav id="sidebar-nav".*?<\/nav>/s', $html, $nav);
            $this->assertSame(1, substr_count($nav[0], 'href="' . route('help') . '"'), "$prefix sidebar");
        }
    }

    // -------------------------------------------------------------- empty states

    public function test_each_empty_list_explains_what_belongs_there(): void
    {
        $building = Building::create(['campus_id' => $this->campus->campus_id, 'building_name' => 'Empty Hall', 'risk_level' => 'Low']);
        $other = Campus::create(['campus_name' => 'ASCOT Bazal Campus']);

        foreach ($this->roles() as $prefix => [$user]) {
            $as = fn (string $url) => $this->actingAs($user)->get($url)->assertOk()->getContent();
            $incident = Incident::forceCreate(['campus_id' => $this->campus->campus_id, 'hazard_type' => 'Fire', 'date_occurred' => '2026-09-20', 'status' => 'Active', 'created_by' => $user->personnel_id]);

            $pages = [
                // Buildings: a campus with none (admin filters to it; a coordinator sees their own campus).
                'buildings' => [$prefix === 'admin' ? route('admin.buildings.index', ['campus_id' => $other->campus_id]) : null, 'List each building or open area here'],
                'inspections' => [route("$prefix.buildings.show", $building), $prefix === 'admin' ? 'Inspections that coordinators record' : 'Walk the building, note any hazards you find'],
                'damage reports' => [route("$prefix.incidents.show", $incident), 'A damage report records what this incident did to one building or area'],
                'alerts' => [route("$prefix.incidents.show", $incident), $prefix === 'admin' ? 'Use Send Alert above' : 'Only the DRRMO can send SMS alerts'],
                'drills' => [route("$prefix.drills.index"), 'Use + Schedule Drill at the top to add one'],
                'incidents trash' => [route("$prefix.incidents.trash"), 'When an incident is deleted it comes here'],
                'buildings trash' => [route("$prefix.buildings.trash"), 'When a building/area is deleted it comes here'],
            ];
            if ($prefix === 'admin') {
                $pages['drills trash'] = [route('admin.drills.trash'), 'When a drill is deleted it comes here'];
                $pages['personnel'] = [route('admin.personnel.index', ['campus_id' => $other->campus_id]), 'No account matches these filters'];
            }

            foreach ($pages as $list => [$url, $guidance]) {
                if ($url === null) {
                    continue;
                }
                $html = $as($url);
                $this->assertStringContainsString('data-empty-guidance', $html, "$prefix $list: guidance line");
                $this->assertStringContainsString($guidance, $html, "$prefix $list");
            }

            $incident->forceDelete();
        }

        // Incidents list: none at all (fresh coordinator; admin filtered to an empty campus has its own wording).
        $html = $this->actingAs($this->admin)->get(route('admin.incidents.index'))->getContent();
        $this->assertStringContainsString('data-empty-guidance', $html);
    }

    public function test_coordinator_empty_states_say_none_of_yours_rather_than_none_at_all(): void
    {
        // A colleague's records exist; this coordinator just can't see them.
        $colleague = Personnel::create(['campus_id' => $this->campus->campus_id, 'full_name' => 'Colleague', 'role' => 'Coordinator', 'email' => 'colleague@ascot-test.local', 'password_hash' => Hash::make('password')]);
        $building = Building::create(['campus_id' => $this->campus->campus_id, 'building_name' => 'Shared Hall', 'risk_level' => 'Low']);
        $building->hazardInspections()->forceCreate(['inspection_date' => '2026-09-01', 'risk_level' => 'High', 'created_by' => $colleague->personnel_id]);
        Incident::forceCreate(['campus_id' => $this->campus->campus_id, 'hazard_type' => 'Flood', 'date_occurred' => '2026-09-20', 'status' => 'Active', 'created_by' => $colleague->personnel_id]);

        $this->actingAs($this->coordinator)->get(route('coordinator.buildings.show', $building))
            ->assertSee("You haven't recorded any inspections for this building yet.")
            ->assertSee('Inspections by other coordinators are not shown to you.')
            ->assertDontSee('No inspections recorded yet.');

        $this->actingAs($this->coordinator)->get(route('coordinator.incidents.index'))
            ->assertSee("You haven't reported any incidents yet.")
            ->assertDontSee('No incidents recorded yet.');

        $this->actingAs($this->coordinator)->get(route('coordinator.incidents.trash'))
            ->assertSee('None of your deleted incidents are in Trash.');

        $dashboard = $this->actingAs($this->coordinator)->get(route('coordinator.dashboard'))->getContent();
        $this->assertStringContainsString('You have no unresolved incidents.', $dashboard);
        $this->assertStringContainsString('None of your inspections has an open high-risk finding.', $dashboard);
        // The coordinator is told what their numbers cover — now in the filter bar's summary line.
        $this->assertStringContainsString('your incidents and inspections, and every drill', $dashboard);
    }

    public function test_activity_log_empty_states_explain_what_is_recorded(): void
    {
        $this->actingAs($this->coordinator)->get(route('coordinator.activity-log.index'))
            ->assertSee('You have no recorded activity yet.')
            ->assertSee('Each time you sign in, or record, change or delete something');

        $this->actingAs($this->admin)->get(route('admin.activity-log.index', ['action' => 'drill_released']))
            ->assertSee('No activity matches these filters.')
            ->assertSee('Clear the filters above');
    }

    // ------------------------------------------------- delete confirmations (A)

    public function test_delete_confirmations_say_whether_it_can_be_undone(): void
    {
        $building = Building::create(['campus_id' => $this->campus->campus_id, 'building_name' => 'Gym', 'risk_level' => 'Low']);
        $building->hazardInspections()->forceCreate(['inspection_date' => '2026-09-01', 'risk_level' => 'Low', 'created_by' => $this->admin->personnel_id]);
        $incident = Incident::forceCreate(['campus_id' => $this->campus->campus_id, 'hazard_type' => 'Fire', 'date_occurred' => '2026-09-20', 'status' => 'Active', 'created_by' => $this->admin->personnel_id]);
        $incident->damageReports()->create(['building_id' => $building->building_id, 'damage_level' => 'Minor', 'casualty_count' => 0]);
        $drill = Drill::create(['campus_id' => $this->campus->campus_id, 'drill_type' => 'Fire', 'scheduled_date' => '2026-10-15', 'scheduled_time' => '09:00', 'venue' => 'Gym']);

        $recoverable = 'to Trash. You can restore it from there';
        $permanent = 'It cannot be undone.';

        $this->actingAs($this->admin)->get(route('admin.buildings.index'))->assertSee($recoverable);
        $this->actingAs($this->admin)->get(route('admin.buildings.show', $building))->assertSee('This permanently deletes the inspection')->assertSee($permanent);
        $html = $this->actingAs($this->admin)->get(route('admin.incidents.show', $incident))->getContent();
        $this->assertStringContainsString('This moves the incident, with its damage reports and alerts, ' . $recoverable, $html);
        $this->assertStringContainsString('This permanently deletes the damage report for Gym. ' . $permanent, $html);
        $this->actingAs($this->admin)->get(route('admin.drills.show', $drill))->assertSee('This moves the drill, with its documentation, After Activity Report and their files, ' . $recoverable);
        $this->actingAs($this->admin)->get(route('admin.personnel.index'))->assertSee("This permanently deletes Guide Coordinator&#039;s account. It cannot be undone.", false);
    }

    // ------------------------------------------------------------ drill page (4)

    public function test_drill_page_explains_and_offers_its_two_separate_submissions(): void
    {
        $drill = Drill::create(['campus_id' => $this->campus->campus_id, 'drill_type' => 'Fire', 'scheduled_date' => '2026-10-15', 'scheduled_time' => '09:00', 'venue' => 'Gym']);

        foreach ($this->roles() as $prefix => [$user]) {
            $this->actingAs($user)->get(route("$prefix.drills.show", $drill))->assertOk()
                ->assertSee('No documentation yet.')
                ->assertSee('No After Activity Report yet.')
                ->assertSee('href="' . route("$prefix.drills.documentation.edit", $drill) . '"', false)
                ->assertSee('href="' . route("$prefix.drills.after-activity.edit", $drill) . '"', false)
                ->assertSee('What does “After Activity Report” mean?', false);
        }
    }

    // ------------------------------------------------------- terms explained (3)

    public function test_terms_are_explained_where_they_appear(): void
    {
        $building = Building::create(['campus_id' => $this->campus->campus_id, 'building_name' => 'Gym', 'risk_level' => 'Low']);
        $incident = Incident::forceCreate(['campus_id' => $this->campus->campus_id, 'hazard_type' => 'Fire', 'date_occurred' => '2026-09-20', 'status' => 'Active', 'created_by' => $this->coordinator->personnel_id]);

        $this->actingAs($this->coordinator)->get(route('coordinator.incidents.create'))
            ->assertSee('aria-describedby="status-help"', false)
            ->assertSee(config('glossary.incident_status.Under Response'));
        $this->actingAs($this->coordinator)->get(route('coordinator.incidents.damage-reports.create', $incident))
            ->assertSee(config('glossary.damage_level.Total'))
            ->assertSee(config('glossary.casualty_count'));
        foreach ([route('coordinator.buildings.create'), route('coordinator.buildings.inspections.create', $building)] as $form) {
            $this->actingAs($this->coordinator)->get($form)
                ->assertSee('aria-describedby="risk_level-help"', false)
                ->assertSee(config('glossary.risk_level'));
        }
        // Info tips next to the risk-level column and status badges.
        $this->actingAs($this->coordinator)->get(route('coordinator.buildings.index'))->assertSee('What does “Risk level” mean?', false);
        $this->actingAs($this->coordinator)->get(route('coordinator.incidents.show', $incident))
            ->assertSee('title="' . config('glossary.incident_status.Active') . '"', false)
            ->assertSee('What does “Damage level” mean?', false);
    }
}
