<?php

namespace Tests\Feature;

use App\Models\Assignment;
use App\Models\Campus;
use App\Models\Personnel;
use App\Models\Reporter\CitizenReport;
use App\Models\Reporter\SosEvent;
use App\Models\Responder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

class DispatchWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private Personnel $coordinator;

    private Personnel $reporter;

    private Responder $responder;

    protected function setUp(): void
    {
        parent::setUp();
        $campus = Campus::create(['campus_name' => 'ASCOT Zabali Campus']);
        $this->coordinator = Personnel::create(['campus_id' => $campus->getKey(), 'full_name' => 'Coordinator Test',
            'role' => 'Coordinator', 'email' => 'coord@example.test', 'password_hash' => Hash::make('password')]);
        $this->reporter = Personnel::create(['campus_id' => $campus->getKey(), 'full_name' => 'Student Test',
            'role' => 'Reporter', 'email' => 'student@example.test', 'password_hash' => Hash::make('password')]);
        $this->responder = Responder::create(['campus_id' => $campus->getKey(), 'full_name' => 'Clinic Responder', 'team' => 'Clinic', 'contact_number' => '09171234567']);
    }

    private function report(string $status = 'Verified'): CitizenReport
    {
        return CitizenReport::create(['campus_id' => $this->reporter->campus_id, 'reporter_id' => $this->reporter->getKey(),
            'client_key' => (string) Str::uuid(), 'category' => 'medical', 'description' => 'Student needs assistance at the library.', 'place' => 'Library', 'status' => $status]);
    }

    public function test_assignment_progress_idempotency_availability_and_response_metric(): void
    {
        $report = $this->report();
        $data = ['kind' => 'report', 'subject_id' => $report->getKey(), 'responder_id' => $this->responder->getKey()];
        $this->actingAs($this->coordinator);
        $id = $this->postJson('/api/command/assignments', $data)->assertOk()->json('id');
        $this->postJson('/api/command/assignments', $data)->assertOk()->assertJsonPath('id', $id);
        $this->assertDatabaseCount('assignments', 1);
        $this->assertSame('Responding', $report->fresh()->status);
        $another = $this->report();
        $this->postJson('/api/command/assignments', array_replace($data, ['subject_id' => $another->getKey()]))->assertUnprocessable();
        $this->patchJson("/api/command/assignments/{$id}", ['status' => 'Completed'])->assertUnprocessable();
        $this->travel(5)->minutes();
        $this->patchJson("/api/command/assignments/{$id}", ['status' => 'On scene'])->assertOk();
        $this->patchJson("/api/command/assignments/{$id}", ['status' => 'Completed'])->assertOk();
        $this->get(route('coordinator.dispatch.index'))->assertOk()->assertViewHas('arrivalMinutes', 5.0);
        $this->assertSame('Responding', $report->fresh()->status, 'completing one assignment does not close the whole case');
        $this->postJson('/api/command/assignments', array_replace($data, ['subject_id' => $another->getKey()]))->assertOk();
        $this->patchJson('/api/command/reports/'.$another->getKey(), ['status' => 'Resolved'])->assertOk();
        $this->assertSame(0, Assignment::whereIn('status', Assignment::ACTIVE)->count(), 'case closure releases the responder');
    }

    public function test_campus_role_duty_and_verification_gates_apply_to_dispatch(): void
    {
        $report = $this->report('New');
        $data = ['target' => 'report:'.$report->getKey(), 'responder_id' => $this->responder->getKey()];
        $this->actingAs($this->reporter)->postJson('/api/command/assignments', $data)->assertForbidden();
        $this->actingAs($this->coordinator)->postJson('/api/command/assignments', $data)->assertUnprocessable();
        $report->update(['status' => 'Verified']);
        $this->responder->update(['is_on_duty' => false]);
        $this->postJson('/api/command/assignments', $data)->assertUnprocessable();
        $this->patchJson('/api/command/responders/'.$this->responder->getKey(), ['is_on_duty' => true])->assertOk();
        $other = Campus::create(['campus_name' => 'ASCOT Bazal Campus']);
        $this->postJson('/api/command/responders', ['campus_id' => $other->getKey(), 'full_name' => 'Other Responder', 'team' => 'Security', 'contact_number' => '09171234567'])->assertNotFound();
        $foreign = Responder::create(['campus_id' => $other->getKey(), 'full_name' => 'Other Responder', 'team' => 'Security', 'contact_number' => '09171234567']);
        $this->postJson('/api/command/assignments', array_replace($data, ['responder_id' => $foreign->getKey()]))->assertUnprocessable();
        $this->get(route('coordinator.dispatch.index'))->assertOk()->assertDontSee('Other Responder');
        $sos = SosEvent::create(['reporter_id' => $this->reporter->getKey(), 'campus_id' => $this->reporter->campus_id,
            'client_key' => (string) Str::uuid(), 'status' => 'Acknowledged']);
        $this->postJson('/api/command/assignments', ['kind' => 'sos', 'subject_id' => $sos->getKey(), 'responder_id' => $this->responder->getKey()])->assertOk();
        $this->assertSame('Responding', $sos->fresh()->status);
        $this->patchJson('/api/command/responders/'.$this->responder->getKey(), ['is_on_duty' => true, 'is_active' => false])->assertUnprocessable();
    }
}
