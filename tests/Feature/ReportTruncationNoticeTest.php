<?php

namespace Tests\Feature;

use App\Http\Controllers\ModuleReportController;
use App\Models\Campus;
use App\Models\Personnel;
use App\Services\DetailedReportService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\Concerns\ReadsReportHtml;
use Tests\TestCase;

/**
 * Period PDFs cap their tables (DetailedReportService::PDF_ROW_LIMIT rows, or
 * ModuleReportController::DRILL_SECTION_LIMIT drills). A signed report must
 * never drop rows silently: the notice appears exactly when the cap bit, and
 * not when the period has exactly as many records as the cap.
 *
 * The partials are rendered from the same data the controller builds, not
 * through dompdf — a real 1,000-row PDF would take minutes per render.
 */
class ReportTruncationNoticeTest extends TestCase
{
    use ReadsReportHtml, RefreshDatabase;

    protected Campus $campus;

    protected CarbonImmutable $start;

    protected CarbonImmutable $end;

    protected function setUp(): void
    {
        parent::setUp();

        $this->campus = Campus::create(['campus_name' => 'ASCOT Zabali Campus']);
        $this->actingAs(Personnel::create([
            'campus_id' => $this->campus->campus_id, 'full_name' => 'Cap Admin', 'role' => 'DRRMO Admin',
            'email' => 'cap-admin@ascot-test.local', 'password_hash' => Hash::make('password'),
        ]));
        $this->start = CarbonImmutable::parse('2026-07-01', 'Asia/Manila')->startOfDay();
        $this->end = CarbonImmutable::parse('2026-09-30', 'Asia/Manila')->endOfDay();
    }

    public function test_a_table_section_says_rows_were_cut_only_when_the_cap_bit(): void
    {
        $limit = DetailedReportService::PDF_ROW_LIMIT;
        $notice = 'Showing the first ' . number_format($limit) . ' records. Narrow the period, campus or school to see the rest.';

        $this->incidents($limit);
        [$data, $text] = $this->incidentsSection();
        $this->assertSame([$limit, $limit, false], [$data['total'], $data['rows']->count(), $data['truncated']]);
        $this->assertStringNotContainsString('Showing the first', $text, 'exactly at the cap: nothing was cut');

        $this->incidents(1);
        [$data, $text] = $this->incidentsSection();
        $this->assertSame([$limit + 1, $limit, true], [$data['total'], $data['rows']->count(), $data['truncated']]);
        $this->assertStringContainsString($notice, preg_replace('/\s+/', ' ', $text));
    }

    public function test_a_drill_section_says_drills_were_cut_only_when_the_cap_bit(): void
    {
        $limit = ModuleReportController::DRILL_SECTION_LIMIT;
        $notice = 'Showing the first ' . number_format($limit) . ' records. Narrow the period or campus to see the rest.';

        $this->documentedDrills($limit);
        [$data, $text] = $this->drillDocumentationSection();
        $this->assertSame([$limit, $limit, false], [$data['total'], $data['rows']->count(), $data['truncated']]);
        $this->assertStringNotContainsString('Showing the first', $text, 'exactly at the cap: nothing was cut');

        $this->documentedDrills(1);
        [$data, $text] = $this->drillDocumentationSection();
        $this->assertSame([$limit + 1, $limit, true], [$data['total'], $data['rows']->count(), $data['truncated']]);
        $this->assertStringContainsString($notice, preg_replace('/\s+/', ' ', $text));
    }

    // ================================ helpers =================================

    /** The Incident Reporting section exactly as ModuleReportController builds it. */
    protected function incidentsSection(): array
    {
        $service = app(DetailedReportService::class);
        $moduleData = [
            'summary' => $service->incidentsSummary($this->start, $this->end, null),
            'data' => $service->rowsForPdf($service->incidentRows($this->start, $this->end, null)),
            'damage_data' => $service->rowsForPdf($service->damageReportRows($this->start, $this->end, null)),
        ];

        return [$moduleData['data'], $this->reportText(view('reports.pdf-partials._detailed-incidents', ['moduleData' => $moduleData])->render())];
    }

    /** The consolidated documentation section, from the controller's own builder. */
    protected function drillDocumentationSection(): array
    {
        $rows = app(DetailedReportService::class)->drillDocumentationRows($this->start, $this->end, null);
        $moduleData = (new \ReflectionMethod(ModuleReportController::class, 'drillSections'))->invoke(app(ModuleReportController::class), $rows);

        return [$moduleData, $this->reportText(view('reports.pdf-partials._detailed-drill-documentation', ['moduleData' => $moduleData])->render())];
    }

    protected function incidents(int $count): void
    {
        $now = now();
        foreach (array_chunk(range(1, $count), 500) as $chunk) {
            DB::table('incidents')->insert(array_map(fn ($i) => [
                'campus_id' => $this->campus->campus_id, 'hazard_type' => 'Fire', 'date_occurred' => '2026-08-05',
                'status' => 'Resolved', 'description' => "Capped incident $i", 'created_at' => $now, 'updated_at' => $now,
            ], $chunk));
        }
    }

    protected function documentedDrills(int $count): void
    {
        $now = now();
        foreach (range(1, $count) as $i) {
            $drillId = DB::table('drills')->insertGetId([
                'campus_id' => $this->campus->campus_id, 'drill_type' => 'Fire', 'scheduled_date' => '2026-08-05',
                'scheduled_time' => '09:00:00', 'venue' => "Capped venue $i", 'created_at' => $now, 'updated_at' => $now,
            ], 'drill_id');
            DB::table('drill_documentations')->insert([
                'drill_id' => $drillId, 'date_conducted' => '2026-08-05', 'narrative' => "Capped narrative $i",
                'created_at' => $now, 'updated_at' => $now,
            ]);
        }
    }
}
