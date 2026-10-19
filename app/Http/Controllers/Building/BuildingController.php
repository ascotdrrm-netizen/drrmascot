<?php

namespace App\Http\Controllers\Building;

use App\Http\Controllers\Concerns\HandlesCampusScoping;
use App\Http\Controllers\Concerns\LogsReportDownloads;
use App\Http\Controllers\Controller;
use App\Models\Building\Building;
use App\Models\Campus;
use App\Support\FilterSummary;
use App\Support\RecordAccess;
use App\Traits\RendersPdfAssets;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\View\View;

class BuildingController extends Controller
{
    use HandlesCampusScoping, RendersPdfAssets, LogsReportDownloads;

    public function index(Request $request): View
    {
        $user = Auth::user();
        $query = Building::with('campus');
        $trashedQuery = Building::onlyTrashed();
        $campuses = $user->role === 'DRRMO Admin' ? Campus::orderBy('campus_name')->get() : null;
        $campus = $this->adminCampusFilter($request, $campuses ?? []);

        if ($user->role === 'Coordinator') {
            $query->where('campus_id', $user->campus_id);
            $trashedQuery->where('campus_id', $user->campus_id);
        } elseif ($campus) {
            $query->where('campus_id', $campus->campus_id);
            $trashedQuery->where('campus_id', $campus->campus_id);
        }

        return view('building.index', [
            'buildings' => $query->orderBy('building_name')->get(),
            'campuses' => $campuses,
            'selectedCampusId' => $campus?->campus_id,
            'routePrefix' => $this->routePrefix(),
            'trashedCount' => $trashedQuery->count(),
            // The list is campus-wide for a coordinator too (shared by the campus's coordinators).
            'filterSummary' => FilterSummary::make('all buildings/areas', $this->campusCovered($campus)),
            'filtersApplied' => $campus !== null,
        ]);
    }

    public function trashed(Request $request): View
    {
        $user = Auth::user();
        // damage_reports_count: the purge keeps a building that damage reports point at, and the row says so.
        $query = Building::onlyTrashed()->with('campus')->withCount('damageReports');
        $campuses = $user->role === 'DRRMO Admin' ? Campus::orderBy('campus_name')->get() : null;
        $campus = $this->adminCampusFilter($request, $campuses ?? []);

        if ($user->role === 'Coordinator') {
            $query->where('campus_id', $user->campus_id);
        } elseif ($campus) {
            $query->where('campus_id', $campus->campus_id);
        }

        return view('building.trash', [
            'buildings' => $query->orderByDesc('deleted_at')->get(),
            'campuses' => $campuses,
            'selectedCampusId' => $campus?->campus_id,
            'routePrefix' => $this->routePrefix(),
            'filterSummary' => FilterSummary::make('all buildings/areas in Trash', $this->campusCovered($campus)),
            'filtersApplied' => $campus !== null,
        ]);
    }

    public function restore(int $building): RedirectResponse
    {
        $building = Building::onlyTrashed()->findOrFail($building);
        $this->authorizeCampusAccess($building);
        $building->restore();

        return redirect()
            ->route("{$this->routePrefix()}.buildings.trash")
            ->with('status', 'Building/Area restored, with its inspections. It is back in the Buildings/Areas list.');
    }

    public function create(): View
    {
        $user = Auth::user();

        return view('building.create', [
            'campuses' => $user->role === 'DRRMO Admin' ? Campus::orderBy('campus_name')->get() : null,
            'routePrefix' => $this->routePrefix(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $user = Auth::user();
        $data = $this->validated($request, $user);

        $building = Building::create([
            'campus_id' => $user->role === 'DRRMO Admin' ? $data['campus_id'] : $user->campus_id,
            'building_name' => $data['building_name'],
            'description' => $data['description'] ?? null,
            'hazard_type' => $data['hazard_type'] ?? null,
            'latitude' => $data['latitude'] ?? null,
            'longitude' => $data['longitude'] ?? null,
            'photo_caption' => $data['photo_caption'] ?? null,
        ]);

        if ($request->hasFile('photo')) {
            $building->update([
                'photo_path' => $this->storePhoto($request->file('photo'), $building),
            ]);
        }

        return redirect()
            ->route("{$this->routePrefix()}.buildings.show", $building)
            ->with('status', 'Building/Area added. Next, record its first hazard inspection with + Record an inspection below.');
    }

    public function show(Building $building): View
    {
        $this->authorizeCampusAccess($building);

        $building->load([
            'campus',
            // The building is campus-wide; its inspections are only the viewer's own (Admin: all).
            'hazardInspections' => fn ($query) => RecordAccess::applyToInspections($query->with('school')->orderByDesc('inspection_date'), Auth::user()),
        ]);

        return view('building.show', [
            'building' => $building,
            'routePrefix' => $this->routePrefix(),
        ]);
    }

    public function edit(Building $building): View
    {
        $this->authorizeCampusAccess($building);
        $user = Auth::user();

        return view('building.edit', [
            'building' => $building,
            'campuses' => $user->role === 'DRRMO Admin' ? Campus::orderBy('campus_name')->get() : null,
            'routePrefix' => $this->routePrefix(),
        ]);
    }

    public function update(Request $request, Building $building): RedirectResponse
    {
        $this->authorizeCampusAccess($building);
        $user = Auth::user();
        $data = $this->validated($request, $user);

        $attributes = [
            'campus_id' => $user->role === 'DRRMO Admin' ? $data['campus_id'] : $building->campus_id,
            'building_name' => $data['building_name'],
            'description' => $data['description'] ?? null,
            'hazard_type' => $data['hazard_type'] ?? null,
            'latitude' => $data['latitude'] ?? null,
            'longitude' => $data['longitude'] ?? null,
            'photo_caption' => $data['photo_caption'] ?? null,
        ];

        if ($request->hasFile('photo')) {
            if ($building->photo_path) {
                \App\Support\Files::delete($building->photo_path);
            }

            $attributes['photo_path'] = $this->storePhoto($request->file('photo'), $building);
        }

        $building->update($attributes);

        return redirect()
            ->route("{$this->routePrefix()}.buildings.show", $building)
            ->with('status', 'Building/Area updated.');
    }

    public function destroy(Building $building): RedirectResponse
    {
        $this->authorizeCampusAccess($building);
        abort_unless(RecordAccess::canTrashBuilding($building, Auth::user()), 403,
            'Only the person who added this building/area, or the DRRMO Admin, can move it to Trash.');

        // Trash keeps the photo, so restoring brings the building back whole.
        // The file goes only on permanent delete (Building::booted).
        $building->delete();

        return redirect()
            ->route("{$this->routePrefix()}.buildings.index")
            ->with('status', 'Building/Area moved to Trash. If that was a mistake, restore it from View Trash.');
    }

    public function downloadReport(Building $building): Response
    {
        $this->authorizeCampusAccess($building);

        $building->load([
            'campus',
            // The building is campus-wide; its inspections are only the viewer's own (Admin: all).
            'hazardInspections' => fn ($query) => RecordAccess::applyToInspections($query->with('school')->orderByDesc('inspection_date'), Auth::user()),
        ]);

        $pdf = $this->loadPdfViewWithPageCount('reports.records.building', [
            'building' => $building,
            'photoDataUri' => $this->storagePhotoDataUri($building->photo_path),
        ]);

        $filename = sprintf(
            'ASCOT-Building-Report_BLDG-%d_%s.pdf',
            $building->building_id,
            now()->format('Y-m-d')
        );

        $this->logReportDownload(sprintf(
            '%s downloaded the Building/Area report for %s (%s)',
            Auth::user()->full_name,
            $building->building_name,
            $building->campus->campus_name ?? 'Unknown campus'
        ));

        return $pdf->download($filename);
    }

    public const HAZARD_TYPES = ['Fire', 'Earthquake', 'Flood', 'Typhoon', 'Landslide', 'Structural', 'Electrical'];

    protected function storePhoto(UploadedFile $photo, Building $building): string
    {
        $slug = Str::slug($building->building_name);

        if ($slug === '') {
            $slug = 'building';
        }

        $filename = sprintf(
            '%s-%d-%s.%s',
            $slug,
            $building->building_id,
            Str::random(6),
            $photo->getClientOriginalExtension()
        );

        return \App\Support\Files::store($photo, 'buildings', $filename);
    }

    protected function validated(Request $request, $user): array
    {
        $data = $request->validate([
            'building_name' => ['required', 'string', 'max:100'],
            'description' => ['nullable', 'string', 'max:2000'],
            'hazard_type' => ['required', 'in:' . implode(',', self::HAZARD_TYPES) . ',Other'],
            'hazard_type_other' => ['required_if:hazard_type,Other', 'nullable', 'string', 'max:100'],
            'photo' => ['nullable', 'image', 'mimes:jpeg,png,jpg,webp', 'max:5120'],
            // What the photo shows (thesis panel, Engineering).
            'photo_caption' => ['nullable', 'string', 'max:200'],
            'latitude' => ['nullable', 'numeric', 'between:-90,90', 'required_with:longitude'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180', 'required_with:latitude'],
            'campus_id' => $user->role === 'DRRMO Admin'
                ? ['required', 'exists:campuses,campus_id']
                : ['nullable'],
        ]);

        $data['hazard_type'] = $data['hazard_type'] === 'Other'
            ? $data['hazard_type_other']
            : $data['hazard_type'];

        return $data;
    }
}
