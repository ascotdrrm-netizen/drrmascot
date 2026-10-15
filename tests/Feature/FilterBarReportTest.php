<?php

namespace Tests\Feature;

use App\Models\Campus;
use App\Models\Personnel;
use App\Models\School;
use DOMDocument;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * The Consolidated Report on the shared filter bar: Period (always a period —
 * no "All dates"), Campus for the Admin, School; "compared with" the period
 * before; Clear filters only away from the default (this quarter, every
 * campus, every school). The school filter narrows incidents and inspections
 * only, and a coordinator's incidents and inspections are their own while
 * drills are the campus's: the line says which is which.
 */
class FilterBarReportTest extends TestCase
{
    use RefreshDatabase;

    protected Campus $zabali;

    protected Campus $bazal;

    protected School $engineering;

    protected Personnel $admin;

    protected Personnel $alpha;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::parse('2026-10-11 09:00:00', 'Asia/Manila'));
        $this->zabali = Campus::create(['campus_name' => 'ASCOT Zabali Campus']);
        $this->bazal = Campus::create(['campus_name' => 'ASCOT Bazal Campus']);
        $this->engineering = School::create(['campus_id' => $this->zabali->campus_id, 'school_name' => 'School of Engineering']);
        $make = fn (Campus $campus, string $name, string $role) => Personnel::create(['campus_id' => $campus->campus_id, 'full_name' => $name, 'role' => $role,
            'email' => strtolower(str_replace(' ', '-', $name)) . '@ascot-test.local', 'password_hash' => Hash::make('password')]);
        $this->admin = $make($this->zabali, 'Report Admin', 'DRRMO Admin');
        $this->alpha = $make($this->zabali, 'Coordinator Alpha', 'Coordinator');
    }

    public function test_the_admins_default_view(): void
    {
        $x = $this->page($this->admin, route('reports.consolidated.index'));
        $this->assertSame(['period', 'campus', 'school'], $this->controls($x));
        $this->assertSame('apply', $x->query('//*[@data-filter-bar]//form')->item(0)->getAttribute('data-filter-form'));
        $this->assertSame('Apply filters', trim($x->query('//button[@data-filter-apply]')->item(0)->textContent));
        // The report always has a period: no "All dates"; this quarter by default.
        $options = array_map(fn ($o) => $o->getAttribute('value'), iterator_to_array($x->query('//*[@data-filter-bar]//select[@name="period_type"]/option')));
        $this->assertSame(['weekly', 'monthly', 'quarterly', 'yearly', 'custom'], $options);
        $this->assertSame('Showing Q4 2026 (October 1 – December 31, 2026) for all campuses — compared with Q3 2026 (July 1 – September 30, 2026).', $this->summary($x));
        $this->assertSame(0, $x->query('//a[@data-filter-clear]')->length, 'the default quarter is not a filter');
    }

    public function test_away_from_the_default_clear_filters_returns_to_it(): void
    {
        foreach ([
            ['period_type' => 'quarterly', 'quarter' => 3, 'year' => 2026],
            ['period_type' => 'quarterly', 'quarter' => 4, 'year' => 2025],
            ['period_type' => 'monthly', 'month' => 10, 'year' => 2026],
            ['period_type' => 'quarterly', 'quarter' => 4, 'year' => 2026, 'campus_id' => $this->bazal->campus_id],
        ] as $query) {
            $x = $this->page($this->admin, route('reports.consolidated.index', $query));
            $clear = $x->query('//a[@data-filter-clear]')->item(0);
            $this->assertNotNull($clear, json_encode($query));
            $this->assertSame(route('reports.consolidated.index'), $clear->getAttribute('href'));
        }
    }

    public function test_the_admins_line_names_campus_and_school(): void
    {
        $q3 = ['period_type' => 'quarterly', 'quarter' => 3, 'year' => 2026];
        $cases = [
            [$q3 + ['campus_id' => $this->bazal->campus_id], 'for ASCOT Bazal Campus'],
            [$q3 + ['campus_id' => $this->zabali->campus_id, 'school_id' => $this->engineering->school_id],
                'for School of Engineering’s incidents and inspections, and every drill and advisory, on ASCOT Zabali Campus'],
            // A school picked under All campuses still names its campus.
            [$q3 + ['school_id' => $this->engineering->school_id], 'for School of Engineering’s incidents and inspections, and every drill and advisory, on ASCOT Zabali Campus'],
            [$q3 + ['school_id' => 'none'], 'for incidents and inspections with no school set, and every drill and advisory, on all campuses'],
        ];
        foreach ($cases as [$query, $who]) {
            $x = $this->page($this->admin, route('reports.consolidated.index', $query));
            $this->assertSame("Showing Q3 2026 (July 1 – September 30, 2026) {$who} — compared with Q2 2026 (April 1 – June 30, 2026).", $this->summary($x));
        }
    }

    public function test_a_coordinators_line_and_bar(): void
    {
        foreach ([[], ['campus_id' => $this->bazal->campus_id]] as $query) {
            $x = $this->page($this->alpha, route('reports.consolidated.index', $query));
            $this->assertSame(['period', 'school'], $this->controls($x));
            $this->assertSame(0, $x->query('//select[@name="campus_id"]')->length);
            $this->assertSame('Showing Q4 2026 (October 1 – December 31, 2026) for your incidents and inspections, and every drill and advisory, on ASCOT Zabali Campus'
                . ' — compared with Q3 2026 (July 1 – September 30, 2026).', $this->summary($x));
        }

        $x = $this->page($this->alpha, route('reports.consolidated.index', ['school_id' => $this->engineering->school_id]));
        $this->assertStringContainsString('for your School of Engineering incidents and inspections, and every drill and advisory, on ASCOT Zabali Campus', $this->summary($x));
        $x = $this->page($this->alpha, route('reports.consolidated.index', ['school_id' => 'none']));
        $this->assertStringContainsString('for your incidents and inspections with no school set, and every drill and advisory, on ASCOT Zabali Campus', $this->summary($x));
    }

    public function test_one_wording_compared_with(): void
    {
        $html = $this->actingAs($this->admin)->get(route('reports.consolidated.index'))->getContent();
        $this->assertStringContainsString('compared with', $html);
        $this->assertStringNotContainsString('compared against', $html);
    }

    // ================================ helpers =================================

    protected function page(Personnel $as, string $url): DOMXPath
    {
        $dom = new DOMDocument;
        libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="UTF-8">' . $this->actingAs($as)->get($url)->assertOk()->getContent());
        libxml_clear_errors();

        return new DOMXPath($dom);
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
