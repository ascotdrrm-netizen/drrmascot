<?php

namespace App\Http\Controllers;

use App\Http\Requests\ModuleReportRequest;
use App\Models\ActivityLog;
use App\Models\Drill\DrillAttachment;
use App\Models\Personnel;
use App\Models\School;
use App\Services\ConsolidatedReportService;
use App\Services\DetailedReportService;
use App\Services\ReportPeriodResolver;
use App\Support\FilenameSlug;
use App\Support\PdfImage;
use App\Traits\RendersPdfAssets;
use Carbon\CarbonImmutable;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * One shared controller for every module's own period report (Facility
 * Hazard Auditor, Incident Reporting, Drill Tracker, SMS & Broadcast
 * Alert), downloaded from that module's "Generate Report"
 * modal — so the report logic exists exactly once instead of being
 * copy-pasted per module. Reuses ReportPeriodResolver and
 * DetailedReportService (both already built for the Consolidated DRRM
 * Report's per-module queries) plus the same PDF wrapper
 * (reports/detailed-pdf.blade.php + reports/pdf-partials/_detailed-*)
 * used there, scoped down to a single module.
 */
class ModuleReportController extends Controller
{
    use RendersPdfAssets;

    /**
     * The Drill Tracker has three, kept apart: 'drills' (the schedule),
     * 'drill-documentation' and 'after-activity' — the two consolidated
     * reports never include each other's content.
     */
    public const MODULES = ['hazard', 'incidents', 'drills', 'drill-documentation', 'after-activity', 'alerts'];

    /** Drill modules: campus-wide records, no school. */
    public const DRILL_MODULES = ['drills', 'drill-documentation', 'after-activity'];

    /**
     * Drills per consolidated documentation / After Activity Report PDF —
     * each carries text and up to 4 thumbnails, so far fewer fit than table
     * rows. When more are in the period the PDF says so (see the partials).
     */
    public const DRILL_SECTION_LIMIT = 100;

    /** Thumbnails per drill in a consolidated PDF; the rest are in that drill's own PDF. */
    public const THUMBNAILS_PER_DRILL = 4;

    protected const TITLES = [
        'hazard' => 'ASCOT-DRRMS Hazard Audit Report',
        'incidents' => 'ASCOT-DRRMS Incident Report',
        'drills' => 'ASCOT-DRRMS Drill Schedule Report',
        'drill-documentation' => 'ASCOT-DRRMS Consolidated Drill Documentation',
        'after-activity' => 'ASCOT-DRRMS Consolidated After Activity Reports',
        'alerts' => 'ASCOT-DRRMS Alert Report',
    ];

    protected const FILENAME_SEGMENTS = [
        'hazard' => 'Hazard-Audit-Report',
        'incidents' => 'Incident-Report',
        'drills' => 'Drill-Schedule-Report',
        'drill-documentation' => 'Drill-Documentation-Report',
        'after-activity' => 'Drill-After-Activity-Reports',
        'alerts' => 'Alert-Report',
    ];

    protected const MODULE_LABELS = [
        'hazard' => 'Facility Hazard Auditor',
        'incidents' => 'Incident Reporting',
        'drills' => 'Drill Tracker schedule',
        'drill-documentation' => 'Drill Tracker documentation',
        'after-activity' => 'Drill Tracker After Activity Reports',
        'alerts' => 'SMS & Broadcast Alert',
    ];

    public function __construct(
        protected ReportPeriodResolver $periodResolver,
        protected ConsolidatedReportService $reportService,
        protected DetailedReportService $detailedReportService,
    ) {
    }

    public function pdf(ModuleReportRequest $request, string $module): Response
    {
        abort_unless(in_array($module, self::MODULES, true), 404);

        $user = Auth::user();
        $period = $this->periodResolver->resolve($request->validated('period_type'), $request->periodParams());
        [$campusId, $schoolFilter] = $this->resolveScope($request);
        $campusName = $this->reportService->campusLabel($campusId)['name'];
        $isDrill = in_array($module, self::DRILL_MODULES, true);
        $schoolName = $isDrill ? 'Not applicable — drills are campus-wide' : School::filterLabel($schoolFilter);

        $moduleData = $this->buildPdfModuleData($module, $period['start'], $period['end'], $campusId);

        $pdf = $this->loadPdfViewWithPageCount('reports.detailed-pdf', [
            'title' => self::TITLES[$module],
            'period' => $period,
            'campusName' => $campusName,
            'schoolName' => $schoolName,
            'modules' => [$module],
            'moduleData' => [$module => $moduleData],
            'generatedByName' => $user->full_name,
            'generatedByPosition' => $this->positionLabel($user),
        ]);

        $filename = $this->moduleReportFilename($module, 'pdf', $request->validated('period_type'), $period, $campusName, $schoolFilter !== null && ! $isDrill ? $schoolName : null);

        $this->logActivity($module, $period, $campusName . ($schoolFilter !== null && ! $isDrill ? ' · ' . $schoolName : ''), 'pdf');

        return $pdf->download($filename);
    }

    // ============================= PDF ROW DATA ==============================

    /**
     * @return array<string, mixed>
     */
    protected function buildPdfModuleData(string $module, CarbonImmutable $start, CarbonImmutable $end, ?int $campusId): array
    {
        return match ($module) {
            'hazard' => [
                'summary' => $this->detailedReportService->hazardAuditorSummary($start, $end, $campusId),
                'data' => $this->detailedReportService->rowsForPdf(
                    $this->detailedReportService->hazardInspectionRows($start, $end, $campusId)
                ),
                'high_risk_data' => $this->detailedReportService->rowsForPdf(
                    $this->detailedReportService->highRiskHazardInspectionRows($start, $end, $campusId)
                ),
            ],
            'incidents' => [
                'summary' => $this->detailedReportService->incidentsSummary($start, $end, $campusId),
                'data' => $this->detailedReportService->rowsForPdf(
                    $this->detailedReportService->incidentRows($start, $end, $campusId)
                ),
                'damage_data' => $this->detailedReportService->rowsForPdf(
                    $this->detailedReportService->damageReportRows($start, $end, $campusId)
                ),
            ],
            'drills' => [
                'summary' => $this->detailedReportService->drillsSummary($start, $end, $campusId),
                'data' => $this->detailedReportService->rowsForPdf(
                    $this->detailedReportService->drillRows($start, $end, $campusId)
                ),
            ],
            'drill-documentation' => $this->drillSections($this->detailedReportService->drillDocumentationRows($start, $end, $campusId)),
            'after-activity' => $this->drillSections($this->detailedReportService->drillAfterActivityRows($start, $end, $campusId)),
            'alerts' => [
                'summary' => $this->detailedReportService->alertsSummary($start, $end, $campusId),
                'data' => $this->detailedReportService->rowsForPdf(
                    $this->detailedReportService->alertRows($start, $end, $campusId)
                ),
            ],
        };
    }


    /**
     * Consolidated documentation / After Activity Report PDF data: at most
     * DRILL_SECTION_LIMIT drills, each with up to THUMBNAILS_PER_DRILL photos
     * (shrunk to 600px) and its PDFs listed by name.
     *
     * @return array{rows: \Illuminate\Support\Collection, total: int, truncated: bool, thumbnails: array<int, array>}
     */
    protected function drillSections($query): array
    {
        $total = (clone $query)->count();
        $rows = $query->limit(self::DRILL_SECTION_LIMIT)->get();

        $thumbnails = $rows->mapWithKeys(fn ($row) => [$row->getKey() => $row->attachments->filter->isImage()
            ->take(self::THUMBNAILS_PER_DRILL)
            ->map(fn (DrillAttachment $a) => ['src' => PdfImage::fromStoredFile($a->path, 600, 70), 'caption' => $a->original_name])
            ->filter(fn ($t) => $t['src'] !== null)
            ->values()
            ->all()])->all();

        return [
            'rows' => $rows,
            'total' => $total,
            'truncated' => $total > self::DRILL_SECTION_LIMIT,
            'thumbnails' => $thumbnails,
        ];
    }

    // =============================== HELPERS ==================================

    /**
     * Campus scope (resolveCampusId(), unchanged) plus the school view filter,
     * set on the report services for this request. A school picked with "All
     * Campuses" narrows the campus to that school's campus (same records), so
     * the header names both. Hazard inspections, incidents and drills filter
     * by their own school; alerts by their incident's school.
     *
     * @return array{0: ?int, 1: int|string|null}
     */
    protected function resolveScope(ModuleReportRequest $request): array
    {
        $campusId = $this->resolveCampusId($request);
        $schoolFilter = $request->schoolFilter();

        if ($campusId === null && is_int($schoolFilter)) {
            $campusId = School::whereKey($schoolFilter)->value('campus_id');
        }

        $this->reportService->forSchool($schoolFilter);
        $this->detailedReportService->forSchool($schoolFilter);
        // A Coordinator's report covers only their own records.
        $this->reportService->forViewer(Auth::user());
        $this->detailedReportService->forViewer(Auth::user());

        return [$campusId, $schoolFilter];
    }

    /**
     * Coordinators are always locked to their own campus regardless of
     * what's in the query string; DRRMO Admin gets null (All Campuses)
     * unless a valid campus_id was submitted. Mirrors
     * ConsolidatedReportController::resolveCampusId() exactly.
     */
    protected function resolveCampusId(ModuleReportRequest $request): ?int
    {
        $user = Auth::user();

        if ($user->role === 'Coordinator') {
            return $user->campus_id;
        }

        return $request->validated('campus_id') !== null ? (int) $request->validated('campus_id') : null;
    }

    protected function positionLabel(Personnel $user): string
    {
        return $user->role === 'DRRMO Admin' ? 'DRRMO Administrator' : 'Campus DRRM Coordinator';
    }

    protected function moduleReportFilename(string $module, string $extension, string $periodType, array $period, string $campusName, ?string $schoolName = null): string
    {
        return sprintf(
            'ASCOT-%s_%s_%s%s.%s',
            self::FILENAME_SEGMENTS[$module],
            $this->periodSlug($periodType, $period),
            FilenameSlug::titleCase($campusName),
            $schoolName !== null ? '_' . FilenameSlug::titleCase($schoolName) : '',
            $extension
        );
    }

    /**
     * Slightly different from ConsolidatedReportController's own
     * periodSlug() (yearly here is "Yearly-2026" not just "2026", and
     * weekly uses the ISO-8601 week number "Week-38-2026" instead of a
     * literal date) — this is a new, separate filename convention for
     * per-module period reports, left independent so the Consolidated
     * DRRM Report's existing filenames never change.
     */
    protected function periodSlug(string $periodType, array $period): string
    {
        return match ($periodType) {
            'weekly' => 'Week-' . (int) $period['start']->format('W') . '-' . $period['start']->format('o'),
            'monthly' => $period['start']->format('M-Y'),
            'quarterly' => 'Q' . ceil($period['start']->month / 3) . '-' . $period['start']->year,
            'yearly' => 'Yearly-' . $period['start']->year,
            'custom' => $period['start']->format('M-d-Y') . '_to_' . $period['end']->format('M-d-Y'),
            default => 'Report',
        };
    }

    protected function logActivity(string $module, array $period, string $campusLabel, string $format): void
    {
        $description = sprintf(
            '%s exported the %s report (%s) for %s (%s)',
            Auth::user()->full_name,
            self::MODULE_LABELS[$module],
            strtoupper($format),
            $period['label'],
            $campusLabel
        );

        ActivityLog::create([
            'personnel_id' => Auth::id(),
            'action' => 'report_exported',
            'subject_type' => null,
            'subject_id' => null,
            'description' => mb_substr($description, 0, 255),
            'ip_address' => request()?->ip(),
        ]);
    }
}
