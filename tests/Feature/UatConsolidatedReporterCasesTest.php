<?php

namespace Tests\Feature;

use App\Models\Campus;
use App\Models\Personnel;
use App\Models\Reporter\CitizenReport;
use App\Models\Reporter\SosEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * UAT 2026-10-18 (3.15): the Consolidated Report never counted what came in
 * through the Reporter app — reports and SOS — so cases staff resolved there
 * were missing ("walang napasok").
 */
class UatConsolidatedReporterCasesTest extends TestCase
{
    use RefreshDatabase;

    protected Campus $zabali;

    protected Campus $bazal;

    protected Personnel $admin;

    protected Personnel $coordinator;

    protected Personnel $reporter;

    protected array $q3 = ['period_type' => 'quarterly', 'quarter' => 3, 'year' => 2026];

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-10-12 09:00:00', 'Asia/Manila'));
        $this->zabali = Campus::create(['campus_name' => 'ASCOT Zabali Campus']);
        $this->bazal = Campus::create(['campus_name' => 'ASCOT Bazal Campus']);
        $make = fn (Campus $campus, string $name, string $role) => Personnel::create(['campus_id' => $campus->campus_id, 'full_name' => $name, 'role' => $role,
            'email' => strtolower(str_replace(' ', '-', $name)).'@ascot-test.local', 'password_hash' => Hash::make('password')]);
        $this->admin = $make($this->zabali, 'Report Admin', 'DRRMO Admin');
        $this->coordinator = $make($this->zabali, 'Zabali Coordinator', 'Coordinator');
        $this->reporter = $make($this->zabali, 'Student Reporter', 'Reporter');

        $this->report($this->zabali, 'Resolved', '2026-08-01 10:00');
        $this->report($this->zabali, 'New', '2026-09-30 23:00');
        $this->report($this->bazal, 'Responding', '2026-07-15 08:00');
        $this->report($this->zabali, 'Resolved', '2026-06-30 23:00');   // Q2: not counted
        $this->sos($this->zabali, 'Resolved', '2026-08-02 11:00');
        $this->sos($this->bazal, 'New', '2026-08-03 11:00');
    }

    public function test_reporter_reports_and_sos_are_counted_by_status(): void
    {
        $section = $this->reportData($this->admin)['reporter_cases'];

        $this->assertSame(3, $section['reports_total']);
        $this->assertSame(['New' => 1, 'Verified' => 0, 'Responding' => 1, 'Resolved' => 1, 'Rejected' => 0], $section['reports_by_status']);
        $this->assertSame(2, $section['sos_total']);
        $this->assertSame(['New' => 1, 'Acknowledged' => 0, 'Responding' => 0, 'Resolved' => 1, 'Rejected' => 0], $section['sos_by_status']);
    }

    public function test_the_executive_summary_counts_them_and_every_resolved_case(): void
    {
        $rows = collect($this->reportData($this->admin)['executive_summary'])->keyBy('label');

        $this->assertSame(3, $rows['Reporter Reports Received']['current']);
        $this->assertSame(1, $rows['Reporter Reports Received']['previous']);
        $this->assertSame(2, $rows['SOS Alerts Received']['current']);
        $this->assertSame(2, $rows['Cases Resolved']['current'], 'one resolved report + one resolved SOS');
    }

    public function test_a_coordinator_sees_their_campus_cases_and_the_section_is_on_screen(): void
    {
        $response = $this->actingAs($this->coordinator)->get(route('reports.consolidated.index', $this->q3))->assertOk();
        $section = $response->viewData('report')['reporter_cases'];

        $this->assertSame(2, $section['reports_total']);
        $this->assertSame(1, $section['sos_total']);
        $response->assertSee('Reporter Reports &amp; SOS', false);
    }

    protected function reportData(Personnel $as): array
    {
        return $this->actingAs($as)->get(route('reports.consolidated.index', $this->q3))->assertOk()->viewData('report');
    }

    protected function report(Campus $campus, string $status, string $at): void
    {
        $report = CitizenReport::create(['campus_id' => $campus->campus_id, 'reporter_id' => $this->reporter->getKey(),
            'client_key' => (string) Str::uuid(), 'category' => 'flood', 'description' => 'Water rising near the gym.', 'place' => 'Gym', 'status' => $status]);
        $report->forceFill(['created_at' => Carbon::parse($at, 'Asia/Manila')])->saveQuietly();
    }

    protected function sos(Campus $campus, string $status, string $at): void
    {
        $sos = SosEvent::create(['reporter_id' => $this->reporter->getKey(), 'campus_id' => $campus->campus_id,
            'client_key' => (string) Str::uuid(), 'type' => 'Medical', 'status' => $status]);
        $sos->forceFill(['created_at' => Carbon::parse($at, 'Asia/Manila')])->saveQuietly();
    }
}
