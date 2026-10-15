<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The landing page's seals use the same --seal trim as the app and sign-in
 * layouts, so both circles come out the same size (with h-16 w-auto they drew
 * at about 55px and 46px, the files padding their artwork differently). The
 * other two layouts' sizes are locked here so a landing change can't drift them.
 */
class LandingSealsTest extends TestCase
{
    use RefreshDatabase;

    /** The trim formulas all three layouts share: [height divisor, margin factors]. */
    protected const ASCOT = ['0.853', 'margin: calc(var(--seal) * -0.0868);'];

    protected const DRRM = ['0.710', 'margin: calc(var(--seal) * -0.2028) calc(var(--seal) * -0.3612) calc(var(--seal) * -0.2056) calc(var(--seal) * -0.3195);'];

    public function test_the_landing_seals_use_the_trim_classes_and_share_one_seal_size(): void
    {
        $html = $this->get('/')->assertOk()->getContent();

        // Both images sit in one container that sets --seal, with the trim classes and no fixed height.
        $this->assertSame(1, preg_match('/<div class="landing-seals[^"]*" data-landing-seals>\s*(<img[^>]*>)\s*(<img[^>]*>)\s*<\/div>/', $html, $m), 'the landing seal row');
        $this->assertMatchesRegularExpression('/src="[^"]*images\/ascot-logo-256\.png"[^>]*class="landing-seal-ascot"/', $m[1]);
        $this->assertMatchesRegularExpression('/src="[^"]*images\/drrm-logo-256\.png"[^>]*class="landing-seal-drrm"/', $m[2]);
        foreach ([$m[1], $m[2]] as $img) {
            $this->assertStringNotContainsString('h-16', $img, 'a fixed height would bypass the trim');
        }

        // --seal is set once, on the container (so both circles resolve to the same value),
        // stepping 80 → 96 → 112px; never on an individual image class.
        $this->assertSame(1, preg_match('/<style>(.*?)<\/style>\s*<div class="landing-seals/s', $html, $css));
        $css = $css[1];
        $this->assertMatchesRegularExpression('/\.landing-seals \{ --seal: 80px; \}/', $css);
        $this->assertMatchesRegularExpression('/@media \(min-width: 640px\) \{ \.landing-seals \{ --seal: 96px; \} \}/', $css);
        $this->assertMatchesRegularExpression('/@media \(min-width: 1024px\) \{ \.landing-seals \{ --seal: 112px; \} \}/', $css);
        $this->assertDoesNotMatchRegularExpression('/\.landing-seal-(ascot|drrm)[^{]*\{[^}]*--seal\s*:/', $css, 'an image class must not set its own --seal');

        // The same trim formulas as the other layouts.
        $this->assertStringContainsString('height: calc(var(--seal) / ' . self::ASCOT[0] . ');', $this->rule($css, '.landing-seal-ascot'));
        $this->assertStringContainsString(self::ASCOT[1], $this->rule($css, '.landing-seal-ascot'));
        $this->assertStringContainsString('height: calc(var(--seal) / ' . self::DRRM[0] . ');', $this->rule($css, '.landing-seal-drrm'));
        $this->assertStringContainsString(self::DRRM[1], $this->rule($css, '.landing-seal-drrm'));
    }

    public function test_the_app_and_sign_in_seal_sizes_are_unchanged(): void
    {
        $app = file_get_contents(resource_path('views/layouts/app.blade.php'));
        $this->assertMatchesRegularExpression('/\.app-seals \{ --seal: 64px; \}/', $app, 'app layout: 64px page-header seals');
        $this->assertMatchesRegularExpression('/\.app-seals-bar \{ --seal: 36px; \}/', $app, 'app layout: 36px phone top bar');

        $guest = file_get_contents(resource_path('views/layouts/guest.blade.php'));
        $this->assertMatchesRegularExpression('/\.auth-seals \{ --seal: 80px; \}/', $guest, 'sign-in: 80px base');
        $this->assertMatchesRegularExpression('/@media \(min-width: 640px\) \{ \.auth-seals \{ --seal: 88px; \} \}/', $guest, 'sign-in: 88px from 640px');

        // And the same trim formulas there as on the landing page.
        foreach (['app-seal' => $app, 'auth-seal' => $guest] as $prefix => $css) {
            $this->assertStringContainsString(self::ASCOT[1], $this->rule($css, ".{$prefix}-ascot"), "$prefix: ASCOT trim");
            $this->assertStringContainsString(self::DRRM[1], $this->rule($css, ".{$prefix}-drrm"), "$prefix: DRRM trim");
        }
    }

    /** The body of the CSS rule whose selector is exactly $selector (alone at the start of its line). */
    protected function rule(string $css, string $selector): string
    {
        $this->assertSame(1, preg_match('/^\s*' . preg_quote($selector, '/') . '\s*\{([^}]*)\}/m', $css, $m), "no rule for $selector");

        return $m[1];
    }
}
