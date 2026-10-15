<?php

namespace Tests\Feature;

use App\Jobs\DeliverBroadcast;
use App\Mail\CampusBroadcast;
use App\Models\Broadcast;
use App\Models\Campus;
use App\Models\Personnel;
use App\Models\StaffNotification;
use App\Services\BroadcastAudience;
use App\Services\BroadcastSender;
use App\Services\Sms\SmsGatewayInterface;
use App\Support\Backup\DataSet;
use App\Support\RecordsClear;
use App\Support\RecordsRestore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Tests\TestCase;

class BroadcastWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private Personnel $staff;

    private Personnel $reporter;

    private Personnel $other;

    protected function setUp(): void
    {
        parent::setUp();
        Bus::fake([DeliverBroadcast::class]);
        Mail::fake();
        $home = Campus::create(['campus_name' => 'Zabali']);
        $away = Campus::create(['campus_name' => 'Bazal']);
        $this->staff = $this->person($home->getKey(), 'Coordinator');
        $this->reporter = $this->person($home->getKey());
        $this->other = $this->person($away->getKey());
    }

    private function person(int $campus, string $role = 'Reporter'): Personnel
    {
        $person = Personnel::create(['campus_id' => $campus, 'full_name' => 'Campus member', 'role' => $role,
            'email' => Str::uuid().'@example.test', 'password_hash' => Hash::make('password')]);
        $person->forceFill(['is_approved' => true, 'email_verified_at' => now()])->save();

        return $person;
    }

    private function draft(array $extra = []): Broadcast
    {
        return Broadcast::create(array_replace(['campus_id' => $this->staff->campus_id, 'created_by' => $this->staff->getKey(),
            'client_key' => (string) Str::uuid(), 'title' => 'Gym temporarily closed', 'body' => 'Use the covered walkway while the gym entrance is inspected.',
            'sms_text' => 'ASCOT DRRMS: Zabali gym temporarily closed. Use the covered walkway.', 'send_email' => true, 'send_sms' => true], $extra));
    }

    private function publish(Broadcast $draft)
    {
        return $this->actingAs($this->staff)->post(route('coordinator.broadcasts.publish', $draft), [
            'audience_fingerprint' => app(BroadcastAudience::class)->snapshot($draft)['fingerprint'],
        ]);
    }

    public function test_publish_snapshots_only_approved_campus_members_and_verified_unique_contacts_once(): void
    {
        $this->reporter->forceFill(['contact_number' => '09171234567'])->save();
        $this->reporter->forceFill(['phone_verified_at' => now()])->save();
        $duplicate = $this->person($this->staff->campus_id);
        $duplicate->forceFill(['contact_number' => '+63 917 123 4567'])->save();
        $duplicate->forceFill(['phone_verified_at' => now(), 'email_verified_at' => null])->save();
        $unapproved = $this->person($this->staff->campus_id);
        $unapproved->forceFill(['is_approved' => false])->save();
        $draft = $this->draft();
        $audience = app(BroadcastAudience::class)->snapshot($draft);
        $this->assertSame(['in_app' => 3, 'email' => 2, 'sms' => 1], $audience['counts']);
        $this->actingAs($this->staff)->get(route('coordinator.broadcasts.show', $draft))->assertOk()->assertSee('Rate not configured');
        $this->publish($draft)->assertRedirect();
        $this->publish($draft->fresh())->assertRedirect();
        $this->assertDatabaseCount('broadcast_deliveries', 6);
        $this->assertDatabaseCount('staff_notifications', 3);
        $this->assertDatabaseHas('broadcast_deliveries', ['personnel_id' => $this->reporter->getKey(), 'channel' => 'sms', 'destination' => '639171234567', 'status' => 'Pending']);
        $this->assertDatabaseMissing('broadcast_deliveries', ['personnel_id' => $this->other->getKey()]);
        $this->assertDatabaseMissing('broadcast_deliveries', ['personnel_id' => $unapproved->getKey()]);
        $this->assertDatabaseCount('activity_logs', 1);
        Bus::assertDispatched(DeliverBroadcast::class);
        $this->put(route('coordinator.broadcasts.update', $draft), ['campus_id' => $draft->campus_id, 'title' => 'Changed', 'body' => 'Changed message'])->assertConflict();
    }

    public function test_stale_preview_cannot_publish_changed_audience_or_message(): void
    {
        $draft = $this->draft();
        $fingerprint = app(BroadcastAudience::class)->snapshot($draft)['fingerprint'];
        $this->person($this->staff->campus_id);
        $this->actingAs($this->staff)->postJson(route('coordinator.broadcasts.publish', $draft), ['audience_fingerprint' => $fingerprint])
            ->assertUnprocessable()->assertJsonValidationErrors('audience_fingerprint');
        $this->assertNull($draft->fresh()->published_at);
        $this->assertDatabaseCount('broadcast_deliveries', 0);
        $fingerprint = app(BroadcastAudience::class)->snapshot($draft)['fingerprint'];
        $draft->update(['body' => 'Updated saved message']);
        $this->postJson(route('coordinator.broadcasts.publish', $draft), ['audience_fingerprint' => $fingerprint])->assertUnprocessable();
    }

    public function test_draft_creation_is_idempotent_and_coordinators_cannot_use_other_campuses(): void
    {
        $data = ['client_key' => (string) Str::uuid(), 'campus_id' => $this->staff->campus_id, 'title' => 'Campus notice', 'body' => 'Please use the east entrance.'];
        $this->actingAs($this->staff)->post(route('coordinator.broadcasts.store'), $data)->assertRedirect();
        $this->post(route('coordinator.broadcasts.store'), $data)->assertRedirect();
        $this->assertDatabaseCount('broadcasts', 1);
        $this->post(route('coordinator.broadcasts.store'), array_replace($data, ['campus_id' => $this->other->campus_id]))->assertForbidden();
        $awayDraft = $this->draft(['campus_id' => $this->other->campus_id]);
        $this->get(route('coordinator.broadcasts.show', $awayDraft))->assertNotFound();
        $this->post(route('coordinator.broadcasts.retry', $awayDraft))->assertNotFound();
        $this->actingAs($this->reporter)->get(route('coordinator.broadcasts.index'))->assertForbidden();
    }

    public function test_worker_claims_are_idempotent_and_simulation_is_visible(): void
    {
        $draft = $this->draft(['send_sms' => false]);
        $this->publish($draft);
        $sender = app(BroadcastSender::class);
        while ($sender->hasPending($draft->getKey())) {
            $sender->sendNext($draft->getKey());
        }
        $sender->sendNext($draft->getKey());
        Mail::assertSent(CampusBroadcast::class, 2);
        $this->assertSame(2, $draft->deliveries()->where('status', 'Simulated')->count());
        $this->assertSame('Complete', $draft->fresh()->status);
        $this->assertSame(1, $draft->deliveries()->where('channel', 'email')->first()->attempts);
    }

    public function test_worker_skips_revoked_or_changed_contacts(): void
    {
        $draft = $this->draft(['send_sms' => false]);
        $this->publish($draft);
        $this->reporter->forceFill(['email_verified_at' => null])->save();
        $this->staff->forceFill(['campus_id' => $this->other->campus_id])->save();
        $sender = app(BroadcastSender::class);
        while ($sender->hasPending($draft->getKey())) {
            $sender->sendNext($draft->getKey());
        }
        Mail::assertNothingSent();
        $this->assertSame(2, $draft->deliveries()->where('status', 'Skipped')->count());
    }

    public function test_only_explicit_rejection_can_be_retried_and_timeouts_remain_unknown(): void
    {
        config(['services.sms.driver' => 'semaphore']);
        $this->reporter->forceFill(['contact_number' => '09171234567'])->save();
        $this->reporter->forceFill(['phone_verified_at' => now()])->save();
        $draft = $this->draft(['send_email' => false]);
        $this->publish($draft);
        $this->mock(SmsGatewayInterface::class)->shouldReceive('send')->once()->andReturn(false);
        app(BroadcastSender::class)->sendNext($draft->getKey());
        $this->assertSame('Failed', $draft->deliveries()->where('channel', 'sms')->first()->status);
        $this->actingAs($this->staff)->post(route('coordinator.broadcasts.retry', $draft))->assertRedirect();
        $this->mock(SmsGatewayInterface::class)->shouldReceive('send')->once()->andThrow(new \RuntimeException('connection closed'));
        app(BroadcastSender::class)->sendNext($draft->getKey());
        $this->assertSame('Unknown', $draft->deliveries()->where('channel', 'sms')->first()->status);
        $this->post(route('coordinator.broadcasts.retry', $draft))->assertRedirect();
        $this->assertFalse(app(BroadcastSender::class)->hasPending($draft->getKey()));
        $this->assertSame(2, $draft->deliveries()->where('channel', 'sms')->first()->attempts);
    }

    public function test_recovery_never_repeats_an_interrupted_external_request(): void
    {
        $draft = $this->draft(['send_sms' => false]);
        $this->publish($draft);
        $delivery = $draft->deliveries()->where('channel', 'email')->first();
        $delivery->update(['status' => 'Processing', 'started_at' => now()->subMinutes(11), 'attempts' => 1]);
        $this->artisan('broadcasts:resume')->assertSuccessful();
        $this->assertSame('Unknown', $delivery->fresh()->status);
        $this->assertSame(1, $delivery->fresh()->attempts);
        Mail::assertNothingSent();
    }

    public function test_job_records_confirmed_sms_acceptance_without_repeating_the_request(): void
    {
        config(['services.sms.driver' => 'semaphore']);
        $this->reporter->update(['contact_number' => '09171234567']);
        $this->reporter->forceFill(['phone_verified_at' => now()])->save();
        $draft = $this->draft(['send_email' => false]);
        $this->publish($draft);
        $this->mock(SmsGatewayInterface::class)->shouldReceive('send')->once()->with('639171234567', $draft->sms_text)->andReturn(true);
        $job = new DeliverBroadcast($draft->getKey());
        $job->handle(app(BroadcastSender::class));
        $job->handle(app(BroadcastSender::class));
        $delivery = $draft->deliveries()->where('channel', 'sms')->firstOrFail();
        $this->assertSame('Accepted', $delivery->status);
        $this->assertNotNull($delivery->sent_at);
        $this->assertSame(1, $delivery->attempts);
        $this->assertSame('Complete', $draft->fresh()->status);
    }

    public function test_reporters_read_their_own_broadcast_and_notification_only(): void
    {
        $draft = $this->draft(['send_sms' => false, 'send_email' => false]);
        $this->actingAs($this->reporter)->get(route('app.broadcasts.show', $draft))->assertNotFound();
        $this->publish($draft);
        $notice = StaffNotification::where('personnel_id', $this->reporter->getKey())->firstOrFail();
        $this->actingAs($this->other)->post(route('app.notifications.open', $notice))->assertNotFound();
        $this->get(route('app.broadcasts.show', $draft))->assertNotFound();
        $this->actingAs($this->reporter)->getJson('/api/reporter/notifications')->assertJsonPath('unread', 1);
        $this->get('/app/alerts')->assertOk()->assertSee('Gym temporarily closed');
        $this->post(route('app.notifications.open', $notice))->assertRedirect(route('app.broadcasts.show', $draft));
        $this->getJson('/api/reporter/notifications')->assertJsonPath('unread', 0);
        $this->get(route('app.broadcasts.show', $draft))->assertOk()->assertSee('Use the covered walkway');
    }

    public function test_records_backup_preserves_broadcasts_and_reporter_notices_but_excludes_otp_hashes(): void
    {
        $draft = $this->draft(['send_sms' => false, 'send_email' => false]);
        $this->publish($draft);
        $rows = RecordsRestore::fromRows(collect(array_keys(RecordsClear::TABLES))->mapWithKeys(fn ($table) => [$table => DB::table($table)->get()->map(fn ($r) => (array) $r)->all()])->all());
        $this->assertSame([], RecordsRestore::problems($rows));
        RecordsRestore::apply($rows);
        $this->assertDatabaseCount('broadcasts', 1);
        $this->assertDatabaseCount('broadcast_deliveries', 2);
        $this->assertDatabaseCount('staff_notifications', 2);
        $this->assertNotContains('phone_verifications', DataSet::tables());
    }

    public function test_restoring_old_pending_deliveries_never_resends_them(): void
    {
        $draft = $this->draft(['send_sms' => false]);
        $this->publish($draft);
        $rows = collect(array_keys(RecordsClear::TABLES))->mapWithKeys(fn ($table) => [$table => DB::table($table)->get()->map(fn ($r) => (array) $r)->all()])->all();
        $sender = app(BroadcastSender::class);
        while ($sender->hasPending($draft->getKey())) {
            $sender->sendNext($draft->getKey());
        }
        Mail::assertSent(CampusBroadcast::class, 2);
        RecordsRestore::apply($rows);
        $this->assertFalse($sender->hasPending($draft->getKey()));
        $this->assertSame(2, $draft->deliveries()->where('status', 'Unknown')->count());
        $this->assertSame('Needs review', $draft->fresh()->status);
        $sender->sendNext($draft->getKey());
        Mail::assertSent(CampusBroadcast::class, 2);
    }
}
