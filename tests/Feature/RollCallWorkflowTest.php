<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Campus;
use App\Models\Personnel;
use App\Models\RollCall;
use App\Models\StaffNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class RollCallWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private Personnel $coordinator;

    private Personnel $otherCoordinator;

    private Personnel $reporter;

    private Personnel $second;

    protected function setUp(): void
    {
        parent::setUp();
        $campus = Campus::create(['campus_name' => 'ASCOT Zabali Campus']);
        $other = Campus::create(['campus_name' => 'ASCOT Bazal Campus']);
        $this->coordinator = $this->person('Coordinator', $campus, 'Coordinator One');
        $this->otherCoordinator = $this->person('Coordinator', $other, 'Coordinator Two');
        $this->reporter = $this->person('Reporter', $campus, 'Student One');
        $this->second = $this->person('Reporter', $campus, 'Student Two');
    }

    private function person(string $role, Campus $campus, string $name): Personnel
    {
        return Personnel::forceCreate(['campus_id' => $campus->getKey(), 'full_name' => $name, 'role' => $role,
            'email' => str_replace(' ', '', $name).'@example.test', 'password_hash' => Hash::make('password'),
            'email_verified_at' => now(), 'is_approved' => true]);
    }

    private function start(): RollCall
    {
        $this->actingAs($this->coordinator)->post(route('coordinator.roll-calls.store'), [
            'campus_id' => $this->coordinator->campus_id, 'title' => 'Heavy rain safety check',
        ])->assertRedirect();

        return RollCall::sole();
    }

    public function test_roster_is_frozen_and_reporters_can_update_once_per_person_until_closed(): void
    {
        $rollCall = $this->start();
        $this->assertDatabaseCount('roll_call_responses', 2);
        $late = $this->person('Reporter', $this->coordinator->campus, 'Late Student');
        $this->actingAs($late)->postJson(route('api.reporter.roll-calls.respond', $rollCall), ['answer' => 'Safe'])->assertNotFound();

        $this->actingAs($this->reporter)->get('/app')->assertOk()->assertSee('data-roll-call-banner', false);
        $this->get(route('app.roll-calls.show', $rollCall))->assertOk()->assertSee('Heavy rain safety check');
        $url = route('api.reporter.roll-calls.respond', $rollCall);
        $this->postJson($url, ['answer' => 'Help', 'note' => 'Library stairs are blocked.', 'latitude' => 15.7, 'longitude' => 121.5])->assertOk();
        $this->postJson($url, ['answer' => 'Help', 'note' => 'Library stairs are blocked.'])->assertOk();
        $this->assertDatabaseCount('roll_call_responses', 2);
        $this->assertSame(1, StaffNotification::where('kind', 'roll_call')->count(), 'a retried Help answer does not repeat the notification');
        $this->postJson($url, ['answer' => 'Safe'])->assertOk();
        $this->assertDatabaseHas('roll_call_responses', ['reporter_id' => $this->reporter->getKey(), 'answer' => 'Safe', 'latitude' => null]);

        $this->actingAs($this->coordinator)->get(route('coordinator.roll-calls.show', $rollCall))->assertOk()->assertSee('No response');
        $this->post(route('coordinator.roll-calls.close', $rollCall))->assertRedirect();
        $this->actingAs($this->reporter)->postJson($url, ['answer' => 'Help'])->assertUnprocessable();
        $this->get(route('app.roll-calls.show', $rollCall))->assertOk()->assertSee('This roll call is closed');
        $this->get('/app')->assertOk()->assertDontSee('data-roll-call-banner', false);
    }

    public function test_staff_scope_export_and_one_open_roll_call_per_campus(): void
    {
        $rollCall = $this->start();
        $this->post(route('coordinator.roll-calls.store'), ['campus_id' => $this->coordinator->campus_id, 'title' => 'Retry'])->assertRedirect();
        $this->assertDatabaseCount('roll_calls', 1);
        $this->actingAs($this->otherCoordinator)->get(route('coordinator.roll-calls.show', $rollCall))->assertNotFound();
        $this->post(route('coordinator.roll-calls.close', $rollCall))->assertNotFound();
        $this->post(route('coordinator.roll-calls.store'), ['campus_id' => $this->coordinator->campus_id, 'title' => 'Wrong campus'])->assertForbidden();
        $this->actingAs($this->reporter)->get(route('coordinator.roll-calls.index'))->assertForbidden();
        $this->reporter->forceFill(['role' => 'Coordinator'])->save();
        $this->second->delete();
        $this->assertDatabaseCount('roll_call_responses', 2);
        $csv = $this->actingAs($this->coordinator)->get(route('coordinator.roll-calls.export', $rollCall))->assertOk()->streamedContent();
        $this->assertStringContainsString('Student Two', $csv);
        $this->assertStringContainsString('No response', $csv);
        $this->assertTrue(ActivityLog::where('action', 'roll_call_exported')->exists());
        $notice = StaffNotification::create(['personnel_id' => $this->coordinator->getKey(), 'campus_id' => $this->coordinator->campus_id,
            'kind' => 'roll_call', 'subject_id' => $rollCall->getKey(), 'message' => 'Needs help']);
        $this->post(route('coordinator.notifications.open', $notice))->assertRedirect(route('coordinator.roll-calls.show', $rollCall));
    }
}
