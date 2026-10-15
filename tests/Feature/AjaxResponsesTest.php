<?php

namespace Tests\Feature;

use App\Models\Campus;
use App\Models\Personnel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AjaxResponsesTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_redirect_is_returned_as_json_for_ajax_and_validation_stays_a_422(): void
    {
        $campus = Campus::create(['campus_name' => 'ASCOT Zabali Campus']);
        Personnel::create([
            'campus_id' => $campus->campus_id,
            'full_name' => 'Test Coordinator',
            'role' => 'Coordinator',
            'email' => 'coordinator@example.test',
            'password_hash' => Hash::make('password'),
        ]);

        $headers = ['X-Requested-With' => 'XMLHttpRequest', 'Accept' => 'application/json'];
        $this->withHeaders($headers)->post('/login', ['email' => 'coordinator@example.test', 'password' => 'wrong'])
            ->assertUnprocessable()->assertJsonValidationErrors('email');

        $this->withHeaders($headers)->post('/login', ['email' => 'coordinator@example.test', 'password' => 'password'])
            ->assertOk()->assertJsonPath('redirect', url('/coordinator/dashboard'))
            ->assertJsonPath('stay', false);
    }

    public function test_csrf_refresh_returns_the_current_session_token_without_caching(): void
    {
        $response = $this->get('/csrf-refresh')->assertOk()->assertJsonStructure(['token']);
        $this->assertSame(csrf_token(), $response->json('token'));
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
    }
}
