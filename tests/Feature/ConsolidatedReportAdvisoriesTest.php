<?php

namespace Tests\Feature;

use App\Models\Advisory\DisasterAdvisory;
use App\Models\Campus;
use App\Models\Incident\Incident;
use App\Models\Personnel;
use App\Models\School;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Tests\Concerns\ReadsReportHtml;
use Tests\TestCase;

/**
 * The Consolidated Report's Disaster Advisories section: advisories ISSUED in
 * the period, by hazard and source, and how many were followed by a recorded
 * incident — whenever that incident was recorded, by anyone, and never by
 * one in Trash. Campus-wide (all-campus advisories under every campus), no
 * school, and the same "followed" rule as the advisory's own page.
 */
class ConsolidatedReportAdvisoriesTest extends TestCase
{
    use RefreshDatabase, ReadsReportHtml;

    protected Campus $zabali;

    protected Campus $bazal;

    protected School $engineering;

    protected Personnel $admin;

    protected Personnel $alpha;   // coordinator, Zabali

    protected Personnel $bravo;   // coordinator, Zabali

    protected array $q3 = ['period_type' => 'quarterly', 'quarter' => 3, 'year' => 2026];

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::parse('2026-10-12 09:00:00', 'Asia/Manila'));
        $this->zabali = Campus::create(['campus_name' => 'ASCOT Zabali Campus']);
        $this->bazal = Campus::create(['campus_name' => 'ASCOT Bazal Campus']);
        $this->engineering = School::create(['campus_id' => $this->zabali->campus_id, 'school_name' => 'School of Engineering']);
        $make = fn (Campus $campus, string $name, string $role) => Personnel::create(['campus_id' => $campus->campus_id, 'full_name' => $name, 'role' => $role,
            'email' => strtolower(str_replace(' ', '-', $name)) . '@ascot-test.local', 'password_hash' => Hash::make('password')]);
        $this->admin = $make($this->zabali, 'Report Admin', 'DRRMO Admin');
        $this->alpha = $make($this->zabali, 'Coordinator Alpha', 'Coordinator');
        $this->bravo = $make($this->zabali, 'Coordinator Bravo', 'Coordinator');
    }

    // ============================ what is counted ============================

    public function test_issued_in_the_period_by_issued_at_and_not_in_trash(): void
    {
        // A different hazard each, so the test pins WHICH advisories count, not just how many.
        $this->advisory('Q3 start', $this->zabali, '2026-07-01 00:05', 'Typhoon');
        $this->advisory('Q3 end', $this->zabali, '2026-09-30 23:55', 'Earthquake');
        $this->advisory('Q2', $this->zabali, '2026-06-30 23:55', 'Flood');           // valid until Jul 1: still not "issued in Q3"
        $this->advisory('Q4', $this->zabali, '2026-10-01 00:05', 'Landslide');
        $this->advisory('Q3 trashed', $this->zabali, '2026-08-01 08:00', 'Storm Surge')->delete();

        $section = $this->report($this->admin, $this->q3)['advisories'];
        $this->assertSame(2, $section['total']);
        $hazards = array_keys($section['by_hazard_type']);
        sort($hazards);
        $this->assertSame(['Earthquake', 'Typhoon'], $hazards, 'the two issued in Q3, not the Q2 one valid into July');
    }

    public function test_by_hazard_and_by_every_source(): void
    {
        $this->advisory('a', $this->zabali, '2026-08-01 08:00', 'Typhoon', 'PAGASA');
        $this->advisory('b', $this->zabali, '2026-08-02 08:00', 'Typhoon', 'PAGASA');
        $this->advisory('c', $this->zabali, '2026-08-03 08:00', 'Earthquake', 'PHIVOLCS');

        $section = $this->report($this->admin, $this->q3)['advisories'];
        $this->assertSame(['Typhoon' => 2, 'Earthquake' => 1], $section['by_hazard_type']);
        $this->assertSame(['PAGASA' => 2, 'PHIVOLCS' => 1, 'Provincial/Municipal DRRMO' => 0, 'ASCOT DRRMO' => 0, 'Other' => 0], $section['by_source']);
    }

    // ================================ followed ===============================

    public function test_followed_counts_an_incident_recorded_after_the_period(): void
    {
        $sep29 = $this->advisory('Typhoon Sep 29', $this->zabali, '2026-09-29 08:00');
        $this->advisory('Nothing happened', $this->zabali, '2026-09-10 08:00');
        $this->incident($sep29, $this->alpha, '2026-10-02');   // recorded in Q4

        $section = $this->report($this->admin, $this->q3)['advisories'];
        $this->assertSame([2, 1, 1], [$section['total'], $section['followed'], $section['not_followed']]);
    }

    public function test_an_incident_in_trash_does_not_make_an_advisory_followed(): void
    {
        $advisory = $this->advisory('Followed only by a trashed incident', $this->zabali, '2026-08-01 08:00');
        $this->incident($advisory, $this->alpha, '2026-08-02')->delete();

        $section = $this->report($this->admin, $this->q3)['advisories'];
        $this->assertSame([1, 0, 1], [$section['total'], $section['followed'], $section['not_followed']]);
    }

    public function test_a_colleagues_incident_counts_for_a_coordinator(): void
    {
        $advisory = $this->advisory('Followed by Bravo', $this->zabali, '2026-08-01 08:00');
        $this->incident($advisory, $this->bravo, '2026-08-02');

        $this->assertSame(1, $this->report($this->alpha, $this->q3)['advisories']['followed']);
    }

    public function test_the_report_and_the_advisory_page_agree_on_followed(): void
    {
        $plain = $this->advisory('No incident', $this->zabali, '2026-08-01 08:00');
        $own = $this->advisory('Alpha recorded one', $this->zabali, '2026-08-02 08:00');
        $theirs = $this->advisory('Bravo recorded one', $this->zabali, '2026-08-03 08:00');
        $trashedOnly = $this->advisory('Only a trashed incident', $this->zabali, '2026-08-04 08:00');
        $later = $this->advisory('Recorded the next quarter', null, '2026-09-29 08:00');
        $this->incident($own, $this->alpha, '2026-08-02');
        $this->incident($theirs, $this->bravo, '2026-08-03');
        $this->incident($trashedOnly, $this->alpha, '2026-08-04')->delete();
        $this->incident($later, $this->bravo, '2026-10-02');

        foreach ([[$this->admin, 'admin'], [$this->alpha, 'coordinator']] as [$user, $prefix]) {
            // The page's verdict: it lists the incident, or counts ones recorded by others.
            $followedOnPage = 0;
            foreach ([$plain, $own, $theirs, $trashedOnly, $later] as $advisory) {
                $page = $this->actingAs($user)->get(route("{$prefix}.advisories.show", $advisory))->assertOk();
                $followedOnPage += ($page->viewData('incidents')->count() + $page->viewData('hiddenIncidentCount')) > 0 ? 1 : 0;
            }
            $this->assertSame(3, $followedOnPage, $prefix);
            $this->assertSame($followedOnPage, $this->report($user, $this->q3)['advisories']['followed'], "{$prefix}: report agrees with the advisory pages");
        }
    }

    // ================================ scoping ================================

    public function test_a_campus_filter_includes_all_campus_advisories(): void
    {
        $this->advisory('Zabali', $this->zabali, '2026-08-01 08:00');
        $this->advisory('Bazal', $this->bazal, '2026-08-01 08:00');
        $this->advisory('Everyone', null, '2026-08-01 08:00');

        $this->assertSame(3, $this->report($this->admin, $this->q3)['advisories']['total']);
        $this->assertSame(2, $this->report($this->admin, $this->q3 + ['campus_id' => $this->bazal->campus_id])['advisories']['total']);
        $this->assertTrue($this->report($this->admin, $this->q3 + ['campus_id' => $this->bazal->campus_id])['advisories']['campus_filtered']);
    }

    public function test_a_coordinator_sees_every_advisory_of_their_campus_and_no_other(): void
    {
        $this->advisory('By the Admin', $this->zabali, '2026-08-01 08:00');
        $this->advisory('By Bravo', $this->zabali, '2026-08-02 08:00', 'Typhoon', 'PAGASA', $this->bravo);
        $this->advisory('All-campus', null, '2026-08-03 08:00');
        $this->advisory('Bazal only', $this->bazal, '2026-08-04 08:00');

        $this->assertSame(3, $this->report($this->alpha, $this->q3)['advisories']['total']);
        $this->assertSame(3, $this->report($this->alpha, $this->q3 + ['campus_id' => $this->bazal->campus_id])['advisories']['total'], 'another campus in the URL changes nothing');
    }

    public function test_a_school_filter_leaves_the_numbers_alone_and_says_so(): void
    {
        $this->advisory('Zabali', $this->zabali, '2026-08-01 08:00');

        $section = $this->report($this->admin, $this->q3 + ['school_id' => $this->engineering->school_id])['advisories'];
        $this->assertSame(1, $section['total']);
        $this->assertTrue($section['school_filtered']);

        $page = $this->actingAs($this->admin)->get(route('reports.consolidated.index', $this->q3 + ['school_id' => $this->engineering->school_id]));
        $page->assertSee('Advisories are issued per campus and are not attributed to a school');
        $this->actingAs($this->admin)->get(route('reports.consolidated.index', $this->q3))->assertDontSee('not attributed to a school');
    }

    // ============================ summary & display ===========================

    public function test_the_executive_summary_row_compares_with_the_previous_period(): void
    {
        $this->advisory('Q2', $this->zabali, '2026-05-01 08:00');
        $this->advisory('Q3 one', $this->zabali, '2026-08-01 08:00');
        $this->advisory('Q3 two', $this->zabali, '2026-09-01 08:00');

        $row = collect($this->report($this->admin, $this->q3)['executive_summary'])->firstWhere('label', 'Disaster Advisories Issued');
        $this->assertSame(['current' => 2, 'previous' => 1, 'change' => 1, 'change_pct' => 100.0],
            array_intersect_key($row, array_flip(['current', 'previous', 'change', 'change_pct'])));
    }

    public function test_the_page_and_pdf_show_the_section_its_rule_and_the_breakdown_note(): void
    {
        $advisory = $this->advisory('Typhoon Egay', $this->zabali, '2026-09-29 08:00');
        $this->incident($advisory, $this->alpha, '2026-10-02');

        $page = $this->actingAs($this->admin)->get(route('reports.consolidated.index', $this->q3))->assertOk();
        $page->assertSee('data-report-advisories', false)->assertSee('Disaster Advisories')
            ->assertSee('whenever it was recorded — an advisory issued Sep 29 whose incident was recorded Oct 2 counts')
            ->assertSee('Disaster advisories aren’t broken down at all: an all-campus advisory belongs to every campus, so the rows would not add up to the total.')
            ->assertSee('xl:grid-cols-5', false);

        $text = $this->reportText($this->consolidatedPdfHtml($this->admin, $this->q3));
        $this->assertStringContainsString('Disaster Advisories', $text);
        $this->assertStringContainsString('whenever it was recorded', $text);
        $this->assertMatchesRegularExpression('/Followed by an incident\s*1/', $text);
        $this->assertStringContainsString('Disaster Advisories Issued', $text);
    }

    public function test_an_empty_period_says_so(): void
    {
        $this->actingAs($this->admin)->get(route('reports.consolidated.index', $this->q3))->assertSee('No advisories were issued in this period.');
        $this->assertStringContainsString('No advisories were issued in this period.', $this->reportText($this->consolidatedPdfHtml($this->admin, $this->q3)));
    }

    // ================================ helpers ================================

    protected function report(Personnel $as, array $query): array
    {
        return $this->actingAs($as)->get(route('reports.consolidated.index', $query))->assertOk()->viewData('report');
    }

    protected function advisory(string $headline, ?Campus $campus, string $issued, string $hazard = 'Typhoon', string $source = 'PAGASA', ?Personnel $by = null): DisasterAdvisory
    {
        $issuedAt = Carbon::parse($issued, 'Asia/Manila');

        return DisasterAdvisory::forceCreate([
            'campus_id' => $campus?->campus_id, 'hazard_type' => $hazard, 'headline' => $headline, 'measurements' => [],
            'source' => $source, 'issued_at' => $issuedAt, 'valid_until' => $issuedAt->copy()->addDay(), 'status' => 'Passed',
            'instructions' => 'Stay safe.', 'created_by' => ($by ?? $this->admin)->personnel_id,
        ]);
    }

    protected function incident(DisasterAdvisory $advisory, Personnel $by, string $date): Incident
    {
        return Incident::forceCreate(['campus_id' => $advisory->campus_id ?? $by->campus_id, 'advisory_id' => $advisory->advisory_id, 'hazard_type' => $advisory->hazard_type,
            'date_occurred' => $date, 'status' => 'Active', 'created_by' => $by->personnel_id]);
    }
}
