<?php

namespace Tests\Feature;

use App\Models\Campus;
use App\Models\Personnel;
use App\Models\Reporter\CitizenReport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class ReporterResponseApiTest extends TestCase
{
    use RefreshDatabase;

    private Personnel $reporter;
    private Personnel $coordinator;
    private Personnel $otherCoordinator;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('records');
        $home = Campus::create(['campus_name' => 'ASCOT Zabali Campus']);
        $other = Campus::create(['campus_name' => 'ASCOT Bazal Campus']);
        $this->reporter = $this->person('Reporter', $home->campus_id, 'reporter@example.test');
        $this->coordinator = $this->person('Coordinator', $home->campus_id, 'coordinator@example.test');
        $this->otherCoordinator = $this->person('Coordinator', $other->campus_id, 'other@example.test');
    }

    private function person(string $role, int $campus, string $email): Personnel
    {
        return Personnel::create(['campus_id' => $campus, 'full_name' => $role . ' Test',
            'role' => $role, 'email' => $email, 'password_hash' => Hash::make('password')]);
    }

    public function test_report_is_saved_once_with_photos_and_only_its_owner_can_read_it(): void
    {
        $payload = ['client_key' => (string) Str::uuid(), 'category' => 'flood', 'place' => 'Library walkway',
            'description' => 'Water is rising at the library walkway.', 'people_injured' => 0,
            'photos' => [UploadedFile::fake()->image('flood.jpg')], 'captions' => ['Water near the entrance']];

        $id = $this->actingAs($this->reporter)->postJson('/api/reporter/reports', $payload)
            ->assertCreated()->assertJsonPath('status', 'New')->json('id');
        $this->assertDatabaseCount('citizen_reports', 1);
        $this->assertDatabaseHas('report_photos', ['report_id' => $id, 'caption' => 'Water near the entrance']);
        $this->assertDatabaseHas('report_events', ['report_id' => $id, 'status' => 'New']);
        $this->assertDatabaseHas('staff_notifications', ['personnel_id' => $this->coordinator->personnel_id,
            'kind' => 'report', 'subject_id' => $id]);
        $this->assertCount(1, Storage::disk('records')->allFiles('reports'));

        $this->postJson('/api/reporter/reports', $payload)->assertOk()->assertJsonPath('id', $id);
        $this->assertDatabaseCount('citizen_reports', 1);
        $this->assertDatabaseCount('staff_notifications', 1);

        $otherReporter = $this->person('Reporter', $this->reporter->campus_id, 'second@example.test');
        $this->actingAs($otherReporter)->getJson("/api/reporter/reports/{$id}")->assertNotFound();
        $this->actingAs($this->reporter)->getJson("/api/reporter/reports/{$id}")
            ->assertOk()->assertJsonPath('events.0.status', 'New');
    }

    public function test_offline_sync_refuses_a_different_account_or_campus_before_storing_files(): void
    {
        $payload = ['client_key' => (string) Str::uuid(), 'owner_id' => $this->reporter->getKey(),
            'owner_campus_id' => $this->reporter->campus_id, 'category' => 'flood', 'place' => 'Gym entrance',
            'description' => 'Water is collecting at the gym entrance.',
            'photos' => [UploadedFile::fake()->image('offline.jpg')],
            'proof' => UploadedFile::fake()->create('proof.pdf', 5, 'application/pdf')];
        $other = $this->person('Reporter', $this->reporter->campus_id, 'sync-other@example.test');
        $this->actingAs($other)->postJson('/api/reporter/reports/sync', $payload)->assertConflict();
        $this->actingAs($this->reporter)->postJson('/api/reporter/reports/sync', array_replace($payload, ['owner_campus_id' => $this->otherCoordinator->campus_id]))->assertConflict();
        $this->assertDatabaseCount('citizen_reports', 0);
        $this->assertSame([], Storage::disk('records')->allFiles());
        $id = $this->postJson('/api/reporter/reports/sync', $payload)->assertCreated()->json('id');
        $this->postJson('/api/reporter/reports/sync', $payload)->assertOk()->assertJsonPath('id', $id);
        $this->assertDatabaseCount('citizen_reports', 1);
        $this->assertCount(2, Storage::disk('records')->allFiles());
    }

    public function test_report_validation_and_staff_campus_scope_and_transitions(): void
    {
        $this->actingAs($this->reporter)->postJson('/api/reporter/reports', [
            'client_key' => (string) Str::uuid(), 'category' => 'bad', 'description' => 'Too short',
        ])->assertUnprocessable()->assertJsonValidationErrors(['category', 'description']);
        $this->postJson('/api/reporter/reports', [
            'client_key' => (string) Str::uuid(), 'category' => 'medical',
            'description' => 'A student fell down the gym stairs.',
        ])->assertUnprocessable()->assertJsonValidationErrors('place');

        $id = $this->postJson('/api/reporter/reports', [
            'client_key' => (string) Str::uuid(), 'category' => 'medical', 'place' => 'Gym stairs',
            'description' => 'A student fell down the gym stairs.',
        ])->assertCreated()->json('id');

        $this->actingAs($this->otherCoordinator)->getJson('/api/command/reports')->assertOk()->assertExactJson([]);
        $this->get(route('coordinator.command.inbox'))->assertOk()->assertDontSee('Gym stairs');
        $this->patchJson("/api/command/reports/{$id}", ['status' => 'Verified'])->assertNotFound();
        $this->actingAs($this->coordinator)->get(route('coordinator.command.inbox'))->assertOk()->assertSee('Gym stairs');
        $this->patchJson("/api/command/reports/{$id}", ['status' => 'Resolved'])
            ->assertUnprocessable();
        $this->patchJson("/api/command/reports/{$id}", ['status' => 'Verified'])->assertOk();
        $this->patchJson("/api/command/reports/{$id}", ['status' => 'Responding'])->assertOk();
        $this->patchJson("/api/command/reports/{$id}", ['status' => 'Resolved'])->assertOk();
        $this->assertSame('Resolved', CitizenReport::findOrFail($id)->status);
        $this->assertDatabaseCount('report_events', 4);
        $this->assertDatabaseHas('staff_notifications', ['personnel_id' => $this->reporter->getKey(), 'kind' => 'report_update',
            'subject_id' => $id, 'message' => "Report #{$id} is now Resolved."]);
        $this->actingAs($this->reporter)->getJson('/api/command/reports')->assertForbidden();
        $this->get('/reports/consolidated')->assertForbidden();
        $this->get('/reports/module/incidents/pdf')->assertForbidden();
    }

    public function test_sos_is_idempotent_and_campus_scoped(): void
    {
        $this->coordinator->update(['contact_number' => '09171234567']);
        $sms = \Mockery::mock(\App\Services\Sms\SmsGatewayInterface::class);
        $sms->shouldReceive('send')->once()->with('09171234567', \Mockery::type('string'))->andReturn(true);
        $this->app->instance(\App\Services\Sms\SmsGatewayInterface::class, $sms);
        $payload = ['client_key' => (string) Str::uuid(), 'type' => 'Medical',
            'latitude' => 15.7362, 'longitude' => 121.5489, 'accuracy_m' => 12];
        $id = $this->actingAs($this->reporter)->postJson('/api/reporter/sos', $payload)
            ->assertCreated()->assertJsonPath('status', 'New')->json('id');
        $this->postJson('/api/reporter/sos', $payload)->assertOk()->assertJsonPath('id', $id);
        $this->assertDatabaseCount('sos_events', 1);
        $this->assertDatabaseCount('staff_notifications', 1);
        $this->actingAs($this->otherCoordinator)->getJson('/api/command/sos')->assertExactJson([]);
        $this->get(route('coordinator.command.sos'))->assertOk()->assertDontSee('Reporter Test');
        $this->patchJson("/api/command/sos/{$id}", ['status' => 'Acknowledged'])->assertNotFound();
        $this->actingAs($this->coordinator)->get(route('coordinator.command.sos'))->assertOk()->assertSee('Reporter Test');
        $this->patchJson("/api/command/sos/{$id}", ['status' => 'Acknowledged'])->assertOk();
        $this->patchJson("/api/command/sos/{$id}", ['status' => 'Responding'])->assertOk();
        $this->patchJson("/api/command/sos/{$id}", ['status' => 'Resolved'])->assertOk();
        $this->assertDatabaseHas('sos_events', ['sos_id' => $id, 'status' => 'Resolved']);
        $this->assertDatabaseHas('staff_notifications', ['personnel_id' => $this->reporter->getKey(), 'kind' => 'sos_update',
            'subject_id' => $id, 'message' => "SOS #{$id} is now Resolved."]);
        $this->actingAs($this->reporter)->get('/app/sos/status')->assertOk()->assertSee('Response completed');
        $this->actingAs($this->coordinator)->get(route('coordinator.notifications.index'))->assertOk()
            ->assertSee('SOS #' . $id);
    }

    public function test_signed_in_reporter_sees_real_reports_and_sos_in_the_phone_app(): void
    {
        $this->actingAs($this->reporter);
        $reportId = $this->postJson('/api/reporter/reports', [
            'client_key' => (string) Str::uuid(), 'category' => 'medical', 'place' => 'Gym stairs',
            'description' => 'A student fell down the gym stairs.',
        ])->assertCreated()->json('id');
        $this->postJson('/api/reporter/sos', ['client_key' => (string) Str::uuid(), 'type' => 'Medical'])
            ->assertCreated();

        $this->get('/app')->assertOk()->assertSee('Gym stairs')->assertDontSee('data-sample-data', false);
        $this->get('/app/reports')->assertOk()->assertSee('Gym stairs');
        $this->get("/app/reports/{$reportId}")->assertOk()->assertSee('A student fell down');
        $this->get('/app/sos/status')->assertOk()->assertSee('SOS received');
    }

    public function test_deleting_a_reporter_removes_cascaded_report_files(): void
    {
        $id = $this->actingAs($this->reporter)->postJson('/api/reporter/reports', [
            'client_key' => (string) Str::uuid(), 'category' => 'flood', 'place' => 'Gym',
            'description' => 'Water is rising inside the gym.',
            'photos' => [UploadedFile::fake()->image('flood.jpg')],
            'proof' => UploadedFile::fake()->create('proof.pdf', 2, 'application/pdf'),
        ])->assertCreated()->json('id');
        $photo = \App\Models\Reporter\CitizenReport::findOrFail($id)->photos()->firstOrFail()->path;
        $proof = \App\Models\Reporter\CitizenReport::findOrFail($id)->proof_path;

        auth()->logout();
        $this->reporter->delete();

        $this->assertDatabaseCount('citizen_reports', 0);
        Storage::disk('records')->assertMissing($photo);
        Storage::disk('records')->assertMissing($proof);
    }
}
