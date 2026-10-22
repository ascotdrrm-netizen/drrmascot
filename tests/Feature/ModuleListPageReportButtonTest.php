<?php

namespace Tests\Feature;

use App\Models\Building\Building;
use App\Models\Campus;
use App\Models\Incident\Incident;
use App\Models\Personnel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class ModuleListPageReportButtonTest extends TestCase
{
    use RefreshDatabase;

    protected Personnel $admin;

    protected Personnel $coordinator;

    protected Building $building;

    protected Incident $incident;

    protected function setUp(): void
    {
        parent::setUp();

        $campus = Campus::create(['campus_name' => 'ASCOT Zabali Campus']);

        $this->admin = Personnel::create([
            'campus_id' => $campus->campus_id,
            'full_name' => 'Button Admin',
            'role' => 'DRRMO Admin',
            'email' => 'button-admin@ascot-test.local',
            'password_hash' => Hash::make('password'),
        ]);

        $this->coordinator = Personnel::create([
            'campus_id' => $campus->campus_id,
            'full_name' => 'Button Coordinator',
            'role' => 'Coordinator',
            'email' => 'button-coord@ascot-test.local',
            'password_hash' => Hash::make('password'),
        ]);

        $this->building = Building::create(['campus_id' => $campus->campus_id, 'building_name' => 'Gym', 'hazard_type' => 'Fire', 'risk_level' => 'Minimal Risk']);
        // Reported by the coordinator, so both roles can open it (per-person visibility).
        $this->incident = Incident::forceCreate(['campus_id' => $campus->campus_id, 'hazard_type' => 'Fire', 'date_occurred' => '2026-08-01', 'status' => 'Active', 'created_by' => $this->coordinator->personnel_id]);
    }

    /**
     * @return array{buttons: int, modals: int, modalIds: int}
     */
    protected function counts(string $html): array
    {
        return [
            // Visible button label only — the modal headings never read exactly "Generate Report".
            'buttons' => preg_match_all('/>\s*Generate Report\s*</', $html),
            // The modal container element itself (JS selectors use "[data-report-modal]", not "data-report-modal>").
            'modals' => substr_count($html, 'data-report-modal>'),
            // The container's own id only (field ids inside it are "report-modal-<module>-<field>").
            'modalIds' => preg_match_all('/id="report-modal-[a-z]+"/', $html),
        ];
    }

    public function test_each_module_list_page_has_exactly_one_generate_report_button_and_modal(): void
    {
        foreach (['admin' => $this->admin, 'coordinator' => $this->coordinator] as $prefix => $user) {
            foreach (['buildings', 'incidents', 'drills'] as $page) {
                $html = $this->actingAs($user)->get(route("{$prefix}.{$page}.index"))->assertOk()->getContent();

                $this->assertSame(
                    ['buttons' => 1, 'modals' => 1, 'modalIds' => 1],
                    $this->counts($html),
                    "{$prefix}.{$page}.index"
                );
            }
        }
    }

    public function test_detail_pages_for_hazard_inspections_and_alerts_have_no_generate_report_button(): void
    {
        foreach (['admin' => $this->admin, 'coordinator' => $this->coordinator] as $prefix => $user) {
            $pages = [
                "{$prefix}.buildings.show" => route("{$prefix}.buildings.show", $this->building),
                "{$prefix}.incidents.show" => route("{$prefix}.incidents.show", $this->incident),
            ];

            foreach ($pages as $name => $url) {
                $html = $this->actingAs($user)->get($url)->assertOk()->getContent();

                $this->assertSame(['buttons' => 0, 'modals' => 0, 'modalIds' => 0], $this->counts($html), $name);
            }
        }
    }

    public function test_incidents_modal_offers_both_incident_and_alert_reports_and_both_targets_download(): void
    {
        $html = $this->actingAs($this->admin)->get(route('admin.incidents.index'))->getContent();

        $this->assertSame(1, preg_match_all('/<select[^>]*data-report-module-select/', $html));
        $this->assertStringContainsString('data-pdf-url="' . route('reports.module.pdf', 'incidents') . '"', $html);
        $this->assertStringContainsString('data-pdf-url="' . route('reports.module.pdf', 'alerts') . '"', $html);
        // Default submit target is the first option (Incident Reporting).
        $this->assertStringContainsString('formaction="' . route('reports.module.pdf', 'incidents') . '"', $html);

        $query = ['period_type' => 'quarterly', 'quarter' => 3, 'year' => 2026];
        foreach (['incidents', 'alerts'] as $module) {
            $this->actingAs($this->admin)
                ->get(route('reports.module.pdf', ['module' => $module] + $query))
                ->assertOk();
        }
    }

    public function test_single_module_pages_have_no_report_selector(): void
    {
        $html = $this->actingAs($this->admin)->get(route('admin.buildings.index'))->getContent();

        $this->assertSame(0, preg_match_all('/<select[^>]*data-report-module-select/', $html));
    }

    public function test_drills_modal_offers_the_schedule_documentation_and_after_activity_reports_separately(): void
    {
        $html = $this->actingAs($this->admin)->get(route('admin.drills.index'))->getContent();

        $this->assertSame(1, preg_match_all('/<select[^>]*data-report-module-select/', $html));
        foreach (['drills', 'drill-documentation', 'after-activity'] as $module) {
            $this->assertStringContainsString('data-pdf-url="' . route('reports.module.pdf', $module) . '"', $html, $module);
        }
        // Drills are campus-wide: the drills modal has no School select.
        $this->assertStringNotContainsString('id="report-modal-drills-school_id"', $html);
    }
}
