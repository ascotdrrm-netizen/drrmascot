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
use Illuminate\View\View;

class DamageReportController extends Controller
{
    use HandlesCampusScoping;

    public function create(Incident $incident): View
    {
        $this->authorizeRecordAccess($incident);

        return view('incident.damage-reports.create', [
            'incident' => $incident,
            'buildings' => Building::where('campus_id', $incident->campus_id)->orderBy('building_name')->get(),
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
            'damageReport' => $damage_report,
            // Its own building stays listed even if it has since gone to Trash.
            'buildings' => Building::withTrashed()->where('campus_id', $incident->campus_id)
                ->where(fn ($q) => $q->whereNull('deleted_at')->orWhere('building_id', $damage_report->building_id))
                ->orderBy('building_name')->get(),
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
     * One damage report per building per incident (a correction is an edit,
     * not a second report — otherwise "buildings affected" and the casualty
     * and cost totals count it twice). The building must be on the incident's
     * campus and not in Trash; editing a report may keep the building it
     * already has even if that building has since gone to Trash.
     */
    protected function validated(Request $request, Incident $incident, ?DamageReport $current = null): array
    {
        $keep = $current?->building_id;

        return $request->validate([
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
            'damage_level' => ['required', 'in:Minor,Major,Total'],
            'casualty_count' => ['required', 'integer', 'min:0', 'max:100000'],
            // decimal(12,2): the most the column holds.
            'est_cost' => ['nullable', 'numeric', 'min:0', 'max:9999999999.99'],
        ], [
            'building_id.unique' => 'This building/area already has a damage report for this incident. Edit that one instead.',
            'building_id.exists' => 'Choose a building/area on this incident\'s campus that is not in Trash.',
        ]);
    }

    protected function ensureBelongsToIncident(Incident $incident, DamageReport $damageReport): void
    {
        if ($damageReport->incident_id !== $incident->incident_id) {
            abort(404);
        }
    }
}
