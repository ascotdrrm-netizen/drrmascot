<?php

namespace Tests\Feature;

use App\Support\MaterialIcon;
use Illuminate\Support\Facades\Blade;
use InvalidArgumentException;
use Symfony\Component\Finder\Finder;
use Tests\TestCase;

/**
 * F0 foundation (docs/PLAN.md): every page is served from our own host —
 * Tailwind compiled by Vite, Chart.js and Leaflet copied to public/vendor —
 * and icons are Google Material Symbols inlined by <x-icon>.
 */
class FrontendFoundationTest extends TestCase
{
    public function test_no_view_loads_anything_from_a_cdn(): void
    {
        $offenders = [];

        foreach (Finder::create()->files()->in(resource_path('views'))->name('*.blade.php') as $file) {
            foreach (['cdn.tailwindcss.com', 'cdn.jsdelivr.net', 'unpkg.com', 'cdnjs.cloudflare.com'] as $cdn) {
                if (str_contains($file->getContents(), $cdn)) {
                    $offenders[] = $file->getRelativePathname()." ($cdn)";
                }
            }
        }

        $this->assertSame([], $offenders, 'views that load from a CDN');
    }

    public function test_every_layout_uses_the_compiled_assets(): void
    {
        foreach (['app', 'public', 'guest'] as $layout) {
            $source = file_get_contents(resource_path("views/layouts/$layout.blade.php"));

            $this->assertStringContainsString("@vite(['resources/css/app.css', 'resources/js/app.js'])", $source, $layout);
        }

        $this->assertStringContainsString("asset('vendor/chart.js/chart.umd.js')", file_get_contents(resource_path('views/layouts/app.blade.php')));
    }

    public function test_the_icon_component_inlines_a_material_symbol(): void
    {
        $html = Blade::render('<x-icon name="sos" />');

        $this->assertStringStartsWith('<svg', trim($html));
        $this->assertStringContainsString('viewBox="0 -960 960 960"', $html);
        $this->assertStringContainsString('fill="currentColor"', $html);
        $this->assertStringContainsString('aria-hidden="true"', $html);
        $this->assertStringContainsString('class="h-5 w-5 shrink-0"', $html);
        $this->assertStringContainsString('<path d="M400-280', $html);
    }

    public function test_a_labelled_icon_is_announced_and_takes_its_own_classes(): void
    {
        $html = Blade::render('<x-icon name="call" label="Call" class="h-6 w-6 text-red-600" />');

        $this->assertStringContainsString('role="img" aria-label="Call"', $html);
        $this->assertStringNotContainsString('aria-hidden', $html);
        $this->assertStringContainsString('class="h-6 w-6 text-red-600"', $html);
    }

    public function test_a_colour_only_class_keeps_the_default_size(): void
    {
        $html = Blade::render('<x-icon name="call" class="text-accent-ink" />');

        $this->assertStringContainsString('class="h-5 w-5 shrink-0 text-accent-ink"', $html);
    }

    public function test_the_filled_variant_is_a_different_drawing(): void
    {
        $this->assertNotSame(MaterialIcon::inner('notifications'), MaterialIcon::inner('notifications', fill: true));
    }

    public function test_an_unknown_or_unsafe_icon_name_fails_loudly(): void
    {
        foreach (['no_such_icon_name', '../../.env'] as $name) {
            try {
                MaterialIcon::inner($name);
                $this->fail("[$name] should have thrown");
            } catch (InvalidArgumentException $e) {
                $this->assertNotEmpty($e->getMessage());
            }
        }
    }
}
