<?php

namespace Tests\Feature;

use App\Models\Campus;
use App\Models\Personnel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class RememberLoginTest extends TestCase
{
    use RefreshDatabase;

    public function test_keep_me_signed_in_survives_the_session_ending(): void
    {
        $campus = Campus::create(['campus_name' => 'ASCOT Zabali Campus']);
        $reporter = Personnel::create(['campus_id' => $campus->getKey(), 'full_name' => 'Ana Cruz', 'email' => 'ana@example.test',
            'role' => 'Reporter', 'password_hash' => Hash::make('Password1!')]);
        $reporter->forceFill(['is_approved' => true, 'email_verified_at' => now(), 'validated_by_coordinator_at' => now()])->save();

        $this->get('/app/login')->assertSee('name="remember"', false);
        $response = $this->post('/app/login', ['email' => 'ana@example.test', 'password' => 'Password1!', 'remember' => '1']);

        $response->assertRedirect('/app');
        $this->assertNotNull($reporter->fresh()->remember_token);
        $response->assertCookie(Auth::guard()->getRecallerName());
    }
}
