<?php

namespace Tests\Feature;

use App\Jobs\DeliverBroadcast;
use App\Models\Broadcast;
use App\Models\Campus;
use App\Models\Personnel;
use App\Services\BroadcastAudience;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * UAT 2026-10-18 (3.14): Delivery history showed only "In-app" — email and
 * SMS go only to verified contacts, and with none on the campus those rows
 * were silently missing. The page now says why a channel sent nothing.
 */
class UatBroadcastHistoryTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_channel_with_no_verified_contacts_says_why_before_and_after_publishing(): void
    {
        Bus::fake([DeliverBroadcast::class]);
        $campus = Campus::create(['campus_name' => 'Zabali']);
        $make = fn (string $role) => tap(Personnel::create(['campus_id' => $campus->getKey(), 'full_name' => 'Campus member', 'role' => $role,
            'email' => Str::uuid().'@example.test', 'password_hash' => Hash::make('password'), 'contact_number' => '09171234567']),
            fn ($p) => $p->forceFill(['is_approved' => true])->save());
        $staff = $make('Coordinator');
        $make('Reporter');
        $draft = Broadcast::create(['campus_id' => $campus->getKey(), 'created_by' => $staff->getKey(), 'client_key' => (string) Str::uuid(),
            'title' => 'Gym temporarily closed', 'body' => 'Use the covered walkway while the gym entrance is inspected.',
            'sms_text' => 'ASCOT DRRMS: gym closed.', 'send_email' => true, 'send_sms' => true]);

        $this->actingAs($staff)->get(route('coordinator.broadcasts.show', $draft))->assertOk()
            ->assertSee('No one on this campus has a verified email address yet')
            ->assertSee('No one on this campus has a verified mobile number yet');

        $this->post(route('coordinator.broadcasts.publish', $draft), ['audience_fingerprint' => app(BroadcastAudience::class)->snapshot($draft)['fingerprint']])
            ->assertRedirect();

        $this->get(route('coordinator.broadcasts.show', $draft))->assertOk()
            ->assertSee('Email: not sent. No one on this campus has a verified email address yet.')
            ->assertSee('SMS: not sent. No one on this campus has a verified mobile number yet.');
    }
}
