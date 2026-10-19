<?php

namespace Tests\Feature;

use App\Models\Campus;
use App\Models\Personnel;
use App\Models\StaffNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * UAT 2026-10-18: a published campus status showed on the dashboard, but
 * tapping it (or its notice) led to a page with no record and no details.
 */
class UatCampusStatusNoticeTest extends TestCase
{
    use RefreshDatabase;

    private Campus $home;

    private Personnel $coordinator;

    private Personnel $reporter;

    protected function setUp(): void
    {
        parent::setUp();
        $this->home = Campus::create(['campus_name' => 'ASCOT Zabali Campus']);
        $this->coordinator = $this->person('Coordinator', $this->home, 'coord@example.test');
        $this->reporter = $this->person('Reporter', $this->home, 'reporter@example.test');
        $this->reporter->forceFill(['email_verified_at' => now(), 'validated_by_coordinator_at' => now()])->save();
    }

    private function publish(string $status, ?string $detail): void
    {
        $this->actingAs($this->coordinator)->putJson("/api/command/campuses/{$this->home->campus_id}/status", ['status' => $status, 'detail' => $detail])->assertOk();
    }

    public function test_publishing_a_status_notifies_the_campus_reporters(): void
    {
        $other = $this->person('Reporter', Campus::create(['campus_name' => 'ASCOT Bazal Campus']), 'other@example.test');

        $this->publish('evacuate', 'Leave by the main gate and go to the oval.');

        $notice = StaffNotification::where('personnel_id', $this->reporter->getKey())->where('kind', 'campus_status')->sole();
        $this->assertStringContainsString('Evacuate', $notice->message);
        $this->assertStringContainsString('Leave by the main gate', $notice->message);
        $this->assertFalse(StaffNotification::where('personnel_id', $other->getKey())->exists());
    }

    public function test_the_alerts_page_shows_the_status_and_its_instruction(): void
    {
        $this->publish('suspended', 'Classes are suspended until further notice.');

        $this->actingAs($this->reporter)->get(route('app.alerts'))->assertOk()
            ->assertSee('id="campus-status"', false)
            ->assertSee('Classes suspended')
            ->assertSee('Classes are suspended until further notice.');
    }

    public function test_opening_the_notice_leads_to_the_status(): void
    {
        $this->publish('advisory', 'Typhoon signal no. 1: stay alert.');
        $notice = StaffNotification::where('personnel_id', $this->reporter->getKey())->sole();

        $this->actingAs($this->reporter)->post(route('app.notifications.open', $notice))
            ->assertRedirect(route('app.alerts').'#campus-status');
        $this->assertNotNull($notice->fresh()->read_at);
    }

    public function test_the_home_banner_links_straight_to_the_status(): void
    {
        $this->publish('evacuate', 'Leave by the main gate.');

        $this->actingAs($this->reporter)->get('/app')->assertOk()
            ->assertSee('href="'.route('app.alerts').'#campus-status"', false);
    }

    public function test_republishing_the_same_status_does_not_notify_twice(): void
    {
        $this->publish('advisory', 'Typhoon signal no. 1: stay alert.');
        $this->publish('advisory', 'Typhoon signal no. 1: stay alert.');

        $this->assertSame(1, StaffNotification::where('personnel_id', $this->reporter->getKey())->count());
    }

    public function test_saving_normal_on_a_campus_with_no_status_yet_notifies_no_one(): void
    {
        $this->publish('normal', null);

        $this->assertSame(0, StaffNotification::where('kind', 'campus_status')->count());
    }

    private function person(string $role, Campus $campus, string $email): Personnel
    {
        return Personnel::create(['campus_id' => $campus->campus_id, 'full_name' => $role.' Test',
            'role' => $role, 'email' => $email, 'password_hash' => Hash::make('password')]);
    }
}
