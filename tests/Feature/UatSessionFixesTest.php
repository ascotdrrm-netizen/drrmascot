<?php

namespace Tests\Feature;

use App\Models\Campus;
use App\Models\Personnel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * UAT 2026-10-18: closing the browser without "Keep me signed in" still left
 * the Admin signed in, and the phone drawer's Log out sat under the
 * browser's bottom bar.
 */
class UatSessionFixesTest extends TestCase
{
    use RefreshDatabase;

    protected Personnel $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $campus = Campus::create(['campus_name' => 'ASCOT Zabali Campus']);
        $this->admin = Personnel::create(['campus_id' => $campus->getKey(), 'full_name' => 'Session Admin', 'role' => 'DRRMO Admin',
            'email' => 'session-admin@ascot-test.local', 'password_hash' => Hash::make('Password1!')]);
    }

    public function test_without_keep_me_signed_in_the_session_ends_with_the_browser(): void
    {
        $response = $this->post('/login', ['email' => $this->admin->email, 'password' => 'Password1!']);

        $response->assertRedirect();
        $session = collect($response->headers->getCookies())->first(fn ($c) => $c->getName() === config('session.cookie'));
        $this->assertNotNull($session);
        $this->assertSame(0, $session->getExpiresTime(), 'The session cookie must not outlive the browser.');
        $response->assertCookieMissing(Auth::guard()->getRecallerName());
    }

    public function test_keep_me_signed_in_still_survives_closing_the_browser(): void
    {
        $this->post('/login', ['email' => $this->admin->email, 'password' => 'Password1!', 'remember' => '1'])
            ->assertCookie(Auth::guard()->getRecallerName());
    }

    public function test_phone_drawer_log_out_fits_the_visible_screen(): void
    {
        $this->actingAs($this->admin)->get(route('admin.dashboard'))->assertOk()
            ->assertSee('flex h-dvh w-64', false)
            ->assertSee('Log out');
    }
}
