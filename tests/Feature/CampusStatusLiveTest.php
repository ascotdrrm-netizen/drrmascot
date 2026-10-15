<?php

namespace Tests\Feature;

use App\Models\Campus;
use App\Models\Personnel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class CampusStatusLiveTest extends TestCase
{
    use RefreshDatabase;

    public function test_coordinator_can_publish_only_own_campus_and_reporter_and_public_see_it(): void
    {
        $home = Campus::create(['campus_name' => 'ASCOT Zabali Campus']);
        $other = Campus::create(['campus_name' => 'ASCOT Bazal Campus']);
        $coordinator = $this->person('Coordinator', $home, 'coord@example.test');
        $reporter = $this->person('Reporter', $home, 'reporter@example.test');

        $this->actingAs($coordinator)->putJson("/api/command/campuses/{$other->campus_id}/status", [
            'status' => 'evacuate', 'detail' => 'Leave the campus now.',
        ])->assertNotFound();
        $this->putJson("/api/command/campuses/{$home->campus_id}/status", [
            'status' => 'evacuate', 'detail' => '',
        ])->assertUnprocessable()->assertJsonValidationErrors('detail');
        $this->putJson("/api/command/campuses/{$home->campus_id}/status", [
            'status' => 'evacuate', 'detail' => 'Leave the campus by the main gate.',
        ])->assertOk()->assertJsonPath('status', 'evacuate');

        $this->actingAs($reporter)->get('/app')->assertOk()->assertSee('Evacuate')
            ->assertSee('data-campus-status="evacuate"', false);
        $this->putJson("/api/command/campuses/{$home->campus_id}/status", [
            'status' => 'normal',
        ])->assertForbidden();
        auth()->logout();
        $this->get('/')->assertOk()->assertSee('Leave the campus by the main gate.');

        $this->actingAs($coordinator)->putJson("/api/command/campuses/{$home->campus_id}/status", [
            'status' => 'normal',
        ])->assertOk()->assertJsonPath('detail', null);
        auth()->logout();
        $this->get('/')->assertOk()->assertDontSee('Leave the campus by the main gate.');
    }

    private function person(string $role, Campus $campus, string $email): Personnel
    {
        return Personnel::create(['campus_id' => $campus->campus_id, 'full_name' => $role . ' Test',
            'role' => $role, 'email' => $email, 'password_hash' => Hash::make('password')]);
    }
}
