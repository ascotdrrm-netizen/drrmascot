<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Pages are tested as HTML; the compiled CSS/JS (npm run build) is not
        // needed for that, so a missing public/build never fails a test.
        $this->withoutVite();

        // The hotline list is read once per request; every test starts fresh.
        \App\Support\Hotlines::flush();
        \App\Support\Settings::flush();
    }
}
