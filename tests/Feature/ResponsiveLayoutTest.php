<?php

namespace Tests\Feature;

use App\Models\Building\Building;
use App\Models\Campus;
use App\Models\Drill\Drill;
use App\Models\Drill\DrillDocumentation;
use App\Models\Incident\Incident;
use App\Models\Personnel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class ResponsiveLayoutTest extends TestCase
{
    use RefreshDatabase;

    protected const VIEWPORT = '<meta name="viewport" content="width=device-width, initial-scale=1">';

    protected Personnel $admin;

    protected Personnel $coordinator;

    protected function setUp(): void
    {
        parent::setUp();

        $campus = Campus::create(['campus_name' => 'ASCOT Zabali Campus']);

        $this->admin = Personnel::create([
            'campus_id' => $campus->campus_id,
            'full_name' => 'Layout Admin',
            'role' => 'DRRMO Admin',
            'email' => 'layout-admin@ascot-test.local',
            'password_hash' => Hash::make('password'),
        ]);

        $this->coordinator = Personnel::create([
            'campus_id' => $campus->campus_id,
            'full_name' => 'Layout Coordinator',
            'role' => 'Coordinator',
            'email' => 'layout-coord@ascot-test.local',
            'password_hash' => Hash::make('password'),
        ]);

        Building::create(['campus_id' => $campus->campus_id, 'building_name' => 'Gym', 'hazard_type' => 'Fire', 'risk_level' => 'Low']);
        // The coordinator's own records, so every page opens for both roles (per-person visibility).
        $own = $this->coordinator->personnel_id;
        Incident::forceCreate(['campus_id' => $campus->campus_id, 'hazard_type' => 'Fire', 'date_occurred' => '2026-08-01', 'status' => 'Active', 'created_by' => $own]);
        // Drill Tracker: one documented drill, one not yet held — both scheduled by the coordinator.
        $held = Drill::forceCreate(['campus_id' => $campus->campus_id, 'drill_type' => 'Fire', 'scheduled_date' => '2026-08-02', 'scheduled_time' => '09:00:00', 'venue' => 'Quad', 'created_by' => $own]);
        (new DrillDocumentation(['date_conducted' => '2026-08-02', 'narrative' => 'Held as planned.']))->forceFill(['drill_id' => $held->drill_id])->save();
        Drill::forceCreate(['campus_id' => $campus->campus_id, 'drill_type' => 'Earthquake', 'scheduled_date' => '2026-10-10', 'scheduled_time' => '08:00:00', 'venue' => 'Field', 'created_by' => $own]);
    }

    public function test_authenticated_layout_renders_the_mobile_sidebar_toggle_and_overlay(): void
    {
        $html = $this->actingAs($this->admin)->get(route('admin.dashboard'))->assertOk()->getContent();

        // Hamburger (visible below lg only), the drawer itself, its overlay, and its close button.
        $this->assertMatchesRegularExpression('/id="sidebar-open"[^>]*aria-controls="sidebar"/', $html);
        $this->assertMatchesRegularExpression('/<div[^>]*class="[^"]*lg:hidden[^"]*"[^>]*>\s*<button type="button" id="sidebar-open"/', $html);
        $this->assertMatchesRegularExpression('/<aside id="sidebar"[^>]*-translate-x-full[^>]*lg:translate-x-0/s', $html);
        $this->assertMatchesRegularExpression('/id="sidebar-overlay"[^>]*class="[^"]*hidden[^"]*lg:hidden/', $html);
        $this->assertStringContainsString('id="sidebar-close"', $html);
    }

    public function test_viewport_meta_tag_is_present_on_app_public_and_guest_layouts(): void
    {
        // Public layout (landing) and guest layout (login) as a guest.
        $this->get(route('landing'))->assertOk()->assertSee(self::VIEWPORT, false);
        $this->get(route('login'))->assertOk()->assertSee(self::VIEWPORT, false);

        // App layout, both roles.
        $this->actingAs($this->admin)->get(route('admin.dashboard'))->assertOk()->assertSee(self::VIEWPORT, false);
        $this->actingAs($this->coordinator)->get(route('coordinator.dashboard'))->assertOk()->assertSee(self::VIEWPORT, false);
    }

    public function test_every_main_page_renders_for_both_roles(): void
    {
        $building = Building::first();
        $incident = Incident::first();
        $drill = Drill::where('venue', 'Quad')->first();
        $openDrill = Drill::where('venue', 'Field')->first();

        $shared = fn (string $p) => [
            route("{$p}.dashboard"),
            route("{$p}.buildings.index"), route("{$p}.buildings.create"), route("{$p}.buildings.show", $building), route("{$p}.buildings.edit", $building), route("{$p}.buildings.trash"),
            route("{$p}.buildings.inspections.create", $building),
            route("{$p}.incidents.index"), route("{$p}.incidents.create"), route("{$p}.incidents.show", $incident), route("{$p}.incidents.edit", $incident), route("{$p}.incidents.trash"),
            route("{$p}.incidents.damage-reports.create", $incident),
            route("{$p}.drills.index"), route("{$p}.drills.index", ['view' => 'list']), route("{$p}.drills.show", $drill), route("{$p}.drills.show", $openDrill),
            route("{$p}.drills.create"), route("{$p}.drills.edit", $openDrill), route("{$p}.drills.trash"),
            route("{$p}.drills.documentation.edit", $drill), route("{$p}.drills.after-activity.edit", $drill),
            route("{$p}.profile.show"),
            route('reports.consolidated.index'),
        ];

        $adminOnly = [
            route('admin.incidents.alerts.create', $incident),
            route('admin.personnel.index'), route('admin.personnel.edit', $this->coordinator),
            route('admin.backup.index'),
            route('admin.activity-log.index'),
        ];

        foreach (array_merge($shared('admin'), $adminOnly) as $url) {
            $this->actingAs($this->admin)->get($url)->assertOk();
        }

        foreach ($shared('coordinator') as $url) {
            $this->actingAs($this->coordinator)->get($url)->assertOk();
        }
    }

    public function test_confirm_modal_script_toggles_flex_so_the_dialog_is_centered(): void
    {
        $html = $this->actingAs($this->admin)->get(route('admin.dashboard'))->getContent();

        $this->assertStringContainsString("modal.classList.add('flex');", $html);
        $this->assertStringContainsString("modal.classList.remove('flex');", $html);
    }
}
