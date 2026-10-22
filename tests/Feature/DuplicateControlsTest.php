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
 * Each control appears exactly once per screen. These count occurrences in the
 * rendered HTML rather than asserting presence, so a second copy fails.
 */
class DuplicateControlsTest extends TestCase
{
    use RefreshDatabase;

    protected Campus $campus;

    protected Personnel $admin;

    protected Personnel $coordinator;

    protected function setUp(): void
    {
        parent::setUp();

        $this->campus = Campus::create(['campus_name' => 'ASCOT Zabali Campus']);
        $this->admin = Personnel::create(['campus_id' => $this->campus->campus_id, 'full_name' => 'Dup Admin', 'role' => 'DRRMO Admin', 'email' => 'dup-admin@ascot-test.local', 'password_hash' => Hash::make('password')]);
        $this->coordinator = Personnel::create(['campus_id' => $this->campus->campus_id, 'full_name' => 'Dup Coordinator', 'role' => 'Coordinator', 'email' => 'dup-coord@ascot-test.local', 'password_hash' => Hash::make('password')]);
    }

    /** @return array<string, array{Personnel, string}> */
    protected function roles(): array
    {
        return ['admin' => [$this->admin, 'admin'], 'coordinator' => [$this->coordinator, 'coordinator']];
    }

    /** Links whose href is exactly $url (absolute or relative). */
    protected function countLinks(string $html, string $url): int
    {
        $path = preg_quote(parse_url($url, PHP_URL_PATH), '/');

        return preg_match_all('/<a\s[^>]*href="(?:https?:\/\/[^\/"]+)?' . $path . '"/', $html);
    }

    protected function assertUniqueIds(string $html, string $page): void
    {
        preg_match_all('/\sid="([^"]+)"/', $html, $m);
        $repeated = array_keys(array_filter(array_count_values($m[1]), fn ($n) => $n > 1));
        $this->assertSame([], $repeated, "$page: repeated element IDs");
    }

    /** The part of the landing page outside the navbar <header>. */
    protected function outsideHeader(string $html): string
    {
        return preg_replace('/<header\b.*?<\/header>/s', '', $html);
    }

    /** The desktop navbar <nav> and the mobile menu: only one of the two is ever visible. */
    protected function navbarParts(string $html): array
    {
        preg_match('/<header\b.*?<\/header>/s', $html, $header);
        preg_match('/<nav class="hidden[^"]*md:flex">.*?<\/nav>/s', $header[0], $desktop);
        preg_match('/<div id="mobile-menu".*$/s', $header[0], $mobile);

        return [$desktop[0], $mobile[0]];
    }

    public function test_guest_landing_shows_login_and_register_in_the_navbar_only(): void
    {
        $html = $this->get(route('landing'))->assertOk()->getContent();
        [$desktop, $mobile] = $this->navbarParts($html);

        foreach (['login', 'register'] as $route) {
            $this->assertSame(1, $this->countLinks($desktop, route($route)), "$route: desktop navbar");
            $this->assertSame(1, $this->countLinks($mobile, route($route)), "$route: mobile menu");
            $this->assertSame(0, $this->countLinks($this->outsideHeader($html), route($route)), "$route: not in the hero");
        }
        $this->assertUniqueIds($html, 'landing');
    }

    public function test_signed_in_landing_shows_dashboard_and_logout_once_per_navbar_and_nowhere_else(): void
    {
        foreach ($this->roles() as [$user, $prefix]) {
            $html = $this->actingAs($user)->get(route('landing'))->assertOk()->getContent();
            [$desktop, $mobile] = $this->navbarParts($html);
            $dashboard = url("/$prefix/dashboard");

            $this->assertSame(1, $this->countLinks($desktop, $dashboard));
            $this->assertSame(1, $this->countLinks($mobile, $dashboard));
            $this->assertSame(1, substr_count($desktop, 'action="' . url('/logout') . '"'));
            $this->assertSame(1, substr_count($mobile, 'action="' . url('/logout') . '"'));

            $rest = $this->outsideHeader($html);
            $this->assertSame(0, $this->countLinks($rest, $dashboard));
            $this->assertSame(0, $this->countLinks($rest, route('login')));
            $this->assertSame(0, $this->countLinks($rest, route('register')));
            $this->assertUniqueIds($html, "$prefix landing");
        }
    }

    public function test_landing_page_lists_each_campus_once_in_the_footer_only(): void
    {
        Campus::create(['campus_name' => 'ASCOT Bazal Campus']);

        foreach ([null, $this->admin, $this->coordinator] as $user) {
            $html = ($user ? $this->actingAs($user) : $this)->get(route('landing'))->assertOk()->getContent();
            $hero = substr($html, strpos($html, '<section'), strpos($html, '</section>') - strpos($html, '<section'));
            preg_match('/<footer\b.*?<\/footer>/s', $html, $footer);

            foreach (['ASCOT Zabali Campus', 'ASCOT Bazal Campus'] as $name) {
                $this->assertSame(1, substr_count($html, $name), "$name appears once");
                $this->assertStringContainsString($name, $footer[0], "$name is in the footer");
                $this->assertStringNotContainsString($name, $hero, "$name is not in the hero");
            }
        }
    }

    public function test_incident_page_has_one_add_damage_report_action_with_or_without_reports(): void
    {
        $building = Building::create(['campus_id' => $this->campus->campus_id, 'building_name' => 'Gym', 'risk_level' => 'Minimal Risk']);

        foreach ($this->roles() as [$user, $prefix]) {
            // Reported by the viewing user, so a coordinator can open it (per-person visibility).
            $incident = Incident::forceCreate(['campus_id' => $this->campus->campus_id, 'hazard_type' => 'Fire', 'date_occurred' => '2026-09-20', 'status' => 'Active', 'created_by' => $user->personnel_id]);
            $create = route("$prefix.incidents.damage-reports.create", $incident);

            // Empty: the section's own button, not a second one in the empty state.
            $html = $this->actingAs($user)->get(route("$prefix.incidents.show", $incident))->assertOk()->getContent();
            $this->assertStringContainsString('No damage reports recorded yet.', $html);
            $this->assertSame(1, $this->countLinks($html, $create), "$prefix: empty incident");
            $this->assertSame(1, substr_count($html, '+ Add damage report'));
            $this->assertUniqueIds($html, "$prefix incident show");

            $incident->damageReports()->create(['building_id' => $building->building_id, 'damage_level' => 'Minor', 'casualty_count' => 0]);
            $html = $this->actingAs($user)->get(route("$prefix.incidents.show", $incident))->assertOk()->getContent();
            $this->assertSame(1, $this->countLinks($html, $create), "$prefix: incident with reports");
        }
    }

    public function test_empty_lists_keep_their_add_action_in_the_page_header_only(): void
    {
        foreach ($this->roles() as [$user, $prefix]) {
            $html = $this->actingAs($user)->get(route("$prefix.buildings.index"))->assertOk()->getContent();
            $this->assertStringContainsString('No buildings/areas recorded yet.', $html);
            $this->assertSame(1, $this->countLinks($html, route("$prefix.buildings.create")), "$prefix buildings");
            $this->assertUniqueIds($html, "$prefix buildings index");

            $html = $this->actingAs($user)->get(route("$prefix.incidents.index"))->assertOk()->getContent();
            // A coordinator sees only their own incidents, so their empty state says so.
            $this->assertStringContainsString($prefix === 'admin' ? 'No incidents recorded yet.' : e("You haven't reported any incidents yet."), $html);
            $this->assertSame(1, $this->countLinks($html, route("$prefix.incidents.create")), "$prefix incidents");
            $this->assertSame(1, preg_match_all('/>\s*Generate Report\s*</', $html), "$prefix incidents: Generate Report");
            $this->assertUniqueIds($html, "$prefix incidents index");

            $html = $this->actingAs($user)->get(route("$prefix.drills.index"))->assertOk()->getContent();
            // Both roles schedule drills now (Drill Tracker): one link each.
            $this->assertSame(1, $this->countLinks($html, route("$prefix.drills.create")), "$prefix drills");
            $this->assertUniqueIds($html, "$prefix drills index");
        }
    }

    public function test_building_page_has_one_add_inspection_action_when_empty(): void
    {
        $building = Building::create(['campus_id' => $this->campus->campus_id, 'building_name' => 'Library', 'risk_level' => 'Minimal Risk']);

        foreach ($this->roles() as [$user, $prefix]) {
            $html = $this->actingAs($user)->get(route("$prefix.buildings.show", $building))->assertOk()->getContent();
            // A coordinator sees only their own inspections, so their empty state says so.
            $this->assertStringContainsString($prefix === 'admin' ? 'No inspections recorded yet.' : e("You haven't recorded any inspections for this building yet."), $html);
            $this->assertSame(1, $this->countLinks($html, route("$prefix.buildings.inspections.create", $building)), $prefix);
            $this->assertUniqueIds($html, "$prefix building show");
        }
    }

    public function test_drill_page_has_one_action_per_submission_when_empty(): void
    {
        $drill = Drill::create(['campus_id' => $this->campus->campus_id, 'drill_type' => 'Fire', 'scheduled_date' => '2026-10-15', 'scheduled_time' => '09:00', 'venue' => 'Gym']);

        foreach ($this->roles() as [$user, $prefix]) {
            $html = $this->actingAs($user)->get(route("$prefix.drills.show", $drill))->assertOk()->getContent();
            // Documentation and the After Activity Report: one way into each, from its own card.
            $this->assertSame(1, $this->countLinks($html, route("$prefix.drills.documentation.edit", $drill)), "$prefix documentation");
            $this->assertSame(1, $this->countLinks($html, route("$prefix.drills.after-activity.edit", $drill)), "$prefix after activity");
            $this->assertUniqueIds($html, "$prefix drill show");
        }
    }
}
