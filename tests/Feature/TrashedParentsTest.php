<?php

namespace Tests\Feature;

use App\Models\Building\Building;
use App\Models\Campus;
use App\Models\Drill\Drill;
use App\Models\Incident\Incident;
use App\Models\Personnel;
use App\Services\ConsolidatedReportService;
use App\Services\DetailedReportService;
use App\Services\ReportPeriodResolver;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\Concerns\ReadsReportHtml;
use Tests\TestCase;

/**
 * Records whose parent is in Trash. A child that is its own dated event (an
 * inspection conducted, an alert sent) keeps counting; a child that is part
 * of its parent's record (an incident's damage, a drill's documentation)
 * follows its parent. Present-tense lists ("open") leave out anything whose
 * parent is in Trash. Permanent deletion cascades, so those rows are gone.
 */
class TrashedParentsTest extends TestCase
{
    use ReadsReportHtml, RefreshDatabase;

    protected Campus $campus;

    protected Personnel $admin;

    protected int $trashedBuilding;

    protected int $liveBuilding;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::parse('2026-09-25 10:00:00', 'Asia/Manila'));
        $this->campus = Campus::create(['campus_name' => 'ASCOT Zabali Campus']);
        $this->admin = Personnel::create(['campus_id' => $this->campus->campus_id, 'full_name' => 'Trash Admin', 'role' => 'DRRMO Admin', 'email' => 'trash-admin@ascot-test.local', 'password_hash' => Hash::make('password')]);

        $c = $this->campus->campus_id;
        $this->trashedBuilding = DB::table('buildings')->insertGetId(['campus_id' => $c, 'building_name' => 'Old Gym', 'risk_level' => 'High Risk'], 'building_id');
        $this->liveBuilding = DB::table('buildings')->insertGetId(['campus_id' => $c, 'building_name' => 'Library', 'risk_level' => 'Minimal Risk'], 'building_id');
        DB::table('hazard_inspections')->insert([
            ['building_id' => $this->trashedBuilding, 'inspection_date' => '2026-09-10', 'risk_level' => 'High Risk', 'findings' => 'Cracked beam'],
            ['building_id' => $this->liveBuilding, 'inspection_date' => '2026-09-11', 'risk_level' => 'Minimal Risk', 'findings' => 'Fine'],
        ]);
    }

    // ======================= inspections: their own event =====================

    public function test_a_trashed_buildings_inspection_still_counts_in_its_period_and_leaves_the_open_findings(): void
    {
        $this->assertSame(1, $this->dashboard()['lists']['open_high_risk']['total'], 'before: the High finding is open');

        Building::find($this->trashedBuilding)->delete();

        // Past tense: counted everywhere.
        $kpis = collect($this->dashboard()['kpis'])->keyBy('key');
        $this->assertSame(2, $kpis['inspections']['current'], 'Inspections conducted KPI');
        $this->assertSame([1, 0, 1, 0], $this->dashboard()['charts']['findings_by_risk']['data'], 'findings by risk level: Low, Moderate, High, Very High');
        $hazard = $this->consolidated()['hazard_auditor'];
        $this->assertSame(2, $hazard['inspections_conducted']);
        $this->assertSame(2, $hazard['buildings_inspected'], 'a trashed building still counts as inspected');
        $this->assertSame(2, $this->consolidated()['campus_breakdown'][0]['inspections'], 'per-campus row');
        $this->assertSame(2, array_sum(array_column($this->consolidated()['school_breakdown'], 'inspections')), 'per-school rows add up');
        $rows = $this->detailed()->rowsForPdf($this->detailed()->hazardInspectionRows(...$this->q3()))['rows'];
        $this->assertCount(2, $rows);
        $this->assertSame('Old Gym', $rows->firstWhere('building_id', $this->trashedBuilding)->building->building_name, 'the name still prints');

        // Present tense: nobody can act on a building in Trash.
        $this->assertSame(0, $kpis['open_high_risk']['current'], 'High-risk findings open KPI');
        $this->assertSame(0, $this->dashboard()['lists']['open_high_risk']['total'], 'Needs attention list');
    }

    public function test_a_permanently_deleted_buildings_inspection_is_gone_entirely(): void
    {
        Building::withTrashed()->find($this->trashedBuilding)->forceDelete();

        $this->assertSame(0, DB::table('hazard_inspections')->where('building_id', $this->trashedBuilding)->count(), 'the foreign key cascades');
        $this->assertSame(1, $this->consolidated()['hazard_auditor']['inspections_conducted']);
        $this->assertSame(1, collect($this->dashboard()['kpis'])->firstWhere('key', 'inspections')['current']);
    }

    // =================== damage reports: part of the incident =================

    public function test_a_trashed_incidents_damage_report_is_excluded_from_casualties_cost_and_damage_level(): void
    {
        [$trashed, $live] = $this->incidentsWithDamage();
        Incident::find($trashed)->delete();

        $incidents = $this->consolidated()['incidents'];
        $this->assertSame(1, $incidents['total']);
        $this->assertSame(2, $incidents['total_casualties'], 'only the live incident’s 2, not the trashed one’s 10');
        $this->assertEquals(300, $incidents['total_est_cost']);
        $this->assertSame(0, $incidents['by_damage_level']['Major'] ?? 0, 'the trashed incident’s Major damage is gone');
        $this->assertSame(1, $incidents['by_damage_level']['Minor'] ?? 0);

        $rows = $this->detailed()->rowsForPdf($this->detailed()->damageReportRows(...$this->q3()));
        $this->assertSame(1, $rows['total']);
        $this->assertSame($live, $rows['rows']->first()->incident_id, 'the period report’s damage table');
    }

    public function test_the_incident_page_names_a_damage_reports_building_even_when_that_building_is_in_trash(): void
    {
        [, $live] = $this->incidentsWithDamage();
        Building::find($this->trashedBuilding)->delete();

        $this->actingAs($this->admin)->get(route('admin.incidents.show', $live))->assertOk()->assertSee('Old Gym');
    }

    // ======================= alerts: their own event ==========================

    public function test_a_trashed_incidents_alert_is_still_counted(): void
    {
        [$trashed] = $this->incidentsWithDamage();
        DB::table('alerts')->insert(['incident_id' => $trashed, 'message' => 'Evacuate', 'sent_at' => '2026-09-12 08:00:00', 'recipient_count' => 40, 'delivery_status' => 'Sent']);
        Incident::find($trashed)->delete();

        $this->assertSame(1, $this->consolidated()['alerts']['total']);
        $this->assertSame(40, $this->consolidated()['alerts']['total_recipients']);
    }

    // ================ drill documentation: part of the drill ==================

    public function test_a_trashed_drills_documentation_stays_excluded(): void
    {
        foreach (['Kept drill', 'Trashed drill'] as $venue) {
            $drill = Drill::forceCreate(['campus_id' => $this->campus->campus_id, 'drill_type' => 'Fire', 'scheduled_date' => '2026-09-15', 'scheduled_time' => '09:00:00', 'venue' => $venue]);
            DB::table('drill_documentations')->insert(['drill_id' => $drill->drill_id, 'date_conducted' => '2026-09-15', 'narrative' => $venue]);
            DB::table('drill_after_activity_reports')->insert(['drill_id' => $drill->drill_id, 'activity_title' => 'Fire Drill', 'date_conducted' => '2026-09-15', 'activities_conducted' => $venue]);
        }
        Drill::where('venue', 'Trashed drill')->first()->delete();

        $drills = $this->consolidated()['drills'];
        $this->assertSame(1, $drills['total'], 'Drills conducted');
        $this->assertSame(1, $drills['after_activity_reports']);
        $this->assertSame(1, collect($this->dashboard()['kpis'])->firstWhere('key', 'drills')['current']);
        $this->assertSame(1, $this->detailed()->drillDocumentationRows(...$this->q3())->count());
    }

    // ============================ printed reports =============================

    public function test_period_and_consolidated_pdfs_say_their_figures_are_as_recorded_when_generated(): void
    {
        $note = 'Figures as recorded when this report was generated; later edits, deletions or late entries change them.';
        $q = ['period_type' => 'quarterly', 'quarter' => 3, 'year' => 2026];

        $this->assertStringContainsString($note, $this->periodPdfHtml($this->admin, 'hazard', $q));
        $this->assertStringContainsString($note, $this->consolidatedPdfHtml($this->admin, $q));
        $record = $this->capturePdf($this->admin, 'reports.records.building', route('admin.buildings.report', $this->liveBuilding))['html'];
        $this->assertStringNotContainsString($note, $record, 'a single record’s report is not a period figure');
    }

    // ================================ helpers =================================

    /** Two incidents in Q3 with damage: [the one that will be trashed (10 casualties, Major), the live one (2, Minor)]. */
    protected function incidentsWithDamage(): array
    {
        $ids = [];
        foreach ([[10, 1000, 'Major'], [2, 300, 'Minor']] as [$casualties, $cost, $level]) {
            $id = DB::table('incidents')->insertGetId(['campus_id' => $this->campus->campus_id, 'hazard_type' => 'Fire', 'date_occurred' => '2026-09-12', 'status' => 'Resolved', 'description' => "Damage $level"], 'incident_id');
            DB::table('damage_reports')->insert(['incident_id' => $id, 'building_id' => $this->trashedBuilding, 'damage_level' => $level, 'casualty_count' => $casualties, 'est_cost' => $cost]);
            $ids[] = $id;
        }

        return $ids;
    }

    protected function q3(): array
    {
        $period = app(ReportPeriodResolver::class)->resolve('quarterly', ['quarter' => 3, 'year' => 2026]);

        return [$period['start'], $period['end'], null];
    }

    protected function consolidated(): array
    {
        $period = app(ReportPeriodResolver::class)->resolve('quarterly', ['quarter' => 3, 'year' => 2026]);

        return app(ConsolidatedReportService::class)->generate($period['start'], $period['end'], null, $period);
    }

    protected function detailed(): DetailedReportService
    {
        return app(DetailedReportService::class);
    }

    protected function dashboard(): array
    {
        return $this->actingAs($this->admin)->get(route('admin.dashboard', ['range' => '30d']))->assertOk()->viewData('analytics');
    }
}
