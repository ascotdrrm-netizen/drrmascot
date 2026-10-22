<?php

namespace Tests\Feature;

use App\Models\Campus;
use App\Models\Incident\Incident;
use App\Models\Personnel;
use App\Models\School;
use App\Support\RecordAccess;
use DOMDocument;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * The Incidents list on the shared filter bar: the first page with
 * per-person scoping AND a period control. "All dates" is the default, an
 * unfiltered list returns every incident the viewer may open, and a
 * coordinator's summary never describes more than they can see.
 */
class FilterBarIncidentsTest extends TestCase
{
    use RefreshDatabase;

    protected Campus $zabali;

    protected Campus $bazal;

    protected School $engineering;

    protected Personnel $admin;

    protected Personnel $alpha;   // coordinator, Zabali

    protected Personnel $bravo;   // coordinator, Zabali

    protected Personnel $charlie; // coordinator, Bazal

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::parse('2026-10-11 09:00:00', 'Asia/Manila'));
        $this->zabali = Campus::create(['campus_name' => 'ASCOT Zabali Campus']);
        $this->bazal = Campus::create(['campus_name' => 'ASCOT Bazal Campus']);
        $this->engineering = School::create(['campus_id' => $this->zabali->campus_id, 'school_name' => 'School of Engineering']);
        $make = fn (Campus $campus, string $name, string $role) => Personnel::create(['campus_id' => $campus->campus_id, 'full_name' => $name, 'role' => $role,
            'email' => strtolower(str_replace(' ', '-', $name)) . '@ascot-test.local', 'password_hash' => Hash::make('password')]);
        $this->admin = $make($this->zabali, 'Incidents Admin', 'DRRMO Admin');
        $this->alpha = $make($this->zabali, 'Coordinator Alpha', 'Coordinator');
        $this->bravo = $make($this->zabali, 'Coordinator Bravo', 'Coordinator');
        $this->charlie = $make($this->bazal, 'Coordinator Charlie', 'Coordinator');

        // Spread across years, statuses, schools, creators and campuses — including
        // dates far outside any default period.
        $this->incident($this->alpha, '2019-03-02', 'Active', $this->engineering);
        $this->incident($this->alpha, '2026-08-14', 'Resolved', null);
        $this->incident($this->alpha, '2026-10-05', 'Active', $this->engineering);
        $this->incident($this->alpha, '2027-01-20', 'Under Response', null);   // dated ahead
        $this->incident($this->bravo, '2026-08-20', 'Active', $this->engineering);
        $this->incident($this->bravo, '2021-11-11', 'Resolved', null);
        $this->incident($this->charlie, '2026-09-01', 'Active', null);
        $this->incident($this->admin, '2018-01-01', 'Resolved', null);
        $trashed = $this->incident($this->alpha, '2026-09-09', 'Active', null);
        $trashed->delete();
    }

    // ============================ "All dates" =================================

    public function test_all_dates_is_the_default_for_both_roles(): void
    {
        foreach ([[$this->admin, 'admin'], [$this->alpha, 'coordinator']] as [$user, $prefix]) {
            [$x] = $this->page($user, route("{$prefix}.incidents.index"));

            $options = $x->query('//*[@data-filter-bar]//select[@name="period_type"]/option');
            $this->assertSame('', $options->item(0)->getAttribute('value'), $prefix);
            $this->assertSame('All dates', trim($options->item(0)->textContent));
            $this->assertSame('', $x->query('//*[@data-filter-bar]//select[@name="period_type"]/option[@selected]')->item(0)->getAttribute('value'), "$prefix: All dates selected");
            // No period field is enabled, so none is submitted.
            foreach ($x->query('//*[@data-filter-period-fields]//input | //*[@data-filter-period-fields]//select') as $field) {
                $this->assertTrue($field->hasAttribute('disabled'), "$prefix: {$field->getAttribute('id')}");
            }
            $this->assertStringEndsWith(', all dates.', $this->summary($x));
            $this->assertSame(0, $x->query('//a[@data-filter-clear]')->length, "$prefix: nothing applied");
        }
    }

    public function test_an_unfiltered_list_returns_every_incident_the_viewer_may_open(): void
    {
        $everyone = Incident::pluck('incident_id')->sort()->values()->all();

        // The Admin: every incident, every date, every campus (Trash excluded, as before).
        [, $r] = $this->page($this->admin, route('admin.incidents.index'));
        $this->assertSame($everyone, $r->viewData('incidents')->pluck('incident_id')->sort()->values()->all());

        // Each coordinator: exactly what RecordAccess lets them open — and every one of
        // those opens, while every other incident is refused.
        foreach ([$this->alpha, $this->bravo, $this->charlie] as $coordinator) {
            $allowed = RecordAccess::applyToIncidents(Incident::query(), $coordinator)->pluck('incident_id')->sort()->values()->all();
            [, $r] = $this->page($coordinator, route('coordinator.incidents.index'));
            $listed = $r->viewData('incidents')->pluck('incident_id')->sort()->values()->all();
            $this->assertSame($allowed, $listed, $coordinator->full_name);

            foreach ($everyone as $id) {
                $this->actingAs($coordinator)->get(route('coordinator.incidents.show', $id))
                    ->assertStatus(in_array($id, $listed, true) ? 200 : 403);
            }
        }
        // Alpha's four (and the Admin's 2018 one on their campus) include the 2019 and 2027 ones: no date window was applied.
        $this->assertSame(['2018-01-01', '2019-03-02', '2026-08-14', '2026-10-05', '2027-01-20'],
            $this->page($this->alpha, route('coordinator.incidents.index'))[1]->viewData('incidents')->pluck('date_occurred')->map->toDateString()->sort()->values()->all());
    }

    // ======================== the coordinator's line ==========================

    public function test_a_coordinators_summary_says_your_and_names_only_their_campus(): void
    {
        $q3 = ['period_type' => 'quarterly', 'quarter' => 3, 'year' => 2026];
        $cases = [
            [[], 'Showing your and the DRRMO\'s incidents for ASCOT Zabali Campus, all dates.'],
            [['status' => 'Active'], 'Showing your and the DRRMO\'s Active incidents for ASCOT Zabali Campus, all dates.'],
            [['school_id' => $this->engineering->school_id], 'Showing your and the DRRMO\'s incidents from School of Engineering for ASCOT Zabali Campus, all dates.'],
            [['school_id' => 'none'], 'Showing your and the DRRMO\'s incidents with no school set for ASCOT Zabali Campus, all dates.'],
            [$q3, 'Showing your and the DRRMO\'s incidents for ASCOT Zabali Campus, dated Q3 2026 (July 1 – September 30, 2026).'],
            [$q3 + ['status' => 'Active', 'school_id' => $this->engineering->school_id],
                'Showing your and the DRRMO\'s Active incidents from School of Engineering for ASCOT Zabali Campus, dated Q3 2026 (July 1 – September 30, 2026).'],
            // Another campus in the URL changes nothing — not the list, not the line.
            [['campus_id' => $this->bazal->campus_id], 'Showing your and the DRRMO\'s incidents for ASCOT Zabali Campus, all dates.'],
        ];

        foreach ($cases as [$query, $expected]) {
            [$x, $r] = $this->page($this->alpha, route('coordinator.incidents.index', $query));
            $this->assertSame($expected, $this->summary($x), json_encode($query));
            $this->assertStringNotContainsString('all incidents', $this->summary($x));
            foreach ($r->viewData('incidents') as $incident) {
                $this->assertContains($incident->created_by, [$this->alpha->personnel_id, $this->admin->personnel_id], 'only their own and the Admin\'s');
            }
        }

        // Q3 2026 holds one of alpha's incidents (Bravo's Aug 20 one isn't theirs to see).
        [, $r] = $this->page($this->alpha, route('coordinator.incidents.index', $q3));
        $this->assertSame(['2026-08-14'], $r->viewData('incidents')->pluck('date_occurred')->map->toDateString()->all());
    }

    public function test_the_coordinators_bar_has_no_campus_control(): void
    {
        [$x] = $this->page($this->alpha, route('coordinator.incidents.index'));
        $this->assertSame(['period', 'school', 'incident_type', 'status'], $this->controls($x));
        $this->assertSame(0, $x->query('//select[@name="campus_id"]')->length);
        $this->assertSame('apply', $x->query('//*[@data-filter-bar]//form')->item(0)->getAttribute('data-filter-form'));
    }

    // ============================ the Admin's bar =============================

    public function test_the_admins_bar_and_line(): void
    {
        [$x] = $this->page($this->admin, route('admin.incidents.index'));
        $this->assertSame(['period', 'campus', 'school', 'incident_type', 'status'], $this->controls($x));
        $this->assertSame('Showing all incidents for all campuses, all dates.', $this->summary($x));

        [$x, $r] = $this->page($this->admin, route('admin.incidents.index', ['campus_id' => $this->bazal->campus_id, 'status' => 'Active']));
        $this->assertSame('Showing all Active incidents for ASCOT Bazal Campus, all dates.', $this->summary($x));
        $this->assertCount(1, $r->viewData('incidents'));
        $this->assertSame(1, $x->query('//a[@data-filter-clear]')->length);
    }

    // ================================ periods =================================

    public function test_each_period_type_filters_date_occurred_and_is_named(): void
    {
        $cases = [
            [['period_type' => 'monthly', 'month' => 8, 'year' => 2026], ['2026-08-14', '2026-08-20'], 'dated August 2026'],
            [['period_type' => 'quarterly', 'quarter' => 3, 'year' => 2026], ['2026-08-14', '2026-08-20', '2026-09-01'], 'dated Q3 2026 (July 1 – September 30, 2026)'],
            [['period_type' => 'yearly', 'year' => 2021], ['2021-11-11'], 'dated 2021'],
            [['period_type' => 'weekly', 'week_date' => '2026-10-07'], ['2026-10-05'], 'dated the week of Oct 5 – Oct 11, 2026'],
            [['period_type' => 'custom', 'start_date' => '2026-08-15', 'end_date' => '2026-09-01'], ['2026-08-20', '2026-09-01'], 'dated August 15 – September 1, 2026'],
        ];

        foreach ($cases as [$query, $dates, $phrase]) {
            [$x, $r] = $this->page($this->admin, route('admin.incidents.index', $query));
            $this->assertSame($dates, $r->viewData('incidents')->pluck('date_occurred')->map->toDateString()->sort()->values()->all(), $query['period_type']);
            $this->assertSame("Showing all incidents for all campuses, {$phrase}.", $this->summary($x));
            $this->assertSame(1, $x->query('//a[@data-filter-clear]')->length);
            // The applied type is selected, and only its fields are enabled.
            $this->assertSame($query['period_type'], $x->query('//*[@data-filter-bar]//select[@name="period_type"]/option[@selected]')->item(0)->getAttribute('value'));
            foreach ($x->query('//*[@data-filter-period-fields]') as $group) {
                $on = $group->getAttribute('data-filter-period-fields') === $query['period_type'];
                $this->assertSame(! $on, $group->hasAttribute('hidden'));
                foreach ($x->query('.//input | .//select', $group) as $field) {
                    $this->assertSame(! $on, $field->hasAttribute('disabled'), $field->getAttribute('id'));
                }
            }
            $this->assertLessThanOrEqual(1, $x->query('//*[@data-filter-bar]//input[@name="year"][not(@disabled)]')->length, 'never two year fields submitted');
        }
    }

    public function test_an_unknown_period_type_is_ignored_and_a_bad_range_is_rejected(): void
    {
        [$x, $r] = $this->page($this->admin, route('admin.incidents.index', ['period_type' => 'fortnightly']));
        $this->assertCount(8, $r->viewData('incidents'));
        $this->assertSame('Showing all incidents for all campuses, all dates.', $this->summary($x));

        $this->actingAs($this->admin)->from(route('admin.incidents.index'))
            ->get(route('admin.incidents.index', ['period_type' => 'custom', 'start_date' => '2026-09-10', 'end_date' => '2026-09-01']))
            ->assertSessionHasErrors('end_date');
        $this->actingAs($this->admin)->from(route('admin.incidents.index'))
            ->get(route('admin.incidents.index', ['period_type' => 'quarterly', 'year' => 2026]))
            ->assertSessionHasErrors('quarter');
    }

    public function test_unknown_status_is_ignored(): void
    {
        [$x, $r] = $this->page($this->admin, route('admin.incidents.index', ['status' => 'Exploded']));
        $this->assertCount(8, $r->viewData('incidents'));
        $this->assertSame('Showing all incidents for all campuses, all dates.', $this->summary($x));
    }

    // ================================ helpers =================================

    protected function incident(Personnel $by, string $date, string $status, ?School $school): Incident
    {
        // The Admin has no campus of their own: theirs is recorded on Zabali.
        return Incident::forceCreate(['campus_id' => $by->campus_id ?? $this->zabali->campus_id, 'school_id' => $school?->school_id, 'hazard_type' => 'Fire',
            'date_occurred' => $date, 'status' => $status, 'created_by' => $by->personnel_id]);
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
