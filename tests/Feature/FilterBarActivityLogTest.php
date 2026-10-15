<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Campus;
use App\Models\Personnel;
use DOMDocument;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * The Activity Log on the shared filter bar: free From/To dates (kept — no
 * quarter picker), User for the Admin only, Action; Apply filters; a line
 * built from the filters applied, which the list and the PDF also use.
 */
class FilterBarActivityLogTest extends TestCase
{
    use RefreshDatabase;

    protected Personnel $admin;

    protected Personnel $alpha; // coordinator

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::parse('2026-10-11 09:00:00', 'Asia/Manila'));
        $campus = Campus::create(['campus_name' => 'ASCOT Zabali Campus']);
        $make = fn (string $name, string $role) => Personnel::create(['campus_id' => $campus->campus_id, 'full_name' => $name, 'role' => $role,
            'email' => strtolower(str_replace(' ', '-', $name)) . '@ascot-test.local', 'password_hash' => Hash::make('password')]);
        $this->admin = $make('Log Admin', 'DRRMO Admin');
        $this->alpha = $make('Coordinator Alpha', 'Coordinator');

        ActivityLog::query()->delete();
        $this->entry($this->admin, 'login', '2026-10-01 08:00');
        $this->entry($this->alpha, 'login', '2026-10-05 08:00');
        $this->entry($this->alpha, 'created', '2026-10-10 08:00');
        $this->entry(null, 'login_failed', '2026-09-20 08:00');
        $this->entry(null, 'permanently_deleted', '2026-10-09 01:00', ActivityLog::SYSTEM_ACTOR);
    }

    public function test_the_admins_bar_and_unfiltered_line(): void
    {
        [$x, $r] = $this->page($this->admin, route('admin.activity-log.index'));
        $this->assertSame(5, $r->viewData('logs')->total());
        $this->assertSame(['date_from', 'date_to', 'personnel_id', 'action'], $this->controls($x));
        $this->assertSame('apply', $x->query('//*[@data-filter-bar]//form')->item(0)->getAttribute('data-filter-form'));
        $this->assertSame(0, $x->query('//*[@data-filter-bar]//*[@onchange]')->length, 'Apply replaces submit-on-change');
        $this->assertSame('Showing all entries for everyone, all dates.', $this->summary($x));
        $this->assertSame(0, $x->query('//a[@data-filter-clear]')->length);
    }

    public function test_applied_filters_are_named(): void
    {
        $query = ['action' => 'login', 'personnel_id' => $this->alpha->personnel_id, 'date_from' => '2026-10-01', 'date_to' => '2026-10-11'];
        [$x, $r] = $this->page($this->admin, route('admin.activity-log.index', $query));
        $this->assertSame(1, $r->viewData('logs')->total());
        $this->assertSame('Showing Login entries for Coordinator Alpha, dated Oct 1, 2026 to Oct 11, 2026.', $this->summary($x));
        $this->assertSame(1, $x->query('//a[@data-filter-clear]')->length);

        $lines = [
            [['personnel_id' => 'system'], 'Showing all entries for the system (no user), all dates.', 2],
            [['personnel_id' => 'deleted'], 'Showing all entries for deleted accounts, all dates.', 0],
            [['date_from' => '2026-10-05'], 'Showing all entries for everyone, dated from Oct 5, 2026.', 3],
            [['date_to' => '2026-10-01'], 'Showing all entries for everyone, dated up to Oct 1, 2026.', 2],
            [['date_from' => '2026-10-10', 'date_to' => '2026-10-10'], 'Showing all entries for everyone, dated Oct 10, 2026.', 1],
        ];
        foreach ($lines as [$q, $expected, $count]) {
            [$x, $r] = $this->page($this->admin, route('admin.activity-log.index', $q));
            $this->assertSame($expected, $this->summary($x));
            $this->assertSame($count, $r->viewData('logs')->total(), $expected);
        }
    }

    public function test_a_coordinator_sees_only_their_own_and_no_user_control(): void
    {
        foreach ([[], ['personnel_id' => $this->admin->personnel_id], ['personnel_id' => 'system']] as $query) {
            [$x, $r] = $this->page($this->alpha, route('coordinator.activity-log.index', $query));
            $this->assertSame(['date_from', 'date_to', 'action'], $this->controls($x));
            $this->assertSame(0, $x->query('//select[@name="personnel_id"]')->length);
            $this->assertSame('Showing all entries for your account, all dates.', $this->summary($x));
            $this->assertSame(2, $r->viewData('logs')->total());
            foreach ($r->viewData('logs') as $log) {
                $this->assertSame($this->alpha->personnel_id, $log->personnel_id);
            }
        }
    }

    public function test_unknown_values_are_ignored_on_the_page_and_in_the_pdf(): void
    {
        foreach ([['action' => 'teleported'], ['personnel_id' => '999999'], ['date_from' => '2026-13-45'], ['date_to' => 'yesterday']] as $query) {
            [$x, $r] = $this->page($this->admin, route('admin.activity-log.index', $query));
            $this->assertSame(5, $r->viewData('logs')->total(), json_encode($query));
            $this->assertSame('Showing all entries for everyone, all dates.', $this->summary($x));
            $this->assertSame(0, $x->query('//a[@data-filter-clear]')->length);
        }

        // The PDF reads the same applied filters: a nonsense date is no date.
        $response = $this->actingAs($this->admin)->get(route('admin.activity-log.report', ['date_from' => '2026-13-45']));
        $response->assertOk();
        $this->assertStringContainsString('Activity-Log-All-Dates.pdf', $response->headers->get('Content-Disposition'));
    }

    // ================================ helpers =================================

    protected function entry(?Personnel $by, string $action, string $at, ?string $actorName = null): void
    {
        $log = ActivityLog::create(['personnel_id' => $by?->personnel_id, 'actor_name' => $actorName, 'action' => $action, 'description' => "{$action} at {$at}"]);
        $log->forceFill(['created_at' => Carbon::parse($at, 'Asia/Manila')])->save();
    }

    protected function page(Personnel $as, string $url): array
    {
        $response = $this->actingAs($as)->get($url)->assertOk();
        $dom = new DOMDocument;
        libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="UTF-8">' . $response->getContent());
        libxml_clear_errors();

        return [new DOMXPath($dom), $response];
    }

    protected function summary(DOMXPath $x): string
    {
        return trim(preg_replace('/\s+/u', ' ', $x->query('//*[@data-filter-summary]')->item(0)->textContent));
    }

    protected function controls(DOMXPath $x): array
    {
        return array_map(fn ($n) => $n->getAttribute('data-filter-control'), iterator_to_array($x->query('//*[@data-filter-bar]//*[@data-filter-control]')));
    }
}
