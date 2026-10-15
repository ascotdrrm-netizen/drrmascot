<?php

namespace Tests\Feature;

use App\Models\Campus;
use App\Models\Personnel;
use App\Models\School;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

class ReporterAccountTest extends TestCase
{
    use RefreshDatabase;

    public function test_reporter_signup_requires_email_and_campus_validation_before_admin_approval(): void
    {
        Notification::fake();
        $campus = Campus::create(['campus_name' => 'ASCOT Zabali Campus']);
        $otherCampus = Campus::create(['campus_name' => 'ASCOT Bazal Campus']);
        $school = School::create(['campus_id' => $campus->campus_id, 'school_name' => 'School of Engineering']);
        $coordinator = $this->person('Coordinator', $campus->campus_id, 'coordinator@example.test');
        $otherCoordinator = $this->person('Coordinator', $otherCampus->campus_id, 'other@example.test');
        $admin = $this->person('DRRMO Admin', $campus->campus_id, 'admin@example.test');

        $this->post('/app/register', [
            'reporter_kind' => 'Student', 'first_name' => 'Ana', 'last_name' => 'Santos',
            'id_number' => '2026-1234', 'campus_id' => $campus->campus_id, 'school_id' => $school->school_id,
            'contact_number' => '09171234567', 'email' => 'ana@example.test',
            'password' => 'Password123!', 'password_confirmation' => 'Password123!',
            'privacy_consent' => '1', 'role' => 'DRRMO Admin',
        ])->assertRedirect(route('app.register'));

        $reporter = Personnel::where('email', 'ana@example.test')->firstOrFail();
        $this->assertSame('Reporter', $reporter->role);
        $this->assertFalse($reporter->is_approved);
        $this->assertNotNull($reporter->privacy_consented_at);
        Notification::assertSentTo($reporter, \Illuminate\Auth\Notifications\VerifyEmail::class);
        $this->post('/app/login', ['email' => 'ana@example.test', 'password' => 'Password123!'])
            ->assertSessionHasErrors('email');

        $this->actingAs($otherCoordinator)->post("/coordinator/reporters/{$reporter->personnel_id}/validate")->assertNotFound();
        $this->actingAs($coordinator)->post("/coordinator/reporters/{$reporter->personnel_id}/validate")->assertUnprocessable();
        $this->actingAs($admin)->post("/admin/personnel/{$reporter->personnel_id}/approve")->assertUnprocessable();
        auth()->logout();

        $url = URL::temporarySignedRoute('verification.verify', now()->addHour(), [
            'id' => $reporter->personnel_id, 'hash' => sha1($reporter->email),
        ]);
        $this->get($url)->assertRedirect(route('app.login'));
        $this->assertNotNull($reporter->fresh()->email_verified_at);

        $this->actingAs($coordinator)->post("/coordinator/reporters/{$reporter->personnel_id}/validate")->assertRedirect();
        $this->actingAs($admin)->post("/admin/personnel/{$reporter->personnel_id}/approve")->assertRedirect();
        auth()->logout();

        $this->post('/app/login', ['email' => 'ana@example.test', 'password' => 'Password123!'])
            ->assertRedirect('/app');
        $this->assertAuthenticatedAs($reporter);
    }

    private function person(string $role, int $campus, string $email): Personnel
    {
        return Personnel::create(['campus_id' => $campus, 'full_name' => $role . ' Test',
            'role' => $role, 'email' => $email, 'password_hash' => Hash::make('Password123!')]);
    }

    public function test_verification_resend_is_private_rate_limited_and_available_to_staff(): void
    {
        Notification::fake();
        $campus = Campus::create(['campus_name' => 'Zabali']);
        $reporter = $this->person('Reporter', $campus->getKey(), 'resend@example.test');
        $this->post('/app/resend-verification', ['email' => $reporter->email])->assertRedirect(route('app.login'));
        $message = session('status');
        $this->post('/app/resend-verification', ['email' => $reporter->email])->assertRedirect();
        Notification::assertSentToTimes($reporter, \Illuminate\Auth\Notifications\VerifyEmail::class, 1);
        $this->post('/app/resend-verification', ['email' => 'missing@example.test'])->assertRedirect();
        $this->assertSame($message, session('status'));
        $staff = $this->person('Coordinator', $campus->getKey(), 'staff-verification@example.test');
        $staff->forceFill(['is_approved' => true])->save();
        $this->actingAs($staff)->from(route('coordinator.profile.show'))->post('/email/verification-link')->assertRedirect();
        Notification::assertSentTo($staff, \Illuminate\Auth\Notifications\VerifyEmail::class);
        $this->get(URL::temporarySignedRoute('verification.verify', now()->addHour(), ['id' => $staff->getKey(), 'hash' => sha1($staff->email)]))->assertRedirect();
        $this->assertNotNull($staff->fresh()->email_verified_at);
        $staff->refresh()->update(['email' => 'new-staff@example.test']);
        $this->assertNull($staff->fresh()->email_verified_at);
    }
}
