<?php

namespace App\Http\Controllers;

use App\Http\Requests\ConsolidatedReportRequest;
use App\Models\ActivityLog;
use App\Models\Campus;
use App\Models\Personnel;
use App\Models\School;
use App\Services\ConsolidatedReportService;
use App\Services\ReportPeriodResolver;
use App\Support\FilenameSlug;
use App\Support\FilterSummary;
use App\Support\ListPeriod;
use App\Traits\RendersPdfAssets;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ConsolidatedReportController extends Controller
{
    use RendersPdfAssets;

    public function __construct(
        protected ReportPeriodResolver $periodResolver,
        protected ConsolidatedReportService $reportService,
    ) {
    }

    public function index(ConsolidatedReportRequest $request): View
    {
        $user = Auth::user();
        $period = $this->periodResolver->resolve($request->validated('period_type'), $request->periodParams());
        [$campusId, $schoolFilter] = $this->resolveScope($request);

        $report = $this->reportService->generate($period['start'], $period['end'], $campusId, $period);

        $this->logActivity('report_generated', $period, $this->scopeLabel($report), null);

        $isAdmin = $user->role === 'DRRMO Admin';
        $campuses = $isAdmin ? Campus::orderBy('campus_name')->get() : null;
        $filters = $this->normalizedFilters($request, $campusId, $schoolFilter);
        $default = $this->periodResolver->defaultParams();

        return view('reports.consolidated', [
            'period' => $period,
            'report' => $report,
            'campuses' => $campuses,
            'selectedPeriodType' => $request->validated('period_type'),
            'selectedCampusId' => $campusId,
            'schools' => School::forDropdowns(),
            'userCampusId' => $isAdmin ? null : $user->campus_id,
            'selectedSchoolId' => $schoolFilter,
            'filters' => $filters,
            // The bar's period control: the applied period, with starting values for the other types.
            'periodValues' => array_merge(ListPeriod::controlValues(null), array_filter($filters, fn ($value) => $value !== null)),
            // From the filters applied, never from the form (see x-filter-bar).
            'filterSummary' => FilterSummary::make($period['label'], $this->scopeCovered($isAdmin, $campusId, $schoolFilter, $campuses), compare: $period['previous_label']),
            // Anything other than the default view: this quarter, every campus, every school.
            'filtersApplied' => $filters['period_type'] !== $default['period_type'] || (int) $filters['quarter'] !== $default['quarter']
                || (int) $filters['year'] !== $default['year'] || ($isAdmin && $campusId !== null) || $schoolFilter !== null,
        ]);
    }

    /**
     * The "for <who>" of the report's line. The school filter narrows incidents
     * and inspections only (drills and advisories carry no school), and a
     * coordinator's incidents and inspections are their own while drills and
     * advisories are the whole campus's (ConsolidatedReportService::forViewer)
     * — so where those differ, the line says which is which.
     */
    protected function scopeCovered(bool $isAdmin, ?int $campusId, int|string|null $schoolFilter, $campuses): string
    {
        $campus = $isAdmin
            ? FilterSummary::who($campusId !== null ? $campuses->firstWhere('campus_id', $campusId) : null, $schoolFilter)
            : (Auth::user()->campus?->campus_name ?? 'your campus');

        if ($isAdmin && $schoolFilter === null) {
            return $campus;
        }

        $records = match (true) {
            $schoolFilter === null => 'your incidents and inspections',
            $schoolFilter === School::NOT_ASSIGNED => ($isAdmin ? '' : 'your ') . 'incidents and inspections with no school set',
            default => ($isAdmin ? School::filterLabel($schoolFilter) . '’s' : 'your ' . School::filterLabel($schoolFilter)) . ' incidents and inspections',
        };

        return "{$records}, and every drill and advisory, on {$campus}";
    }

    public function downloadPdf(ConsolidatedReportRequest $request): Response
    {
        $user = Auth::user();
        $period = $this->periodResolver->resolve($request->validated('period_type'), $request->periodParams());
        [$campusId] = $this->resolveScope($request);

        $report = $this->reportService->generate($period['start'], $period['end'], $campusId, $period);

        $pdf = $this->loadPdfViewWithPageCount('reports.consolidated-pdf', [
            'period' => $period,
            'report' => $report,
            'generatedByName' => $user->full_name,
            'generatedByPosition' => $this->positionLabel($user),
        ]);

        $filename = sprintf(
            'ASCOT-DRRM-Report_%s_%s.pdf',
            $this->periodSlug($request->validated('period_type'), $period),
            $this->scopeSlug($report)
        );

        $this->logActivity('report_exported', $period, $this->scopeLabel($report), 'pdf');

        return $pdf->download($filename);
    }

    /**
     * Coordinators are always locked to their own campus, regardless of
     * what's in the query string — this is the server-side enforcement
     * the task requires so the URL can't be edited to read another
     * campus's data. DRRMO Admin gets null (All Campuses) unless a valid
     * campus_id was submitted (already checked against campuses table by
     * the Form Request's `exists` rule).
     */
    /**
     * Campus scope (resolveCampusId(), unchanged) plus the school view filter,
     * which is set on the report service for this request. A specific school
     * picked with "All Campuses" narrows the campus to that school's campus —
     * same records (a school implies its campus), and the report header then
     * names both.
     *
     * @return array{0: ?int, 1: int|string|null}
     */
    protected function resolveScope(ConsolidatedReportRequest $request): array
    {
        $campusId = $this->resolveCampusId($request);
        $schoolFilter = $request->schoolFilter();

        if ($campusId === null && is_int($schoolFilter)) {
            $campusId = School::whereKey($schoolFilter)->value('campus_id');
        }

        $this->reportService->forSchool($schoolFilter);
        $this->reportService->forViewer(Auth::user());

        return [$campusId, $schoolFilter];
    }

    /** "ASCOT Zabali Campus · School of Engineering" (school only when filtered). */
    protected function scopeLabel(array $report): string
    {
        return $report['campus']['name'] . ($report['school']['filter'] !== null ? ' · ' . $report['school']['name'] : '');
    }

    protected function scopeSlug(array $report): string
    {
        return FilenameSlug::titleCase($report['campus']['name'])
            . ($report['school']['filter'] !== null ? '_' . FilenameSlug::titleCase($report['school']['name']) : '');
    }

    protected function resolveCampusId(ConsolidatedReportRequest $request): ?int
    {
        $user = Auth::user();

        if ($user->role === 'Coordinator') {
            return $user->campus_id;
        }

        return $request->validated('campus_id') !== null ? (int) $request->validated('campus_id') : null;
    }

    /**
     * FormRequest::validated() only returns keys that were actually present
     * in the input — a field absent from the query string (e.g. week_date
     * when period_type=quarterly) doesn't come back as null, it's just
     * missing entirely. The filter form and the Download PDF link both
     * need every key to exist (even as null) so Blade can read them and
     * route()'s query-string builder can round-trip them safely. This also
     * substitutes the server-enforced campusId in place of whatever a
     * Coordinator may have tried to pass, so exported links never carry a
     * campus they don't actually have access to.
     */
    protected function normalizedFilters(ConsolidatedReportRequest $request, ?int $campusId, int|string|null $schoolFilter = null): array
    {
        $defaults = [
            'period_type' => null,
            'week_date' => null,
            'month' => null,
            'year' => null,
            'quarter' => null,
            'start_date' => null,
            'end_date' => null,
            'campus_id' => null,
            'school_id' => null,
        ];

        return array_merge($defaults, $request->validated(), ['campus_id' => $campusId, 'school_id' => $schoolFilter]);
    }

    protected function positionLabel(Personnel $user): string
    {
        return $user->role === 'DRRMO Admin' ? 'DRRMO Administrator' : 'Campus DRRM Coordinator';
    }

    protected function periodSlug(string $periodType, array $period): string
    {
        return match ($periodType) {
            'weekly' => 'Week-' . $period['start']->format('M-d-Y'),
            'monthly' => $period['start']->format('M-Y'),
            'quarterly' => 'Q' . ceil($period['start']->month / 3) . '-' . $period['start']->year,
            'yearly' => (string) $period['start']->year,
            'custom' => $period['start']->format('M-d-Y') . '_to_' . $period['end']->format('M-d-Y'),
            default => 'Report',
        };
    }

    protected function logActivity(string $action, array $period, string $campusLabel, ?string $format): void
    {
        $description = $format === null
            ? sprintf('%s generated the Consolidated DRRM Report for %s (%s)', Auth::user()->full_name, $period['label'], $campusLabel)
            : sprintf('%s exported the Consolidated DRRM Report (%s) for %s (%s)', Auth::user()->full_name, strtoupper($format), $period['label'], $campusLabel);

        ActivityLog::create([
            'personnel_id' => Auth::id(),
            'action' => $action,
            'subject_type' => null,
            'subject_id' => null,
            'description' => mb_substr($description, 0, 255),
            'ip_address' => request()?->ip(),
        ]);
    }
}
