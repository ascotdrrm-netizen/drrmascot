<?php

namespace Tests\Feature;

use App\Models\Building\Building;
use App\Models\Building\HazardInspection;
use App\Models\Campus;
use App\Models\Drill\Drill;
use App\Models\Drill\DrillDocumentation;
use App\Models\Incident\Incident;
use App\Models\Personnel;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Clock frozen at 2026-09-23 (Asia/Manila). Ranges then resolve to:
 *   7d      Sep 17–23   (previous Sep 10–16)
 *   30d     Aug 25–Sep 23 (previous Jul 26–Aug 24)
 *   quarter Jul 1–Sep 23, 85 days (previous Apr 7–Jun 30)
 *   year    Jan 1–Sep 23
 */
class DashboardAnalyticsTest extends TestCase
{
    use RefreshDatabase;

    protected Campus $zabali;

    protected Campus $bazal;

    protected Personnel $admin;

    protected Personnel $coordinator;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-09-23 10:00:00', 'Asia/Manila'));

        $this->zabali = Campus::create(['campus_name' => 'ASCOT Zabali Campus']);
        $this->bazal = Campus::create(['campus_name' => 'ASCOT Bazal Campus']);

        $this->admin = Personnel::create(['campus_id' => $this->zabali->campus_id, 'full_name' => 'Dash Admin', 'role' => 'DRRMO Admin', 'email' => 'dash-admin@ascot-test.local', 'password_hash' => Hash::make('password')]);
        $this->coordinator = Personnel::create(['campus_id' => $this->zabali->campus_id, 'full_name' => 'Dash Coordinator', 'role' => 'Coordinator', 'email' => 'dash-coord@ascot-test.local', 'password_hash' => Hash::make('password')]);
    }

    protected function seedSampleData(): void
    {
        $z = $this->zabali->campus_id;
        $b = $this->bazal->campus_id;

        // Incidents (Zabali unless noted).
        // Zabali records are this coordinator's own (per-person visibility); Bazal's belong to nobody.
        $mine = ['created_by' => $this->coordinator->personnel_id];
        Incident::forceCreate(['campus_id' => $z, 'hazard_type' => 'Fire', 'date_occurred' => '2026-09-20', 'status' => 'Active'] + $mine);          // 7d, 30d, Q, Y
        Incident::forceCreate(['campus_id' => $z, 'hazard_type' => 'Flood', 'date_occurred' => '2026-09-05', 'status' => 'Resolved'] + $mine);       // 30d, Q, Y
        Incident::forceCreate(['campus_id' => $z, 'hazard_type' => 'Typhoon', 'date_occurred' => '2026-08-10', 'status' => 'Under Response'] + $mine); // prev-30d, Q, Y
        Incident::forceCreate(['campus_id' => $z, 'hazard_type' => 'Fire', 'date_occurred' => '2026-02-10', 'status' => 'Resolved'] + $mine);        // Y only
        Incident::create(['campus_id' => $b, 'hazard_type' => 'Earthquake', 'date_occurred' => '2026-09-21', 'status' => 'Active']);    // Bazal

        // Inspections. Gym's High is its latest -> open. Library's High was
        // superseded by a later Low -> not open. Bazal's High -> open (other campus).
        $gym = Building::create(['campus_id' => $z, 'building_name' => 'Zabali Gym', 'hazard_type' => 'Fire', 'risk_level' => 'High Risk']);
        $lib = Building::create(['campus_id' => $z, 'building_name' => 'Zabali Library', 'hazard_type' => 'Flood', 'risk_level' => 'Minimal Risk']);
        $bzl = Building::create(['campus_id' => $b, 'building_name' => 'Bazal Hall', 'hazard_type' => 'Fire', 'risk_level' => 'High Risk']);
        HazardInspection::forceCreate(['building_id' => $gym->building_id, 'inspection_date' => '2026-09-10', 'risk_level' => 'High Risk'] + $mine);
        HazardInspection::forceCreate(['building_id' => $lib->building_id, 'inspection_date' => '2026-09-01', 'risk_level' => 'High Risk'] + $mine);
        HazardInspection::forceCreate(['building_id' => $lib->building_id, 'inspection_date' => '2026-09-15', 'risk_level' => 'Minimal Risk'] + $mine);
        HazardInspection::create(['building_id' => $bzl->building_id, 'inspection_date' => '2026-09-12', 'risk_level' => 'High Risk']);

        // Drills: one held (documented) in range, one scheduled in the future, one held at Bazal.
        // "Conducted" = documented, dated by the documentation's date held.
        $held = function (int $campusId, string $type, string $date, string $venue) {
            $drill = Drill::forceCreate(['campus_id' => $campusId, 'drill_type' => $type, 'scheduled_date' => $date, 'scheduled_time' => '09:00:00', 'venue' => $venue]);
            (new DrillDocumentation(['date_conducted' => $date, 'narrative' => 'Held.']))->forceFill(['drill_id' => $drill->drill_id])->save();
        };
        $held($z, 'Fire', '2026-09-18', 'Quadrangle');
        Drill::forceCreate(['campus_id' => $z, 'drill_type' => 'Earthquake', 'scheduled_date' => '2026-10-05', 'scheduled_time' => '08:00:00', 'venue' => 'Zabali Field']);
        $held($b, 'Evacuation', '2026-09-19', 'Bazal Court');
    }

    protected function kpis(array $analytics): array
    {
        return collect($analytics['kpis'])->keyBy('key')->all();
    }

    protected function coordinatorAnalytics(array $query = []): array
    {
        return $this->actingAs($this->coordinator)
            ->get(route('coordinator.dashboard', $query))
            ->assertOk()
            ->viewData('analytics');
    }

    public function test_both_dashboards_return_200_with_default_range_last_30_days(): void
    {
        $this->seedSampleData();

        $this->actingAs($this->admin)->get(route('admin.dashboard'))->assertOk()->assertViewHas('range', '30d');
        $this->actingAs($this->coordinator)->get(route('coordinator.dashboard'))->assertOk()->assertViewHas('range', '30d');
    }

    public function test_each_time_range_changes_the_numbers_correctly(): void
    {
        $this->seedSampleData();

        $expected = [
            //          total, unresolved, inspections, open high, drills, total change
            '7d' => [1, 1, 0, 0, 1, 'New'],
            '30d' => [2, 1, 3, 1, 1, '+100%'],
            'quarter' => [3, 2, 3, 1, 1, 'New'],
            'year' => [4, 2, 3, 1, 1, 'New'], // previous 266 days (2025) had none
        ];

        foreach ($expected as $range => [$total, $unresolved, $inspections, $openHigh, $drills, $totalChange]) {
            $k = $this->kpis($this->coordinatorAnalytics(['range' => $range]));

            $this->assertSame($total, $k['total_incidents']['current'], "{$range} total");
            $this->assertSame($unresolved, $k['unresolved_incidents']['current'], "{$range} unresolved");
            $this->assertSame($inspections, $k['inspections']['current'], "{$range} inspections");
            $this->assertSame($openHigh, $k['open_high_risk']['current'], "{$range} open high-risk");
            $this->assertSame($drills, $k['drills']['current'], "{$range} drills");
            $this->assertSame($totalChange, $k['total_incidents']['change'], "{$range} change label");
        }
    }

    public function test_incidents_over_time_buckets_by_day_week_and_month(): void
    {
        $this->seedSampleData();

        $a = $this->coordinatorAnalytics(['range' => '7d']);
        $this->assertSame('day', $a['charts']['incidents_over_time']['granularity']);
        $this->assertCount(7, $a['charts']['incidents_over_time']['data']);

        $a = $this->coordinatorAnalytics(['range' => 'quarter']);
        $this->assertSame('week', $a['charts']['incidents_over_time']['granularity']);
        $this->assertSame(3, array_sum($a['charts']['incidents_over_time']['data']));

        $a = $this->coordinatorAnalytics(['range' => 'year']);
        $this->assertSame('month', $a['charts']['incidents_over_time']['granularity']);
        $this->assertCount(9, $a['charts']['incidents_over_time']['data']); // Jan–Sep
        $this->assertSame(4, array_sum($a['charts']['incidents_over_time']['data']));
    }

    public function test_coordinator_totals_exclude_other_campuses_even_with_a_tampered_campus_id(): void
    {
        $this->seedSampleData();

        $response = $this->actingAs($this->coordinator)
            ->get(route('coordinator.dashboard', ['range' => '30d', 'campus_id' => $this->bazal->campus_id]))
            ->assertOk()
            ->assertViewHas('campusId', $this->zabali->campus_id)
            ->assertViewHas('campuses', null)
            ->assertDontSee('Bazal Hall')
            ->assertDontSee('Drills conducted per campus');

        $a = $response->viewData('analytics');
        $this->assertSame(2, $this->kpis($a)['total_incidents']['current']);
        $this->assertSame(1, $this->kpis($a)['open_high_risk']['current']);
        $this->assertNull($a['charts']['drills_per_campus']);
        // List is "right now", not range-bound: Zabali's Active + Under Response incidents.
        $this->assertSame(2, $a['lists']['unresolved_incidents']['total']);
        $this->assertSame(['Zabali Field'], $a['lists']['upcoming_drills']->pluck('venue')->all());
    }

    public function test_admin_sees_all_campuses_with_campus_comparison_and_can_filter_to_one(): void
    {
        $this->seedSampleData();

        $all = $this->actingAs($this->admin)->get(route('admin.dashboard', ['range' => '30d']))->assertOk()->viewData('analytics');
        $this->assertSame(3, $this->kpis($all)['total_incidents']['current']);
        $this->assertSame(2, $this->kpis($all)['open_high_risk']['current']);
        $this->assertSame(2, $this->kpis($all)['drills']['current']);
        $this->assertSame(['ASCOT Bazal Campus', 'ASCOT Zabali Campus'], $all['charts']['drills_per_campus']['labels']);
        $this->assertSame([1, 1], $all['charts']['drills_per_campus']['data']);

        $bazal = $this->actingAs($this->admin)
            ->get(route('admin.dashboard', ['range' => '30d', 'campus_id' => $this->bazal->campus_id]))
            ->assertOk()
            ->viewData('analytics');
        $this->assertSame(1, $this->kpis($bazal)['total_incidents']['current']);
        $this->assertNull($bazal['charts']['drills_per_campus'], 'No cross-campus chart when one campus is selected');
    }

    public function test_empty_states_render_when_there_is_no_data(): void
    {
        $html = $this->actingAs($this->admin)->get(route('admin.dashboard'))->assertOk()->getContent();

        foreach (['incidents_over_time', 'incidents_by_hazard', 'findings_by_risk', 'drills_per_campus'] as $chart) {
            $this->assertStringContainsString('data-empty="' . $chart . '"', $html, $chart);
        }
        $this->assertSame(4, substr_count($html, 'No data for this period'));
        $this->assertStringNotContainsString('<canvas', $html);
        $this->assertSame(5, substr_count($html, '>—</span>'), 'every KPI change reads "—" when both periods are 0');
        $this->assertStringContainsString('No unresolved incidents.', $html);
        $this->assertStringContainsString('No drills scheduled.', $html);
    }

    public function test_a_building_in_trash_takes_its_findings_off_the_dashboard_until_restored(): void
    {
        $this->seedSampleData();
        $analytics = fn () => $this->actingAs($this->admin)->get(route('admin.dashboard', ['campus_id' => $this->zabali->campus_id]))->assertOk()->viewData('analytics');
        $high = array_search('High Risk', \App\Support\RiskMatrix::LEVELS, true);
        $minimal = array_search('Minimal Risk', \App\Support\RiskMatrix::LEVELS, true);

        // Zabali in the last 30 days: Gym High, Library High then Minimal.
        $before = $analytics();
        $this->assertSame(2, $before['charts']['findings_by_risk']['data'][$high]);
        $this->assertSame(1, $before['charts']['findings_by_risk']['data'][$minimal]);
        $this->assertSame(3, $this->kpis($before)['inspections']['current']);

        // The Library goes to Trash: its two findings leave the chart and the count.
        $library = Building::where('building_name', 'Zabali Library')->firstOrFail();
        $this->actingAs($this->admin)->delete(route('admin.buildings.destroy', $library))->assertRedirect();
        $trashed = $analytics();
        $this->assertSame(1, $trashed['charts']['findings_by_risk']['data'][$high], 'only the Gym');
        $this->assertSame(0, $trashed['charts']['findings_by_risk']['data'][$minimal]);
        $this->assertSame(1, $this->kpis($trashed)['inspections']['current']);

        // Restored: back exactly as before.
        $this->post(route('admin.buildings.restore', $library->building_id))->assertRedirect();
        $this->assertSame($before['charts']['findings_by_risk'], $analytics()['charts']['findings_by_risk']);
        $this->assertSame(3, $this->kpis($analytics())['inspections']['current']);
    }

    public function test_invalid_range_is_rejected(): void
    {
        $this->actingAs($this->admin)
            ->get(route('admin.dashboard', ['range' => 'forever']))
            ->assertSessionHasErrors('range');
    }

    public function test_no_alert_analytics_are_included(): void
    {
        $this->seedSampleData();

        $a = $this->actingAs($this->admin)->get(route('admin.dashboard'))->assertOk()->viewData('analytics');

        $this->assertArrayNotHasKey('alerts', $a['kpis']);
        $this->assertSame(['incidents_over_time', 'incidents_by_hazard', 'findings_by_risk', 'drills_per_campus'], array_keys($a['charts']));
        $this->assertSame(['total_incidents', 'unresolved_incidents', 'inspections', 'open_high_risk', 'drills'], array_column($a['kpis'], 'key'));
    }

    public function test_dashboard_query_count_stays_bounded(): void
    {
        $this->seedSampleData();

        foreach ([[$this->admin, 'admin.dashboard'], [$this->coordinator, 'coordinator.dashboard']] as [$user, $route]) {
            $this->actingAs($user);
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->get(route($route))->assertOk();
            $count = count(DB::getQueryLog());
            DB::disableQueryLog();

            $this->assertLessThanOrEqual(20, $count, "{$route} ran {$count} queries");
        }
    }
}
