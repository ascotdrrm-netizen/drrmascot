<?php

namespace App\Http\Controllers\Advisory;

use App\Http\Controllers\Concerns\HandlesCampusScoping;
use App\Http\Controllers\Concerns\LogsReportDownloads;
use App\Http\Controllers\Controller;
use App\Models\Advisory\DisasterAdvisory;
use App\Models\Campus;
use App\Support\FilenameSlug;
use App\Support\FilterSummary;
use App\Support\ListPeriod;
use App\Support\RecordAccess;
use App\Traits\RendersPdfAssets;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

/**
 * Disaster Advisories, for both roles: a warning typed in from a PAGASA /
 * PHIVOLCS / DRRMO bulletin, with hazard-specific measurements
 * (config/hazard_measurements.php). While Active and not past its valid-until
 * time it shows as a warning on the dashboards of its campus (or every campus).
 * If the event hits, "Record what happened" starts an incident linked to it.
 *
 * Who may do what:
 *   see          Admin: all. Coordinator: their campus's and every all-campus advisory.
 *   issue        Admin: any campus, or all campuses. Coordinator: their own campus only.
 *   edit / delete / restore
 *                Admin: any. Coordinator: only advisories they issued
 *                (RecordAccess::canManageAdvisory()).
 */
class DisasterAdvisoryController extends Controller
{
    use HandlesCampusScoping, RendersPdfAssets, LogsReportDownloads;

    public const SOURCES = ['PAGASA', 'PHIVOLCS', 'Provincial/Municipal DRRMO', 'ASCOT DRRMO', 'Other'];

    public const STATUSES = DisasterAdvisory::STATUSES;

    public function index(Request $request): View
    {
        $user = Auth::user();
        $isAdmin = RecordAccess::isAdmin($user);
        $hazardTypes = array_keys(config('hazard_measurements'));

        $campuses = $isAdmin ? Campus::orderBy('campus_name')->get() : null;

        // Unknown filter values are ignored rather than matched literally.
        $status = in_array($request->input('status'), self::STATUSES, true) ? $request->input('status') : null;
        $hazard = in_array($request->input('hazard_type'), $hazardTypes, true) ? $request->input('hazard_type') : null;
        $campus = $this->adminCampusFilter($request, $campuses ?? []);
        // All dates unless a period is chosen; by issued_at. ("In effect during
        // the period" was considered and not built: it needs valid_until,
        // which can be far off, and then "in effect" has no real end.)
        $period = ListPeriod::fromRequest($request);
        $tz = config('app.timezone');

        $advisories = RecordAccess::applyToAdvisories(DisasterAdvisory::query(), $user)
            ->with('campus:campus_id,campus_name')
            ->when($status !== null, fn ($q) => $q->where('status', $status))
            ->when($hazard !== null, fn ($q) => $q->where('hazard_type', $hazard))
            // An all-campus advisory belongs under every campus.
            ->when($campus !== null, fn ($q) => $q->where(fn ($q) => $q->where('campus_id', $campus->campus_id)->orWhereNull('campus_id')))
            ->when($period !== null, fn ($q) => $q->whereBetween('issued_at', [
                $period['start']->setTimezone($tz)->toDateTimeString(), $period['end']->setTimezone($tz)->toDateTimeString(),
            ]))
            ->orderByDesc('issued_at')
            ->orderByDesc('advisory_id')
            ->get();

        // A coordinator sees every advisory of their campus plus the all-campus
        // ones (advisories are campus-wide, not per person): "all", and say that
        // all-campus ones are in. Stored Active covers In effect AND Expired.
        $statusPhrase = match ($status) {
            null => '',
            'Active' => ' with status In effect or Expired',
            default => ' with status ' . DisasterAdvisory::STATUS_LABELS[$status],
        };
        $allCampusNote = ($campus !== null || ! $isAdmin) ? ' (all-campus ones included)' : '';

        return view('advisory.index', [
            'routePrefix' => $this->routePrefix(),
            'advisories' => $advisories,
            'trashedCount' => $this->trashedQuery($user)->count(),
            'campuses' => $campuses,
            'hazardTypes' => $hazardTypes,
            'selectedCampusId' => $campus?->campus_id,
            'selectedStatus' => $status,
            'selectedHazard' => $hazard,
            'periodValues' => ListPeriod::controlValues($period),
            'filterSummary' => FilterSummary::make(
                'all ' . ($hazard !== null ? "{$hazard} " : '') . 'advisories' . $statusPhrase . $allCampusNote,
                $this->campusCovered($campus),
                ListPeriod::phrase($period),
            ),
            'filtersApplied' => $status !== null || $hazard !== null || $campus !== null || $period !== null,
        ]);
    }

    public function show(DisasterAdvisory $advisory): View
    {
        $this->authorizeRecordAccess($advisory);
        $advisory->load('campus');
        $user = Auth::user();
        // Only the incidents this viewer could open anyway (a coordinator sees only their own).
        $incidents = RecordAccess::applyToIncidents($advisory->incidents()->with('campus:campus_id,campus_name'), $user)
            ->orderByDesc('date_occurred')->get();

        return view('advisory.show', [
            'advisory' => $advisory,
            'routePrefix' => $this->routePrefix(),
            'canManage' => RecordAccess::canManageAdvisory($advisory, $user),
            'incidents' => $incidents,
            // How many linked incidents others recorded that this viewer can't open: a count
            // only, so the page never says "none" while a colleague has recorded one.
            'hiddenIncidentCount' => RecordAccess::isAdmin($user) ? 0 : max(0, $advisory->incidents()->count() - $incidents->count()),
        ]);
    }

    public function create(): View
    {
        $user = Auth::user();

        return view('advisory.create', [
            'routePrefix' => $this->routePrefix(),
            'campuses' => RecordAccess::isAdmin($user) ? Campus::orderBy('campus_name')->get() : null,
            'ownCampus' => $user->campus,
            'measurements' => config('hazard_measurements'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $user = Auth::user();
        $isAdmin = RecordAccess::isAdmin($user);

        // A coordinator's advisory is always for their own campus: any other
        // campus (or "all") in the request is refused outright, not replaced.
        if (! $isAdmin) {
            abort_if($request->filled('campus_id') && (string) $request->input('campus_id') !== (string) $user->campus_id,
                403, 'You can only issue advisories for your own campus.');
            $request->request->remove('campus_id');
        }

        $data = $this->validated($request, pickCampus: $isAdmin, withStatus: false);

        $advisory = DisasterAdvisory::create([
            'campus_id' => $isAdmin ? ($data['campus_id'] === 'all' ? null : (int) $data['campus_id']) : $user->campus_id,
            'status' => 'Active',
        ] + $this->fields($data));

        return redirect()
            ->route("{$this->routePrefix()}.advisories.show", $advisory)
            ->with('status', 'Advisory issued. It shows as a warning on the dashboards of ' . ($advisory->campus_id === null ? 'every campus' : $advisory->campus_label)
                . ' until ' . $advisory->valid_until->format('M j, g:i A') . ', or until you mark it Passed or Cancelled.');
    }

    public function edit(DisasterAdvisory $advisory): View
    {
        $this->authorizeManage($advisory);

        return view('advisory.edit', [
            'advisory' => $advisory->load('campus'),
            'routePrefix' => $this->routePrefix(),
            'campuses' => null, // the campus is fixed once issued
            'ownCampus' => $advisory->campus,
            'measurements' => config('hazard_measurements'),
        ]);
    }

    public function update(Request $request, DisasterAdvisory $advisory): RedirectResponse
    {
        $this->authorizeManage($advisory);
        $request->request->remove('campus_id');

        $data = $this->validated($request, pickCampus: false, withStatus: true);
        $advisory->update(['status' => $data['status']] + $this->fields($data));

        return redirect()
            ->route("{$this->routePrefix()}.advisories.show", $advisory)
            ->with('status', 'Advisory updated.');
    }

    public function destroy(DisasterAdvisory $advisory): RedirectResponse
    {
        $this->authorizeManage($advisory);
        $advisory->delete();

        return redirect()
            ->route("{$this->routePrefix()}.advisories.index")
            ->with('status', 'Advisory moved to Trash; it no longer shows on the dashboards. Incidents recorded from it keep their link. If that was a mistake, restore it from View Trash.');
    }

    public function trashed(Request $request): View
    {
        $user = Auth::user();

        $isAdmin = RecordAccess::isAdmin($user);

        return view('advisory.trash', [
            'advisories' => $this->trashedQuery($user)->with('campus:campus_id,campus_name')->orderByDesc('deleted_at')->get(),
            'isAdmin' => $isAdmin,
            'routePrefix' => $this->routePrefix(),
            // No controls (a list that is usually empty); a coordinator's holds only advisories they issued.
            'filterSummary' => FilterSummary::make($isAdmin ? 'all advisories in Trash' : 'your advisories in Trash', $this->campusCovered(null)),
        ]);
    }

    public function restore(int $advisory): RedirectResponse
    {
        $advisory = DisasterAdvisory::onlyTrashed()->findOrFail($advisory);
        abort_unless(RecordAccess::canManageAdvisory($advisory, Auth::user()), 403, "You don't have access to this page.");
        $advisory->restore();

        return redirect()
            ->route("{$this->routePrefix()}.advisories.trash")
            ->with('status', 'Advisory restored.' . ($advisory->isLive() ? ' It is in effect, so it shows on the dashboards again.' : ''));
    }

    /**
     * Printable advisory memo on the ASCOT letterhead, for posting and
     * sharing with students and staff. Like the drill announcement it carries
     * no personal data: no names, numbers or emails.
     */
    public function memorandum(DisasterAdvisory $advisory): Response
    {
        $this->authorizeRecordAccess($advisory);
        $advisory->load('campus');

        $pdf = $this->loadPdfViewWithPageCount('reports.records.advisory-memorandum', [
            'advisory' => $advisory,
        ]);

        $this->logReportDownload(sprintf('%s printed the Disaster Advisory memo "%s" (%s)', Auth::user()->full_name, $advisory->headline, $advisory->campus_label));

        return $pdf->download(sprintf(
            'ASCOT-Disaster-Advisory_%s_%s_%s.pdf',
            FilenameSlug::titleCase($advisory->hazard_label),
            $advisory->issued_at->format('Y-m-d'),
            FilenameSlug::titleCase($advisory->campus_id === null ? 'All Campuses' : $advisory->campus_label)
        ));
    }

    // ================================ Helpers =================================

    /** Trash: the Admin sees every deleted advisory; a coordinator only the ones they issued. */
    protected function trashedQuery($user)
    {
        $query = DisasterAdvisory::onlyTrashed();

        return RecordAccess::isAdmin($user)
            ? $query
            : $query->where('campus_id', $user->campus_id)->where('created_by', $user->personnel_id);
    }

    protected function authorizeManage(DisasterAdvisory $advisory): void
    {
        $this->authorizeRecordAccess($advisory);
        abort_unless(RecordAccess::canManageAdvisory($advisory, Auth::user()), 403, 'Only the person who issued this advisory, or the DRRMO, can change it.');
    }

    protected function fields(array $data): array
    {
        return [
            'hazard_type' => $data['hazard_type'],
            'hazard_type_other' => $data['hazard_type'] === 'Other' ? $data['hazard_type_other'] : null,
            'headline' => $data['headline'],
            // Empty optional fields are not stored.
            'measurements' => array_filter($data['measurements'] ?? [], fn ($v) => $v !== null && $v !== ''),
            'source' => $data['source'],
            'source_other' => $data['source'] === 'Other' ? $data['source_other'] : null,
            'bulletin_reference' => $data['bulletin_reference'] ?? null,
            'issued_at' => $data['issued_at'],
            'expected_at' => $data['expected_at'] ?? null,
            'valid_until' => $data['valid_until'],
            'instructions' => $data['instructions'],
        ];
    }

    /**
     * The measurements are checked against the chosen hazard type's fields in
     * config/hazard_measurements.php: a field that belongs to another type (a
     * magnitude on a typhoon) is rejected, and each field gets its own rule.
     */
    protected function validated(Request $request, bool $pickCampus, bool $withStatus): array
    {
        $config = config('hazard_measurements');
        $fields = $config[$request->input('hazard_type')] ?? [];

        $rules = [
            'hazard_type' => ['required', 'in:' . implode(',', array_keys($config))],
            'hazard_type_other' => ['required_if:hazard_type,Other', 'nullable', 'string', 'max:100'],
            'campus_id' => $pickCampus ? ['required', fn ($attr, $value, $fail) => $value === 'all' || Campus::whereKey($value)->exists() || $fail('Choose a campus, or All campuses.')] : ['prohibited'],
            'headline' => ['required', 'string', 'max:150'],
            'measurements' => $fields ? ['required', 'array:' . implode(',', array_keys($fields))] : ['nullable', 'array'],
            'source' => ['required', 'in:' . implode(',', self::SOURCES)],
            'source_other' => ['required_if:source,Other', 'nullable', 'string', 'max:100'],
            'bulletin_reference' => ['nullable', 'string', 'max:60'],
            'issued_at' => ['required', 'date'],
            'expected_at' => ['nullable', 'date'],
            'valid_until' => ['required', 'date', 'after:issued_at'],
            'status' => $withStatus ? ['required', 'in:' . implode(',', self::STATUSES)] : ['nullable'],
            'instructions' => ['required', 'string', 'max:2000'],
        ];

        // Each field's rules, shared with incidents (App\Support\HazardMeasurements).
        [$fieldRules, $attributes] = \App\Support\HazardMeasurements::fieldRules($fields);
        $rules += $fieldRules;

        return $request->validate($rules, [
            'measurements.array' => 'These measurements don’t belong to the chosen hazard type.',
            'measurements.required' => 'Enter the measurements from the bulletin.',
            'valid_until.after' => 'Valid until must be after the time the advisory was issued.',
        ], $attributes);
    }
}
