<?php

namespace Tests\Feature;

use App\Models\Advisory\DisasterAdvisory;
use App\Models\Campus;
use App\Models\Personnel;
use App\Support\RecordAccess;
use DOMDocument;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Disaster Advisories on the shared filter bar: Period (issued_at, all dates
 * by default), Campus for the Admin only, Status and Hazard. Advisories are
 * campus-wide, so a coordinator's line says "all" — with the all-campus ones
 * named — and never names another campus.
 */
class FilterBarAdvisoriesTest extends TestCase
{
    use RefreshDatabase;

    protected Campus $zabali;

    protected Campus $bazal;

    protected Personnel $admin;

    protected Personnel $alpha; // coordinator, Zabali

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::parse('2026-10-11 09:00:00', 'Asia/Manila'));
        $this->zabali = Campus::create(['campus_name' => 'ASCOT Zabali Campus']);
        $this->bazal = Campus::create(['campus_name' => 'ASCOT Bazal Campus']);
        $make = fn (Campus $campus, string $name, string $role) => Personnel::create(['campus_id' => $campus->campus_id, 'full_name' => $name, 'role' => $role,
            'email' => strtolower(str_replace(' ', '-', $name)) . '@ascot-test.local', 'password_hash' => Hash::make('password')]);
        $this->admin = $make($this->zabali, 'Advisory Filter Admin', 'DRRMO Admin');
        $this->alpha = $make($this->zabali, 'Coordinator Alpha', 'Coordinator');

        $this->advisory('Zabali typhoon 2019', $this->zabali, '2019-07-01 08:00', 'Passed');
        $this->advisory('Zabali flood live', $this->zabali, '2026-10-10 08:00', 'Active', 'Flood');
        $this->advisory('Zabali typhoon expired', $this->zabali, '2026-09-30 23:30', 'Active', 'Typhoon', '2026-10-01 08:00');
        $this->advisory('All-campus quake', null, '2026-10-01 00:10', 'Cancelled', 'Earthquake');
        $this->advisory('Bazal typhoon', $this->bazal, '2026-08-15 10:00', 'Active');
    }

    public function test_all_dates_is_the_default_and_an_unfiltered_list_shows_everything_the_viewer_may_open(): void
    {
        [$x, $r] = $this->page($this->admin, route('admin.advisories.index'));
        $this->assertSame(DisasterAdvisory::pluck('advisory_id')->sort()->values()->all(), $r->viewData('advisories')->pluck('advisory_id')->sort()->values()->all());
        $this->assertSame('', $x->query('//*[@data-filter-bar]//select[@name="period_type"]/option[@selected]')->item(0)->getAttribute('value'));
        $this->assertSame('Showing all advisories for all campuses, all dates.', $this->summary($x));

        [$x, $r] = $this->page($this->alpha, route('coordinator.advisories.index'));
        $allowed = RecordAccess::applyToAdvisories(DisasterAdvisory::query(), $this->alpha)->pluck('advisory_id')->sort()->values()->all();
        $listed = $r->viewData('advisories')->pluck('advisory_id')->sort()->values()->all();
        $this->assertSame($allowed, $listed);
        $this->assertCount(4, $listed, 'Zabali\'s three, the all-campus one, from 2019 on');
        foreach (DisasterAdvisory::all() as $advisory) {
            $this->actingAs($this->alpha)->get(route('coordinator.advisories.show', $advisory))->assertStatus(in_array($advisory->advisory_id, $listed, true) ? 200 : 403);
        }
        $this->assertSame('', $x->query('//*[@data-filter-bar]//select[@name="period_type"]/option[@selected]')->item(0)->getAttribute('value'));
    }

    public function test_the_coordinators_bar_and_line(): void
    {
        foreach ([[], ['campus_id' => $this->bazal->campus_id]] as $query) {
            [$x, $r] = $this->page($this->alpha, route('coordinator.advisories.index', $query));
            $this->assertSame(['period', 'status', 'hazard_type'], $this->controls($x));
            $this->assertSame(0, $x->query('//select[@name="campus_id"]')->length);
            $this->assertSame('Showing all advisories (all-campus ones included) for ASCOT Zabali Campus, all dates.', $this->summary($x));
            $this->assertNotContains('Bazal typhoon', $r->viewData('advisories')->pluck('headline')->all());
        }
    }

    public function test_the_admins_bar_and_line(): void
    {
        [$x] = $this->page($this->admin, route('admin.advisories.index'));
        $this->assertSame(['period', 'campus', 'status', 'hazard_type'], $this->controls($x));
        $this->assertSame('apply', $x->query('//*[@data-filter-bar]//form')->item(0)->getAttribute('data-filter-form'));

        [$x, $r] = $this->page($this->admin, route('admin.advisories.index', ['campus_id' => $this->bazal->campus_id, 'hazard_type' => 'Typhoon']));
        $this->assertSame(['Bazal typhoon'], $r->viewData('advisories')->pluck('headline')->all());
        $this->assertSame('Showing all Typhoon advisories (all-campus ones included) for ASCOT Bazal Campus, all dates.', $this->summary($x));
        $this->assertSame(1, $x->query('//a[@data-filter-clear]')->length);

        // A campus alone: that campus's advisories and the all-campus one, as the line says.
        [$x, $r] = $this->page($this->admin, route('admin.advisories.index', ['campus_id' => $this->bazal->campus_id]));
        $this->assertEqualsCanonicalizing(['Bazal typhoon', 'All-campus quake'], $r->viewData('advisories')->pluck('headline')->all());
        $this->assertSame('Showing all advisories (all-campus ones included) for ASCOT Bazal Campus, all dates.', $this->summary($x));
    }

    public function test_status_in_effect_also_lists_expired_and_the_line_says_so(): void
    {
        [$x, $r] = $this->page($this->admin, route('admin.advisories.index', ['status' => 'Active']));
        $this->assertEqualsCanonicalizing(['Zabali flood live', 'Zabali typhoon expired', 'Bazal typhoon'], $r->viewData('advisories')->pluck('headline')->all());
        $this->assertSame('Showing all advisories with status In effect or Expired for all campuses, all dates.', $this->summary($x));

        [$x] = $this->page($this->admin, route('admin.advisories.index', ['status' => 'Cancelled']));
        $this->assertSame('Showing all advisories with status Cancelled for all campuses, all dates.', $this->summary($x));
    }

    public function test_the_period_filters_issued_at_to_the_minute(): void
    {
        [$x, $r] = $this->page($this->admin, route('admin.advisories.index', ['period_type' => 'quarterly', 'quarter' => 3, 'year' => 2026]));
        // Issued Sep 30 23:30 is in Q3; Oct 1 00:10 is not.
        $this->assertEqualsCanonicalizing(['Zabali typhoon expired', 'Bazal typhoon'], $r->viewData('advisories')->pluck('headline')->all());
        $this->assertSame('Showing all advisories for all campuses, dated Q3 2026 (July 1 – September 30, 2026).', $this->summary($x));

        [, $r] = $this->page($this->alpha, route('coordinator.advisories.index', ['period_type' => 'monthly', 'month' => 10, 'year' => 2026]));
        $this->assertEqualsCanonicalizing(['Zabali flood live', 'All-campus quake'], $r->viewData('advisories')->pluck('headline')->all());
    }

    public function test_unknown_values_are_ignored(): void
    {
        foreach ([['status' => 'Exploded'], ['hazard_type' => 'Meteor'], ['campus_id' => '999999'], ['period_type' => 'fortnightly']] as $query) {
            [$x, $r] = $this->page($this->admin, route('admin.advisories.index', $query));
            $this->assertCount(5, $r->viewData('advisories'), json_encode($query));
            $this->assertSame('Showing all advisories for all campuses, all dates.', $this->summary($x));
        }
    }

    // ================================ helpers =================================

    protected function advisory(string $headline, ?Campus $campus, string $issued, string $status, string $hazard = 'Typhoon', ?string $validUntil = null): DisasterAdvisory
    {
        $issuedAt = Carbon::parse($issued, 'Asia/Manila');

        return DisasterAdvisory::forceCreate([
            'campus_id' => $campus?->campus_id, 'hazard_type' => $hazard, 'headline' => $headline, 'measurements' => [],
            'source' => 'PAGASA', 'issued_at' => $issuedAt, 'valid_until' => $validUntil ? Carbon::parse($validUntil, 'Asia/Manila') : now()->addDay(),
            'status' => $status, 'instructions' => 'Stay safe.', 'created_by' => $this->admin->personnel_id,
        ]);
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
