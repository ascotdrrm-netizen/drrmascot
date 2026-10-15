<?php

namespace Tests\Feature;

use App\Models\Campus;
use App\Models\Personnel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Errors keep the system's look and never strand anyone: each page says what
 * happened in plain words, offers a way back, and still shows the DRRMO
 * hotline (resources/views/errors).
 */
class ErrorPagesTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_missing_page_is_themed_with_a_way_home_and_the_hotline(): void
    {
        $this->get('/no-such-page')->assertNotFound()
            ->assertSee('data-error-page="404"', false)
            ->assertSee('Page not found')
            ->assertSee('Go to the home page')
            ->assertSee('href="tel:09171529432"', false);
    }

    public function test_a_coordinator_opening_an_admin_page_gets_the_themed_403(): void
    {
        $campus = Campus::create(['campus_name' => 'ASCOT Zabali Campus']);
        $coordinator = Personnel::forceCreate([
            'campus_id' => $campus->campus_id, 'full_name' => 'Ana Cruz', 'role' => 'Coordinator',
            'email' => 'ana@example.com', 'password_hash' => Hash::make('Password123!'), 'is_approved' => true,
        ]);

        $this->actingAs($coordinator)->get(route('admin.personnel.index'))->assertForbidden()
            ->assertSee('data-error-page="403"', false)
            ->assertSee('ask the DRRMO Admin');
    }

    public function test_every_error_page_extends_the_shared_layout(): void
    {
        foreach ([403, 404, 419, 429, 500, 503] as $code) {
            $html = view("errors.$code")->render();
            $this->assertStringContainsString('data-error-page="' . $code . '"', $html, (string) $code);
        }
    }
}
