<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The student/staff phone app (docs/PLAN.md §5B), frontend first on sample
 * data: every screen renders inside the mobile shell, says it is a preview,
 * and the installable-app pieces (manifest, service worker, offline page)
 * are served.
 */
class ReporterAppPreviewTest extends TestCase
{
    use RefreshDatabase;

    public static function screens(): array
    {
        return [
            'home' => ['/app', 'Emergency? Press SOS'],
            'sos' => ['/app/sos', 'Press for help'],
            'sos status' => ['/app/sos/status', 'Help is coming'],
            'report' => ['/app/report', 'What is happening?'],
            'reports' => ['/app/reports', 'Your reports'],
            'report detail' => ['/app/reports/1042', 'Report #1042'],
            'alerts' => ['/app/alerts', 'Upcoming drills'],
            'map' => ['/app/map', 'Campus map'],
            'me' => ['/app/me', 'Your location is only sent when you press SOS'],
            'hotlines' => ['/app/hotlines', 'Emergency hotlines'],
            'check-in' => ['/app/check-in', 'Are you safe?'],
        ];
    }

    #[DataProvider('screens')]
    public function test_each_screen_renders_in_the_app_shell_and_says_it_is_sample_data(string $url, string $text): void
    {
        $html = $this->get($url)->assertOk()->assertSee($text, false)->getContent();

        if ($url !== '/app/hotlines') {
            $this->assertStringContainsString('data-sample-data', $html, 'the preview says it is sample data');
        }
        $this->assertStringContainsString('<link rel="manifest" href="' . asset('manifest.webmanifest') . '">', $html);
        $this->assertStringContainsString('aria-label="App"', $html, 'the bottom tab bar');
        $this->assertLessThanOrEqual(1, substr_count($html, 'aria-current="page"'), 'at most one tab is current');
    }

    public function test_an_unknown_report_is_not_found(): void
    {
        $this->get('/app/reports/999999')->assertNotFound();
    }

    public function test_the_hotlines_are_the_real_ones_from_config(): void
    {
        $html = $this->get('/app/hotlines')->assertOk()->getContent();

        foreach (config('hotlines.groups') as $group) {
            $this->assertStringContainsString(e($group['title']), $html);
        }
        // Every number is a tap-to-call link.
        $this->assertStringContainsString('href="tel:09205941906"', $html);
    }

    public function test_sos_is_one_press_with_a_cancel_and_an_offline_text_fallback(): void
    {
        $html = $this->get('/app/sos')->assertOk()->getContent();

        $this->assertStringContainsString('this.seconds = 5', $html, 'a 5-second countdown');
        $this->assertStringContainsString('x-on:click="cancel()"', $html);
        $this->assertStringContainsString("'sms:' + opts.smsTo", $html, 'no internet: send it by text');
    }

    public function test_the_report_form_asks_for_injured_count_photo_captions_and_optional_proof(): void
    {
        $this->get('/app/report')->assertOk()
            ->assertSee('How many people are hurt?')
            ->assertSee('What does this photo show?')
            ->assertSee('Proof or other file (optional)');
    }

    public function test_the_installable_app_pieces_are_served(): void
    {
        $manifest = json_decode(file_get_contents(public_path('manifest.webmanifest')), true);
        $this->assertSame('standalone', $manifest['display']);
        $this->assertSame('/app', $manifest['start_url']);
        $this->assertContains('maskable', array_column($manifest['icons'], 'purpose'));
        foreach ($manifest['icons'] as $icon) {
            $this->assertFileExists(public_path(ltrim($icon['src'], '/')));
        }
        $this->assertSame(['/app/sos', '/app/report', '/app/hotlines'], array_column($manifest['shortcuts'], 'url'));

        $this->assertFileExists(public_path('sw.js'));
        $this->get('/offline')->assertOk()->assertSee('No signal');
    }

    public function test_sign_up_log_in_and_welcome_have_no_tab_bar_and_cover_the_panels_requests(): void
    {
        $html = $this->get('/app/register')->assertOk()->getContent();
        $this->assertStringNotContainsString('aria-label="App"', $html, 'no tab bar while signing up');
        foreach (['Any email works', 'Recovery email (optional)', 'Mobile number', 'Data Privacy Act', 'Your campus coordinator', 'The DRRMO Admin approves', 'data-no-paste'] as $text) {
            $this->assertStringContainsString($text, $html, $text);
        }

        $this->get('/app/login')->assertOk()->assertSee('Forgot your email too?')->assertDontSee('aria-label="App"', false);
        $this->get('/app/welcome')->assertOk()->assertSee('Add to Home Screen')->assertSee('beforeinstallprompt', false);
        $this->get('/app')->assertOk()->assertSee('data-roll-call-banner', false);
    }
}
