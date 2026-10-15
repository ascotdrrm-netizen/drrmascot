<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\HandlesCampusScoping;
use App\Models\ActivityLog;
use App\Models\Personnel;
use App\Support\FilterSummary;
use App\Traits\RendersPdfAssets;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

/**
 * The Activity Log for both roles. The DRRMO Admin sees every entry and can
 * filter by user; a Coordinator sees only their own entries (the user filter
 * is ignored) — in the list and the PDF alike.
 */
class ActivityLogController extends Controller
{
    use HandlesCampusScoping, RendersPdfAssets;

    public const ACTIONS = ['login', 'logout', 'login_failed', 'created', 'updated', 'deleted', 'restored', 'permanently_deleted', 'data_privacy_denied', 'report_generated', 'report_exported', 'password_reset_requested', 'password_reset_completed', 'drill_scheduled', 'drill_documented', 'drill_after_activity_submitted', 'records_cleared',
        'account_registered', 'account_approved', 'account_rejected',
        // The old drill workflow's actions: no longer written, kept so its history stays filterable.
        'drill_acknowledged', 'drill_submitted', 'drill_returned', 'drill_completed', 'drill_released'];

    public function index(Request $request): View
    {
        $personnel = $this->isCoordinator() ? null : Personnel::orderBy('full_name')->get();
        $applied = $this->appliedFilters($request, $personnel);

        return view('activity-log.index', [
            'logs' => $this->filteredQuery($applied)->paginate(20)->withQueryString(),
            'personnel' => $personnel,
            'routePrefix' => $this->routePrefix(),
            'selectedAction' => $applied['action'],
            'selectedPersonnelId' => $applied['personnel'],
            'dateFrom' => $applied['from'],
            'dateTo' => $applied['to'],
            // From the filters applied above, never from the form (see x-filter-bar).
            'filterSummary' => FilterSummary::make(
                $applied['action'] !== null ? ActivityLog::ACTION_LABELS[$applied['action']] . ' entries' : 'all entries',
                match (true) {
                    // A coordinator's log is only ever their own entries.
                    $personnel === null => 'your account',
                    $applied['personnel'] === null => 'everyone',
                    $applied['personnel'] === 'system' => 'the system (no user)',
                    $applied['personnel'] === 'deleted' => 'deleted accounts',
                    default => $personnel->firstWhere('personnel_id', $applied['personnel'])->full_name,
                },
                $this->datesPhrase($applied['from'], $applied['to']),
            ),
            'filtersApplied' => collect($applied)->filter(fn ($value) => $value !== null)->isNotEmpty(),
        ]);
    }

    public function downloadReport(Request $request): Response
    {
        $applied = $this->appliedFilters($request, $this->isCoordinator() ? null : Personnel::orderBy('full_name')->get());
        $logs = $this->filteredQuery($applied)->get();

        $dateFrom = $applied['from'];
        $dateTo = $applied['to'];

        $pdf = $this->loadPdfViewWithPageCount('activity-log.report', [
            'logs' => $logs,
            'totalCount' => $logs->count(),
            'dateFrom' => $dateFrom,
            'dateTo' => $dateTo,
        ]);

        $rangeLabel = match (true) {
            $dateFrom && $dateTo => "{$dateFrom}-to-{$dateTo}",
            (bool) $dateFrom => "{$dateFrom}-to-" . now()->format('Y-m-d'),
            (bool) $dateTo => 'All-Dates-to-' . $dateTo,
            default => 'All-Dates',
        };

        return $pdf->download("Activity-Log-{$rangeLabel}.pdf");
    }

    /**
     * The filters as applied — the list, its summary line and the PDF all use
     * these. Unknown values are ignored rather than matched literally (see
     * x-filter-bar): an action not in ACTIONS, a user who isn't one ($personnel
     * is null for a coordinator, whose log is always just their own), a date
     * that isn't a real Y-m-d date.
     *
     * @return array{action: ?string, personnel: int|string|null, from: ?string, to: ?string}
     */
    protected function appliedFilters(Request $request, $personnel): array
    {
        $action = $request->input('action');
        $who = $request->input('personnel_id');
        $date = fn ($value) => is_string($value) && preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $m) && checkdate((int) $m[2], (int) $m[3], (int) $m[1]) ? $value : null;

        return [
            'action' => is_string($action) && in_array($action, self::ACTIONS, true) ? $action : null,
            'personnel' => match (true) {
                $personnel === null => null,
                in_array($who, ['system', 'deleted'], true) => $who,
                ctype_digit((string) $who) && $personnel->contains('personnel_id', (int) $who) => (int) $who,
                default => null,
            },
            'from' => $date($request->input('date_from')),
            'to' => $date($request->input('date_to')),
        ];
    }

    protected function filteredQuery(array $applied)
    {
        $query = ActivityLog::with('actor')
            ->orderByDesc('created_at')
            ->orderByDesc('activity_log_id');

        if ($applied['action'] !== null) {
            $query->where('action', $applied['action']);
        }

        if ($this->isCoordinator()) {
            $query->where('personnel_id', Auth::id());
        } elseif ($applied['personnel'] !== null) {
            match ($applied['personnel']) {
                // No user signed in (seeders, scripts, failed logins, the automatic Trash purge).
                'system' => $query->whereNull('personnel_id')
                    ->where(fn ($q) => $q->whereNull('actor_name')->orWhere('actor_name', ActivityLog::SYSTEM_ACTOR)),
                // Accounts deleted since the row was written — only the stored name remains.
                'deleted' => $query->whereNull('personnel_id')->whereNotNull('actor_name')->where('actor_name', '!=', ActivityLog::SYSTEM_ACTOR),
                default => $query->where('personnel_id', $applied['personnel']),
            };
        }

        if ($applied['from'] !== null) {
            $query->whereDate('created_at', '>=', $applied['from']);
        }

        if ($applied['to'] !== null) {
            $query->whereDate('created_at', '<=', $applied['to']);
        }

        return $query;
    }

    /** The summary's period clause for the free From/To dates. */
    protected function datesPhrase(?string $from, ?string $to): string
    {
        $fmt = fn (string $date) => \Illuminate\Support\Carbon::parse($date)->format('M j, Y');

        return match (true) {
            $from !== null && $to !== null => $from === $to ? 'dated ' . $fmt($from) : 'dated ' . $fmt($from) . ' to ' . $fmt($to),
            $from !== null => 'dated from ' . $fmt($from),
            $to !== null => 'dated up to ' . $fmt($to),
            default => 'all dates',
        };
    }

    protected function isCoordinator(): bool
    {
        return Auth::user()->role === 'Coordinator';
    }
}
