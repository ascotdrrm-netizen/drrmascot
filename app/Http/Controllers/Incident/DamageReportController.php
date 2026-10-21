<?php

namespace App\Http\Controllers\Incident;

use App\Http\Controllers\Concerns\HandlesCampusScoping;
use App\Http\Controllers\Controller;
use App\Models\Building\Building;
use App\Models\Incident\DamageReport;
use App\Models\Incident\Incident;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class DamageReportController extends Controller
{
    use HandlesCampusScoping;

    public function create(Incident $incident): View
    {
        $this->authorizeRecordAccess($incident);

        return view('incident.damage-reports.create', [
            'incident' => $incident,
            'routePrefix' => $this->routePrefix(),
        ]);
    }

    public function store(Request $request, Incident $incident): RedirectResponse
    {
        $this->authorizeRecordAccess($incident);

        $incident->damageReports()->create($this->validated($request, $incident));

        return redirect()
            ->route("{$this->routePrefix()}.incidents.show", $incident)
            ->with('status', 'Damage report added. Add one for each other building or area affected, if any.');
    }

    public function edit(Incident $incident, DamageReport $damage_report): View
    {
        $this->authorizeRecordAccess($incident);
        $this->ensureBelongsToIncident($incident, $damage_report);

        return view('incident.damage-reports.edit', [
            'incident' => $incident,
            'damageReport' => $damage_report->load('building'),
            'routePrefix' => $this->routePrefix(),
        ]);
    }

    public function update(Request $request, Incident $incident, DamageReport $damage_report): RedirectResponse
    {
        $this->authorizeRecordAccess($incident);
        $this->ensureBelongsToIncident($incident, $damage_report);

        $damage_report->update($this->validated($request, $incident, $damage_report));

        return redirect()
            ->route("{$this->routePrefix()}.incidents.show", $incident)
            ->with('status', 'Damage report updated.');
    }

    public function destroy(Incident $incident, DamageReport $damage_report): RedirectResponse
    {
        $this->authorizeRecordAccess($incident);
        $this->ensureBelongsToIncident($incident, $damage_report);
        $damage_report->delete();

        return redirect()
            ->route("{$this->routePrefix()}.incidents.show", $incident)
            ->with('status', 'Damage report permanently deleted.');
    }

    /**
     * The building/area is TYPED (area_name). When it matches a building on
     * the incident's campus that is not in Trash (or the one this report
     * already has), the report is linked to it, so that building's history
     * lists the damage; anything else is kept as typed.
     *
     * One damage report per building/area per incident (a correction is an
     * edit, not a second report — otherwise "buildings affected" and the
     * casualty and cost totals count it twice).
     *
     * A building_id sent without area_name (older clients) still works: the
     * building must be on the incident's campus and not in Trash; editing a
     * report may keep the building it already has even if it is in Trash.
     */
    protected function validated(Request $request, Incident $incident, ?DamageReport $current = null): array
    {
        $keep = $current?->building_id;

        if ($request->filled('area_name') || ! $request->filled('building_id')) {
            $data = $request->validate([
                'area_name' => ['required', 'string', 'max:150'],
            ] + $this->figureRules(), [
                'area_name.required' => 'Type the building or area affected.',
            ]);
            $data['area_name'] = preg_replace('/\s+/u', ' ', trim($data['area_name']));
            $area = DamageReport::normalizeArea($data['area_name']);

            $taken = $incident->damageReports()->with('building')
                ->when($current, fn ($q) => $q->whereKeyNot($current->report_id))->get()
                ->contains(fn (DamageReport $r) => DamageReport::normalizeArea($r->areaLabel()) === $area);
            if ($taken) {
                throw ValidationException::withMessages(['area_name' => 'This building/area already has a damage report for this incident. Edit that one instead.']);
            }

            $data['building_id'] = Building::withTrashed()->where('campus_id', $incident->campus_id)
                ->where(fn ($q) => $keep ? $q->whereNull('deleted_at')->orWhere('building_id', $keep) : $q->whereNull('deleted_at'))
                ->get(['building_id', 'building_name'])
                ->first(fn (Building $b) => DamageReport::normalizeArea($b->building_name) === $area)?->building_id;

            return $data;
        }

        $data = $request->validate([
            'building_id' => [
                'required',
                'integer',
                Rule::exists('buildings', 'building_id')
                    ->where('campus_id', $incident->campus_id)
                    ->where(fn ($q) => $keep ? $q->whereNull('deleted_at')->orWhere('building_id', $keep) : $q->whereNull('deleted_at')),
                Rule::unique('damage_reports', 'building_id')
                    ->where('incident_id', $incident->incident_id)
                    ->ignore($current?->report_id, 'report_id'),
            ],
        ] + $this->figureRules(), [
            'building_id.unique' => 'This building/area already has a damage report for this incident. Edit that one instead.',
            'building_id.exists' => 'Choose a building/area on this incident\'s campus that is not in Trash.',
        ]);
        $data['area_name'] = Building::withTrashed()->find($data['building_id'])->building_name;

        return $data;
    }

    protected function figureRules(): array
    {
        return [
            'damage_level' => ['required', 'in:Minor,Major,Total'],
            'casualty_count' => ['required', 'integer', 'min:0', 'max:100000'],
            // decimal(12,2): the most the column holds.
            'est_cost' => ['nullable', 'numeric', 'min:0', 'max:9999999999.99'],
        ];
    }

    protected function ensureBelongsToIncident(Incident $incident, DamageReport $damageReport): void
    {
        if ($damageReport->incident_id !== $incident->incident_id) {
            abort(404);
        }
    }
}
