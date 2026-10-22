<?php

namespace App\Http\Controllers\Building;

use App\Http\Controllers\Concerns\HandlesCampusScoping;
use App\Http\Controllers\Concerns\LogsReportDownloads;
use App\Http\Controllers\Controller;
use App\Models\Building\Building;
use App\Models\Building\HazardInspection;
use App\Models\School;
use App\Traits\RendersPdfAssets;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\View\View;

class HazardInspectionController extends Controller
{
    use HandlesCampusScoping, RendersPdfAssets, LogsReportDownloads;

    public function index(Building $building): RedirectResponse
    {
        $this->authorizeCampusAccess($building);

        return redirect()->route("{$this->routePrefix()}.buildings.show", $building);
    }

    public function create(Building $building): View
    {
        $this->authorizeCampusAccess($building);

        return view('building.hazard-inspections.create', [
            'building' => $building,
            'routePrefix' => $this->routePrefix(),
        ]);
    }

    public function store(Request $request, Building $building): RedirectResponse
    {
        $this->authorizeCampusAccess($building);

        $data = $this->validated($request);

        $inspection = $building->hazardInspections()->make([
            // Attribution: the inspector's own school (null for the Admin / no school).
            'school_id' => School::attributedTo(Auth::user(), (int) $building->campus_id),
            'inspection_date' => $data['inspection_date'],
            'findings' => $data['findings'] ?? null,
            'latitude' => $data['latitude'] ?? null,
            'longitude' => $data['longitude'] ?? null,
            'photo_caption' => $data['photo_caption'] ?? null,
        ]);
        // Score and level come from the matrix, never from the request.
        $inspection->rate((int) $data['likelihood'], (int) $data['impact'])->save();

        if ($request->hasFile('photo')) {
            $inspection->update([
                'photo_path' => $this->storePhoto($request->file('photo'), $building, $inspection),
            ]);
        }

        return redirect()
            ->route("{$this->routePrefix()}.buildings.show", $building)
            ->with('status', 'Inspection recorded. It is listed below; use Download PDF on it when you need a printed copy.');
    }

    public function edit(Building $building, HazardInspection $inspection): View
    {
        $this->authorizeCampusAccess($building);
        $this->ensureBelongsToBuilding($building, $inspection);
        $this->authorizeRecordManage($inspection);

        return view('building.hazard-inspections.edit', [
            'building' => $building,
            'inspection' => $inspection,
            'routePrefix' => $this->routePrefix(),
        ]);
    }

    public function update(Request $request, Building $building, HazardInspection $inspection): RedirectResponse
    {
        $this->authorizeCampusAccess($building);
        $this->ensureBelongsToBuilding($building, $inspection);
        $this->authorizeRecordManage($inspection);

        $data = $this->validated($request);

        $attributes = [
            'inspection_date' => $data['inspection_date'],
            'findings' => $data['findings'] ?? null,
            'latitude' => $data['latitude'] ?? null,
            'longitude' => $data['longitude'] ?? null,
            'photo_caption' => $data['photo_caption'] ?? null,
        ];
        $inspection->rate((int) $data['likelihood'], (int) $data['impact']);

        if ($request->hasFile('photo')) {
            if ($inspection->photo_path) {
                \App\Support\Files::delete($inspection->photo_path);
            }

            $attributes['photo_path'] = $this->storePhoto($request->file('photo'), $building, $inspection);
        }

        $inspection->update($attributes);

        return redirect()
            ->route("{$this->routePrefix()}.buildings.show", $building)
            ->with('status', 'Inspection updated.');
    }

    public function destroy(Building $building, HazardInspection $inspection): RedirectResponse
    {
        $this->authorizeCampusAccess($building);
        $this->ensureBelongsToBuilding($building, $inspection);
        $this->authorizeRecordManage($inspection);

        if ($inspection->photo_path) {
            \App\Support\Files::delete($inspection->photo_path);
        }

        $inspection->delete();

        return redirect()
            ->route("{$this->routePrefix()}.buildings.show", $building)
            ->with('status', 'Inspection permanently deleted.');
    }

    public function downloadReport(Building $building, HazardInspection $inspection): Response
    {
        $this->authorizeCampusAccess($building);
        $this->ensureBelongsToBuilding($building, $inspection);
        $this->authorizeRecordAccess($inspection);

        $building->loadMissing('campus');
        $inspection->loadMissing('school');

        $pdf = $this->loadPdfViewWithPageCount('reports.records.hazard-inspection', [
            'building' => $building,
            'inspection' => $inspection,
            'photoDataUri' => $this->storagePhotoDataUri($inspection->photo_path),
        ]);

        $filename = sprintf(
            'ASCOT-Hazard-Inspection-Report_INSP-%d_%s.pdf',
            $inspection->inspection_id,
            $inspection->inspection_date->format('Y-m-d')
        );

        $this->logReportDownload(sprintf(
            '%s downloaded the Hazard Inspection report for %s (%s)',
            Auth::user()->full_name,
            $building->building_name,
            $building->campus->campus_name ?? 'Unknown campus'
        ));

        return $pdf->download($filename);
    }

    protected function storePhoto(UploadedFile $photo, Building $building, HazardInspection $inspection): string
    {
        $slug = Str::slug($building->building_name);

        if ($slug === '') {
            $slug = 'building';
        }

        $filename = sprintf(
            '%s-inspection-%s-%d-%s.%s',
            $slug,
            $inspection->inspection_date->format('Y-m-d'),
            $inspection->inspection_id,
            Str::random(6),
            $photo->getClientOriginalExtension()
        );

        return \App\Support\Files::store($photo, 'hazard-inspections', $filename);
    }

    protected function validated(Request $request): array
    {
        return $request->validate([
            'inspection_date' => ['required', 'date', 'before_or_equal:today'],
            'findings' => ['nullable', 'string', 'max:2000'],
            // The 5×5 risk matrix (App\Support\RiskMatrix): the level is computed.
            'likelihood' => ['required', 'integer', 'between:1,5'],
            'impact' => ['required', 'integer', 'between:1,5'],
            'photo' => ['nullable', 'image', 'mimes:jpeg,png,jpg,webp', 'max:5120'],
            // What the photo shows (thesis panel, Engineering).
            'photo_caption' => ['nullable', 'string', 'max:200'],
            'latitude' => ['nullable', 'numeric', 'between:-90,90', 'required_with:longitude'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180', 'required_with:latitude'],
        ], [
            'likelihood.required' => 'Choose how likely the hazard is to cause harm (1 to 5).',
            'impact.required' => 'Choose how bad the harm would be (1 to 5).',
            'likelihood.between' => 'Likelihood is rated 1 to 5.',
            'impact.between' => 'Impact is rated 1 to 5.',
        ]);
    }

    protected function ensureBelongsToBuilding(Building $building, HazardInspection $inspection): void
    {
        if ($inspection->building_id !== $building->building_id) {
            abort(404);
        }
    }
}

