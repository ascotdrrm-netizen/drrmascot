<?php

namespace Tests\Feature;

use App\Models\Campus;
use App\Models\Personnel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * UAT 2026-10-18: the panel's main suggestion — an SOS must reach the DRRM
 * Office by SMS. The text now says what, who and where (with a map link),
 * goes to properly formatted numbers, and carries the approved sender name.
 */
class UatSosSmsTest extends TestCase
{
    use RefreshDatabase;

    private Campus $campus;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.sms.driver' => 'semaphore', 'services.semaphore.api_key' => 'test-key', 'services.semaphore.sender_name' => 'ASCOTDRRM']);
        Http::fake(['api.semaphore.co/*' => Http::response([['message_id' => 1, 'status' => 'Queued']])]);
        $this->campus = Campus::create(['campus_name' => 'ASCOT Zabali Campus']);
    }

    private function person(string $role, string $email, ?string $phone): Personnel
    {
        $person = Personnel::create(['campus_id' => $this->campus->campus_id, 'full_name' => $role === 'Reporter' ? 'Juan Dela Cruz' : $role.' Staff',
            'role' => $role, 'email' => $email, 'password_hash' => Hash::make('password'), 'contact_number' => $phone]);
        $person->forceFill(['is_approved' => true, 'email_verified_at' => now(), 'validated_by_coordinator_at' => now()])->save();

        return $person;
    }

    public function test_an_sos_texts_staff_what_who_and_where(): void
    {
        $this->person('Coordinator', 'coord@example.test', '09171234567');
        $this->person('DRRMO Admin', 'admin@example.test', 'not a number');   // left over from before digits-only: skipped
        $reporter = $this->person('Reporter', 'reporter@example.test', '09998887777');

        $this->actingAs($reporter)->postJson('/api/reporter/sos', ['client_key' => (string) Str::uuid(), 'type' => 'Medical',
            'place' => 'Library 2nd floor', 'latitude' => 15.7581, 'longitude' => 121.5603])->assertCreated();

        Http::assertSentCount(1);
        Http::assertSent(function (HttpRequest $request) {
            $message = $request['message'];

            return $request['number'] === '639171234567'
                && $request['sendername'] === 'ASCOTDRRM'
                && str_contains($message, 'Medical')
                && str_contains($message, 'Juan Dela Cruz')
                && str_contains($message, '09998887777')
                && str_contains($message, 'Library 2nd floor')
                && str_contains($message, 'maps.google.com/?q=15.7581,121.5603');
        });
    }

    public function test_without_an_approved_sender_name_the_text_still_goes_out(): void
    {
        config(['services.semaphore.sender_name' => null]);
        $this->person('Coordinator', 'coord@example.test', '09171234567');
        $reporter = $this->person('Reporter', 'reporter@example.test', '09998887777');

        $this->actingAs($reporter)->postJson('/api/reporter/sos', ['client_key' => (string) Str::uuid()])->assertCreated();

        Http::assertSent(fn (HttpRequest $request) => ! isset($request['sendername']) && str_contains($request['message'], 'Location not shared'));
    }
}
