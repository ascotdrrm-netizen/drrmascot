<?php

namespace App\Http\Controllers\Drill;

use App\Http\Controllers\Concerns\HandlesCampusScoping;
use App\Http\Controllers\Concerns\LogsReportDownloads;
use App\Http\Controllers\Controller;
use App\Models\Campus;
use App\Models\Drill\Drill;
use App\Support\DrillLog;
use App\Support\FilenameSlug;
use App\Support\FilterSummary;
use App\Support\RecordAccess;
use App\Traits\RendersPdfAssets;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * Drill Tracker for both roles: the month calendar (and its list view), the
 * drill page, scheduling, Trash and the printable memorandum.
 *
 * Who may do what:
 *   see a drill          Admin: all. Coordinator: every drill of their campus.
 *   schedule             Admin: any campuses (one drill per campus).
 *                        Coordinator: their own campus only.
 *   edit / delete /      Admin: any. Coordinator: only drills they created
 *   restore              (RecordAccess::canManageDrill()).
 * Documentation and the After Activity Report have their own controllers.
 */
class DrillController extends Controller
{
    use HandlesCampusScoping, RendersPdfAssets, LogsReportDownloads;

    public const DRILL_TYPES = Drill::DRILL_TYPES;

    /** A calendar day shows this many pills, then "+n more". */
    public const DAY_PILL_LIMIT = 3;

    public function index(Request $request): View
    {
        $user = Auth::user();
        $isAdmin = RecordAccess::isAdmin($user);
        $campuses = $isAdmin ? Campus::orderBy('campus_name')->get() : null;
        // An unknown campus_id is ignored (see x-filter-bar); a coordinator is always their own campus.
        $campus = $this->adminCampusFilter($request, $campuses ?? []);
        $campusId = $isAdmin ? $campus?->campus_id : $user->campus_id;
        $view = $request->input('view') === 'list' ? 'list' : 'calendar';
        $today = CarbonImmutable::now('Asia/Manila')->startOfDay();

        // ?date=YYYY-MM-DD narrows the list to one day ("+n more"); ?month=YYYY-MM picks the month.
        $day = $this->parseDate($request->input('date'));
        $month = $day?->startOfMonth() ?? $this->parseMonth($request->input('month')) ?? $today->startOfMonth();

        $gridStart = $month->startOfWeek(CarbonImmutable::SUNDAY);
        $gridEnd = $month->endOfMonth()->endOfWeek(CarbonImmutable::SATURDAY);
        [$from, $to] = match (true) {
            $view === 'list' && $day !== null => [$day, $day],
            $view === 'list' => [$month, $month->endOfMonth()],
            default => [$gridStart, $gridEnd],
        };

        $drills = $this->scoped($user, $campusId)
            ->with('campus:campus_id,campus_name')
            ->withExists(['documentation', 'afterActivityReport'])
            ->whereBetween('scheduled_date', [$from->toDateString(), $to->toDateString()])
            ->orderBy('scheduled_date')
            ->orderBy('scheduled_time')
            ->orderBy('drill_id')
            ->get();

        return view('drill.index', [
            'view' => $view,
            'month' => $month,
            'day' => $day,
            'today' => $today,
            'gridStart' => $gridStart,
            'gridEnd' => $gridEnd,
            'drills' => $drills,
            'byDate' => $drills->groupBy(fn (Drill $d) => $d->scheduled_date->toDateString()),
            'campuses' => $campuses,
            'selectedCampusId' => $campusId,
            'routePrefix' => $this->routePrefix(),
            'trashedCount' => $this->trashedQuery($user, $campusId)->count(),
            // Drills are campus-wide: "all". The month navigator is the period
            // (no picker): the line names the month on screen, or the one day.
            'filterSummary' => FilterSummary::make('all drills', $this->campusCovered($campus),
                $view === 'list' && $day !== null ? $day->format('M j, Y') : $month->format('F Y')),
            'filtersApplied' => $campus !== null,
        ]);
    }

    public function show(Drill $drill): View
    {
        $this->authorizeRecordAccess($drill);
        $drill->load(['campus', 'documentation', 'afterActivityReport', 'attachments']);

        return view('drill.show', [
            'drill' => $drill,
            'routePrefix' => $this->routePrefix(),
            'canManage' => RecordAccess::canManageDrill($drill, Auth::user()),
            'documentationFiles' => $drill->attachments->where('section', 'documentation')->values(),
            'afterActivityFiles' => $drill->attachments->where('section', 'after_activity')->values(),
            // The Admin's calendar shows these as one pill, which opens this page.
            'otherCampuses' => RecordAccess::isAdmin(Auth::user()) ? $this->sameDrillElsewhere($drill) : collect(),
        ]);
    }

    /** The same drill at other campuses: same type, date, time and venue (the calendar's grouping). */
    protected function sameDrillElsewhere(Drill $drill): \Illuminate\Support\Collection
    {
        $key = fn (Drill $d) => $d->scheduled_time?->format('H:i') . '|' . mb_strtolower(trim($d->venue));

        return Drill::with('campus:campus_id,campus_name')
            ->where('drill_type', $drill->drill_type)
            ->whereDate('scheduled_date', $drill->scheduled_date->toDateString())
            ->whereKeyNot($drill->getKey())
            ->get()
            ->filter(fn (Drill $d) => $key($d) === $key($drill))
            ->sortBy(fn (Drill $d) => $d->campus->campus_name ?? '')
            ->values();
    }

    // =============================== Schedule =================================

    public function create(): View
    {
        $user = Auth::user();

        return view('drill.create', [
            'campuses' => RecordAccess::isAdmin($user) ? Campus::orderBy('campus_name')->get() : null,
            'ownCampus' => RecordAccess::isAdmin($user) ? null : $user->campus,
            'routePrefix' => $this->routePrefix(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $user = Auth::user();
        $isAdmin = RecordAccess::isAdmin($user);

        // A coordinator's drill is always for their own campus: any other campus
        // in the request is refused outright rather than silently replaced.
        if (! $isAdmin) {
            $asked = array_map('intval', array_filter(array_merge((array) $request->input('campus_ids', []), [$request->input('campus_id')])));
            abort_if(collect($asked)->contains(fn ($id) => $id !== (int) $user->campus_id), 403, 'You can only schedule drills for your own campus.');
            $request->request->remove('campus_ids');
            $request->request->remove('campus_id');
        }

        $data = $this->validatedSchedule($request, $isAdmin);
        $campusIds = $isAdmin ? $data['campus_ids'] : [$user->campus_id];

        // The same drill entered twice (a second tap, or two people scheduling
        // it) is refused: one per campus, type, date, time and venue. The check
        // and the insert hold one lock, so two submits arriving together can't
        // both pass the check before either has saved.
        $lock = Cache::lock('drill-schedule:' . md5(implode('|', [$data['drill_type'], $data['scheduled_date'],
            $data['scheduled_time'], mb_strtolower(trim($data['venue']))])), 10);
        try {
            $lock->block(5);
        } catch (LockTimeoutException) {
            return back()->withInput()->withErrors(['scheduled_date' => 'This drill is being saved right now. Check the calendar before scheduling it again.']);
        }

        try {
            if ($existing = $this->sameDrill($campusIds, $data)) {
                return back()->withInput()->withErrors(['scheduled_date' => $this->duplicateMessage($existing)]);
            }
            if (! $request->boolean('confirm_same_day') && $sameDay = $this->sameDayDrill($campusIds, $data)) {
                return back()->withInput()->withErrors(['confirm_same_day' => $this->sameDayMessage($sameDay)]);
            }

            $drills = DB::transaction(fn () => Campus::whereIn('campus_id', $campusIds)->orderBy('campus_name')->get()
                ->map(function (Campus $campus) use ($data, $user) {
                    $drill = Drill::withoutActivityLog(fn () => Drill::create(['campus_id' => $campus->campus_id] + $this->scheduleFields($data)));
                    $drill->setRelation('campus', $campus);
                    DrillLog::record($user, $drill, 'drill_scheduled', 'scheduled');

                    return $drill;
                }));
        } finally {
            $lock->release();
        }

        $this->noticeToReporters($drills);

        if ($drills->count() === 1) {
            return redirect()
                ->route("{$this->routePrefix()}.drills.show", $drills->first())
                ->with('status', 'Drill scheduled for ' . $drills->first()->campus->campus_name . '. After it is held, add its documentation and After Activity Report here.');
        }

        return redirect()
            ->route("{$this->routePrefix()}.drills.index", ['month' => $drills->first()->scheduled_date->format('Y-m')])
            ->with('status', $drills->count() . ' drills scheduled — one for each of: ' . $drills->pluck('campus.campus_name')->join(', ', ' and ') . '.');
    }

    public function edit(Drill $drill): View
    {
        $this->authorizeManage($drill);

        return view('drill.edit', [
            'drill' => $drill->load('campus'),
            'routePrefix' => $this->routePrefix(),
        ]);
    }

    public function update(Request $request, Drill $drill): RedirectResponse
    {
        $this->authorizeManage($drill);

        $data = $this->validatedSchedule($request, false);
        // Editing must not turn this drill into a copy of another one on its campus.
        if ($existing = $this->sameDrill([$drill->campus_id], $data, $drill->drill_id)) {
            return back()->withInput()->withErrors(['scheduled_date' => $this->duplicateMessage($existing)]);
        }
        // Asked only when the edit moves it onto that day or that type: a drill
        // already sharing its day (confirmed when scheduled) saves as before.
        $moved = $drill->scheduled_date->toDateString() !== \Carbon\Carbon::parse($data['scheduled_date'])->toDateString()
            || $drill->drill_type !== $data['drill_type'];
        if ($moved && ! $request->boolean('confirm_same_day') && $sameDay = $this->sameDayDrill([$drill->campus_id], $data, $drill->drill_id)) {
            return back()->withInput()->withErrors(['confirm_same_day' => $this->sameDayMessage($sameDay)]);
        }
        $drill->update($this->scheduleFields($data));

        return redirect()
            ->route("{$this->routePrefix()}.drills.show", $drill)
            ->with('status', 'Drill schedule updated.');
    }

    public function destroy(Drill $drill): RedirectResponse
    {
        $this->authorizeManage($drill);
        $drill->delete();

        return redirect()
            ->route("{$this->routePrefix()}.drills.index")
            ->with('status', 'Drill moved to Trash, with its documentation and After Activity Report. If that was a mistake, restore it from View Trash.');
    }

    public function trashed(Request $request): View
    {
        $user = Auth::user();
        $isAdmin = RecordAccess::isAdmin($user);
        $campuses = $isAdmin ? Campus::orderBy('campus_name')->get() : null;
        $campus = $this->adminCampusFilter($request, $campuses ?? []);

        return view('drill.trash', [
            'drills' => $this->trashedQuery($user, $campus?->campus_id)->with('campus')->orderByDesc('deleted_at')->get(),
            'campuses' => $campuses,
            'selectedCampusId' => $campus?->campus_id,
            'routePrefix' => $this->routePrefix(),
            // A coordinator's drills Trash holds only the drills they scheduled: say "your".
            'filterSummary' => FilterSummary::make($isAdmin ? 'all drills in Trash' : 'your drills in Trash', $this->campusCovered($campus)),
            'filtersApplied' => $campus !== null,
        ]);
    }

    public function restore(int $drill): RedirectResponse
    {
        $drill = Drill::onlyTrashed()->findOrFail($drill);
        abort_unless(RecordAccess::canManageDrill($drill, Auth::user()), 403, "You don't have access to this page.");
        $drill->restore();

        return redirect()
            ->route("{$this->routePrefix()}.drills.trash")
            ->with('status', 'Drill restored, with its documentation and After Activity Report.');
    }

    // ============================== Memorandum ================================

    /**
     * Printable notice of the drill for students and staff. Named for ASCOT's
     * Memorandum Order, which will replace the announcement template
     * (reports.records.drill-memorandum) in a separate task. Carries no
     * personal data.
     */
    public function memorandum(Drill $drill): Response
    {
        $this->authorizeRecordAccess($drill);
        $drill->load('campus');

        $pdf = $this->loadPdfViewWithPageCount('reports.records.drill-memorandum', [
            'drill' => $drill,
        ]);

        $this->logReportDownload(sprintf('%s printed the Drill announcement for %s (%s)', Auth::user()->full_name, $drill->drill_type, $drill->campus->campus_name ?? 'Unknown campus'));

        return $pdf->download(sprintf(
            'ASCOT-Drill-Announcement_%s_%s_%s.pdf',
            FilenameSlug::titleCase($drill->drill_type),
            $drill->scheduled_date->format('Y-m-d'),
            FilenameSlug::titleCase($drill->campus->campus_name ?? 'Campus')
        ));
    }

    // ================================ Helpers =================================

    /** Drills $user can see, narrowed to one campus when given. */
    protected function scoped($user, ?int $campusId)
    {
        return RecordAccess::applyToDrills(Drill::query(), $user)
            ->when($campusId !== null, fn ($q) => $q->where('drills.campus_id', $campusId));
    }

    /** Trash: the Admin sees every deleted drill; a coordinator only the ones they created. */
    protected function trashedQuery($user, ?int $campusId)
    {
        $query = Drill::onlyTrashed()->when($campusId !== null, fn ($q) => $q->where('campus_id', $campusId));

        return RecordAccess::isAdmin($user)
            ? $query
            : $query->where('campus_id', $user->campus_id)->where('created_by', $user->personnel_id);
    }

    protected function authorizeManage(Drill $drill): void
    {
        $this->authorizeRecordAccess($drill);
        abort_unless(RecordAccess::canManageDrill($drill, Auth::user()), 403, 'Only the person who scheduled this drill, or the DRRMO, can change it.');
    }

    /**
     * Students and staff of the campus see upcoming drills in the phone app
     * (Alerts); a new one also raises their Alerts badge. A drill recorded
     * after it was held needs no notice.
     */
    protected function noticeToReporters($drills): void
    {
        $today = CarbonImmutable::now('Asia/Manila')->startOfDay();
        foreach ($drills as $drill) {
            if ($drill->scheduled_date->lt($today)) {
                continue;
            }
            $reporters = \App\Models\Personnel::where('role', 'Reporter')->where('is_approved', true)->where('campus_id', $drill->campus_id)->get();
            \App\Support\EmergencyNotices::record($reporters, 'drill', (int) $drill->drill_id, (int) $drill->campus_id, sprintf(
                'Drill scheduled: %s, %s%s at %s', $drill->drill_type, $drill->scheduled_date->format('M j'),
                $drill->scheduled_time ? ', ' . $drill->scheduled_time->format('g:i A') : '', $drill->venue));
        }
    }

    /** An undeleted drill matching the schedule in any of $campusIds, if there is one. */
    protected function sameDrill(array $campusIds, array $data, ?int $except = null): ?Drill
    {
        $venue = mb_strtolower(trim($data['venue']));

        return Drill::with('campus:campus_id,campus_name')
            ->whereIn('campus_id', $campusIds)
            ->when($except !== null, fn ($q) => $q->whereKeyNot($except))
            ->where('drill_type', $data['drill_type'])
            ->whereDate('scheduled_date', $data['scheduled_date'])
            ->get()
            ->first(fn (Drill $d) => $d->scheduled_time?->format('H:i') === $data['scheduled_time']
                && mb_strtolower(trim($d->venue)) === $venue);
    }

    /**
     * An undeleted drill of the same type on the same day in any of $campusIds,
     * at another time or venue. Allowed, but asked about first: the usual
     * cause is the same drill entered again (UAT 2026-10-21: "Gym court"
     * at 12:45 PM, then "Gym" at 5:00 PM).
     */
    protected function sameDayDrill(array $campusIds, array $data, ?int $except = null): ?Drill
    {
        return Drill::with('campus:campus_id,campus_name')
            ->whereIn('campus_id', $campusIds)
            ->when($except !== null, fn ($q) => $q->whereKeyNot($except))
            ->where('drill_type', $data['drill_type'])
            ->whereDate('scheduled_date', $data['scheduled_date'])
            ->orderBy('scheduled_time')
            ->first();
    }

    protected function sameDayMessage(Drill $existing): string
    {
        return sprintf(
            '%s already has a%s %s drill on %s (%s, %s). If this is a separate drill, tick "Schedule it anyway" and save again.',
            $existing->campus->campus_name ?? 'This campus',
            preg_match('/^[AEIOU]/i', $existing->drill_type) ? 'n' : '', $existing->drill_type,
            $existing->scheduled_date->format('M j, Y'), $existing->scheduled_time?->format('g:i A') ?? 'no time set', $existing->venue,
        );
    }

    protected function duplicateMessage(Drill $existing): string
    {
        return sprintf(
            'This drill is already on the calendar for %s (%s, %s at %s). Open it from the calendar instead of scheduling it again.',
            $existing->campus->campus_name ?? 'this campus', $existing->drill_type,
            $existing->scheduled_date->format('M j, Y'), $existing->scheduled_time?->format('g:i A') ?? '',
        );
    }

    protected function scheduleFields(array $data): array
    {
        return [
            'drill_type' => $data['drill_type'],
            'scheduled_date' => $data['scheduled_date'],
            'scheduled_time' => $data['scheduled_time'],
            'venue' => $data['venue'],
            'description' => $data['description'] ?? null,
            'partner_agency' => $data['partner_agency'] ?? null,
        ];
    }

    /**
     * Past dates are allowed: a coordinator may record a drill that was held
     * before it was entered here, then document it.
     */
    protected function validatedSchedule(Request $request, bool $pickCampuses): array
    {
        $data = $request->validate([
            'drill_type' => ['required', 'in:' . implode(',', self::DRILL_TYPES) . ',Other'],
            'drill_type_other' => ['required_if:drill_type,Other', 'nullable', 'string', 'max:100'],
            'scheduled_date' => ['required', 'date'],
            'scheduled_time' => ['required', 'date_format:H:i'],
            'venue' => ['required', 'string', 'max:150'],
            'description' => ['nullable', 'string', 'max:1000'],
            'partner_agency' => ['nullable', 'string', 'max:100'],
            'campus_ids' => $pickCampuses ? ['required', 'array', 'min:1'] : ['prohibited'],
            'campus_ids.*' => ['integer', 'distinct', 'exists:campuses,campus_id'],
        ], [
            'campus_ids.required' => 'Choose at least one campus.',
        ]);

        $data['drill_type'] = $data['drill_type'] === 'Other' ? $data['drill_type_other'] : $data['drill_type'];

        return $data;
    }

    protected function parseMonth(?string $value): ?CarbonImmutable
    {
        if (! is_string($value) || ! preg_match('/^(\d{4})-(\d{2})$/', $value, $m) || (int) $m[2] < 1 || (int) $m[2] > 12) {
            return null;
        }

        return CarbonImmutable::create((int) $m[1], (int) $m[2], 1, 0, 0, 0, 'Asia/Manila');
    }

    protected function parseDate(?string $value): ?CarbonImmutable
    {
        if (! is_string($value) || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) || ! checkdate((int) substr($value, 5, 2), (int) substr($value, 8, 2), (int) substr($value, 0, 4))) {
            return null;
        }

        return CarbonImmutable::createFromFormat('Y-m-d', $value, 'Asia/Manila')->startOfDay();
    }
}
