<?php

namespace Tests\Feature;

use App\Models\Campus;
use App\Models\Personnel;
use App\Notifications\ResetPasswordNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Tests\TestCase;

class PasswordResetTest extends TestCase
{
    use RefreshDatabase;

    protected Campus $campus;

    protected Personnel $personnel;

    protected function setUp(): void
    {
        parent::setUp();

        $this->campus = Campus::create(['campus_name' => 'ASCOT Zabali Campus']);

        $this->personnel = Personnel::create([
            'campus_id' => $this->campus->campus_id,
            'full_name' => 'Reset Test Coordinator',
            'role' => 'Coordinator',
            'email' => 'reset-test@ascot-test.local',
            'password_hash' => Hash::make('OldPassword1!'),
        ]);
        $this->personnel->markEmailAsVerified();
    }

    public function test_reset_link_is_sent_for_a_registered_email(): void
    {
        Notification::fake();

        $response = $this->post('/forgot-password', ['email' => $this->personnel->email]);

        $response->assertRedirect();
        $response->assertSessionHas('status', 'If that email is registered, a reset link has been sent. It works for 60 minutes. If you ask again, only the newest email’s link works.');

        Notification::assertSentTo($this->personnel, ResetPasswordNotification::class);

        $this->assertDatabaseHas('activity_logs', [
            'personnel_id' => $this->personnel->personnel_id,
            'action' => 'password_reset_requested',
        ]);
    }

    public function test_unknown_email_gets_the_same_generic_message_and_no_notification_is_sent(): void
    {
        Notification::fake();

        $response = $this->post('/forgot-password', ['email' => 'nobody@ascot-test.local']);

        $response->assertRedirect();
        $response->assertSessionHas('status', 'If that email is registered, a reset link has been sent. It works for 60 minutes. If you ask again, only the newest email’s link works.');

        Notification::assertNothingSent();

        // Still logged internally (audit trail), but with no personnel_id —
        // never exposed back to the requester via the HTTP response above.
        $this->assertDatabaseHas('activity_logs', [
            'personnel_id' => null,
            'action' => 'password_reset_requested',
        ]);
    }

    public function test_valid_token_resets_password_hash_and_the_new_password_works_for_login(): void
    {
        $token = Password::createToken($this->personnel);

        $response = $this->post('/reset-password', [
            'token' => $token,
            'email' => $this->personnel->email,
            'password' => 'NewPassword1!',
            'password_confirmation' => 'NewPassword1!',
        ]);

        $response->assertRedirect(route('login'));
        $response->assertSessionHas('status');

        $this->personnel->refresh();
        $this->assertTrue(Hash::check('NewPassword1!', $this->personnel->password_hash));

        $loginResponse = $this->post('/login', [
            'email' => $this->personnel->email,
            'password' => 'NewPassword1!',
        ]);
        $loginResponse->assertRedirect('/coordinator/dashboard');

        $this->assertDatabaseHas('activity_logs', [
            'personnel_id' => $this->personnel->personnel_id,
            'action' => 'password_reset_completed',
        ]);
    }

    public function test_old_password_no_longer_works_after_reset(): void
    {
        $token = Password::createToken($this->personnel);

        $this->post('/reset-password', [
            'token' => $token,
            'email' => $this->personnel->email,
            'password' => 'NewPassword1!',
            'password_confirmation' => 'NewPassword1!',
        ]);

        $loginResponse = $this->from('/login')->post('/login', [
            'email' => $this->personnel->email,
            'password' => 'OldPassword1!',
        ]);

        $loginResponse->assertSessionHasErrors('email');
        $this->assertGuest();
    }

    public function test_expired_token_is_rejected(): void
    {
        $token = Password::createToken($this->personnel);

        // The broker's DatabaseTokenRepository checks created_at against the
        // configured expiry (60 minutes) — push it back past that window.
        DB::table('password_reset_tokens')
            ->where('email', $this->personnel->email)
            ->update(['created_at' => now()->subMinutes(61)]);

        $response = $this->post('/reset-password', [
            'token' => $token,
            'email' => $this->personnel->email,
            'password' => 'NewPassword1!',
            'password_confirmation' => 'NewPassword1!',
        ]);

        $response->assertSessionHasErrors('email');

        $this->personnel->refresh();
        $this->assertTrue(Hash::check('OldPassword1!', $this->personnel->password_hash));
    }

    public function test_invalid_token_is_rejected(): void
    {
        Password::createToken($this->personnel);

        $response = $this->post('/reset-password', [
            'token' => 'this-is-not-a-real-token',
            'email' => $this->personnel->email,
            'password' => 'NewPassword1!',
            'password_confirmation' => 'NewPassword1!',
        ]);

        $response->assertSessionHasErrors(['email' => 'This reset link no longer works: it is over 60 minutes old, was already used, or a newer link was sent after it. Request a new link and open the newest email.']);

        $this->personnel->refresh();
        $this->assertTrue(Hash::check('OldPassword1!', $this->personnel->password_hash));
    }

    /**
     * UAT 2026-10-21: "This password reset token is invalid." with the button
     * stuck on "Please wait…". A newer request replaces the older link; the
     * page says so, always offers a new link, and leaves the AJAX form's
     * button to ajax.js.
     */
    public function test_an_older_link_after_a_newer_request_explains_what_to_do(): void
    {
        $older = Password::createToken($this->personnel);
        $newer = Password::createToken($this->personnel);

        $this->post('/reset-password', ['token' => $older, 'email' => $this->personnel->email,
            'password' => 'NewPassword1!', 'password_confirmation' => 'NewPassword1!'])
            ->assertSessionHasErrors(['email' => 'This reset link no longer works: it is over 60 minutes old, was already used, or a newer link was sent after it. Request a new link and open the newest email.']);

        $page = $this->get(route('password.reset', ['token' => $newer, 'email' => $this->personnel->email]))->assertOk();
        $page->assertSee('Request a new one')->assertSee("e.target.matches('form[data-ajax]')", false);

        $this->post('/reset-password', ['token' => $newer, 'email' => $this->personnel->email,
            'password' => 'NewPassword1!', 'password_confirmation' => 'NewPassword1!'])->assertRedirect(route('login'));
    }

    public function test_a_used_token_cannot_be_reused(): void
    {
        $token = Password::createToken($this->personnel);

        $first = $this->post('/reset-password', [
            'token' => $token,
            'email' => $this->personnel->email,
            'password' => 'NewPassword1!',
            'password_confirmation' => 'NewPassword1!',
        ]);
        $first->assertRedirect(route('login'));

        $second = $this->post('/reset-password', [
            'token' => $token,
            'email' => $this->personnel->email,
            'password' => 'AnotherPassword2!',
            'password_confirmation' => 'AnotherPassword2!',
        ]);
        $second->assertSessionHasErrors('email');

        // Still the password from the FIRST (successful) reset, not the second attempt.
        $this->personnel->refresh();
        $this->assertTrue(Hash::check('NewPassword1!', $this->personnel->password_hash));
    }

    public function test_forgot_password_route_is_throttled_per_ip(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $response = $this->post('/forgot-password', ['email' => 'someone' . $i . '@ascot-test.local']);
            $response->assertStatus(302);
        }

        // The 6th request within the same minute must be throttled.
        $response = $this->post('/forgot-password', ['email' => 'onemore@ascot-test.local']);
        $response->assertStatus(429);
    }
}
