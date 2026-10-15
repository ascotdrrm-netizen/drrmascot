<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExampleTest extends TestCase
{
    use RefreshDatabase;

    /**
     * '/' is the public landing page — guests see it directly rather than
     * being redirected to /login.
     */
    public function test_a_guest_visiting_the_root_sees_the_landing_page(): void
    {
        $response = $this->get('/');

        $response->assertOk();
        $response->assertViewIs('landing');
        $response->assertSee('Login');
        $response->assertSee('Register');
    }
}
