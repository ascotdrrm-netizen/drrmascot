<?php

namespace App\Http\Controllers\Incident;

use App\Http\Controllers\Concerns\HandlesCampusScoping;
use App\Http\Controllers\Concerns\HandlesSchoolFilter;
use App\Http\Controllers\Concerns\LogsReportDownloads;
use App\Http\Controllers\Controller;
use App\Models\Advisory\DisasterAdvisory;
use App\Models\Campus;
use App\Models\Incident\Incident;
use App\Models\School;
use App\Support\FilterSummary;
use App\Support\HazardMeasurements;
use App\Support\ListPeriod;
use App\Support\RecordAccess;
use App\Traits\RendersPdfAssets;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class IncidentController extends Controller
{
    use HandlesCampusScoping, HandlesSchoolFilter, RendersPdfAssets, LogsReportDownloads;

    public function index(Request $request): View
    {
        $user = Auth::user();
        $isCoordinator = $user->role === 'Coordinator';
        $campuses = $isCoordinator ? null : Campus::orderBy('campus_name')->get();

        // The filters as applied. Unknown status/campus/period values are
        // ignored (see x-filter-bar); school_id is validated and rejected.
        $campus = $this->adminCampusFilter($request, $campuses ?? []);
        $status = in_array($request->input('status'), Incident::STATUSES, true) ? $request->input('status') : null;
        // Incident type: one type, "Other" (whatever neither group lists), or a whole group.
        $groupFilters = Incident::typeGroupFilters();
        $type = $request->input('incident_type');
        $type = is_string($type) && (in_array($type, [...Incident::types(), Incident::OTHER_TYPE], true) || isset($groupFilters[$type])) ? $type : null;
        $schoolFilter = $this->schoolFilterFor($request);
        // All dates unless a period is chosen: a list never starts narrowed.
        $period = ListPeriod::fromRequest($request);

        $trashedQuery = Incident::onlyTrashed();
        $this->scopeIncidents($trashedQuery, $campus);
        $this->applySchoolFilter($trashedQuery, $schoolFilter);

        $query = Incident::with(['campus', 'school']);
        $this->scopeIncidents($query, $campus);
        $query->when($status !== null, fn ($q) => $q->where('status', $status));
        $query->when($type !== null, fn ($q) => match (true) {
            isset($groupFilters[$type]) => $q->whereIn('hazard_type', Incident::typeGroups()[$groupFilters[$type]['group']]),
            $type === Incident::OTHER_TYPE => $q->whereNotIn('hazard_type', Incident::types()),
            default => $q->where('hazard_type', $type),
        });
        // Campus scope above first; the school filter only narrows within it.
        $this->applySchoolFilter($query, $schoolFilter);
        $query->when($period !== null, fn ($q) => $q->whereBetween('date_occurred', [$period['start']->toDateString(), $period['end']->toDateString()]));

        return view('incident.index', [
            'incidents' => $query->orderByDesc('date_occurred')->orderByDesc('incident_id')->get(),
            'campuses' => $campuses,
            'selectedCampusId' => $campus?->campus_id,
            'schools' => School::forDropdowns(),
            'userCampusId' => $isCoordinator ? $user->campus_id : null,
            'selectedSchoolId' => $schoolFilter,
            'selectedStatus' => $status,
            'selectedType' => $type,
            'periodValues' => ListPeriod::controlValues($period),
            'routePrefix' => $this->routePrefix(),
            'trashedCount' => $trashedQuery->count(),
            // A coordinator sees only the incidents they or the DRRMO recorded: never "all".
            'filterSummary' => FilterSummary::make(
                ($isCoordinator ? 'your and the DRRMO\'s' : 'all') . ($status !== null ? " {$status}" : '') . ' '
                    . match (true) {
                        $type === null => 'incidents',
                        isset($groupFilters[$type]) => $groupFilters[$type]['noun'],
                        default => "incidents of type {$type}",
                    }
                    . FilterSummary::school($schoolFilter),
                $this->campusCovered($campus, $schoolFilter),
                ListPeriod::phrase($period),
            ),
            'filtersApplied' => $campus !== null || $status !== null || $type !== null || $schoolFilter !== null || $period !== null,
        ]);
    }

    /** A coordinator: their own campus, then only the incidents they recorded. The Admin: everything, or the applied campus. */
    protected function scopeIncidents($query, ?Campus $campus): void
    {
        $user = Auth::user();

        if ($user->role === 'Coordinator') {
            RecordAccess::applyToIncidents($query, $user);
        } elseif ($campus) {
            $query->where('campus_id', $campus->campus_id);
        }
    }

    public function trashed(Request $request): View
    {
        $user = Auth::user();
        $query = Incident::onlyTrashed()->with(['campus', 'school']);
        $campuses = $user->role === 'DRRMO Admin' ? Campus::orderBy('campus_name')->get() : null;
        $campus = $this->adminCampusFilter($request, $campuses ?? []);
        $isCoordinator = $user->role === 'Coordinator';

        if ($isCoordinator) {
            // Own campus, then only the incidents this coordinator reported
            // (the Admin's they can see but not restore).
            RecordAccess::applyToOwnIncidents($query, $user);
        } elseif ($campus) {
            $query->where('campus_id', $campus->campus_id);
        }

        $schoolFilter = $this->schoolFilterFor($request);
        $this->applySchoolFilter($query, $schoolFilter);

        return view('incident.trash', [
            'incidents' => $query->orderByDesc('deleted_at')->get(),
            'campuses' => $campuses,
            'selectedCampusId' => $campus?->campus_id,
            'schools' => School::forDropdowns(),
            'userCampusId' => $isCoordinator ? $user->campus_id : null,
            'selectedSchoolId' => $schoolFilter,
            'routePrefix' => $this->routePrefix(),
            // A coordinator's Trash holds only the incidents they recorded: say "your".
            'filterSummary' => FilterSummary::make(
                ($isCoordinator ? 'your incidents' : 'all incidents') . FilterSummary::school($schoolFilter) . ' in Trash',
                $this->campusCovered($campus, $schoolFilter),
            ),
            'filtersApplied' => $campus !== null || $schoolFilter !== null,
        ]);
    }

    public function restore(int $incident): RedirectResponse
    {
        $incident = Incident::onlyTrashed()->findOrFail($incident);
        $this->authorizeRecordManage($incident);
        $incident->restore();

        return redirect()
            ->route("{$this->routePrefix()}.incidents.trash")
            ->with('status', 'Incident restored, with its damage reports and alerts.');
    }

    /**
     * ?advisory=ID ("Record what happened" on a Disaster Advisory) pre-fills
     * the hazard and campus from that advisory and links the new incident to it.
     */
    public function create(Request $request): View
    {
        $user = Auth::user();
        $advisory = $request->filled('advisory') ? $this->linkableAdvisory($request->input('advisory'), $user) : null;

        return view('incident.create', [
            'campuses' => $user->role === 'DRRMO Admin' ? Campus::orderBy('campus_name')->get() : null,
            'routePrefix' => $this->routePrefix(),
            'advisory' => $advisory,
            'prefill' => $advisory ? $advisory->incidentHazardPrefill() + ['campus_id' => $advisory->campus_id, 'date_occurred' => now()->toDateString()] : [],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $user = Auth::user();
        $data = $this->validated($request, $user);

        if (! empty($data['client_key'])) {
            $existing = Incident::where('created_by', $user->personnel_id)->where('client_key', $data['client_key'])->first();
            if ($existing) return redirect()->route("{$this->routePrefix()}.incidents.show", $existing)->with('status', 'Incident already saved.');
        }

        $campusId = (int) ($user->role === 'DRRMO Admin' ? $data['campus_id'] : $user->campus_id);

        $advisory = isset($data['advisory_id']) ? $this->linkableAdvisory($data['advisory_id'], $user) : null;
        if ($advisory !== null && $advisory->campus_id !== null && (int) $advisory->campus_id !== $campusId) {
            throw ValidationException::withMessages(['campus_id' => 'The advisory this is recorded from covers ' . $advisory->campus_label . ' only. Choose that campus, or report the incident on its own.']);
        }

        $storedPath = null;
        try {
            $incident = \Illuminate\Support\Facades\DB::transaction(function () use ($request, $user, $data, $campusId, $advisory, &$storedPath) {
                $incident = Incident::create([
                    'client_key' => $data['client_key'] ?? null,
                    'campus_id' => $campusId,
                    'advisory_id' => $advisory?->advisory_id,
                    // Attribution: the reporter's own school (null for the Admin / no school).
                    'school_id' => School::attributedTo($user, $campusId),
                    'hazard_type' => $data['hazard_type'],
                    // As observed; blanks dropped. A type that records none (a campus emergency, Other) clears them.
                    'measurements' => HazardMeasurements::filled($data['measurements'] ?? null),
                    'date_occurred' => $data['date_occurred'],
                    'status' => $data['status'],
                    'description' => $data['description'] ?? null,
                    'people_affected' => $data['people_affected'] ?? null,
                    'people_injured' => $data['people_injured'] ?? null,
                    'people_missing' => $data['people_missing'] ?? null,
                    'people_dead' => $data['people_dead'] ?? null,
                    'latitude' => $data['latitude'] ?? null,
                    'longitude' => $data['longitude'] ?? null,
                    'photo_caption' => $data['photo_caption'] ?? null,
                ]);

                if ($request->hasFile('photo')) {
                    $storedPath = $this->storePhoto($request->file('photo'), $incident);
                    $incident->update([
                        'photo_path' => $storedPath,
                    ]);
                }
                \App\Support\EmergencyNotices::record(\App\Support\EmergencyNotices::recipients($campusId), 'incident', (int) $incident->getKey(), $campusId,
                    "New incident #{$incident->getKey()}: {$incident->hazard_type} reported by {$user->full_name}.");
                return $incident;
            });
        } catch (\Throwable $exception) {
            \App\Support\Files::delete($storedPath);
            if ($exception instanceof \Illuminate\Database\QueryException && ! empty($data['client_key'])) {
                $existing = Incident::where('created_by', $user->personnel_id)->where('client_key', $data['client_key'])->first();
                if ($existing) return redirect()->route("{$this->routePrefix()}.incidents.show", $existing)->with('status', 'Incident already saved.');
            }
            throw $exception;
        }

        return redirect()
            ->route("{$this->routePrefix()}.incidents.show", $incident)
            ->with('status', 'Incident reported. Next, add a damage report for each building or area it affected, and keep its status up to date.');
    }

    public function show(Incident $incident): View
    {
        $this->authorizeRecordAccess($incident);

        $incident->load([
            'campus',
            'school',
            // The advisory it was recorded from, even if that is in Trash now.
            'advisory' => fn ($query) => $query->withTrashed(),
            // A damage report's building may be in Trash; keep its name showing.
            'damageReports.building' => fn ($query) => $query->withTrashed(),
            'alerts' => fn ($query) => $query->orderByDesc('sent_at'),
        ]);

        return view('incident.show', [
            'incident' => $incident,
            'routePrefix' => $this->routePrefix(),
            'canManage' => $canManage = RecordAccess::canManage($incident, Auth::user()),
            'canManageDamageReports' => $canManage,
            'canSendAlerts' => Auth::user()->role === 'DRRMO Admin',
        ]);
    }

    public function edit(Incident $incident): View
    {
        $this->authorizeRecordManage($incident);
        $user = Auth::user();

        return view('incident.edit', [
            'incident' => $incident,
            'campuses' => $user->role === 'DRRMO Admin' ? Campus::orderBy('campus_name')->get() : null,
            'routePrefix' => $this->routePrefix(),
        ]);
    }

    public function update(Request $request, Incident $incident): RedirectResponse
    {
        $this->authorizeRecordManage($incident);
        $user = Auth::user();
        $data = $this->validated($request, $user);

        $campusId = (int) ($user->role === 'DRRMO Admin' ? $data['campus_id'] : $incident->campus_id);

        $attributes = [
            'campus_id' => $campusId,
            // Moved to another campus: its school (on the old campus) no longer
            // applies, so it's cleared rather than left pointing across campuses.
            'school_id' => $campusId === (int) $incident->campus_id ? $incident->school_id : null,
            'hazard_type' => $data['hazard_type'],
            // As observed; blanks dropped. A type that records none (a campus emergency, Other) clears them.
            'measurements' => HazardMeasurements::filled($data['measurements'] ?? null),
            'date_occurred' => $data['date_occurred'],
            'status' => $data['status'],
            'description' => $data['description'] ?? null,
            'people_affected' => $data['people_affected'] ?? null,
            'people_injured' => $data['people_injured'] ?? null,
            'people_missing' => $data['people_missing'] ?? null,
            'people_dead' => $data['people_dead'] ?? null,
            'latitude' => $data['latitude'] ?? null,
            'longitude' => $data['longitude'] ?? null,
            'photo_caption' => $data['photo_caption'] ?? null,
        ];

        if ($request->hasFile('photo')) {
            $attributes['photo_path'] = $this->storePhoto($request->file('photo'), $incident);
        }

        $previousPhoto = $incident->photo_path;
        $incident->update($attributes);
        if (isset($attributes['photo_path']) && $previousPhoto) {
            \App\Support\TrashPurge::deleteFilesAfterCommit(\App\Support\Files::diskName(), [$previousPhoto]);
        }

        return redirect()
            ->route("{$this->routePrefix()}.incidents.show", $incident)
            ->with('status', 'Incident updated.');
    }

    public function destroy(Incident $incident): RedirectResponse
    {
        $this->authorizeRecordManage($incident);

        // Trash keeps the photo, so restoring brings the incident back whole.
        // The file goes only on permanent delete (Incident::booted).
        $incident->delete();

        return redirect()
            ->route("{$this->routePrefix()}.incidents.index")
            ->with('status', 'Incident moved to Trash. If that was a mistake, restore it from View Trash.');
    }

    public function downloadReport(Incident $incident): Response
    {
        $this->authorizeRecordAccess($incident);

        $incident->load([
            'campus',
            'school',
            // A damage report's building may be in Trash; keep its name showing.
            'damageReports.building' => fn ($query) => $query->withTrashed(),
            'alerts' => fn ($query) => $query->orderByDesc('sent_at'),
        ]);

        $pdf = $this->loadPdfViewWithPageCount('reports.records.incident', [
            'incident' => $incident,
            'totalBuildings' => $incident->damageReports->unique(fn ($r) => \App\Models\Incident\DamageReport::normalizeArea($r->areaLabel()))->count(),
            'totalCasualties' => $incident->damageReports->sum('casualty_count'),
            'totalCost' => $incident->damageReports->sum('est_cost'),
            'photoDataUri' => $this->storagePhotoDataUri($incident->photo_path),
        ]);

        $filename = sprintf(
            'ASCOT-Incident-Report_INC-%d_%s.pdf',
            $incident->incident_id,
            $incident->date_occurred->format('Y-m-d')
        );

        $this->logReportDownload(sprintf(
            '%s downloaded the Incident report for %s (%s)',
            Auth::user()->full_name,
            $incident->hazard_type,
            $incident->campus->campus_name ?? 'Unknown campus'
        ));

        return $pdf->download($filename);
    }

    protected function storePhoto(UploadedFile $photo, Incident $incident): string
    {
        $slug = Str::slug($incident->hazard_type);

        if ($slug === '') {
            $slug = 'incident';
        }

        $filename = sprintf(
            '%s-%d-%s.%s',
            $slug,
            $incident->incident_id,
            Str::random(6),
            $photo->getClientOriginalExtension()
        );

        return \App\Support\Files::store($photo, 'incidents', $filename);
    }

    /**
     * The advisory an incident may be recorded from: one the user can see,
     * not in Trash, and Active or Passed (never Cancelled). Anything else is
     * refused rather than silently unlinked.
     */
    protected function linkableAdvisory($id, $user): DisasterAdvisory
    {
        abort_unless(ctype_digit((string) $id), 404);
        $advisory = DisasterAdvisory::findOrFail((int) $id);
        abort_unless(RecordAccess::allows($advisory, $user), 403, "You don't have access to this page.");
        abort_unless($advisory->canRecordIncident(), 422, 'A cancelled advisory can’t have an incident recorded from it.');

        return $advisory;
    }

    protected function validated(Request $request, $user): array
    {
        // Measurements, as observed: only the chosen Disaster-related type's
        // fields, each optional (App\Support\HazardMeasurements); none at all
        // for a campus emergency or Other.
        $fields = HazardMeasurements::forIncident(is_string($request->input('hazard_type')) ? $request->input('hazard_type') : null);
        [$measurementRules, $measurementAttributes] = HazardMeasurements::fieldRules($fields);

        $data = $request->validate([
            'client_key' => ['nullable', 'uuid'],
            'measurements' => $fields ? ['nullable', 'array:' . implode(',', array_keys($fields))] : ['prohibited'],
            ...$measurementRules,
            // The incident type (config/incident_types.php), or Other with its name typed in.
            'hazard_type' => ['required', \Illuminate\Validation\Rule::in([...Incident::types(), Incident::OTHER_TYPE])],
            'hazard_type_other' => ['required_if:hazard_type,Other', 'nullable', 'string', 'max:100'],
            'date_occurred' => ['required', 'date', 'before_or_equal:today'],
            'status' => ['required', 'in:Active,Under Response,Resolved'],
            'description' => ['nullable', 'string', 'max:2000'],
            // People counts, each optional (blank = not known).
            ...collect(Incident::PEOPLE)->mapWithKeys(fn ($label, $column) => [$column => ['nullable', 'integer', 'min:0', 'max:60000']])->all(),
            'photo' => ['nullable', 'image', 'mimes:jpeg,png,jpg,webp', 'max:5120'],
            // What the photo shows (thesis panel, Engineering).
            'photo_caption' => ['nullable', 'string', 'max:200'],
            'latitude' => ['nullable', 'numeric', 'between:-90,90', 'required_with:longitude'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180', 'required_with:latitude'],
            'campus_id' => $user->role === 'DRRMO Admin'
                ? ['required', 'exists:campuses,campus_id']
                : ['nullable'],
            'advisory_id' => ['nullable', 'integer'],
        ], [
            'measurements.array' => 'These measurements don’t belong to the chosen incident type.',
            'measurements.prohibited' => 'Measurements are recorded only for disaster-related incidents.',
        ], $measurementAttributes);

        $data['hazard_type'] = $data['hazard_type'] === 'Other'
            ? $data['hazard_type_other']
            : $data['hazard_type'];

        return $data;
    }
}
