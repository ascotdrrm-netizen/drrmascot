<?php

namespace Tests\Feature;

use App\Models\Advisory\DisasterAdvisory;
use App\Models\Campus;
use App\Models\Personnel;
use App\Models\StaffNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * UAT 2026-10-18 (2.15): an advisory showed in the Reporter's Alerts list but
 * never raised the unread badge, because issuing one created no notice.
 */
class UatAdvisoryNoticeTest extends TestCase
{
    use RefreshDatabase;

    private Campus $zabali;

    private Campus $bazal;

    private Personnel $admin;

    private Personnel $coordinator;

    private Personnel $zabaliReporter;

    private Personnel $bazalReporter;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-10-10 09:00:00', 'Asia/Manila'));
        $this->zabali = Campus::create(['campus_name' => 'ASCOT Zabali Campus']);
        $this->bazal = Campus::create(['campus_name' => 'ASCOT Bazal Campus']);
        $make = fn (Campus $c, string $role, string $email) => Personnel::create(['campus_id' => $c->campus_id, 'full_name' => $role.' Person',
            'role' => $role, 'email' => $email, 'password_hash' => Hash::make('password')]);
        $this->admin = $make($this->zabali, 'DRRMO Admin', 'admin@example.test');
        $this->coordinator = $make($this->zabali, 'Coordinator', 'coord@example.test');
        $this->zabaliReporter = $make($this->zabali, 'Reporter', 'zabali.reporter@example.test');
        $this->bazalReporter = $make($this->bazal, 'Reporter', 'bazal.reporter@example.test');
    }

    private function typhoon(array $overrides = []): array
    {
        return array_replace([
            'hazard_type' => 'Typhoon', 'headline' => 'Typhoon Egay over Aurora',
            'measurements' => ['storm_name' => 'Egay', 'wind_signal' => '2', 'max_winds' => ''],
            'source' => 'PAGASA', 'bulletin_reference' => 'TCB #12',
            'issued_at' => '2026-10-10T08:00', 'expected_at' => '2026-10-10T18:00', 'valid_until' => '2026-10-11T08:00',
            'instructions' => 'Classes are suspended. Stay indoors and follow your building warden.',
        ], $overrides);
    }

    public function test_a_campus_advisory_notifies_that_campus_reporters_and_raises_the_badge(): void
    {
        $this->actingAs($this->coordinator)->post(route('coordinator.advisories.store'), $this->typhoon())->assertRedirect();

        $notice = StaffNotification::where('personnel_id', $this->zabaliReporter->getKey())->where('kind', 'advisory')->sole();
        $this->assertStringContainsString('Typhoon Egay over Aurora', $notice->message);
        $this->assertFalse(StaffNotification::where('personnel_id', $this->bazalReporter->getKey())->exists());
        $this->actingAs($this->zabaliReporter)->getJson(route('api.reporter.notifications'))->assertJsonPath('unread', 1);
    }

    public function test_an_all_campus_advisory_reaches_every_campus_and_opening_clears_the_badge(): void
    {
        $this->actingAs($this->admin)->post(route('admin.advisories.store'), $this->typhoon(['campus_id' => 'all']))->assertRedirect();
        $advisory = DisasterAdvisory::sole();

        $notice = StaffNotification::where('personnel_id', $this->bazalReporter->getKey())->sole();
        $this->assertSame($this->bazal->campus_id, (int) $notice->campus_id);
        $this->assertTrue(StaffNotification::where('personnel_id', $this->zabaliReporter->getKey())->exists());

        $this->actingAs($this->bazalReporter)->post(route('app.notifications.open', $notice))
            ->assertRedirect(route('app.alerts').'#advisory-'.$advisory->advisory_id);
        $this->getJson(route('api.reporter.notifications'))->assertJsonPath('unread', 0);
    }
}
