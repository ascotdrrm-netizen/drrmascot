<?php

namespace App\Services;

use App\Models\Advisory\DisasterAdvisory;
use App\Models\Alert;
use App\Models\Building\HazardInspection;
use App\Models\Campus;
use App\Models\Drill\Drill;
use App\Models\Drill\DrillAfterActivityReport;
use App\Models\Drill\DrillDocumentation;
use App\Models\Incident\DamageReport;
use App\Models\Incident\Incident;
use App\Models\Personnel;
use App\Models\Reporter\CitizenReport;
use App\Models\Reporter\SosEvent;
use App\Models\School;
use App\Support\RecordAccess;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

/**
 * Builds the single structured array the Consolidated DRRM Report's web
 * view and the PDF both render from — so the numbers are guaranteed to
 * match on screen and on paper, since both come from one call.
 *
 * Date column chosen per module (see the task's investigation requirement):
 *   - Facility Hazard Auditor -> hazard_inspections.inspection_date
 *   - Incident Reporting      -> incidents.date_occurred
 *   - Drill Tracker           -> drill_documentations.date_conducted (a drill
 *                                counts as conducted once it is documented)
 *   - SMS & Broadcast Alert   -> alerts.sent_at
 * None of these four tables have created_at/updated_at at all (each model
 * declares `public $timestamps = false`), so there was no fallback needed.
 */
class ConsolidatedReportService
{
    /**
     * School VIEW filter for every query below: null = all schools, "none" =
     * not assigned, or a school_id. It only narrows within the campus scope
     * the caller already resolved; callers set it on every request (even to
     * null), so a value can never carry over between requests.
     */
    protected int|string|null $schoolFilter = null;

    public function forSchool(int|string|null $filter): static
    {
        $this->schoolFilter = $filter;

        return $this;
    }

    /**
     * Whose records the report covers: a Coordinator only their own
     * (App\Support\RecordAccess), on top of the campus scope; the Admin (or
     * null) everything. Like forSchool(), callers set it on every request.
     */
    protected ?Personnel $viewer = null;

    public function forViewer(?Personnel $viewer): static
    {
        $this->viewer = $viewer;

        return $this;
    }

    protected function restrictedToViewer(): bool
    {
        return $this->viewer !== null && ! RecordAccess::isAdmin($this->viewer);
    }

    public function schoolFilter(): int|string|null
    {
        return $this->schoolFilter;
    }

    /** @return array{filter: int|string|null, name: string} */
    public function schoolLabel(): array
    {
        return ['filter' => $this->schoolFilter, 'name' => School::filterLabel($this->schoolFilter)];
    }

    /**
     * @param  array{previous_start: CarbonImmutable, previous_end: CarbonImmutable}  $period  The full resolved period array from ReportPeriodResolver (only previous_start/previous_end are read from it — $start/$end above are its current-period bounds).
     * @return array<string, mixed>
     */
    public function generate(CarbonImmutable $start, CarbonImmutable $end, ?int $campusId, array $period): array
    {
        $current = $this->moduleTotals($start, $end, $campusId);
        $previous = $this->moduleTotals($period['previous_start'], $period['previous_end'], $campusId);

        return [
            'campus' => $this->campusLabel($campusId),
            'school' => $this->schoolLabel(),
            'executive_summary' => $this->buildExecutiveSummary($current, $previous),
            'hazard_auditor' => $this->hazardAuditorSection($start, $end, $campusId),
            'incidents' => $this->incidentsSection($start, $end, $campusId),
            'drills' => $this->drillsSection($start, $end, $campusId),
            'alerts' => $this->alertsSection($start, $end, $campusId),
            'advisories' => $this->advisoriesSection($start, $end, $campusId),
            'reporter_cases' => $this->reporterCasesSection($start, $end, $campusId),
            'campus_breakdown' => $campusId === null ? $this->campusBreakdown($start, $end) : null,
            'school_breakdown' => $this->schoolBreakdown($start, $end, $campusId),
        ];
    }

    public function campusLabel(?int $campusId): array
    {
        if ($campusId === null) {
            return ['id' => null, 'name' => 'All Campuses'];
        }

        $campus = Campus::find($campusId);

        return ['id' => $campusId, 'name' => $campus->campus_name ?? 'Unknown Campus'];
    }

    /**
     * The handful of headline counts needed for both the current AND
     * previous period, kept in one place so buildExecutiveSummary() can
     * diff them without duplicating each query twice.
     */
    protected function moduleTotals(CarbonImmutable $start, CarbonImmutable $end, ?int $campusId): array
    {
        return [
            'inspections' => $this->hazardInspectionsQuery($start, $end, $campusId)->count(),
            'incidents' => $this->incidentsQuery($start, $end, $campusId)->count(),
            'drills' => $this->drillDocumentationsQuery($start, $end, $campusId)->count(),
            'alerts' => $this->alertsQuery($start, $end, $campusId)->count(),
            'advisories' => $this->advisoriesQuery($start, $end, $campusId)->count(),
            'reporter_reports' => $this->citizenReportsQuery($start, $end, $campusId)->count(),
            'sos' => $this->sosQuery($start, $end, $campusId)->count(),
            // Every case closed as Resolved, whichever module it came in through.
            'resolved' => $this->incidentsQuery($start, $end, $campusId)->where('status', 'Resolved')->count()
                + $this->citizenReportsQuery($start, $end, $campusId)->where('citizen_reports.status', 'Resolved')->count()
                + $this->sosQuery($start, $end, $campusId)->where('status', 'Resolved')->count(),
        ];
    }

    protected function buildExecutiveSummary(array $current, array $previous): array
    {
        $rows = [
            ['key' => 'inspections', 'label' => 'Hazard Inspections Conducted'],
            ['key' => 'incidents', 'label' => 'Incidents Reported'],
            ['key' => 'drills', 'label' => 'Drills Conducted'],
            ['key' => 'alerts', 'label' => 'Alerts Sent'],
            ['key' => 'advisories', 'label' => 'Disaster Advisories Issued'],
            ['key' => 'reporter_reports', 'label' => 'Reporter Reports Received'],
            ['key' => 'sos', 'label' => 'SOS Alerts Received'],
            ['key' => 'resolved', 'label' => 'Cases Resolved'],
        ];

        return array_map(function ($row) use ($current, $previous) {
            $currentValue = $current[$row['key']];
            $previousValue = $previous[$row['key']];

            return [
                'label' => $row['label'],
                'current' => $currentValue,
                'previous' => $previousValue,
                'change' => $currentValue - $previousValue,
                'change_pct' => $this->percentChange($currentValue, $previousValue),
            ];
        }, $rows);
    }

    /**
     * Null means "show — " (no data either period, nothing meaningful to
     * compare). A previous value of 0 with a positive current value is
     * "New" (division by zero avoided) rather than an infinite percentage.
     */
    protected function percentChange(int $current, int $previous): ?float
    {
        if ($previous === 0) {
            return $current > 0 ? null : 0.0;
        }

        return round((($current - $previous) / $previous) * 100, 1);
    }

    // ============================ HAZARD AUDITOR ============================

    /**
     * Public — also called directly by DetailedReportService so the
     * detailed report's per-module summary counts are guaranteed to match
     * this (the Consolidated Summary Report's) numbers for the same filters,
     * since both read from the exact same method/query.
     */
    public function hazardAuditorSection(CarbonImmutable $start, CarbonImmutable $end, ?int $campusId): array
    {
        $inspectionsConducted = $this->hazardInspectionsQuery($start, $end, $campusId)->count();

        $buildingsInspected = $this->hazardInspectionsQuery($start, $end, $campusId)
            ->distinct()
            ->count('hazard_inspections.building_id');

        $byRiskLevel = $this->hazardInspectionsQuery($start, $end, $campusId)
            ->select('hazard_inspections.risk_level')
            ->selectRaw('COUNT(*) as total')
            ->groupBy('hazard_inspections.risk_level')
            ->pluck('total', 'risk_level')
            ->all();

        return [
            'inspections_conducted' => $inspectionsConducted,
            'buildings_inspected' => $buildingsInspected,
            'by_risk_level' => [
                'Low' => $byRiskLevel['Low'] ?? 0,
                'Medium' => $byRiskLevel['Medium'] ?? 0,
                'High' => $byRiskLevel['High'] ?? 0,
            ],
            'has_data' => $inspectionsConducted > 0,
        ];
    }

    /**
     * Inspections conducted in the period. An inspection is its own dated
     * event, so it keeps counting when its building is in Trash: the work
     * happened. (The open-findings list on the dashboard leaves those out —
     * nobody can act on a building in Trash.)
     */
    public function hazardInspectionsQuery(CarbonImmutable $start, CarbonImmutable $end, ?int $campusId): Builder
    {
        $query = HazardInspection::query()
            ->join('buildings', 'buildings.building_id', '=', 'hazard_inspections.building_id')
            ->whereBetween('hazard_inspections.inspection_date', [$start->toDateString(), $end->toDateString()]);

        if ($campusId !== null) {
            $query->where('buildings.campus_id', $campusId);
        }

        if ($this->restrictedToViewer()) {
            RecordAccess::applyToInspections($query, $this->viewer, 'hazard_inspections', 'buildings');
        }

        return School::applyFilter($query, $this->schoolFilter, 'hazard_inspections.school_id');
    }

    // ============================== INCIDENTS ================================

    public function incidentsSection(CarbonImmutable $start, CarbonImmutable $end, ?int $campusId): array
    {
        $total = $this->incidentsQuery($start, $end, $campusId)->count();

        $byHazardType = $this->incidentsQuery($start, $end, $campusId)
            ->select('hazard_type')
            ->selectRaw('COUNT(*) as total')
            ->groupBy('hazard_type')
            ->orderByDesc('total')
            ->pluck('total', 'hazard_type')
            ->all();

        $byStatus = $this->incidentsQuery($start, $end, $campusId)
            ->select('status')
            ->selectRaw('COUNT(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status')
            ->all();

        $damageQuery = $this->damageReportsQuery($start, $end, $campusId);

        // damage_level (Minor/Major/Total) on damage reports is the closest
        // existing proxy for "severity" — incidents themselves have no
        // severity column, only a workflow `status` (Active/Under
        // Response/Resolved), which is reported separately above.
        $byDamageLevel = (clone $damageQuery)
            ->select('damage_reports.damage_level')
            ->selectRaw('COUNT(*) as total')
            ->groupBy('damage_reports.damage_level')
            ->pluck('total', 'damage_level')
            ->all();

        $totalCasualties = (clone $damageQuery)->sum('damage_reports.casualty_count');
        $totalEstCost = (clone $damageQuery)->sum('damage_reports.est_cost');

        return [
            'total' => $total,
            'by_hazard_type' => $byHazardType,
            'by_status' => [
                'Active' => $byStatus['Active'] ?? 0,
                'Under Response' => $byStatus['Under Response'] ?? 0,
                'Resolved' => $byStatus['Resolved'] ?? 0,
            ],
            'by_damage_level' => [
                'Minor' => $byDamageLevel['Minor'] ?? 0,
                'Major' => $byDamageLevel['Major'] ?? 0,
                'Total' => $byDamageLevel['Total'] ?? 0,
            ],
            'total_casualties' => (int) $totalCasualties,
            'total_est_cost' => (float) $totalEstCost,
            'has_data' => $total > 0,
        ];
    }

    public function incidentsQuery(CarbonImmutable $start, CarbonImmutable $end, ?int $campusId): Builder
    {
        $query = Incident::query()
            ->whereBetween('date_occurred', [$start->toDateString(), $end->toDateString()]);

        if ($campusId !== null) {
            $query->where('campus_id', $campusId);
        }

        if ($this->restrictedToViewer()) {
            RecordAccess::applyToIncidents($query, $this->viewer);
        }

        return School::applyFilter($query, $this->schoolFilter, 'incidents.school_id');
    }

    /**
     * Damage reports of incidents that occurred in the period. A damage report
     * is part of its incident's record (not an event of its own), so it follows
     * the incident: when the incident is in Trash, its damage is not counted —
     * otherwise casualties and cost would describe an incident the report says
     * doesn't exist. (Contrast inspections and alerts, which are their own
     * dated events and keep counting when their building/incident is in Trash.)
     */
    public function damageReportsQuery(CarbonImmutable $start, CarbonImmutable $end, ?int $campusId): Builder
    {
        $query = DamageReport::query()
            ->join('incidents', 'incidents.incident_id', '=', 'damage_reports.incident_id')
            ->whereNull('incidents.deleted_at')
            ->whereBetween('incidents.date_occurred', [$start->toDateString(), $end->toDateString()]);

        if ($campusId !== null) {
            $query->where('incidents.campus_id', $campusId);
        }

        if ($this->restrictedToViewer()) {
            RecordAccess::applyToIncidents($query, $this->viewer);
        }

        return School::applyFilter($query, $this->schoolFilter, 'incidents.school_id');
    }

    // ================================ DRILLS ==================================

    /**
     * Drill Tracker. A drill counts as CONDUCTED once it has documentation,
     * dated by the documentation's date held (there is no status). Drills are
     * campus-wide records with no school, so the school filter doesn't apply
     * to them; a Coordinator sees every drill of their campus (RecordAccess).
     */
    public function drillsSection(CarbonImmutable $start, CarbonImmutable $end, ?int $campusId): array
    {
        $scheduled = $this->drillsQuery($start, $end, $campusId)->count();
        $documented = $this->drillDocumentationsQuery($start, $end, $campusId);
        $conducted = (clone $documented)->count();

        $byType = (clone $documented)
            ->select('drills.drill_type')
            ->selectRaw('COUNT(*) as total')
            ->groupBy('drills.drill_type')
            ->orderByDesc('total')
            ->pluck('total', 'drill_type')
            ->all();

        return [
            'scheduled' => $scheduled,
            'total' => $conducted,
            'after_activity_reports' => $this->drillAfterActivityReportsQuery($start, $end, $campusId)->count(),
            'total_participants' => (int) (clone $documented)->sum('drill_documentations.participant_count'),
            'by_type' => $byType,
            'has_data' => $scheduled > 0 || $conducted > 0,
        ];
    }

    /** Drills SCHEDULED in the period (by scheduled date). */
    public function drillsQuery(CarbonImmutable $start, CarbonImmutable $end, ?int $campusId): Builder
    {
        $query = Drill::query()->whereBetween('drills.scheduled_date', [$start->toDateString(), $end->toDateString()]);

        return $this->scopeDrills($query, $campusId);
    }

    /** Documentation of drills HELD in the period (by the documentation's date held). */
    public function drillDocumentationsQuery(CarbonImmutable $start, CarbonImmutable $end, ?int $campusId): Builder
    {
        $query = DrillDocumentation::query()
            ->join('drills', 'drills.drill_id', '=', 'drill_documentations.drill_id')
            ->whereNull('drills.deleted_at')
            ->whereBetween('drill_documentations.date_conducted', [$start->toDateString(), $end->toDateString()]);

        return $this->scopeDrills($query, $campusId);
    }

    /** After Activity Reports of drills held in the period (by the report's date conducted). */
    public function drillAfterActivityReportsQuery(CarbonImmutable $start, CarbonImmutable $end, ?int $campusId): Builder
    {
        $query = DrillAfterActivityReport::query()
            ->join('drills', 'drills.drill_id', '=', 'drill_after_activity_reports.drill_id')
            ->whereNull('drills.deleted_at')
            ->whereBetween('drill_after_activity_reports.date_conducted', [$start->toDateString(), $end->toDateString()]);

        return $this->scopeDrills($query, $campusId);
    }

    protected function scopeDrills(Builder $query, ?int $campusId): Builder
    {
        if ($campusId !== null) {
            $query->where('drills.campus_id', $campusId);
        }

        if ($this->restrictedToViewer()) {
            RecordAccess::applyToDrills($query, $this->viewer);
        }

        return $query;
    }

    // ================================ ALERTS ===================================

    public function alertsSection(CarbonImmutable $start, CarbonImmutable $end, ?int $campusId): array
    {
        $total = $this->alertsQuery($start, $end, $campusId)->count();

        $totalRecipients = $this->alertsQuery($start, $end, $campusId)->sum('alerts.recipient_count');

        // Alerts have no standalone "type" column — delivery_status
        // (Sent/Partial/Failed) is the only existing categorical field.
        $byDeliveryStatus = $this->alertsQuery($start, $end, $campusId)
            ->select('alerts.delivery_status')
            ->selectRaw('COUNT(*) as total')
            ->groupBy('alerts.delivery_status')
            ->pluck('total', 'delivery_status')
            ->all();

        return [
            'total' => $total,
            'total_recipients' => (int) $totalRecipients,
            'by_delivery_status' => [
                'Sent' => $byDeliveryStatus['Sent'] ?? 0,
                'Partial' => $byDeliveryStatus['Partial'] ?? 0,
                'Failed' => $byDeliveryStatus['Failed'] ?? 0,
            ],
            'has_data' => $total > 0,
        ];
    }

    /** Alerts sent in the period — their own event (a message went out), so they count even when their incident is in Trash. */
    public function alertsQuery(CarbonImmutable $start, CarbonImmutable $end, ?int $campusId): Builder
    {
        $query = Alert::query()
            ->join('incidents', 'incidents.incident_id', '=', 'alerts.incident_id')
            ->whereBetween('alerts.sent_at', [$start, $end]);

        if ($campusId !== null) {
            $query->where('incidents.campus_id', $campusId);
        }

        // Alerts follow their incident.
        if ($this->restrictedToViewer()) {
            RecordAccess::applyToIncidents($query, $this->viewer);
        }

        // Alerts have no school of their own: attributed via their incident.
        return School::applyFilter($query, $this->schoolFilter, 'incidents.school_id');
    }

    // ============================ DISASTER ADVISORIES ===========================

    /**
     * Advisories ISSUED in the period (by issued_at). Not in Trash. A campus
     * means that campus's advisories AND the all-campus ones, as on the
     * Advisories list; a Coordinator sees every advisory of their campus,
     * whoever issued it (RecordAccess::applyToAdvisories). Advisories carry
     * no school, so the school filter does not apply — the page says so.
     *
     * "Followed" = at least one incident was recorded from it, WHENEVER that
     * incident was recorded (one issued Sep 29 and followed Oct 2 counts),
     * whoever recorded it. It reads the advisory's incidents() relation — the
     * same one the advisory page lists — so the two can't disagree, and an
     * incident in Trash doesn't count (Incident's soft-delete scope), as it
     * counts nowhere else in the report.
     */
    public function advisoriesSection(CarbonImmutable $start, CarbonImmutable $end, ?int $campusId): array
    {
        $query = $this->advisoriesQuery($start, $end, $campusId);
        $total = (clone $query)->count();
        $followed = (clone $query)->whereHas('incidents')->count();

        $byHazard = (clone $query)->toBase()
            ->select('disaster_advisories.hazard_type')->selectRaw('COUNT(*) as total')
            ->groupBy('disaster_advisories.hazard_type')->orderByDesc('total')->orderBy('disaster_advisories.hazard_type')
            ->pluck('total', 'hazard_type')->map(fn ($n) => (int) $n)->all();

        $bySource = (clone $query)->toBase()
            ->select('disaster_advisories.source')->selectRaw('COUNT(*) as total')
            ->groupBy('disaster_advisories.source')
            ->pluck('total', 'source')->all();

        return [
            'total' => $total,
            'followed' => $followed,
            'not_followed' => $total - $followed,
            'by_hazard_type' => $byHazard,
            // Every source, zeros included, in the form's order.
            'by_source' => collect(\App\Http\Controllers\Advisory\DisasterAdvisoryController::SOURCES)
                ->mapWithKeys(fn (string $source) => [$source => (int) ($bySource[$source] ?? 0)])->all(),
            'campus_filtered' => $campusId !== null,
            'school_filtered' => $this->schoolFilter !== null,
            'has_data' => $total > 0,
        ];
    }

    public function advisoriesQuery(CarbonImmutable $start, CarbonImmutable $end, ?int $campusId): Builder
    {
        $query = DisasterAdvisory::query()->whereBetween('disaster_advisories.issued_at', [$start, $end]);

        if ($campusId !== null) {
            // An all-campus advisory belongs under every campus.
            $query->where(fn ($q) => $q->where('disaster_advisories.campus_id', $campusId)->orWhereNull('disaster_advisories.campus_id'));
        }

        if ($this->restrictedToViewer()) {
            RecordAccess::applyToAdvisories($query, $this->viewer);
        }

        return $query;
    }

    // ========================= REPORTER REPORTS & SOS ==========================

    /**
     * What came in through the Reporter app, dated by when it was sent
     * (created_at), by status. Campus-scoped only: Coordinators handle every
     * report and SOS on their campus, not just ones they created.
     */
    public function reporterCasesSection(CarbonImmutable $start, CarbonImmutable $end, ?int $campusId): array
    {
        $reportCounts = $this->citizenReportsQuery($start, $end, $campusId)
            ->select('citizen_reports.status')->selectRaw('COUNT(*) as total')
            ->groupBy('citizen_reports.status')->pluck('total', 'status')->all();
        $sosCounts = $this->sosQuery($start, $end, $campusId)
            ->select('status')->selectRaw('COUNT(*) as total')
            ->groupBy('status')->pluck('total', 'status')->all();
        $byCategory = $this->citizenReportsQuery($start, $end, $campusId)
            ->select('citizen_reports.category')->selectRaw('COUNT(*) as total')
            ->groupBy('citizen_reports.category')->orderByDesc('total')->pluck('total', 'category')->all();

        $reportsByStatus = array_map(fn ($s) => (int) ($reportCounts[$s] ?? 0), array_combine(self::REPORT_STATUSES, self::REPORT_STATUSES));
        $sosByStatus = array_map(fn ($s) => (int) ($sosCounts[$s] ?? 0), array_combine(self::SOS_STATUSES, self::SOS_STATUSES));
        $labels = collect(config('report_categories', []))->keyBy('key')->all();

        return [
            'reports_total' => array_sum($reportsByStatus),
            'reports_by_status' => $reportsByStatus,
            'reports_by_category' => collect($byCategory)->mapWithKeys(fn ($n, $key) => [($labels[$key]['label'] ?? ucfirst((string) $key)) => (int) $n])->all(),
            'sos_total' => array_sum($sosByStatus),
            'sos_by_status' => $sosByStatus,
            'school_filtered' => $this->schoolFilter !== null,
            'has_data' => array_sum($reportsByStatus) + array_sum($sosByStatus) > 0,
        ];
    }

    public const REPORT_STATUSES = ['New', 'Verified', 'Responding', 'Resolved', 'Rejected'];

    public const SOS_STATUSES = ['New', 'Acknowledged', 'Responding', 'Resolved'];

    /** Reporter-app reports; the school is the reporter's (a deleted reporter's reports count as "not assigned"). */
    public function citizenReportsQuery(CarbonImmutable $start, CarbonImmutable $end, ?int $campusId): Builder
    {
        $query = CitizenReport::query()
            ->leftJoin('personnel as report_reporters', 'report_reporters.personnel_id', '=', 'citizen_reports.reporter_id')
            ->whereBetween('citizen_reports.created_at', [$start, $end]);

        if ($campusId !== null) {
            $query->where('citizen_reports.campus_id', $campusId);
        }

        return School::applyFilter($query, $this->schoolFilter, 'report_reporters.school_id');
    }

    /** SOS alerts. They carry no school; the school filter narrows by the sender's school. */
    public function sosQuery(CarbonImmutable $start, CarbonImmutable $end, ?int $campusId): Builder
    {
        $query = SosEvent::query()->whereBetween('sos_events.created_at', [$start, $end]);

        if ($campusId !== null) {
            $query->where('sos_events.campus_id', $campusId);
        }
        if ($this->schoolFilter !== null) {
            $query->whereIn('sos_events.reporter_id', School::applyFilter(Personnel::query()->select('personnel_id'), $this->schoolFilter, 'personnel.school_id'));
        }

        return $query;
    }

    // ============================ CAMPUS BREAKDOWN =============================

    /**
     * Per-campus totals shown alongside the system-wide figures when the
     * report is viewed with "All Campuses" selected.
     */
    protected function campusBreakdown(CarbonImmutable $start, CarbonImmutable $end): array
    {
        return Campus::orderBy('campus_name')->get()->map(function (Campus $campus) use ($start, $end) {
            return [
                'campus_id' => $campus->campus_id,
                'campus_name' => $campus->campus_name,
                'inspections' => $this->hazardInspectionsQuery($start, $end, $campus->campus_id)->count(),
                'incidents' => $this->incidentsQuery($start, $end, $campus->campus_id)->count(),
                'drills' => $this->drillDocumentationsQuery($start, $end, $campus->campus_id)->count(),
                'alerts' => $this->alertsQuery($start, $end, $campus->campus_id)->count(),
            ];
        })->all();
    }

    // ============================ SCHOOL BREAKDOWN =============================

    /**
     * Per-school totals, alongside (not replacing) the per-campus breakdown:
     * the schools in scope (the campus's, or all), plus a "Not assigned" row
     * for records with no school — so the rows always add up to the totals.
     * Drills have no school (they're campus-wide), so they aren't broken down here.
     * One grouped query per module, not one per school.
     */
    protected function schoolBreakdown(CarbonImmutable $start, CarbonImmutable $end, ?int $campusId): array
    {
        $counts = [
            'inspections' => $this->countBySchool($this->hazardInspectionsQuery($start, $end, $campusId), 'hazard_inspections.school_id'),
            'incidents' => $this->countBySchool($this->incidentsQuery($start, $end, $campusId), 'incidents.school_id'),
            'alerts' => $this->countBySchool($this->alertsQuery($start, $end, $campusId), 'incidents.school_id'),
        ];

        $schools = School::with('campus:campus_id,campus_name')
            ->when($campusId !== null, fn ($q) => $q->where('campus_id', $campusId))
            ->when(is_int($this->schoolFilter), fn ($q) => $q->whereKey($this->schoolFilter))
            ->when($this->schoolFilter === School::NOT_ASSIGNED, fn ($q) => $q->whereRaw('1 = 0'))
            ->get()
            ->sortBy(fn (School $s) => ($s->campus->campus_name ?? '') . '|' . $s->school_name);

        $row = fn (string $key, ?string $campusName, string $schoolName) => [
            'campus_name' => $campusName,
            'school_name' => $schoolName,
            'inspections' => $counts['inspections'][$key] ?? 0,
            'incidents' => $counts['incidents'][$key] ?? 0,
            'alerts' => $counts['alerts'][$key] ?? 0,
        ];

        $rows = $schools->map(fn (School $s) => $row((string) $s->school_id, $s->campus->campus_name ?? null, $s->school_name))->values()->all();

        if (! is_int($this->schoolFilter)) {
            $rows[] = $row(School::NOT_ASSIGNED, null, 'Not assigned');
        }

        return $rows;
    }

    /** @return array<string, int> school_id (or "none") => count */
    protected function countBySchool(Builder $query, string $column): array
    {
        return $query->toBase()
            ->selectRaw("{$column} AS sid, COUNT(*) AS n")
            ->groupBy($column)
            ->get()
            ->mapWithKeys(fn ($r) => [($r->sid === null ? School::NOT_ASSIGNED : (string) $r->sid) => (int) $r->n])
            ->all();
    }
}
