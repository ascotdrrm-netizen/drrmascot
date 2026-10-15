<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\HandlesCampusScoping;
use App\Models\Advisory\DisasterAdvisory;
use App\Models\Campus;
use App\Services\DashboardAnalyticsService;
use App\Support\FilterSummary;
use App\Support\RecordAccess;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

class DashboardController extends Controller
{
    use HandlesCampusScoping;

    public function __construct(protected DashboardAnalyticsService $analytics)
    {
    }

    public function coordinator(Request $request): View
    {
        return $this->render($request, 'coordinator.dashboard');
    }

    public function admin(Request $request): View
    {
        return $this->render($request, 'admin.dashboard');
    }

    protected function render(Request $request, string $view): View
    {
        $isAdmin = Auth::user()->role === 'DRRMO Admin';

        $validated = $request->validate([
            'range' => ['nullable', 'in:' . implode(',', array_keys(DashboardAnalyticsService::RANGES))],
            // Only validated for Admins; a Coordinator's campus_id is ignored
            // entirely by scopedCampusId() rather than rejected.
            'campus_id' => $isAdmin ? ['nullable', 'integer', 'exists:campuses,campus_id'] : ['nullable'],
        ]);

        $range = $validated['range'] ?? DashboardAnalyticsService::DEFAULT_RANGE;
        $campusId = $this->scopedCampusId(isset($validated['campus_id']) ? (int) $validated['campus_id'] : null);
        $campuses = $isAdmin ? Campus::orderBy('campus_name')->get(['campus_id', 'campus_name']) : null;
        // Cross-campus comparison only for an Admin looking at All Campuses.
        $analytics = $this->analytics->build($range, $campusId, $isAdmin && $campusId === null, $isAdmin, Auth::user());

        return view($view, [
            'range' => $range,
            'ranges' => DashboardAnalyticsService::RANGES,
            'campusId' => $campusId,
            'campuses' => $campuses,
            'routePrefix' => $this->routePrefix(),
            'analytics' => $analytics,
            // From the filters applied, never from the form (see x-filter-bar). A
            // coordinator's incidents and inspections are their own, their drills
            // the whole campus's (DashboardAnalyticsService): the line says so.
            'filterSummary' => FilterSummary::make(
                $analytics['period']['label'],
                $isAdmin
                    ? ($campusId !== null ? $campuses->firstWhere('campus_id', $campusId)?->campus_name : null) ?? 'all campuses'
                    : 'your incidents and inspections, and every drill, on ' . (Auth::user()->campus?->campus_name ?? 'your campus'),
                compare: $analytics['period']['previous_label'],
            ),
            'filtersApplied' => $range !== DashboardAnalyticsService::DEFAULT_RANGE || ($isAdmin && $campusId !== null),
            // Automatic snapshot health (reads the backup folder, no queries); Admin only.
            'backupSummary' => $isAdmin ? \App\Support\Backup\Snapshot::summary() : null,
            // Self-registrations waiting for the Admin's approval (User Management).
            'pendingAccounts' => $isAdmin ? \App\Models\Personnel::where('is_approved', false)->count() : 0,
            // The Disaster Advisory warning: Active and not past valid-until,
            // for the campus in view (an all-campus advisory counts for every campus).
            'liveAdvisories' => $this->liveAdvisories($campusId),
        ]);
    }

    /**
     * One query: the campus name is joined rather than eager-loaded (the
     * dashboard has a query budget). Which campuses a coordinator sees comes
     * from RecordAccess alone; the campus filter is the Admin's dropdown.
     */
    protected function liveAdvisories(?int $campusId)
    {
        $user = Auth::user();

        return RecordAccess::applyToAdvisories(DisasterAdvisory::live(), $user)
            ->leftJoin('campuses', 'campuses.campus_id', '=', 'disaster_advisories.campus_id')
            ->when(RecordAccess::isAdmin($user) && $campusId !== null, fn ($q) =>$q->where(fn ($q) => $q->where('disaster_advisories.campus_id', $campusId)->orWhereNull('disaster_advisories.campus_id')))
            ->select('disaster_advisories.*', 'campuses.campus_name')
            ->orderByRaw('disaster_advisories.expected_at IS NULL, disaster_advisories.expected_at')
            ->orderByDesc('disaster_advisories.issued_at')
            ->get();
    }
}
