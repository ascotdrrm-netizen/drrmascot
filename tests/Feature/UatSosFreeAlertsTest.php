<?php

namespace Tests\Feature;

use App\Mail\SosAlert;
use App\Models\Campus;
use App\Models\Personnel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * UAT 2026-10-18: no budget for Semaphore. An SOS still reaches staff phones
 * for free — an email through Brevo, and a text sent by an Android phone
 * running TextBee (textbee.dev) on the office SIM.
 */
class UatSosFreeAlertsTest extends TestCase
{
    use RefreshDatabase;

    private Campus $campus;

    protected function setUp(): void
    {
        parent::setUp();
        $this->campus = Campus::create(['campus_name' => 'ASCOT Zabali Campus']);
    }

    private function person(string $role, string $email, ?string $phone): Personnel
    {
        $person = Personnel::create(['campus_id' => $this->campus->campus_id, 'full_name' => $role === 'Reporter' ? 'Juan Dela Cruz' : $role.' Staff',
            'role' => $role, 'email' => $email, 'password_hash' => Hash::make('password'), 'contact_number' => $phone]);
        $person->forceFill(['is_approved' => true, 'email_verified_at' => now(), 'validated_by_coordinator_at' => now()])->save();

        return $person;
    }

    private function sendSos(Personnel $reporter): void
    {
        $this->actingAs($reporter)->postJson('/api/reporter/sos', ['client_key' => (string) Str::uuid(), 'type' => 'Fire',
            'place' => 'Science building', 'latitude' => 15.7581, 'longitude' => 121.5603])->assertCreated();
    }

    public function test_an_sos_emails_each_staff_recipient_separately(): void
    {
        Mail::fake();
        $this->person('Coordinator', 'coord@example.test', null);
        $this->person('DRRMO Admin', 'admin@example.test', null);
        $reporter = $this->person('Reporter', 'reporter@example.test', '09998887777');

        $this->sendSos($reporter);

        Mail::assertQueued(SosAlert::class, 2);
        Mail::assertQueued(SosAlert::class, fn (SosAlert $mail) => $mail->hasTo('coord@example.test') && ! $mail->hasTo('admin@example.test'));
        Mail::assertNotQueued(SosAlert::class, fn (SosAlert $mail) => $mail->hasTo('reporter@example.test'));
    }

    public function test_the_sos_email_says_what_who_and_where(): void
    {
        $this->person('Coordinator', 'coord@example.test', null);
        $reporter = $this->person('Reporter', 'reporter@example.test', '09998887777');
        Mail::fake();

        $this->sendSos($reporter);

        Mail::assertQueued(SosAlert::class, function (SosAlert $mail) {
            $html = $mail->render();

            return str_contains($mail->envelope()->subject, 'SOS')
                && str_contains($html, 'Juan Dela Cruz')
                && str_contains($html, '09998887777')
                && str_contains($html, 'Science building')
                && str_contains($html, 'maps.google.com/?q=15.7581,121.5603')
                && str_contains($html, route('coordinator.command.sos'));
        });
    }

    public function test_textbee_sends_the_sos_text_from_the_office_phone(): void
    {
        config(['services.sms.driver' => 'textbee', 'services.textbee.api_key' => 'tb-key', 'services.textbee.device_id' => 'device-1']);
        Http::fake(['api.textbee.dev/*' => Http::response(['data' => ['success' => true, 'smsBatchId' => 'b1', 'recipientCount' => 1]])]);
        $this->person('Coordinator', 'coord@example.test', '09171234567');
        $reporter = $this->person('Reporter', 'reporter@example.test', '09998887777');

        $this->sendSos($reporter);

        Http::assertSentCount(1);
        Http::assertSent(fn (HttpRequest $request) => $request->url() === 'https://api.textbee.dev/api/v1/gateway/send-sms'
            && $request->hasHeader('x-api-key', 'tb-key')
            && $request['recipients'] === ['+639171234567']
            && $request['deviceId'] === 'device-1'
            && str_contains($request['message'], 'Juan Dela Cruz'));
    }

    public function test_textbee_also_sends_phone_verification_codes(): void
    {
        config(['services.sms.driver' => 'textbee', 'services.textbee.api_key' => 'tb-key']);
        Http::fake(['api.textbee.dev/*' => Http::response(['data' => ['success' => true, 'smsBatchId' => 'b1', 'recipientCount' => 1]])]);
        $coordinator = $this->person('Coordinator', 'coord@example.test', '09171234567');

        $this->actingAs($coordinator)->get(route('coordinator.profile.show'))->assertOk()->assertSee('Request verification code');
        $this->post(route('phone.send'))->assertSessionHasNoErrors();

        Http::assertSent(fn (HttpRequest $request) => $request['recipients'] === ['+639171234567']);
        $this->assertDatabaseHas('phone_verifications', ['personnel_id' => $coordinator->personnel_id]);
    }

    public function test_a_textbee_limit_or_offline_phone_never_blocks_the_sos(): void
    {
        config(['services.sms.driver' => 'textbee', 'services.textbee.api_key' => 'tb-key']);
        Http::fake(['api.textbee.dev/*' => Http::response(['error' => 'Daily limit reached'], 429)]);
        $this->person('Coordinator', 'coord@example.test', '09171234567');
        $reporter = $this->person('Reporter', 'reporter@example.test', '09998887777');

        $this->sendSos($reporter);

        Http::assertSent(fn (HttpRequest $request) => ! isset($request['deviceId']));
        $this->assertDatabaseHas('staff_notifications', ['kind' => 'sos']);
    }

    /**
     * UAT 2026-10-21: every incident alert showed "Failed" — its numbers went to
     * TextBee as "+0917…" instead of "+63917…". A number that isn't a mobile
     * is skipped and counts as not sent.
     */
    public function test_an_incident_alert_texts_each_number_in_international_form(): void
    {
        config(['services.sms.driver' => 'textbee', 'services.textbee.api_key' => 'tb-key']);
        Http::fake(['api.textbee.dev/*' => Http::response(['data' => ['success' => true, 'smsBatchId' => 'b1', 'recipientCount' => 1]])]);
        $admin = $this->person('DRRMO Admin', 'admin@example.test', '09171234567');
        $this->person('Coordinator', 'coord@example.test', '0918 765 4321');
        $this->person('Reporter', 'reporter@example.test', '09171112222');
        $incidentId = DB::table('incidents')->insertGetId(['campus_id' => $this->campus->campus_id, 'hazard_type' => 'Fire',
            'date_occurred' => '2026-10-21', 'description' => 'Fire at the science building'], 'incident_id');

        $this->actingAs($admin)->post(route('admin.incidents.alerts.store', $incidentId), ['message' => 'Evacuate the science building.'])
            ->assertSessionHasNoErrors();

        // Everyone but the Admin sending it.
        Http::assertSentCount(2);
        Http::assertSent(fn (HttpRequest $request) => $request['recipients'] === ['+639187654321']);
        Http::assertSent(fn (HttpRequest $request) => $request['recipients'] === ['+639171112222']);
        Http::assertNotSent(fn (HttpRequest $request) => $request['recipients'] === ['+639171234567']);
        $this->assertDatabaseHas('alerts', ['incident_id' => $incidentId, 'recipient_count' => 2, 'delivery_status' => 'Sent']);

        $this->person('Reporter', 'landline@example.test', '(078) 123 4567');
        $this->actingAs($admin)->post(route('admin.incidents.alerts.store', $incidentId), ['message' => 'All clear.']);
        $this->assertDatabaseHas('alerts', ['incident_id' => $incidentId, 'message' => 'All clear.', 'recipient_count' => 3, 'delivery_status' => 'Partial']);
    }
}
