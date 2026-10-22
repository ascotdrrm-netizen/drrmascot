<?php

namespace Tests\Feature;

use App\Models\Alert;
use App\Models\Building\Building;
use App\Models\Building\HazardInspection;
use App\Models\Campus;
use App\Models\Drill\Drill;
use App\Models\Drill\DrillDocumentation;
use App\Models\Incident\Incident;
use App\Models\Personnel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class ConsolidatedReportControllerTest extends TestCase
{
    use RefreshDatabase;

    protected Campus $zabali;

    protected Campus $bazal;

    protected Personnel $admin;

    protected Personnel $zabaliCoordinator;

    protected function setUp(): void
    {
        parent::setUp();

        $this->zabali = Campus::create(['campus_name' => 'ASCOT Zabali Campus']);
        $this->bazal = Campus::create(['campus_name' => 'ASCOT Bazal Campus']);

        $this->admin = Personnel::create([
            'campus_id' => $this->zabali->campus_id,
            'full_name' => 'Test Admin',
            'role' => 'DRRMO Admin',
            'email' => 'admin@ascot-test.local',
            'password_hash' => Hash::make('password'),
        ]);

        $this->zabaliCoordinator = Personnel::create([
            'campus_id' => $this->zabali->campus_id,
            'full_name' => 'Zabali Coordinator',
            'role' => 'Coordinator',
            'email' => 'zabali-coord@ascot-test.local',
            'password_hash' => Hash::make('password'),
        ]);

        // One incident per campus, both inside the target quarter (Q3 2026).
        // Zabali's was reported by the Zabali coordinator (per-person visibility).
        Incident::forceCreate([
            'campus_id' => $this->zabali->campus_id,
            'hazard_type' => 'Fire',
            'date_occurred' => '2026-08-15',
            'status' => 'Active',
            'created_by' => $this->zabaliCoordinator->personnel_id,
        ]);

        Incident::create([
            'campus_id' => $this->bazal->campus_id,
            'hazard_type' => 'Flood',
            'date_occurred' => '2026-08-16',
            'status' => 'Resolved',
        ]);

        // An incident from a DIFFERENT quarter — must never be counted for Q3.
        Incident::create([
            'campus_id' => $this->zabali->campus_id,
            'hazard_type' => 'Earthquake',
            'date_occurred' => '2026-01-05',
            'status' => 'Resolved',
        ]);
    }

    public function test_coordinator_report_is_locked_to_their_own_campus_even_if_campus_id_is_tampered_with(): void
    {
        $response = $this->actingAs($this->zabaliCoordinator)->get(
            route('reports.consolidated.index', [
                'period_type' => 'quarterly',
                'quarter' => 3,
                'year' => 2026,
                'campus_id' => $this->bazal->campus_id,
            ])
        );

        $response->assertOk();
        $response->assertViewHas('report', function (array $report) {
            return $report['campus']['id'] === $this->zabali->campus_id
                && $report['campus']['name'] === $this->zabali->campus_name
                && $report['incidents']['total'] === 1
                && $report['campus_breakdown'] === null;
        });
    }

    public function test_admin_sees_all_campuses_by_default_with_a_per_campus_breakdown(): void
    {
        $response = $this->actingAs($this->admin)->get(
            route('reports.consolidated.index', ['period_type' => 'quarterly', 'quarter' => 3, 'year' => 2026])
        );

        $response->assertOk();
        $response->assertViewHas('report', function (array $report) {
            return $report['campus']['id'] === null
                && $report['campus']['name'] === 'All Campuses'
                && $report['incidents']['total'] === 2
                && $report['campus_breakdown'] !== null
                && count($report['campus_breakdown']) === 2;
        });
    }

    public function test_admin_can_filter_to_a_single_campus(): void
    {
        $response = $this->actingAs($this->admin)->get(
            route('reports.consolidated.index', [
                'period_type' => 'quarterly',
                'quarter' => 3,
                'year' => 2026,
                'campus_id' => $this->bazal->campus_id,
            ])
        );

        $response->assertOk();
        $response->assertViewHas('report', function (array $report) {
            return $report['campus']['name'] === $this->bazal->campus_name
                && $report['incidents']['total'] === 1
                && $report['campus_breakdown'] === null;
        });
    }

    public function test_out_of_period_records_are_excluded(): void
    {
        $response = $this->actingAs($this->admin)->get(
            route('reports.consolidated.index', ['period_type' => 'quarterly', 'quarter' => 3, 'year' => 2026])
        );

        // Q3 2026 total is 2 (Zabali + Bazal); the January incident must not appear.
        $response->assertViewHas('report', fn (array $report) => $report['incidents']['total'] === 2);
    }

    public function test_executive_summary_shows_new_when_the_previous_period_had_zero_records(): void
    {
        // Q3 2026 has incidents (seeded in setUp); Q2 2026 (the previous
        // period) has none, so the comparison must read as "New", not a
        // division-by-zero error or a misleading 0%.
        $response = $this->actingAs($this->admin)->get(
            route('reports.consolidated.index', ['period_type' => 'quarterly', 'quarter' => 3, 'year' => 2026])
        );

        $response->assertViewHas('report', function (array $report) {
            $incidentsRow = collect($report['executive_summary'])->firstWhere('label', 'Incidents Reported');

            return $incidentsRow['previous'] === 0
                && $incidentsRow['current'] === 2
                && $incidentsRow['change_pct'] === null;
        });
    }

    public function test_default_period_on_first_load_is_the_current_quarter(): void
    {
        $response = $this->actingAs($this->admin)->get(route('reports.consolidated.index'));

        $response->assertOk();
        $response->assertViewHas('period', function (array $period) {
            return str_starts_with($period['label'], 'Q' . ceil(now('Asia/Manila')->month / 3));
        });
    }

    public function test_pdf_export_downloads_successfully(): void
    {
        $response = $this->actingAs($this->admin)->get(
            route('reports.consolidated.pdf', ['period_type' => 'quarterly', 'quarter' => 3, 'year' => 2026])
        );

        $response->assertOk();
        $this->assertSame('application/pdf', $response->headers->get('Content-Type'));
    }

    public function test_generating_and_exporting_the_report_are_recorded_in_the_activity_log(): void
    {
        $this->actingAs($this->admin)->get(
            route('reports.consolidated.index', ['period_type' => 'quarterly', 'quarter' => 3, 'year' => 2026])
        );
        $this->actingAs($this->admin)->get(
            route('reports.consolidated.pdf', ['period_type' => 'quarterly', 'quarter' => 3, 'year' => 2026])
        );

        $this->assertDatabaseHas('activity_logs', [
            'personnel_id' => $this->admin->personnel_id,
            'action' => 'report_generated',
        ]);
        $this->assertDatabaseHas('activity_logs', [
            'personnel_id' => $this->admin->personnel_id,
            'action' => 'report_exported',
        ]);
    }

    public function test_hazard_inspections_and_drills_and_alerts_are_counted_via_their_own_date_columns(): void
    {
        $building = Building::create([
            'campus_id' => $this->zabali->campus_id,
            'building_name' => 'Gym',
            'risk_level' => 'High Risk',
        ]);

        HazardInspection::create([
            'building_id' => $building->building_id,
            'inspection_date' => '2026-08-01',
            'risk_level' => 'High Risk',
        ]);

        // Scheduled for the end of June (previous quarter) but held, and
        // documented, in August: a drill is counted by when it was held.
        $drill = Drill::forceCreate([
            'campus_id' => $this->zabali->campus_id,
            'drill_type' => 'Fire',
            'scheduled_date' => '2026-06-30',
            'scheduled_time' => '09:00:00',
            'venue' => 'Quadrangle',
        ]);

        (new DrillDocumentation(['date_conducted' => '2026-08-02', 'narrative' => 'Held.', 'participant_count' => 40]))
            ->forceFill(['drill_id' => $drill->drill_id])->save();

        $incident = Incident::where('campus_id', $this->zabali->campus_id)->where('hazard_type', 'Fire')->firstOrFail();

        Alert::create([
            'incident_id' => $incident->incident_id,
            'message' => 'Evacuate now',
            'sent_at' => '2026-08-15 10:00:00',
            'recipient_count' => 25,
            'delivery_status' => 'Sent',
        ]);

        $response = $this->actingAs($this->admin)->get(
            route('reports.consolidated.index', ['period_type' => 'quarterly', 'quarter' => 3, 'year' => 2026])
        );

        $response->assertViewHas('report', function (array $report) {
            return $report['hazard_auditor']['inspections_conducted'] === 1
                && $report['hazard_auditor']['buildings_inspected'] === 1
                && $report['hazard_auditor']['by_risk_level']['High Risk'] === 1
                && $report['drills']['total'] === 1
                && $report['drills']['scheduled'] === 0
                && $report['drills']['total_participants'] === 40
                && $report['alerts']['total'] === 1
                && $report['alerts']['total_recipients'] === 25;
        });
    }
}
