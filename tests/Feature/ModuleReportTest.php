<?php

namespace Tests\Feature;

use App\Models\Alert;
use App\Models\Building\Building;
use App\Models\Building\HazardInspection;
use App\Models\Campus;
use App\Models\Drill\Drill;
use App\Models\Drill\DrillDocumentation;
use App\Models\Incident\DamageReport;
use App\Models\Incident\Incident;
use App\Models\Personnel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\Concerns\ReadsReportHtml;
use Tests\TestCase;

class ModuleReportTest extends TestCase
{
    use ReadsReportHtml, RefreshDatabase;

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
            'full_name' => 'Module Report Admin',
            'role' => 'DRRMO Admin',
            'email' => 'module-admin@ascot-test.local',
            'password_hash' => Hash::make('password'),
        ]);

        $this->zabaliCoordinator = Personnel::create([
            'campus_id' => $this->zabali->campus_id,
            'full_name' => 'Module Report Coordinator',
            'role' => 'Coordinator',
            'email' => 'module-coord@ascot-test.local',
            'password_hash' => Hash::make('password'),
        ]);

        $zabaliBuilding = Building::create([
            'campus_id' => $this->zabali->campus_id,
            'building_name' => 'Zabali Gym',
            'hazard_type' => 'Fire',
            'risk_level' => 'High Risk',
        ]);
        $bazalBuilding = Building::create([
            'campus_id' => $this->bazal->campus_id,
            'building_name' => 'Bazal Library',
            'hazard_type' => 'Flood',
            'risk_level' => 'Medium',
        ]);

        HazardInspection::create(['building_id' => $zabaliBuilding->building_id, 'inspection_date' => '2026-08-01', 'findings' => 'Zabali finding', 'risk_level' => 'High Risk']);
        HazardInspection::create(['building_id' => $bazalBuilding->building_id, 'inspection_date' => '2026-08-02', 'findings' => 'Bazal finding', 'risk_level' => 'Medium']);

        $zabaliIncident = Incident::create(['campus_id' => $this->zabali->campus_id, 'hazard_type' => 'Fire', 'date_occurred' => '2026-08-05', 'status' => 'Resolved', 'description' => 'Zabali incident description']);
        Incident::create(['campus_id' => $this->bazal->campus_id, 'hazard_type' => 'Flood', 'date_occurred' => '2026-08-06', 'status' => 'Active', 'description' => 'Bazal incident description']);

        DamageReport::create(['incident_id' => $zabaliIncident->incident_id, 'building_id' => $zabaliBuilding->building_id, 'damage_level' => 'Minor', 'casualty_count' => 0, 'est_cost' => 5000]);

        $zabaliDrill = Drill::forceCreate(['campus_id' => $this->zabali->campus_id, 'drill_type' => 'Fire', 'scheduled_date' => '2026-08-03', 'scheduled_time' => '09:00:00', 'venue' => 'Zabali Quadrangle']);
        $bazalDrill = Drill::forceCreate(['campus_id' => $this->bazal->campus_id, 'drill_type' => 'Earthquake', 'scheduled_date' => '2026-08-04', 'scheduled_time' => '10:00:00', 'venue' => 'Bazal Field']);
        foreach ([$zabaliDrill, $bazalDrill] as $drill) {
            (new DrillDocumentation(['date_conducted' => $drill->scheduled_date, 'narrative' => 'Held at ' . $drill->venue]))->forceFill(['drill_id' => $drill->drill_id])->save();
        }

        Alert::create(['incident_id' => $zabaliIncident->incident_id, 'message' => 'Zabali alert message', 'sent_at' => '2026-08-05 10:00:00', 'recipient_count' => 30, 'delivery_status' => 'Sent']);

        // Out-of-period rows for every module, for the exclusion test.
        HazardInspection::create(['building_id' => $zabaliBuilding->building_id, 'inspection_date' => '2026-01-15', 'findings' => 'Out of period finding', 'risk_level' => 'High Risk']);
        Incident::create(['campus_id' => $this->zabali->campus_id, 'hazard_type' => 'Earthquake', 'date_occurred' => '2026-01-05', 'status' => 'Resolved', 'description' => 'Out of period incident description']);
        Drill::forceCreate(['campus_id' => $this->zabali->campus_id, 'drill_type' => 'Evacuation', 'scheduled_date' => '2026-01-20', 'scheduled_time' => '09:00:00', 'venue' => 'Out-Of-Period Venue']);
    }

    protected function periodQuery(): array
    {
        return ['period_type' => 'quarterly', 'quarter' => 3, 'year' => 2026];
    }

    public function test_every_module_pdf_downloads_successfully(): void
    {
        foreach (['hazard', 'incidents', 'drills', 'drill-documentation', 'after-activity', 'alerts'] as $module) {
            $response = $this->actingAs($this->admin)->get(
                route('reports.module.pdf', array_merge(['module' => $module], $this->periodQuery()))
            );

            $response->assertOk();
            $this->assertSame('application/pdf', $response->headers->get('Content-Type'));
        }
    }

    public function test_hazard_module_report_contains_only_hazard_data(): void
    {
        $content = $this->reportContent('hazard');

        $this->assertStringContainsStringIgnoringCase('FACILITY HAZARD AUDITOR', $content);
        $this->assertStringContainsString('Zabali finding', $content);
        $this->assertStringNotContainsStringIgnoringCase('INCIDENT REPORTING', $content);
        $this->assertStringNotContainsStringIgnoringCase('DRILL TRACKER', $content);
        $this->assertStringNotContainsStringIgnoringCase('SMS & BROADCAST ALERT', $content);
    }

    public function test_incidents_module_report_contains_only_incident_data(): void
    {
        $content = $this->reportContent('incidents');

        $this->assertStringContainsStringIgnoringCase('INCIDENT REPORTING', $content);
        $this->assertStringContainsString('Zabali incident description', $content);
        $this->assertStringNotContainsStringIgnoringCase('FACILITY HAZARD AUDITOR', $content);
        $this->assertStringNotContainsStringIgnoringCase('DRILL TRACKER', $content);
        $this->assertStringNotContainsStringIgnoringCase('SMS & BROADCAST ALERT', $content);
    }

    public function test_drills_module_report_contains_only_drill_data(): void
    {
        $content = $this->reportContent('drills');

        $this->assertStringContainsStringIgnoringCase('DRILL TRACKER', $content);
        $this->assertStringContainsString('Zabali Quadrangle', $content);
        $this->assertStringNotContainsStringIgnoringCase('FACILITY HAZARD AUDITOR', $content);
        $this->assertStringNotContainsStringIgnoringCase('INCIDENT REPORTING', $content);
        $this->assertStringNotContainsStringIgnoringCase('SMS & BROADCAST ALERT', $content);
    }

    public function test_alerts_module_report_contains_only_alert_data(): void
    {
        $content = $this->reportContent('alerts');

        $this->assertStringContainsStringIgnoringCase('SMS & BROADCAST ALERT', $content);
        $this->assertStringContainsString('Zabali alert message', $content);
        $this->assertStringNotContainsStringIgnoringCase('FACILITY HAZARD AUDITOR', $content);
        $this->assertStringNotContainsStringIgnoringCase('INCIDENT REPORTING', $content);
        $this->assertStringNotContainsStringIgnoringCase('DRILL TRACKER', $content);
    }

    public function test_out_of_period_records_are_excluded(): void
    {
        $hazardContent = $this->reportContent('hazard');
        $this->assertStringNotContainsString('Out of period finding', $hazardContent);

        $incidentContent = $this->reportContent('incidents');
        $this->assertStringNotContainsString('Out of period incident description', $incidentContent);

        $drillContent = $this->reportContent('drills');
        $this->assertStringNotContainsString('Out-Of-Period Venue', $drillContent);
    }

    public function test_coordinator_module_report_is_locked_to_their_own_campus_even_if_tampered(): void
    {
        foreach (['hazard', 'incidents', 'drills', 'drill-documentation', 'after-activity', 'alerts'] as $module) {
            $content = $this->reportText($this->periodPdfHtml($this->zabaliCoordinator, $module, ['campus_id' => $this->bazal->campus_id] + $this->periodQuery()));

            $this->assertStringContainsString('ASCOT Zabali Campus', $content);
            $this->assertStringNotContainsString('Bazal finding', $content);
            $this->assertStringNotContainsString('Bazal incident description', $content);
            $this->assertStringNotContainsString('Bazal Field', $content);
        }
    }

    public function test_invalid_period_type_is_rejected(): void
    {
        $response = $this->actingAs($this->admin)->get(
            route('reports.module.pdf', ['module' => 'drills', 'period_type' => 'not-a-real-type'])
        );

        $response->assertSessionHasErrors('period_type');
    }

    public function test_invalid_module_segment_is_a_404(): void
    {
        $response = $this->actingAs($this->admin)->get('/reports/module/not-a-module/pdf?' . http_build_query($this->periodQuery()));

        $response->assertNotFound();
    }

    public function test_module_report_downloads_are_logged_to_the_activity_log(): void
    {
        $this->actingAs($this->admin)->get(
            route('reports.module.pdf', array_merge(['module' => 'drills'], $this->periodQuery()))
        );

        $this->assertDatabaseHas('activity_logs', [
            'personnel_id' => $this->admin->personnel_id,
            'action' => 'report_exported',
        ]);
    }

    /** The admin's period report for one module, as the text the PDF is rendered from. */
    protected function reportContent(string $module): string
    {
        return $this->reportText($this->periodPdfHtml($this->admin, $module, $this->periodQuery()));
    }
}
