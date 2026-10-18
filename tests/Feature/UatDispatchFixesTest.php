<?php

namespace Tests\Feature;

use App\Models\Campus;
use App\Models\Personnel;
use App\Models\Reporter\CitizenReport;
use App\Models\Reporter\SosEvent;
use App\Models\Responder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * UAT 2026-10-18 (3.11): Dispatch's "Report or SOS" list only held Verified
 * reports and Acknowledged SOS, while everything from the Reporter app
 * starts as New, so the list was empty. New cases are listed now, and
 * dispatching one verifies (or acknowledges) it on the record.
 */
class UatDispatchFixesTest extends TestCase
{
    use RefreshDatabase;

    private Campus $campus;

    private Personnel $coordinator;

    private Personnel $reporter;

    private Responder $responder;

    protected function setUp(): void
    {
        parent::setUp();
        $this->campus = Campus::create(['campus_name' => 'ASCOT Zabali Campus']);
        $this->coordinator = Personnel::create(['campus_id' => $this->campus->getKey(), 'full_name' => 'Coordinator Test',
            'role' => 'Coordinator', 'email' => 'coord@example.test', 'password_hash' => Hash::make('password')]);
        $this->reporter = Personnel::create(['campus_id' => $this->campus->getKey(), 'full_name' => 'Student Test',
            'role' => 'Reporter', 'email' => 'student@example.test', 'password_hash' => Hash::make('password')]);
        $this->responder = Responder::create(['campus_id' => $this->campus->getKey(), 'full_name' => 'Clinic Responder', 'team' => 'Clinic', 'contact_number' => '09171234567']);
    }

    private function report(string $status = 'New', ?Campus $campus = null): CitizenReport
    {
        return CitizenReport::create(['campus_id' => ($campus ?? $this->campus)->getKey(), 'reporter_id' => $this->reporter->getKey(),
            'client_key' => (string) Str::uuid(), 'category' => 'fire', 'description' => 'Smoke near the canteen kitchen.', 'place' => 'Canteen', 'status' => $status]);
    }

    private function sos(string $status = 'New'): SosEvent
    {
        return SosEvent::create(['reporter_id' => $this->reporter->getKey(), 'campus_id' => $this->campus->getKey(),
            'client_key' => (string) Str::uuid(), 'type' => 'Medical', 'status' => $status]);
    }

    public function test_new_reports_and_sos_from_the_campus_can_be_chosen(): void
    {
        $report = $this->report();
        $sos = $this->sos();
        $foreign = $this->report('New', Campus::create(['campus_name' => 'ASCOT Bazal Campus']));

        $this->actingAs($this->coordinator)->get(route('coordinator.dispatch.index'))->assertOk()
            ->assertSee('value="report:'.$report->getKey().'"', false)
            ->assertSee('value="sos:'.$sos->getKey().'"', false)
            ->assertDontSee('value="report:'.$foreign->getKey().'"', false);
    }

    public function test_an_empty_list_says_so_instead_of_showing_bare_headings(): void
    {
        $this->actingAs($this->coordinator)->get(route('coordinator.dispatch.index'))->assertOk()
            ->assertSee('No open reports or SOS right now')
            ->assertDontSee('<optgroup label="SOS"></optgroup>', false);
    }

    public function test_dispatching_a_new_report_verifies_it_on_the_record(): void
    {
        $report = $this->report();

        $this->actingAs($this->coordinator)->postJson('/api/command/assignments', ['target' => 'report:'.$report->getKey(), 'responder_id' => $this->responder->getKey()])
            ->assertOk();

        $this->assertSame('Responding', $report->fresh()->status);
        $this->assertSame(['Verified', 'Responding'], $report->events()->pluck('status')->all());
    }

    public function test_dispatching_a_new_sos_acknowledges_it(): void
    {
        $sos = $this->sos();

        $this->actingAs($this->coordinator)->postJson('/api/command/assignments', ['target' => 'sos:'.$sos->getKey(), 'responder_id' => $this->responder->getKey()])
            ->assertOk();

        $sos->refresh();
        $this->assertSame('Responding', $sos->status);
        $this->assertNotNull($sos->acknowledged_at);
        $this->assertSame($this->coordinator->getKey(), (int) $sos->acknowledged_by);
    }

    public function test_closed_cases_still_cannot_be_dispatched(): void
    {
        $rejected = $this->report('Rejected');

        $this->actingAs($this->coordinator)->postJson('/api/command/assignments', ['target' => 'report:'.$rejected->getKey(), 'responder_id' => $this->responder->getKey()])
            ->assertUnprocessable();
    }
}
