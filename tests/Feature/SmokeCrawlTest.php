<?php

namespace Tests\Feature;

use App\Models\Personnel;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\DemoDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Smoke crawl: on the demo data, every page and download a role can reach by
 * following links opens without a server error. Catches broken views,
 * missing variables and failing PDFs that no single-feature test opens.
 */
class SmokeCrawlTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('records');
        $this->seed(DatabaseSeeder::class);
        $this->seed(DemoDataSeeder::class);
    }

    /** @return array<string, string> url => problem */
    protected function crawl(Personnel $user, string $start, int $limit = 400): array
    {
        $this->actingAs($user);
        $queue = [$start];
        $seen = [];
        $problems = [];
        $host = parse_url(config('app.url'), PHP_URL_HOST);

        while ($queue && count($seen) < $limit) {
            $url = array_shift($queue);
            $path = parse_url($url, PHP_URL_PATH) ?: '/';
            $query = parse_url($url, PHP_URL_QUERY);
            $key = $path . ($query ? "?$query" : '');
            // Static files are served by Apache, not Laravel.
            if (isset($seen[$key]) || preg_match('#/logout|/files/|/csrf-refresh|^/(build|vendor|icons|images)/|\.(webmanifest|js|css|png|jpg|svg|ico)$#', $path)) {
                continue;
            }
            $seen[$key] = true;

            $response = $this->get($key);
            $status = $response->getStatusCode();
            if ($status >= 500 || in_array($status, [404, 405], true)) {
                $message = $response->exception ? get_class($response->exception) . ': ' . $response->exception->getMessage() : '';
                $problems[$key] = $status . ' ' . mb_substr($message, 0, 300);
                continue;
            }
            if ($response->isRedirect()) {
                $queue[] = $response->headers->get('Location');
                continue;
            }
            $type = (string) $response->headers->get('Content-Type');
            if (! str_contains($type, 'text/html')) {
                continue;
            }
            $html = $response->getContent();
            // Real links only: not Alpine bindings (:href="selected.tel").
            preg_match_all('/(?<![:\w-])href="([^"#]+)"/', $html, $m);
            foreach ($m[1] as $href) {
                $href = html_entity_decode($href);
                $h = parse_url($href, PHP_URL_HOST);
                if ($h !== null && $h !== $host) {
                    continue;
                }
                if (! preg_match('#^(/|https?://)#', $href) || str_contains($href, chr(92))) {
                    continue;
                }
                $queue[] = $href;
            }
        }

        return $problems;
    }

    public function test_admin_pages_open(): void
    {
        $admin = Personnel::where('role', 'DRRMO Admin')->firstOrFail();
        $this->assertSame([], $this->crawl($admin, '/admin/dashboard'));
    }

    public function test_coordinator_pages_open(): void
    {
        $admin = Personnel::where('role', 'DRRMO Admin')->firstOrFail();
        $coordinator = Personnel::forceCreate(['campus_id' => $admin->campus_id, 'full_name' => 'Crawl Coordinator', 'role' => 'Coordinator',
            'email' => 'crawl-coord@example.test', 'password_hash' => Hash::make('password'), 'is_approved' => true, 'email_verified_at' => now()]);
        $this->assertSame([], $this->crawl($coordinator, '/coordinator/dashboard'));
    }

    public function test_reporter_pages_open(): void
    {
        $admin = Personnel::where('role', 'DRRMO Admin')->firstOrFail();
        $reporter = Personnel::forceCreate(['campus_id' => $admin->campus_id, 'full_name' => 'Crawl Student', 'role' => 'Reporter', 'reporter_kind' => 'Student',
            'email' => 'crawl-student@example.test', 'password_hash' => Hash::make('password'), 'is_approved' => true, 'email_verified_at' => now(),
            'validated_by_coordinator_at' => now(), 'approved_at' => now(), 'privacy_consented_at' => now()]);
        $this->assertSame([], $this->crawl($reporter, '/app'));
    }
}
