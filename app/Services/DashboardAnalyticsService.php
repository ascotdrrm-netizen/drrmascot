<?php

namespace App\Services;

use App\Support\RiskMatrix;
use App\Models\ActivityLog;
use App\Models\Building\HazardInspection;
use App\Models\Drill\Drill;
use App\Models\Incident\Incident;
use App\Models\Personnel;
use App\Support\RecordAccess;
use App\Support\Sql\DateBucket;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Everything the admin/coordinator dashboards show, built from aggregate
 * queries (COUNT / SUM(CASE ...) / GROUP BY) rather than loaded collections.
 * Lists are capped with LIMIT and eager-load their relations.
 *
 * Definitions (the schema has no status history, so these are the honest
 * versions available):
 *   - Unresolved incident: status is not 'Resolved' (Active / Under Response).
 *   - Drill conducted: a drill with documentation, dated by when it was
 *     held (the Drill Tracker has no statuses).
 *   - Open high-risk finding: a High-rated inspection that is still its
 *     building's latest inspection (a newer inspection supersedes it) on a
 *     building that hasn't been deleted.
 */
class DashboardAnalyticsService
{
    public const RANGES = [
        '7d' => 'Last 7 days',
        '30d' => 'Last 30 days',
        'quarter' => 'This quarter',
        'year' => 'This year',
    ];

    public const DEFAULT_RANGE = '30d';

    protected const LIST_LIMIT = 5;

    /** Set per build(): whose records a Coordinator's dashboard covers. */
    protected ?Personnel $viewer = null;

    public function __construct(protected ReportPeriodResolver $periodResolver)
    {
    }

    /**
     * Every range ends today; "This quarter"/"This year" are to-date, and the
     * comparison period is the same number of days immediately before (the
     * resolver's custom-range rule), so a partial quarter is never compared
     * against a full one.
     */
    public function resolvePeriod(string $range): array
    {
        $today = CarbonImmutable::now('Asia/Manila')->startOfDay();

        $start = match ($range) {
            '7d' => $today->subDays(6),
            'quarter' => $today->firstOfQuarter(),
            'year' => $today->startOfYear(),
            default => $today->subDays(29),
        };

        return $this->periodResolver->resolve('custom', [
            'start_date' => $start->toDateString(),
            'end_date' => $today->toDateString(),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    /**
     * $viewer: a Coordinator's numbers, charts and lists cover only their own
     * records (App\Support\RecordAccess) on top of the campus filter; the
     * Admin's (or null) are unchanged.
     */
    public function build(string $range, ?int $campusId, bool $includeCampusComparison, bool $forAdmin = false, ?Personnel $viewer = null): array
    {
        $this->viewer = $viewer;
        $period = $this->resolvePeriod($range);
        $metrics = $this->metrics($period, $campusId);

        return [
            'period' => $period,
            'kpis' => $metrics['kpis'],
            'people' => $metrics['people'],
            // Alert analytics (SMS & Broadcast Alert: alerts sent, delivery
            // status breakdown, recipients reached) slot in here once that
            // module is reworked — add an 'alerts' KPI group next to kpis()
            // and an alerts chart next to the ones below.
            'charts' => [
                'incidents_over_time' => $this->incidentsOverTime($range, $period, $campusId),
                'incidents_by_hazard' => $this->incidentsByHazardType($period, $campusId),
                'findings_by_risk' => $this->findingsByRiskLevel($period, $campusId),
                'drills_per_campus' => $includeCampusComparison ? $this->drillsPerCampus($period) : null,
            ],
            'lists' => [
                'recent_activity' => $this->recentActivity($campusId),
                'unresolved_incidents' => $this->unresolvedIncidents($campusId),
                'open_high_risk' => $this->openHighRiskFindings($campusId),
                'upcoming_drills' => $this->upcomingDrills($campusId),
                'drills_to_document' => $this->drillsToDocument($campusId),
            ],
        ];
    }

    // ================================= KPIs ===================================

    protected function metrics(array $period, ?int $campusId): array
    {
        [$curStart, $prevStart, $curEnd] = [
            $period['start']->toDateString(),
            $period['previous_start']->toDateString(),
            $period['end']->toDateString(),
        ];

        $peopleSelect = [];
        $peopleBindings = [];
        foreach (Incident::PEOPLE as $column => $label) {
            $peopleSelect[] = "SUM(CASE WHEN date_occurred >= ? THEN {$column} END) AS {$column}";
            $peopleSelect[] = "COUNT(CASE WHEN date_occurred >= ? THEN {$column} END) AS {$column}_known";
            array_push($peopleBindings, $curStart, $curStart);
        }

        // One query per module, current and previous period together:
        // the two periods are contiguous, so "date >= curStart" splits them.
        $incidents = $this->ownIncidents(Incident::query())
            ->when($campusId !== null, fn ($q) => $q->where('campus_id', $campusId))
            ->whereBetween('date_occurred', [$prevStart, $curEnd])
            ->selectRaw(
                "SUM(CASE WHEN date_occurred >= ? THEN 1 ELSE 0 END) AS cur_total,
                 SUM(CASE WHEN date_occurred >= ? AND status <> 'Resolved' THEN 1 ELSE 0 END) AS cur_unresolved,
                 SUM(CASE WHEN date_occurred < ? THEN 1 ELSE 0 END) AS prev_total,
                 SUM(CASE WHEN date_occurred < ? AND status <> 'Resolved' THEN 1 ELSE 0 END) AS prev_unresolved",
                [$curStart, $curStart, $curStart, $curStart]
            )
            ->selectRaw(implode(', ', $peopleSelect), $peopleBindings)
            ->toBase()
            ->first();

        $open = $this->openFindingSql('hi');
        $inspections = $this->ownInspections(DB::table('hazard_inspections as hi')
            ->join('buildings as b', 'b.building_id', '=', 'hi.building_id'))
            ->when($campusId !== null, fn ($q) => $q->where('b.campus_id', $campusId))
            ->whereBetween('hi.inspection_date', [$prevStart, $curEnd])
            ->selectRaw(
                "SUM(CASE WHEN hi.inspection_date >= ? THEN 1 ELSE 0 END) AS cur_total,
                 SUM(CASE WHEN hi.inspection_date >= ? AND {$open} THEN 1 ELSE 0 END) AS cur_open,
                 SUM(CASE WHEN hi.inspection_date < ? THEN 1 ELSE 0 END) AS prev_total,
                 SUM(CASE WHEN hi.inspection_date < ? AND {$open} THEN 1 ELSE 0 END) AS prev_open",
                [$curStart, $curStart, $curStart, $curStart]
            )
            ->first();

        $drills = $this->ownDrills(DB::table('drill_documentations as dd')
            ->join('drills', 'drills.drill_id', '=', 'dd.drill_id')
            ->whereNull('drills.deleted_at'))
            ->when($campusId !== null, fn ($q) => $q->where('drills.campus_id', $campusId))
            ->whereBetween('dd.date_conducted', [$prevStart, $curEnd])
            ->selectRaw(
                'SUM(CASE WHEN dd.date_conducted >= ? THEN 1 ELSE 0 END) AS cur_total,
                 SUM(CASE WHEN dd.date_conducted < ? THEN 1 ELSE 0 END) AS prev_total',
                [$curStart, $curStart]
            )
            ->first();

        return ['kpis' => [
            $this->kpi('total_incidents', 'Total incidents', $incidents->cur_total, $incidents->prev_total, 'Reported in this period', true),
            $this->kpi('unresolved_incidents', 'Unresolved incidents', $incidents->cur_unresolved, $incidents->prev_unresolved, 'Active or under response', true),
            $this->kpi('inspections', 'Inspections conducted', $inspections->cur_total, $inspections->prev_total, 'Hazard inspections'),
            $this->kpi('open_high_risk', 'High-risk findings open', $inspections->cur_open, $inspections->prev_open, 'High-rated, not yet re-inspected', true),
            $this->kpi('drills', 'Drills conducted', $drills->cur_total, $drills->prev_total, 'Documented as held'),
        ], 'people' => collect(Incident::PEOPLE)->map(fn ($label, $column) => [
            'label' => $label, 'total' => (int) $incidents->{$column . '_known'} > 0 ? (int) $incidents->{$column} : null,
            'known' => (int) $incidents->{$column . '_known'},
        ])->values()->all()];
    }

    /**
     * @param  bool  $higherIsWorse  flips the colour of the change indicator
     */
    protected function kpi(string $key, string $label, $current, $previous, string $subtitle, bool $higherIsWorse = false): array
    {
        $current = (int) $current;
        $previous = (int) $previous;

        return [
            'key' => $key,
            'label' => $label,
            'subtitle' => $subtitle,
            'current' => $current,
            'previous' => $previous,
            'change' => $this->changeLabel($current, $previous),
            'direction' => $current <=> $previous,
            'higher_is_worse' => $higherIsWorse,
        ];
    }

    /**
     * "+25%" / "-10%" / "0%", "New" when the previous period was 0 and this
     * one isn't (no division by zero), and "—" when both are 0.
     */
    public function changeLabel(int $current, int $previous): string
    {
        if ($previous === 0) {
            return $current > 0 ? 'New' : '—';
        }

        $pct = round((($current - $previous) / $previous) * 100);

        return ($pct > 0 ? '+' : '') . $pct . '%';
    }

    // ================================ CHARTS ==================================

    /**
     * Day buckets for 7/30 days, weeks (starting Monday) for a quarter,
     * months for a year. Empty buckets are filled with 0 in PHP so the line
     * has one point per bucket.
     */
    protected function incidentsOverTime(string $range, array $period, ?int $campusId): array
    {
        [$granularity, $bucketSql] = match ($range) {
            'quarter' => ['week', DateBucket::week('date_occurred')],
            'year' => ['month', DateBucket::month('date_occurred')],
            default => ['day', DateBucket::day('date_occurred')],
        };

        $counts = $this->ownIncidents(Incident::query())
            ->when($campusId !== null, fn ($q) => $q->where('campus_id', $campusId))
            ->whereBetween('date_occurred', [$period['start']->toDateString(), $period['end']->toDateString()])
            ->selectRaw("{$bucketSql} AS bucket, COUNT(*) AS total")
            ->groupBy('bucket')
            ->toBase()
            ->pluck('total', 'bucket');

        $labels = [];
        $data = [];
        $cursor = match ($granularity) {
            'week' => $period['start']->startOfWeek(CarbonImmutable::MONDAY),
            'month' => $period['start']->startOfMonth(),
            default => $period['start'],
        };

        while ($cursor->lte($period['end'])) {
            $key = $granularity === 'month' ? $cursor->format('Y-m') : $cursor->toDateString();
            $labels[] = match ($granularity) {
                'week' => 'Wk of ' . $cursor->format('M j'),
                'month' => $cursor->format('M Y'),
                default => $cursor->format('M j'),
            };
            $data[] = (int) ($counts[$key] ?? 0);

            $cursor = match ($granularity) {
                'week' => $cursor->addWeek(),
                'month' => $cursor->addMonthNoOverflow(),
                default => $cursor->addDay(),
            };
        }

        return [
            'granularity' => $granularity,
            'labels' => $labels,
            'data' => $data,
            'has_data' => array_sum($data) > 0,
        ];
    }

    protected function incidentsByHazardType(array $period, ?int $campusId): array
    {
        $rows = $this->ownIncidents(Incident::query())
            ->when($campusId !== null, fn ($q) => $q->where('campus_id', $campusId))
            ->whereBetween('date_occurred', [$period['start']->toDateString(), $period['end']->toDateString()])
            ->selectRaw('hazard_type, COUNT(*) AS total')
            ->groupBy('hazard_type')
            ->orderByDesc('total')
            ->toBase()
            ->pluck('total', 'hazard_type');

        return [
            'labels' => $rows->keys()->all(),
            'data' => $rows->values()->map(fn ($v) => (int) $v)->all(),
            'has_data' => $rows->isNotEmpty(),
        ];
    }

    protected function findingsByRiskLevel(array $period, ?int $campusId): array
    {
        $rows = $this->ownInspections(DB::table('hazard_inspections as hi')
            ->join('buildings as b', 'b.building_id', '=', 'hi.building_id'))
            ->when($campusId !== null, fn ($q) => $q->where('b.campus_id', $campusId))
            ->whereBetween('hi.inspection_date', [$period['start']->toDateString(), $period['end']->toDateString()])
            ->selectRaw('hi.risk_level, COUNT(*) AS total')
            ->groupBy('hi.risk_level')
            ->pluck('total', 'risk_level');

        $data = array_map(fn ($level) => (int) ($rows[$level] ?? 0), RiskMatrix::LEVELS);

        return [
            'labels' => RiskMatrix::LEVELS,
            'data' => $data,
            'has_data' => array_sum($data) > 0,
        ];
    }

    /**
     * Admin "All Campuses" only. LEFT JOIN so campuses with no drills still
     * appear as 0 — one query for names and counts together.
     */
    protected function drillsPerCampus(array $period): array
    {
        $rows = DB::table('campuses as c')
            ->leftJoin('drills as d', function ($join) {
                $join->on('d.campus_id', '=', 'c.campus_id')->whereNull('d.deleted_at');
            })
            ->leftJoin('drill_documentations as dd', function ($join) use ($period) {
                $join->on('dd.drill_id', '=', 'd.drill_id')
                    ->whereBetween('dd.date_conducted', [$period['start']->toDateString(), $period['end']->toDateString()]);
            })
            ->groupBy('c.campus_id', 'c.campus_name')
            ->orderBy('c.campus_name')
            ->selectRaw('c.campus_name, COUNT(dd.documentation_id) AS total')
            ->get();

        $data = $rows->map(fn ($r) => (int) $r->total)->all();

        return [
            'labels' => $rows->pluck('campus_name')->all(),
            'data' => $data,
            'has_data' => array_sum($data) > 0,
        ];
    }

    // ================================= LISTS ==================================
    // "Right now" views — not bound to the selected range.

    /**
     * A Coordinator sees only their own entries. activity_logs has no campus
     * column, so an Admin filtered to one campus sees entries made by personnel
     * of that campus.
     */
    protected function recentActivity(?int $campusId)
    {
        $ownOnly = $this->viewer !== null && ! RecordAccess::isAdmin($this->viewer);

        return ActivityLog::with('actor:personnel_id,full_name,campus_id')
            ->when($ownOnly, fn ($q) => $q->where('personnel_id', $this->viewer->personnel_id))
            ->when(! $ownOnly && $campusId !== null, fn ($q) => $q->whereHas('actor', fn ($a) => $a->where('campus_id', $campusId)))
            ->orderByDesc('created_at')
            ->orderByDesc('activity_log_id')
            ->limit(8)
            ->get();
    }

    protected function unresolvedIncidents(?int $campusId): array
    {
        $base = $this->ownIncidents(Incident::query())
            ->when($campusId !== null, fn ($q) => $q->where('incidents.campus_id', $campusId))
            ->where('incidents.status', '<>', 'Resolved');

        return [
            'total' => (clone $base)->count(),
            // Campus name joined, not eager-loaded: one query fewer (the dashboard has a query budget).
            'items' => $base->join('campuses', 'campuses.campus_id', '=', 'incidents.campus_id')
                ->orderByDesc('incidents.date_occurred')
                ->orderByDesc('incidents.incident_id')
                ->limit(self::LIST_LIMIT)
                ->get(['incidents.incident_id', 'incidents.campus_id', 'incidents.hazard_type', 'incidents.date_occurred', 'incidents.status', 'campuses.campus_name']),
        ];
    }

    protected function openHighRiskFindings(?int $campusId): array
    {
        $base = $this->ownInspections(HazardInspection::query()
            ->from('hazard_inspections as hi')
            ->join('buildings as b', 'b.building_id', '=', 'hi.building_id'))
            ->when($campusId !== null, fn ($q) => $q->where('b.campus_id', $campusId))
            ->whereRaw($this->openFindingSql('hi'));

        return [
            'total' => (clone $base)->count(),
            'items' => $base->select('hi.*')
                ->with('building:building_id,campus_id,building_name', 'building.campus:campus_id,campus_name')
                ->orderByDesc('hi.inspection_date')
                ->orderByDesc('hi.inspection_id')
                ->limit(self::LIST_LIMIT)
                ->get(),
        ];
    }

    /** Drills scheduled from today on. */
    protected function upcomingDrills(?int $campusId)
    {
        // Campus name joined, not eager-loaded (one query fewer).
        return $this->ownDrills(Drill::query())
            ->join('campuses', 'campuses.campus_id', '=', 'drills.campus_id')
            ->when($campusId !== null, fn ($q) => $q->where('drills.campus_id', $campusId))
            ->where('drills.scheduled_date', '>=', CarbonImmutable::now('Asia/Manila')->toDateString())
            ->orderBy('drills.scheduled_date')
            ->orderBy('drills.scheduled_time')
            ->limit(self::LIST_LIMIT)
            ->get(['drills.drill_id', 'drills.campus_id', 'drills.drill_type', 'drills.scheduled_date', 'drills.scheduled_time', 'drills.venue', 'campuses.campus_name']);
    }

    /**
     * Drills whose start has passed but that still lack their documentation
     * or their After Activity Report — oldest first. One query: the first
     * LIST_LIMIT rows, the full total (COUNT(*) OVER () is evaluated before
     * LIMIT) and the campus name (joined, not eager-loaded).
     */
    protected function drillsToDocument(?int $campusId): array
    {
        $items = $this->ownDrills(Drill::query())
            ->join('campuses', 'campuses.campus_id', '=', 'drills.campus_id')
            ->when($campusId !== null, fn ($q) => $q->where('drills.campus_id', $campusId))
            // Held already: an earlier day, or today once its start time has passed.
            ->where(function ($q) {
                $now = CarbonImmutable::now('Asia/Manila');
                $q->where('drills.scheduled_date', '<', $now->toDateString())
                    ->orWhere(fn ($q) => $q->where('drills.scheduled_date', $now->toDateString())
                        ->where('drills.scheduled_time', '<=', $now->format('H:i:s')));
            })
            ->where(fn ($q) => $q->whereDoesntHave('documentation')->orWhereDoesntHave('afterActivityReport'))
            ->select(['drills.drill_id', 'drills.campus_id', 'drills.drill_type', 'drills.scheduled_date', 'drills.scheduled_time', 'drills.venue', 'campuses.campus_name'])
            ->withExists(['documentation', 'afterActivityReport'])
            ->selectRaw('COUNT(*) OVER () AS queue_total')
            ->orderBy('drills.scheduled_date')
            ->orderBy('drills.scheduled_time')
            ->limit(self::LIST_LIMIT)
            ->get();

        return ['total' => (int) ($items->first()->queue_total ?? 0), 'items' => $items];
    }

    // ============================ per-person scope ============================
    // No-ops for the Admin (or no viewer); a Coordinator's own records only.

    protected function ownIncidents($query)
    {
        return $this->restricted() ? RecordAccess::applyToIncidents($query, $this->viewer) : $query;
    }

    protected function ownInspections($query)
    {
        return $this->restricted() ? RecordAccess::applyToInspections($query, $this->viewer, 'hi', 'b') : $query;
    }

    protected function ownDrills($query)
    {
        return $this->restricted() ? RecordAccess::applyToDrills($query, $this->viewer) : $query;
    }

    protected function restricted(): bool
    {
        return $this->viewer !== null && ! RecordAccess::isAdmin($this->viewer);
    }

    /**
     * SQL condition for "open high-risk finding" on inspection alias $alias
     * joined to buildings as `b`: rated High or Critical Risk, building not deleted, and no
     * later inspection of the same building — by anyone, so a re-inspection a
     * colleague recorded still closes a coordinator's finding (only whether
     * one exists is used, never its contents).
     */
    protected function openFindingSql(string $alias): string
    {
        return "{$alias}.risk_level IN ('" . implode("', '", RiskMatrix::HIGH_LEVELS) . "') AND b.deleted_at IS NULL AND NOT EXISTS (
            SELECT 1 FROM hazard_inspections later
            WHERE later.building_id = {$alias}.building_id
              AND (later.inspection_date > {$alias}.inspection_date
                   OR (later.inspection_date = {$alias}.inspection_date AND later.inspection_id > {$alias}.inspection_id))
        )";
    }
}
