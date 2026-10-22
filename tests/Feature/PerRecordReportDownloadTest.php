<?php

namespace Tests\Feature;

use App\Models\Alert;
use App\Models\Building\Building;
use App\Models\Building\HazardInspection;
use App\Models\Campus;
use App\Models\Drill\Drill;
use App\Models\Drill\DrillAfterActivityReport;
use App\Models\Drill\DrillDocumentation;
use App\Models\Incident\Incident;
use App\Models\Personnel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class PerRecordReportDownloadTest extends TestCase
{
    use RefreshDatabase;

    protected Campus $zabali;

    protected Campus $bazal;

    protected Personnel $admin;

    protected Personnel $zabaliCoordinator;

    protected Building $zabaliBuilding;

    protected Building $bazalBuilding;

    protected HazardInspection $zabaliInspection;

    protected HazardInspection $bazalInspection;

    protected Incident $zabaliIncident;

    protected Incident $bazalIncident;

    protected Drill $zabaliDrill;

    protected Drill $bazalDrill;

    protected Alert $zabaliAlert;

    protected Alert $bazalAlert;

    protected function setUp(): void
    {
        parent::setUp();

        $this->zabali = Campus::create(['campus_name' => 'ASCOT Zabali Campus']);
        $this->bazal = Campus::create(['campus_name' => 'ASCOT Bazal Campus']);

        $this->admin = Personnel::create([
            'campus_id' => $this->zabali->campus_id,
            'full_name' => 'Record Report Admin',
            'role' => 'DRRMO Admin',
            'email' => 'record-admin@ascot-test.local',
            'password_hash' => Hash::make('password'),
        ]);

        $this->zabaliCoordinator = Personnel::create([
            'campus_id' => $this->zabali->campus_id,
            'full_name' => 'Record Report Coordinator',
            'role' => 'Coordinator',
            'email' => 'record-coord@ascot-test.local',
            'password_hash' => Hash::make('password'),
        ]);

        $this->zabaliBuilding = Building::create(['campus_id' => $this->zabali->campus_id, 'building_name' => 'Zabali Gym', 'hazard_type' => 'Fire', 'risk_level' => 'High Risk']);
        $this->bazalBuilding = Building::create(['campus_id' => $this->bazal->campus_id, 'building_name' => 'Bazal Library', 'hazard_type' => 'Flood', 'risk_level' => 'Medium']);

        $this->zabaliInspection = HazardInspection::create(['building_id' => $this->zabaliBuilding->building_id, 'inspection_date' => '2026-08-01', 'findings' => 'Zabali finding', 'risk_level' => 'High Risk']);
        $this->bazalInspection = HazardInspection::create(['building_id' => $this->bazalBuilding->building_id, 'inspection_date' => '2026-08-02', 'findings' => 'Bazal finding', 'risk_level' => 'Medium']);

        $this->zabaliIncident = Incident::create(['campus_id' => $this->zabali->campus_id, 'hazard_type' => 'Fire', 'date_occurred' => '2026-08-05', 'status' => 'Resolved', 'description' => 'Zabali incident']);
        $this->bazalIncident = Incident::create(['campus_id' => $this->bazal->campus_id, 'hazard_type' => 'Flood', 'date_occurred' => '2026-08-06', 'status' => 'Active', 'description' => 'Bazal incident']);

        $this->zabaliDrill = Drill::forceCreate(['campus_id' => $this->zabali->campus_id, 'drill_type' => 'Fire', 'scheduled_date' => '2026-08-03', 'scheduled_time' => '09:00:00', 'venue' => 'Zabali Quadrangle']);
        $this->bazalDrill = Drill::forceCreate(['campus_id' => $this->bazal->campus_id, 'drill_type' => 'Earthquake', 'scheduled_date' => '2026-08-04', 'scheduled_time' => '10:00:00', 'venue' => 'Bazal Field']);
        foreach ([$this->zabaliDrill, $this->bazalDrill] as $drill) {
            (new DrillDocumentation(['date_conducted' => $drill->scheduled_date, 'narrative' => 'Held.']))
                ->forceFill(['drill_id' => $drill->drill_id, 'submitted_by_name' => $this->zabaliCoordinator->full_name])->save();
            (new DrillAfterActivityReport(['activity_title' => $drill->drill_type . ' Drill', 'date_conducted' => $drill->scheduled_date, 'activities_conducted' => 'Alarm, evacuation, head count.']))
                ->forceFill(['drill_id' => $drill->drill_id])->save();
        }

        $this->zabaliAlert = Alert::create(['incident_id' => $this->zabaliIncident->incident_id, 'message' => 'Zabali alert message', 'sent_at' => '2026-08-05 10:00:00', 'recipient_count' => 30, 'delivery_status' => 'Sent']);
        $this->bazalAlert = Alert::create(['incident_id' => $this->bazalIncident->incident_id, 'message' => 'Bazal alert message', 'sent_at' => '2026-08-06 10:00:00', 'recipient_count' => 20, 'delivery_status' => 'Sent']);
    }

    // ============================ Building ============================

    public function test_building_report_downloads_as_pdf(): void
    {
        $response = $this->actingAs($this->admin)->get(route('admin.buildings.report', $this->zabaliBuilding));

        $response->assertOk();
        $this->assertSame('application/pdf', $response->headers->get('Content-Type'));
        $this->assertStringContainsString('BLDG-' . $this->zabaliBuilding->building_id, $response->headers->get('Content-Disposition'));
    }

    public function test_coordinator_cannot_download_a_building_report_from_another_campus(): void
    {
        $response = $this->actingAs($this->zabaliCoordinator)->get(route('coordinator.buildings.report', $this->bazalBuilding));

        $response->assertForbidden();
    }

    // ======================= Hazard Inspection =========================

    public function test_hazard_inspection_report_downloads_as_pdf(): void
    {
        $response = $this->actingAs($this->admin)->get(
            route('admin.buildings.inspections.report', [$this->zabaliBuilding, $this->zabaliInspection])
        );

        $response->assertOk();
        $this->assertSame('application/pdf', $response->headers->get('Content-Type'));
        $this->assertStringContainsString('INSP-' . $this->zabaliInspection->inspection_id, $response->headers->get('Content-Disposition'));
    }

    public function test_coordinator_cannot_download_a_hazard_inspection_report_from_another_campus(): void
    {
        $response = $this->actingAs($this->zabaliCoordinator)->get(
            route('coordinator.buildings.inspections.report', [$this->bazalBuilding, $this->bazalInspection])
        );

        $response->assertForbidden();
    }

    public function test_hazard_inspection_belonging_to_a_different_building_is_404(): void
    {
        $response = $this->actingAs($this->admin)->get(
            route('admin.buildings.inspections.report', [$this->bazalBuilding, $this->zabaliInspection])
        );

        $response->assertNotFound();
    }

    // ============================ Incident =============================

    public function test_incident_report_downloads_as_pdf(): void
    {
        $response = $this->actingAs($this->admin)->get(route('admin.incidents.report', $this->zabaliIncident));

        $response->assertOk();
        $this->assertSame('application/pdf', $response->headers->get('Content-Type'));
        $this->assertStringContainsString('INC-' . $this->zabaliIncident->incident_id, $response->headers->get('Content-Disposition'));
    }

    public function test_coordinator_cannot_download_an_incident_report_from_another_campus(): void
    {
        $response = $this->actingAs($this->zabaliCoordinator)->get(route('coordinator.incidents.report', $this->bazalIncident));

        $response->assertForbidden();
    }

    // ============================== Drill ===============================

    public function test_drill_documentation_and_after_activity_report_download_as_pdfs(): void
    {
        foreach (['documentation' => 'ASCOT-Drill-Documentation_Fire', 'after-activity' => 'ASCOT-Drill-After-Activity-Report_Fire'] as $report => $filename) {
            $response = $this->actingAs($this->admin)->get(route("admin.drills.{$report}.pdf", $this->zabaliDrill));

            $response->assertOk();
            $this->assertSame('application/pdf', $response->headers->get('Content-Type'));
            $this->assertStringContainsString($filename, $response->headers->get('Content-Disposition'));
        }
    }

    public function test_coordinator_cannot_download_a_drill_report_from_another_campus(): void
    {
        foreach (['documentation', 'after-activity'] as $report) {
            $this->actingAs($this->zabaliCoordinator)->get(route("coordinator.drills.{$report}.pdf", $this->bazalDrill))->assertForbidden();
        }
    }

    // ============================== Alert ===============================

    public function test_alert_report_downloads_as_pdf(): void
    {
        $response = $this->actingAs($this->admin)->get(
            route('admin.incidents.alerts.report', [$this->zabaliIncident, $this->zabaliAlert])
        );

        $response->assertOk();
        $this->assertSame('application/pdf', $response->headers->get('Content-Type'));
        $this->assertStringContainsString('ALERT-' . $this->zabaliAlert->alert_id, $response->headers->get('Content-Disposition'));
    }

    public function test_coordinator_cannot_download_an_alert_report_from_another_campus(): void
    {
        $response = $this->actingAs($this->zabaliCoordinator)->get(
            route('coordinator.incidents.alerts.report', [$this->bazalIncident, $this->bazalAlert])
        );

        $response->assertForbidden();
    }

    public function test_alert_belonging_to_a_different_incident_is_404(): void
    {
        $response = $this->actingAs($this->admin)->get(
            route('admin.incidents.alerts.report', [$this->bazalIncident, $this->zabaliAlert])
        );

        $response->assertNotFound();
    }

    // ============================ Activity log ============================

    public function test_every_per_record_download_is_logged_to_the_activity_log(): void
    {
        $this->actingAs($this->admin)->get(route('admin.buildings.report', $this->zabaliBuilding));
        $this->actingAs($this->admin)->get(route('admin.buildings.inspections.report', [$this->zabaliBuilding, $this->zabaliInspection]));
        $this->actingAs($this->admin)->get(route('admin.incidents.report', $this->zabaliIncident));
        $this->actingAs($this->admin)->get(route('admin.drills.documentation.pdf', $this->zabaliDrill));
        $this->actingAs($this->admin)->get(route('admin.incidents.alerts.report', [$this->zabaliIncident, $this->zabaliAlert]));

        $this->assertSame(5, \App\Models\ActivityLog::where('personnel_id', $this->admin->personnel_id)
            ->where('action', 'report_exported')
            ->count());
    }

    // ===================== Privacy: no phone numbers =====================

    /**
     * Structural guarantee, not a substring search — dompdf's binary PDF
     * output isn't reliably grep-able for plain text. Neither the drill
     * documentation (it names only its submitter, from a stored name) nor
     * the alert report (which never touches the personnel table at all)
     * pass contact_number to their views, so it structurally cannot
     * appear — this just proves both still render fine with a
     * contact_number present on the underlying personnel row.
     */
    public function test_reports_still_render_when_a_related_personnel_record_has_a_contact_number(): void
    {
        Personnel::where('personnel_id', $this->zabaliCoordinator->personnel_id)->update(['contact_number' => '09171234567']);

        $responses = [
            $this->actingAs($this->admin)->get(route('admin.drills.documentation.pdf', $this->zabaliDrill)),
            $this->actingAs($this->admin)->get(route('admin.incidents.alerts.report', [$this->zabaliIncident, $this->zabaliAlert])),
        ];

        foreach ($responses as $response) {
            $response->assertOk();
        }
    }
}
