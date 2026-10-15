<?php

namespace Tests\Feature;

use App\Models\Campus;
use App\Models\Personnel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class LandingPageTest extends TestCase
{
    use RefreshDatabase;

    protected Campus $campus;

    protected function setUp(): void
    {
        parent::setUp();

        $this->campus = Campus::create(['campus_name' => 'ASCOT Zabali Campus']);
    }

    public function test_guest_sees_landing_page_with_login_and_register_and_no_dashboard_link(): void
    {
        $response = $this->get('/');

        $response->assertOk();
        $response->assertSee('Login');
        $response->assertSee('Register');
        $response->assertDontSee('Go to Dashboard');
    }

    public function test_landing_page_shows_hero_and_modules(): void
    {
        $response = $this->get('/');

        $response->assertSee('ASCOT Disaster Risk Reduction and Management System');
        $response->assertSee('ASCOT Zabali Campus');
        $response->assertSee('Facility Hazard Auditor');
        $response->assertSee('Incident Reporting');
        $response->assertSee('Disaster Advisories');
        $response->assertSee('Drill Tracker');
        $response->assertSee('SMS &amp; Broadcast Alert', false);
        // The Facebook page is a plain link (no embed), so people can still find it.
        $response->assertSee('<a href="https://www.facebook.com/profile.php?id=61579337100805" target="_blank" rel="noopener noreferrer"', false);
    }

    /**
     * The embedded Facebook feed ("Latest Updates") was removed; the page links
     * to Facebook instead (see the hero test). The unused info panel
     * (landing/_info-panel.blade.php, kept for later) must not be included:
     * its three markers below appear nowhere else.
     */
    public function test_neither_the_feed_nor_the_unused_info_panel_is_on_the_page(): void
    {
        $response = $this->get('/');

        $response->assertDontSee('fb-page', false);
        $response->assertDontSee('Latest Updates');
        $response->assertDontSee('Follow ASCOT DRRMO');
        $response->assertDontSee('Be Prepared');
        $response->assertDontSee('Our Campuses');
    }

    /**
     * The public page loads resources from our own host only: every script
     * src, link href and img/iframe/source src is collected, and there must be
     * no outside host at all (Tailwind is compiled by Vite, not the CDN).
     * Adding any third party (fonts, analytics, an embed…) fails here until
     * someone deliberately decides to allow it.
     */
    public function test_the_landing_page_loads_only_from_our_host(): void
    {
        $html = $this->get('/')->assertOk()->getContent();

        $this->assertSame([], $this->externalHosts($html), 'outside hosts the landing page loads from');

        // No SDK, no widget markup, no FB calls anywhere in the page.
        foreach (['connect.facebook.net', 'fb-root', 'fb-page', 'FB.', 'xfbml'] as $trace) {
            $this->assertStringNotContainsString($trace, $html, "Facebook embed trace: $trace");
        }

        // The only mention left is the plain footer link.
        preg_match_all('/<a\b[^>]*facebook\.com[^>]*>/i', $html, $links);
        $this->assertCount(1, $links[0], 'exactly one link to Facebook');
        $this->assertStringContainsString('rel="noopener noreferrer"', $links[0][0]);
        $this->assertSame(1, substr_count(strtolower($html), 'facebook.com'), 'facebook.com appears only in that link');
    }

    /** Hosts (other than our own) that the page loads resources from. */
    protected function externalHosts(string $html): array
    {
        $document = new \DOMDocument();
        libxml_use_internal_errors(true);
        $document->loadHTML('<?xml encoding="utf-8"?>' . $html);
        libxml_clear_errors();
        $xpath = new \DOMXPath($document);

        $own = parse_url(config('app.url'), PHP_URL_HOST);
        $hosts = [];
        foreach (['//script[@src]/@src', '//link[@href]/@href', '//img[@src]/@src', '//iframe[@src]/@src', '//source[@src]/@src'] as $query) {
            foreach ($xpath->query($query) as $attribute) {
                $host = parse_url(str_starts_with($attribute->value, '//') ? 'https:' . $attribute->value : $attribute->value, PHP_URL_HOST);
                if ($host && strcasecmp($host, (string) $own) !== 0) {
                    $hosts[strtolower($host)] = true;
                }
            }
        }
        ksort($hosts);

        return array_keys($hosts);
    }

    public function test_logged_in_coordinator_sees_go_to_dashboard_instead_of_login_and_register(): void
    {
        $coordinator = Personnel::create([
            'campus_id' => $this->campus->campus_id,
            'full_name' => 'Test Coordinator',
            'role' => 'Coordinator',
            'email' => 'coord@ascot-test.local',
            'password_hash' => Hash::make('password'),
        ]);

        $response = $this->actingAs($coordinator)->get('/');

        $response->assertOk();
        $response->assertSee('Go to Dashboard');
        $response->assertSee('/coordinator/dashboard', false);
        $response->assertDontSee('Login');
        $response->assertDontSee('Register');
    }

    public function test_logged_in_admin_sees_go_to_dashboard_pointing_at_admin_dashboard(): void
    {
        $admin = Personnel::create([
            'campus_id' => $this->campus->campus_id,
            'full_name' => 'Test Admin',
            'role' => 'DRRMO Admin',
            'email' => 'admin@ascot-test.local',
            'password_hash' => Hash::make('password'),
        ]);

        $response = $this->actingAs($admin)->get('/');

        $response->assertOk();
        $response->assertSee('Go to Dashboard');
        $response->assertSee('/admin/dashboard', false);
    }

    public function test_no_facebook_embed_on_the_dashboards(): void
    {
        $admin = Personnel::create([
            'campus_id' => $this->campus->campus_id,
            'full_name' => 'Test Admin',
            'role' => 'DRRMO Admin',
            'email' => 'admin@ascot-test.local',
            'password_hash' => Hash::make('password'),
        ]);
        $coordinator = Personnel::create([
            'campus_id' => $this->campus->campus_id,
            'full_name' => 'Test Coordinator',
            'role' => 'Coordinator',
            'email' => 'coord@ascot-test.local',
            'password_hash' => Hash::make('password'),
        ]);

        $adminResponse = $this->actingAs($admin)->get(route('admin.dashboard'));
        $adminResponse->assertOk();
        $adminResponse->assertDontSee('Latest Updates');
        $adminResponse->assertDontSee('fb-page', false);
        $adminResponse->assertDontSee('connect.facebook.net', false);
        $adminResponse->assertDontSee('fb-root', false);

        $coordResponse = $this->actingAs($coordinator)->get(route('coordinator.dashboard'));
        $coordResponse->assertOk();
        $coordResponse->assertDontSee('Latest Updates');
        $coordResponse->assertDontSee('fb-page', false);
        $coordResponse->assertDontSee('connect.facebook.net', false);
        $coordResponse->assertDontSee('fb-root', false);
    }

    public function test_protected_routes_still_redirect_guests_to_login(): void
    {
        $this->get(route('admin.dashboard'))->assertRedirect('/login');
        $this->get(route('coordinator.dashboard'))->assertRedirect('/login');
    }

    public function test_logout_redirects_to_the_landing_page(): void
    {
        $admin = Personnel::create([
            'campus_id' => $this->campus->campus_id,
            'full_name' => 'Test Admin',
            'role' => 'DRRMO Admin',
            'email' => 'admin@ascot-test.local',
            'password_hash' => Hash::make('password'),
        ]);

        $response = $this->actingAs($admin)->post('/logout');

        $response->assertRedirect('/');
    }

    public function test_login_and_register_pages_link_back_to_the_landing_page(): void
    {
        $this->get(route('login'))->assertSee('Back to Home');
        $this->get(route('register'))->assertSee('Back to Home');
    }
}
