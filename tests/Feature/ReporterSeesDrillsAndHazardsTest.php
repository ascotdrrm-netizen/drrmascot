<?php

namespace Tests\Feature;

use App\Models\Building\Building;
use App\Models\Building\HazardInspection;
use App\Models\Campus;
use App\Models\Drill\Drill;
use App\Models\Personnel;
use App\Models\StaffNotification;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Panel round 2: students (Reporters) see their campus's drill schedule and
 * hazardous areas in the phone app, next to the advisories they already see.
 */
class ReporterSeesDrillsAndHazardsTest extends TestCase
{
    use RefreshDatabase;

    protected Campus $zabali;

    protected Campus $bazal;

    protected Personnel $reporter;

    protected Personnel $coord;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-10-20 10:00:00', 'Asia/Manila'));
        $this->zabali = Campus::create(['campus_name' => 'ASCOT Zabali Campus']);
        $this->bazal = Campus::create(['campus_name' => 'ASCOT Bazal Campus']);
        $make = fn (string $role, Campus $c, string $email) => Personnel::forceCreate([
            'campus_id' => $c->campus_id, 'full_name' => "$role Person", 'role' => $role, 'email' => $email,
            'password_hash' => Hash::make('password'), 'is_approved' => true, 'email_verified_at' => now(),
        ]);
        $this->reporter = $make('Reporter', $this->zabali, 'student@example.test');
        $this->coord = $make('Coordinator', $this->zabali, 'coord@example.test');
    }

    protected function drill(Campus $campus, string $date, string $venue): Drill
    {
        return Drill::forceCreate(['campus_id' => $campus->campus_id, 'drill_type' => 'Earthquake', 'scheduled_date' => $date,
            'scheduled_time' => '09:00:00', 'venue' => $venue, 'description' => 'Walk to the oval.']);
    }

    protected function area(Campus $campus, string $name, int $likelihood, int $impact): Building
    {
        $building = Building::create(['campus_id' => $campus->campus_id, 'building_name' => $name, 'hazard_type' => 'Flood',
            'latitude' => 15.74, 'longitude' => 121.55]);
        (new HazardInspection(['building_id' => $building->building_id, 'inspection_date' => '2026-10-10', 'findings' => "Secret note on $name"]))
            ->rate($likelihood, $impact)->save();

        return $building;
    }

    public function test_alerts_list_only_upcoming_drills_of_their_own_campus(): void
    {
        $this->drill($this->zabali, '2026-10-21', 'Zabali Gym');
        $this->drill($this->zabali, '2026-10-01', 'Past Field');
        $this->drill($this->bazal, '2026-10-22', 'Bazal Court');

        $this->actingAs($this->reporter)->get(route('app.alerts'))->assertOk()
            ->assertSee('data-reporter-drills', false)->assertSee('Zabali Gym')->assertSee('Walk to the oval.')
            ->assertDontSee('Past Field')->assertDontSee('Bazal Court');
        $this->actingAs($this->reporter)->get(route('app.home'))->assertOk()->assertSee('Next drill')->assertSee('Zabali Gym');
    }

    public function test_map_lists_hazardous_areas_moderate_and_above_without_findings(): void
    {
        $this->area($this->zabali, 'Hill Road', 5, 4);   // 20 Very High
        $this->area($this->zabali, 'Old Annex', 3, 2);   // 6 Moderate
        $this->area($this->zabali, 'Library', 1, 2);     // 2 Low: not listed
        $this->area($this->bazal, 'Bazal Lab', 5, 5);    // other campus

        $html = $this->actingAs($this->reporter)->get(route('app.map'))->assertOk()->getContent();

        $this->assertStringContainsString('data-reporter-hazards', $html);
        $this->assertStringContainsString('Hill Road', $html);
        $this->assertStringContainsString('Old Annex', $html);
        $this->assertStringNotContainsString('Library', $html);
        $this->assertStringNotContainsString('Bazal Lab', $html);
        $this->assertStringNotContainsString('Secret note', $html);
        $this->assertLessThan(strpos($html, 'Old Annex'), strpos($html, 'Hill Road'), 'worst first');
        $this->actingAs($this->reporter)->get(route('app.home'))->assertSee('2 hazardous areas on your campus');
    }

    public function test_scheduling_a_drill_notifies_the_campus_reporters_and_opens_it_in_alerts(): void
    {
        $this->actingAs($this->coord)->post(route('coordinator.drills.store'), [
            'drill_type' => 'Fire', 'scheduled_date' => '2026-10-25', 'scheduled_time' => '14:00', 'venue' => 'Engineering Building',
        ])->assertRedirect();
        $drill = Drill::firstOrFail();

        $notice = StaffNotification::where('personnel_id', $this->reporter->personnel_id)->where('kind', 'drill')->firstOrFail();
        $this->assertStringContainsString('Fire', $notice->message);
        $this->actingAs($this->reporter)->post(route('app.notifications.open', $notice))
            ->assertRedirect(route('app.alerts') . '#drill-' . $drill->drill_id);

        // A drill recorded after it was held: no notice.
        $this->actingAs($this->coord)->post(route('coordinator.drills.store'), [
            'drill_type' => 'Fire', 'scheduled_date' => '2026-10-02', 'scheduled_time' => '14:00', 'venue' => 'Old Field',
        ])->assertRedirect();
        $this->assertSame(1, StaffNotification::where('kind', 'drill')->count());
    }

    public function test_the_app_help_is_public_stored_offline_and_carries_no_personal_data(): void
    {
        $this->get(route('app.help'))->assertOk()->assertSee('data-reporter-help', false)
            ->assertSee('Roll call: tell the office you are safe')->assertSee('Map and hazardous areas');
        $this->actingAs($this->reporter)->get(route('app.help'))->assertOk()->assertDontSee('Reporter Person');
        $this->actingAs($this->reporter)->get(route('app.me'))->assertSee(route('app.help'), false);
        $this->assertStringContainsString("'/app/help'", file_get_contents(public_path('sw.js')));
    }
}
