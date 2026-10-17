<?php

namespace App\Http\Controllers;

use App\Models\Campus;
use App\Models\School;
use App\Support\Settings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * DRRMO Admin settings (thesis panel):
 *  - Schools and courses per campus, so one the list is missing (Accountancy)
 *    is added here rather than in the code.
 *  - Who signs "Noted by" on the PDF reports, so it is never the same person
 *    as "Prepared by".
 */
class SettingsController extends Controller
{
    public function index(): View
    {
        return view('settings.index', [
            'campuses' => Campus::with(['schools' => fn ($q) => $q->orderBy('school_name')])->orderBy('campus_name')->get(),
            'notedByName' => Settings::get('report.noted_by_name'),
            'notedByPosition' => Settings::get('report.noted_by_position'),
        ]);
    }

    public function updateSignatory(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'noted_by_name' => ['nullable', 'string', 'max:120'],
            'noted_by_position' => ['required', 'string', 'max:120'],
        ]);

        Settings::set('report.noted_by_name', $data['noted_by_name'] ?? null);
        Settings::set('report.noted_by_position', $data['noted_by_position']);
        \App\Models\ActivityLog::create(['personnel_id' => $request->user()->getKey(), 'action' => 'updated',
            'subject_type' => 'Settings', 'subject_id' => null, 'ip_address' => $request->ip(),
            'description' => mb_substr('Report signatory set to '.trim(($data['noted_by_name'] ?? '').', '.$data['noted_by_position'], ', '), 0, 255)]);

        return redirect()->route('admin.settings.index')->with('status', 'Report signatory saved. New PDFs use it.');
    }

    public function storeSchool(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'campus_id' => ['required', 'exists:campuses,campus_id'],
            'school_name' => ['required', 'string', 'max:100', $this->uniqueSchoolName($request->input('campus_id'))],
        ]);

        School::create($data);

        return redirect()->route('admin.settings.index')->with('status', "{$data['school_name']} added.");
    }

    public function updateSchool(Request $request, School $school): RedirectResponse
    {
        $data = $request->validate([
            'school_name' => ['required', 'string', 'max:100', $this->uniqueSchoolName($school->campus_id, $school->school_id)],
        ]);

        $school->update($data);

        return redirect()->route('admin.settings.index')->with('status', 'School renamed. Records keep it under the new name.');
    }

    /**
     * One name per campus, whatever the capitals ("School of Arts" and
     * "school of arts" are the same school) — the same rule as the database's
     * unique index on (campus_id, lower(school_name)).
     */
    protected function uniqueSchoolName(mixed $campusId, ?int $ignoreSchoolId = null): \Closure
    {
        return function (string $attribute, mixed $value, \Closure $fail) use ($campusId, $ignoreSchoolId) {
            $taken = School::where('campus_id', $campusId)
                ->whereRaw('lower(school_name) = ?', [mb_strtolower(trim((string) $value))])
                ->when($ignoreSchoolId, fn ($q) => $q->where('school_id', '!=', $ignoreSchoolId))
                ->exists();
            if ($taken) {
                $fail('That campus already has a school with this name.');
            }
        };
    }
}
