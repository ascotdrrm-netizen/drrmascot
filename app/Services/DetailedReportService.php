<?php

namespace App\Services;

use App\Models\Alert;
use App\Models\Building\HazardInspection;
use App\Models\Drill\Drill;
use App\Models\Incident\DamageReport;
use App\Models\Incident\Incident;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

/**
 * Builds the record-level query for each module's detailed report. Every
 * query here starts from ConsolidatedReportService's own scoped query
 * builder methods (hazardInspectionsQuery(), incidentsQuery(), etc.) rather
 * than redefining the date/campus filtering — so a detailed report's
 * row count can never drift from the Consolidated Summary Report's totals
 * for the same filters; they are, literally, the same underlying query.
 *
 * Every listing query explicitly ->select('<own table>.*') because the
 * base queries JOIN another table (buildings, incidents) for campus
 * scoping — without a qualified select, a plain get() would silently let
 * the joined table's identically-named columns (building_id, incident_id,
 * created_at, ...) clobber the primary table's own values during hydration.
 *
 * PRIVACY: none of these queries touch the personnel table. Facility Hazard
 * Auditor and Incident Reporting have no "recorded by" personnel link in
 * the schema at all. The Drill Tracker records participants only as
 * counts — no personnel row, name, or contact_number is ever selected or
 * displayed (the submitter's name is shown on a drill's own PDFs). SMS & Broadcast Alert
 * has no recipient contact data in the schema at all (only an integer
 * recipient_count) — nothing to strip.
 */
class DetailedReportService
{
    /**
     * Hard cap on rows rendered into a PDF — dompdf slows down badly past
     * roughly a thousand table rows. Each PDF section that hits it says so.
     */
    public const PDF_ROW_LIMIT = 1000;

    public function __construct(protected ConsolidatedReportService $summaryService)
    {
    }

    /** Sets the school view filter on the underlying queries (see ConsolidatedReportService). */
    /** Per-person scope for a Coordinator (see ConsolidatedReportService::forViewer()). */
    public function forViewer(?\App\Models\Personnel $viewer): static
    {
        $this->summaryService->forViewer($viewer);

        return $this;
    }

    public function forSchool(int|string|null $filter): static
    {
        $this->summaryService->forSchool($filter);

        return $this;
    }

    // ============================ HAZARD AUDITOR ============================

    public function hazardAuditorSummary(CarbonImmutable $start, CarbonImmutable $end, ?int $campusId): array
    {
        return $this->summaryService->hazardAuditorSection($start, $end, $campusId);
    }

    public function hazardInspectionRows(CarbonImmutable $start, CarbonImmutable $end, ?int $campusId): Builder
    {
        // Scoped by a raw SQL join on buildings (bypassing Eloquent's
        // soft-delete global scope, matching ConsolidatedReportService's
        // existing behavior), so the building behind an inspection could
        // be soft-deleted — withTrashed() keeps the eager-loaded relation
        // resolvable instead of coming back null.
        return $this->summaryService->hazardInspectionsQuery($start, $end, $campusId)
            ->select('hazard_inspections.*')
            ->with(['school', 'building' => fn ($q) => $q->withTrashed()->with('campus')])
            ->orderBy('hazard_inspections.inspection_date')
            ->orderBy('hazard_inspections.inspection_id');
    }

    public function highRiskHazardInspectionRows(CarbonImmutable $start, CarbonImmutable $end, ?int $campusId): Builder
    {
        return $this->hazardInspectionRows($start, $end, $campusId)->where('hazard_inspections.risk_level', 'High');
    }

    // ============================== INCIDENTS ================================

    public function incidentsSummary(CarbonImmutable $start, CarbonImmutable $end, ?int $campusId): array
    {
        return $this->summaryService->incidentsSection($start, $end, $campusId);
    }

    public function incidentRows(CarbonImmutable $start, CarbonImmutable $end, ?int $campusId): Builder
    {
        return $this->summaryService->incidentsQuery($start, $end, $campusId)
            ->with(['campus', 'school'])
            ->orderBy('date_occurred')
            ->orderBy('incident_id');
    }

    public function damageReportRows(CarbonImmutable $start, CarbonImmutable $end, ?int $campusId): Builder
    {
        // The base query already leaves out damage reports whose incident is
        // in Trash (they follow their incident). The building they describe
        // can still be in Trash — withTrashed() keeps its name printing
        // instead of the relation coming back null.
        return $this->summaryService->damageReportsQuery($start, $end, $campusId)
            ->select('damage_reports.*')
            ->with([
                'incident' => fn ($q) => $q->withTrashed()->with(['campus', 'school']),
                'building' => fn ($q) => $q->withTrashed(),
            ])
            ->orderBy('incidents.date_occurred')
            ->orderBy('damage_reports.report_id');
    }

    // ================================ DRILLS ==================================
    // Three separate reports: the schedule, the documentation and the After
    // Activity Reports. The last two never appear in each other's output.

    public function drillsSummary(CarbonImmutable $start, CarbonImmutable $end, ?int $campusId): array
    {
        return $this->summaryService->drillsSection($start, $end, $campusId);
    }

    /** Drills scheduled in the period, each with whether it's been documented / reported. */
    public function drillRows(CarbonImmutable $start, CarbonImmutable $end, ?int $campusId): Builder
    {
        return $this->summaryService->drillsQuery($start, $end, $campusId)
            ->select('drills.*')
            ->with('campus')
            ->withExists(['documentation', 'afterActivityReport'])
            ->orderBy('drills.scheduled_date')
            ->orderBy('drills.scheduled_time')
            ->orderBy('drills.drill_id');
    }

    /** Documentation of drills held in the period, oldest first. */
    public function drillDocumentationRows(CarbonImmutable $start, CarbonImmutable $end, ?int $campusId): Builder
    {
        return $this->summaryService->drillDocumentationsQuery($start, $end, $campusId)
            ->select('drill_documentations.*')
            ->with(['drill.campus', 'attachments'])
            ->orderBy('drill_documentations.date_conducted')
            ->orderBy('drill_documentations.documentation_id');
    }

    /** After Activity Reports of drills held in the period, oldest first. */
    public function drillAfterActivityRows(CarbonImmutable $start, CarbonImmutable $end, ?int $campusId): Builder
    {
        return $this->summaryService->drillAfterActivityReportsQuery($start, $end, $campusId)
            ->select('drill_after_activity_reports.*')
            ->with(['drill.campus', 'attachments'])
            ->orderBy('drill_after_activity_reports.date_conducted')
            ->orderBy('drill_after_activity_reports.report_id');
    }

    // ================================ ALERTS ===================================

    public function alertsSummary(CarbonImmutable $start, CarbonImmutable $end, ?int $campusId): array
    {
        return $this->summaryService->alertsSection($start, $end, $campusId);
    }

    public function alertRows(CarbonImmutable $start, CarbonImmutable $end, ?int $campusId): Builder
    {
        return $this->summaryService->alertsQuery($start, $end, $campusId)
            ->select('alerts.*')
            ->with(['incident' => fn ($q) => $q->withTrashed()->with(['campus', 'school'])])
            ->orderBy('alerts.sent_at')
            ->orderBy('alerts.alert_id');
    }

    // ============================== PDF HELPERS ===============================

    /**
     * Fetches at most PDF_ROW_LIMIT + 1 rows (one extra to detect
     * truncation without a separate COUNT query) and the true total via a
     * cheap count() on a clone, so the PDF can both cap its table AND tell
     * the reader exactly how many records exist in total.
     *
     * @return array{rows: \Illuminate\Support\Collection, total: int, truncated: bool}
     */
    public function rowsForPdf(Builder $query): array
    {
        $total = (clone $query)->count();
        $rows = $query->limit(self::PDF_ROW_LIMIT)->get();

        return [
            'rows' => $rows,
            'total' => $total,
            'truncated' => $total > self::PDF_ROW_LIMIT,
        ];
    }

}
