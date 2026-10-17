<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SecurityHeadersTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_page_gets_browser_protections(): void
    {
        foreach (['/', '/login', '/app/register', '/up', '/does-not-exist'] as $url) {
            $response = $this->get($url);
            $response->assertHeader('X-Frame-Options', 'SAMEORIGIN');
            $response->assertHeader('X-Content-Type-Options', 'nosniff');
            $response->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin');
            $this->assertStringContainsString('geolocation=(self)', $response->headers->get('Permissions-Policy'));
            $response->assertHeaderMissing('Strict-Transport-Security');
        }
    }

    public function test_https_pages_tell_the_browser_to_stay_on_https(): void
    {
        $this->get('https://localhost/login')->assertHeader('Strict-Transport-Security', 'max-age=31536000');
    }
}
