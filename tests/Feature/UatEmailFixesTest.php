<?php

namespace Tests\Feature;

use App\Models\Campus;
use App\Models\Personnel;
use App\Models\School;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Symfony\Component\Mailer\Bridge\Brevo\Transport\BrevoApiTransport;
use Symfony\Component\Mailer\Exception\TransportException;
use Tests\TestCase;

/**
 * UAT 2026-10-18: verification and reset emails never arrived on Render
 * (free plan blocks outbound SMTP), staff sign-up sent no verification email,
 * and a failed reset email surfaced as a "server error".
 */
class UatEmailFixesTest extends TestCase
{
    use RefreshDatabase;

    protected const PASSWORD = 'Str0ng!Passw0rd#2026';

    public function test_brevo_mailer_sends_over_the_https_api(): void
    {
        config(['services.brevo.key' => 'test-key']);

        $this->assertInstanceOf(BrevoApiTransport::class, Mail::mailer('brevo')->getSymfonyTransport());
    }

    public function test_staff_registration_sends_a_verification_email(): void
    {
        Notification::fake();
        $campus = Campus::create(['campus_name' => 'ASCOT Zabali Campus']);
        $school = School::create(['campus_id' => $campus->campus_id, 'school_name' => 'School of Engineering']);

        $this->post('/register', [
            'first_name' => 'New', 'last_name' => 'Coordinator', 'email' => 'new.coord@example.com',
            'contact_number' => '09171234567', 'password' => self::PASSWORD, 'password_confirmation' => self::PASSWORD,
            'campus_id' => $campus->campus_id, 'school_id' => $school->school_id,
        ])->assertRedirect(route('login'));

        Notification::assertSentTo(Personnel::where('email', 'new.coord@example.com')->firstOrFail(), VerifyEmail::class);
    }

    public function test_forgot_password_still_answers_normally_when_the_email_cannot_be_sent(): void
    {
        Password::shouldReceive('sendResetLink')->once()->andThrow(new TransportException('Connection timed out'));

        $this->from('/forgot-password')->post('/forgot-password', ['email' => 'someone@example.com'])
            ->assertRedirect('/forgot-password')
            ->assertSessionHas('status', 'If that email is registered, a reset link has been sent. It works for 60 minutes. If you ask again, only the newest email’s link works.');
    }
}
