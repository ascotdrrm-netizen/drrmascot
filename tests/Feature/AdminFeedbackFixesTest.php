<?php

namespace Tests\Feature;

use App\Models\Campus;
use App\Models\Drill\Drill;
use App\Models\Hotline\HotlineGroup;
use App\Models\Personnel;
use App\Models\Reporter\CitizenReport;
use App\Models\Reporter\SosEvent;
use App\Models\Responder;
use App\Support\DataPrivacyPassword;
use App\Support\Hotlines;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * The DRRMO's Admin/Coordinator test round (Oct 2026): deleted reporters,
 * SOS location/reject/delete, responder details, drill schedule times and
 * duplicates, no negative report figures, the in-app Data Privacy Password
 * and the coordinators' hotlines.
 */
class AdminFeedbackFixesTest extends TestCase
{
    use RefreshDatabase;

    private Campus $campus;

    private Personnel $admin;

    private Personnel $coordinator;

    private Personnel $reporter;

    protected function setUp(): void
    {
        parent::setUp();
        $this->campus = Campus::create(['campus_name' => 'ASCOT Zabali Campus']);
        $this->admin = Personnel::create(['campus_id' => $this->campus->getKey(), 'full_name' => 'Feedback Admin',
            'role' => 'DRRMO Admin', 'email' => 'fb-admin@example.test', 'password_hash' => Hash::make('admin-login-1')]);
        $this->coordinator = Personnel::create(['campus_id' => $this->campus->getKey(), 'full_name' => 'Feedback Coordinator',
            'role' => 'Coordinator', 'email' => 'fb-coord@example.test', 'password_hash' => Hash::make('password')]);
        $this->reporter = Personnel::create(['campus_id' => $this->campus->getKey(), 'full_name' => 'Feedback Student',
            'role' => 'Reporter', 'email' => 'fb-student@example.test', 'password_hash' => Hash::make('password')]);
    }

    private function sos(array $attributes = []): SosEvent
    {
        return SosEvent::create($attributes + ['campus_id' => $this->campus->getKey(), 'reporter_id' => $this->reporter->getKey(),
            'client_key' => (string) Str::uuid(), 'type' => 'Medical', 'status' => 'New']);
    }

    private function report(): CitizenReport
    {
        return CitizenReport::create(['campus_id' => $this->campus->getKey(), 'reporter_id' => $this->reporter->getKey(),
            'client_key' => (string) Str::uuid(), 'category' => 'medical', 'description' => 'Someone fainted at the library.',
            'place' => 'Library', 'status' => 'New']);
    }

    // ---------------------------------------------------- deleted reporters

    public function test_staff_can_act_on_a_deleted_reporters_sos_and_reports(): void
    {
        $sos = $this->sos();
        $verify = $this->report();
        $reject = $this->report();
        $this->reporter->delete();

        $this->actingAs($this->admin);
        $this->patchJson('/api/command/sos/' . $sos->getKey(), ['status' => 'Acknowledged'])->assertOk();
        $this->patchJson('/api/command/reports/' . $verify->getKey(), ['status' => 'Verified'])->assertOk();
        $this->patchJson('/api/command/reports/' . $reject->getKey(), ['status' => 'Rejected'])->assertOk();

        $this->assertSame('Acknowledged', $sos->fresh()->status);
        $this->assertSame('Rejected', $reject->fresh()->status);
        $this->get(route('admin.command.sos'))->assertOk()->assertSee('Former reporter');
    }

    // ---------------------------------------------------------- SOS console

    public function test_the_sos_console_shows_the_gps_fix_and_a_map_link(): void
    {
        $this->sos(['latitude' => 15.7361234, 'longitude' => 121.5490123, 'accuracy_m' => 12]);
        $this->sos(['client_key' => (string) Str::uuid()]);

        // The page embeds its alerts as JSON for Alpine; the live poll returns the same labels.
        $this->actingAs($this->admin)->get(route('admin.command.sos'))->assertOk()
            ->assertSee('15.73612, 121.54901', false)->assertSee('Open in Google Maps')
            ->assertSee('Location not shared');
        $this->getJson('/api/command/sos')->assertOk()
            ->assertJsonFragment(['location_label' => '15.73612, 121.54901 (±12 m)', 'map_url' => 'https://maps.google.com/?q=15.736123,121.549012'])
            ->assertJsonFragment(['location_label' => 'Location not shared', 'map_url' => null]);
    }

    public function test_an_sos_can_be_rejected_as_a_false_alarm(): void
    {
        $sos = $this->sos();
        $responder = Responder::create(['campus_id' => $this->campus->getKey(), 'full_name' => 'Guard', 'team' => 'Security', 'contact_number' => '09170000000']);
        $this->actingAs($this->coordinator)->postJson('/api/command/assignments', ['responder_id' => $responder->getKey(), 'target' => 'sos:' . $sos->getKey()])->assertOk();

        $this->patchJson('/api/command/sos/' . $sos->getKey(), ['status' => 'Rejected'])->assertOk();

        $this->assertSame('Rejected', $sos->fresh()->status);
        $this->assertSame('Cancelled', $responder->assignments()->first()->status);
        $this->getJson('/api/command/sos')->assertOk()->assertJsonCount(0);
        $this->assertDatabaseHas('staff_notifications', ['personnel_id' => $this->reporter->getKey(), 'kind' => 'sos_update']);
        // A closed SOS can't be rejected again.
        $this->patchJson('/api/command/sos/' . $sos->getKey(), ['status' => 'Rejected'])->assertUnprocessable();
    }

    public function test_a_deleted_sos_goes_to_the_archive_and_can_be_restored(): void
    {
        $sos = $this->sos();

        $this->actingAs($this->admin)->deleteJson('/api/command/sos/' . $sos->getKey())->assertOk();
        $this->assertSoftDeleted('sos_events', ['sos_id' => $sos->getKey()]);
        $this->getJson('/api/command/sos')->assertJsonCount(0);
        $this->get(route('admin.archive.index', ['type' => 'sos']))->assertOk()->assertSee('SOS #' . $sos->getKey());

        $this->post(route('admin.sos.restore', $sos->getKey()))->assertRedirect();
        $this->assertNotSoftDeleted('sos_events', ['sos_id' => $sos->getKey()]);
    }

    public function test_a_coordinator_cannot_delete_another_campus_sos(): void
    {
        $other = Campus::create(['campus_name' => 'ASCOT Bazal Campus']);
        $sos = $this->sos(['campus_id' => $other->getKey()]);

        $this->actingAs($this->coordinator)->deleteJson('/api/command/sos/' . $sos->getKey())->assertNotFound();
        $this->assertNotSoftDeleted('sos_events', ['sos_id' => $sos->getKey()]);
    }

    // ------------------------------------------------------------- dispatch

    public function test_responders_carry_position_and_post_and_show_where_they_are_assigned(): void
    {
        $this->actingAs($this->admin)->postJson('/api/command/responders', ['campus_id' => $this->campus->getKey(),
            'full_name' => 'Ana Cruz', 'position' => 'Campus nurse', 'team' => 'Clinic', 'assigned_post' => 'Clinic, Admin building',
            'contact_number' => '09171112222'])->assertCreated();
        $responder = Responder::firstOrFail();
        $this->putJson('/api/command/responders/' . $responder->getKey(), ['full_name' => 'Ana Cruz', 'position' => 'Head nurse',
            'team' => 'Clinic', 'assigned_post' => 'Main gate', 'contact_number' => '09171112222'])->assertOk();
        $report = $this->report();
        $this->postJson('/api/command/assignments', ['responder_id' => $responder->getKey(), 'target' => 'report:' . $report->getKey()])->assertOk();

        $this->get(route('admin.dispatch.index'))->assertOk()
            ->assertSee('Head nurse')->assertSee('Main gate')
            ->assertSee('Now assigned to:')->assertSee('Report #' . $report->getKey() . ' · Injury / medical · Library', false);
    }

    // ---------------------------------------------------------------- drills

    public function test_a_drill_time_reads_on_the_drills_own_date(): void
    {
        $this->travelTo(Carbon::parse('2026-10-21 08:00:00', 'Asia/Manila'));
        $drill = Drill::forceCreate(['campus_id' => $this->campus->getKey(), 'drill_type' => 'Fire',
            'scheduled_date' => '2026-12-05', 'scheduled_time' => '09:00', 'venue' => 'Gym']);
        $drill = $drill->fresh();

        $this->assertSame('2026-12-05 09:00', $drill->scheduled_time->format('Y-m-d H:i'));
        $this->assertSame('09:00', $drill->toArray()['scheduled_time']);
        $this->assertFalse($drill->hasStarted());
    }

    public function test_a_drill_later_today_is_not_held_yet(): void
    {
        $this->travelTo(Carbon::parse('2026-10-21 08:00:00', 'Asia/Manila'));
        $drill = Drill::forceCreate(['campus_id' => $this->campus->getKey(), 'drill_type' => 'Fire',
            'scheduled_date' => '2026-10-21', 'scheduled_time' => '15:00', 'venue' => 'Gym', 'created_by' => $this->admin->getKey()]);

        $this->assertFalse($drill->fresh()->hasStarted());
        $this->actingAs($this->admin)->get(route('admin.drills.documentation.edit', $drill))->assertOk()
            ->assertSee('name="date_conducted"', false)->assertDontSee('value="2026-10-21"', false);

        $this->travelTo(Carbon::parse('2026-10-21 15:30:00', 'Asia/Manila'));
        $this->assertTrue($drill->fresh()->hasStarted());
    }

    public function test_editing_a_drill_into_a_copy_of_another_is_refused(): void
    {
        $this->travelTo(Carbon::parse('2026-10-20 10:00:00', 'Asia/Manila'));
        $fields = ['drill_type' => 'Fire', 'scheduled_date' => '2026-11-10', 'scheduled_time' => '09:00', 'venue' => 'Gym'];
        $this->actingAs($this->coordinator)->post(route('coordinator.drills.store'), $fields)->assertRedirect();
        $this->post(route('coordinator.drills.store'), ['scheduled_time' => '13:00', 'confirm_same_day' => '1'] + $fields)->assertRedirect();
        $second = Drill::where('scheduled_time', '13:00:00')->firstOrFail();

        $this->put(route('coordinator.drills.update', $second), $fields)->assertSessionHasErrors('scheduled_date');
        $this->assertSame('13:00', $second->fresh()->scheduled_time->format('H:i'));
        // Saving a drill unchanged is not a "duplicate of itself".
        $this->put(route('coordinator.drills.update', $second), ['scheduled_time' => '13:00'] + $fields)->assertSessionHasNoErrors();
    }

    public function test_one_drill_for_several_campuses_shows_once_on_the_admin_calendar(): void
    {
        $this->travelTo(Carbon::parse('2026-10-20 10:00:00', 'Asia/Manila'));
        $bazal = Campus::create(['campus_name' => 'ASCOT Bazal Campus']);
        $this->actingAs($this->admin)->post(route('admin.drills.store'), ['campus_ids' => [$this->campus->getKey(), $bazal->getKey()],
            'drill_type' => 'Earthquake', 'scheduled_date' => '2026-10-28', 'scheduled_time' => '09:00', 'venue' => 'Covered Court'])->assertRedirect();
        $this->assertSame(2, Drill::count());

        $this->get(route('admin.drills.index', ['month' => '2026-10']))->assertOk()
            ->assertSee('data-drill-group="2"', false)->assertSee('2 campuses');
        // One campus picked: its own drill, no grouping.
        $this->get(route('admin.drills.index', ['month' => '2026-10', 'campus_id' => $bazal->getKey()]))->assertOk()
            ->assertDontSee('data-drill-group', false);
    }

    // -------------------------------------------------- consolidated report

    public function test_a_smaller_period_prints_down_not_a_minus_number(): void
    {
        $this->travelTo(Carbon::parse('2026-10-21 10:00:00', 'Asia/Manila'));
        // Two SOS last month, none this month.
        foreach (['2026-09-10 09:00:00', '2026-09-11 09:00:00'] as $at) {
            $sos = $this->sos();
            DB::table('sos_events')->where('sos_id', $sos->getKey())->update(['created_at' => $at]);
        }

        $html = $this->actingAs($this->admin)->get(route('reports.consolidated.index', ['period_type' => 'monthly', 'month' => 10, 'year' => 2026]))
            ->assertOk()->assertSee('Down 2 (100%)')->getContent();
        $this->assertDoesNotMatchRegularExpression('/data-change="[a-z]+">\s*-/', $html);
    }

    // ------------------------------------------------ Data Privacy Password

    public function test_the_admin_sets_the_data_privacy_password_in_the_app(): void
    {
        config(['privacy.data_privacy_password_hash' => null]);
        $this->actingAs($this->admin)->get(route('admin.backup.index'))->assertOk()
            ->assertSee('Set the Data Privacy Password')->assertDontSee('DATA_PRIVACY_PASSWORD_HASH');

        $set = fn (array $data) => $this->post(route('admin.backup.privacy-password'), $data);
        $set(['account_password' => 'wrong', 'new_data_privacy_password' => 'privacy-123', 'new_data_privacy_password_confirmation' => 'privacy-123'])
            ->assertSessionHasErrors('account_password');
        $set(['account_password' => 'admin-login-1', 'new_data_privacy_password' => 'admin-login-1', 'new_data_privacy_password_confirmation' => 'admin-login-1'])
            ->assertSessionHasErrors('new_data_privacy_password');
        $set(['account_password' => 'admin-login-1', 'new_data_privacy_password' => 'privacy-123', 'new_data_privacy_password_confirmation' => 'privacy-123'])
            ->assertRedirect(route('admin.backup.index'));

        $this->assertTrue(DataPrivacyPassword::check('privacy-123'));
        $this->assertDatabaseMissing('settings', ['key' => DataPrivacyPassword::KEY]);
        $this->assertNotContains('system_secrets', \App\Support\Backup\DataSet::tables());

        // Changing it needs the current one.
        $set(['data_privacy_password' => 'nope', 'new_data_privacy_password' => 'privacy-456', 'new_data_privacy_password_confirmation' => 'privacy-456'])
            ->assertSessionHasErrors('data_privacy_password');
        $set(['data_privacy_password' => 'privacy-123', 'new_data_privacy_password' => 'privacy-456', 'new_data_privacy_password_confirmation' => 'privacy-456'])
            ->assertRedirect();
        $this->assertTrue(DataPrivacyPassword::check('privacy-456'));
    }

    // ------------------------------------------------------- damage reports

    public function test_a_damage_reports_building_or_area_is_typed(): void
    {
        $incident = \App\Models\Incident\Incident::create(['campus_id' => $this->campus->getKey(), 'hazard_type' => 'Fire',
            'date_occurred' => '2026-10-20', 'status' => 'Active']);
        $gym = \App\Models\Building\Building::forceCreate(['campus_id' => $this->campus->getKey(), 'building_name' => 'Main Gym']);
        $post = fn (string $area) => $this->actingAs($this->admin)->post(route('admin.incidents.damage-reports.store', $incident),
            ['area_name' => $area, 'damage_level' => 'Minor', 'casualty_count' => 1]);

        $this->actingAs($this->admin)->get(route('admin.incidents.damage-reports.create', $incident))->assertOk()
            ->assertSee('name="area_name"', false)->assertDontSee('name="building_id"', false);

        // A building on the campus is linked; anything else is kept as typed.
        $post('  main   gym ')->assertSessionHasNoErrors();
        $post('Parking area behind the canteen')->assertSessionHasNoErrors();
        $post('MAIN GYM')->assertSessionHasErrors('area_name');

        $reports = $incident->damageReports()->orderBy('report_id')->get();
        $this->assertSame([$gym->building_id, null], $reports->pluck('building_id')->all());
        $this->assertSame(['main gym', 'Parking area behind the canteen'], $reports->pluck('area_name')->all());
        $this->get(route('admin.incidents.show', $incident))->assertOk()
            ->assertSee('Parking area behind the canteen')->assertSee('2 buildings affected');

        // Editing keeps the report as one, and may rename it.
        $this->put(route('admin.incidents.damage-reports.update', [$incident, $reports[1]]),
            ['area_name' => 'Canteen parking', 'damage_level' => 'Major', 'casualty_count' => 0])->assertSessionHasNoErrors();
        $this->assertSame('Canteen parking', $reports[1]->fresh()->area_name);
    }

    // ------------------------------------------------------------- hotlines

    public function test_a_coordinator_sees_every_hotline_group_and_edits_their_own(): void
    {
        // The hotline migration ran before this campus existed: nothing is linked yet.
        $this->assertSame(0, HotlineGroup::whereNotNull('campus_id')->count());
        Hotlines::linkCampuses();
        $own = HotlineGroup::where('campus_id', $this->campus->getKey())->firstOrFail();
        $other = HotlineGroup::where('title', 'Aurora Province')->firstOrFail();

        $this->actingAs($this->coordinator)->get(route('coordinator.hotlines.index'))->assertOk()
            ->assertSee('Aurora Province')->assertSee($own->title)
            ->assertSee(route('coordinator.hotlines.edit', $own), false)
            ->assertDontSee(route('coordinator.hotlines.edit', $other), false);
        $this->get(route('coordinator.hotlines.edit', $own))->assertOk();
        $this->get(route('coordinator.hotlines.edit', $other))->assertForbidden();
    }
}
