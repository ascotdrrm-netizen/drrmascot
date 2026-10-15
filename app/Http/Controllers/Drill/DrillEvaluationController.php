<?php

namespace App\Http\Controllers\Drill;

use App\Http\Controllers\Concerns\HandlesCampusScoping;
use App\Http\Controllers\Controller;
use App\Models\Drill\Drill;
use App\Models\Drill\DrillEvaluation;
use App\Models\Personnel;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class DrillEvaluationController extends Controller
{
    use HandlesCampusScoping;

    public function create(Drill $drill): View
    {
        $this->authorizeCampusAccess($drill);

        return view('drill.evaluations.create', [
            'drill' => $drill,
            'personnel' => Personnel::where('campus_id', $drill->campus_id)->orderBy('full_name')->get(),
            'routePrefix' => $this->routePrefix(),
        ]);
    }

    public function store(Request $request, Drill $drill): RedirectResponse
    {
        $this->authorizeCampusAccess($drill);

        $drill->evaluations()->create($this->validated($request, $drill));

        return redirect()
            ->route("{$this->routePrefix()}.drills.show", $drill)
            ->with('status', 'Evaluation recorded.');
    }

    public function edit(Drill $drill, DrillEvaluation $evaluation): View
    {
        $this->authorizeCampusAccess($drill);
        $this->ensureBelongsToDrill($drill, $evaluation);

        return view('drill.evaluations.edit', [
            'drill' => $drill,
            'evaluation' => $evaluation,
            'personnel' => Personnel::where('campus_id', $drill->campus_id)->orderBy('full_name')->get(),
            'routePrefix' => $this->routePrefix(),
        ]);
    }

    public function update(Request $request, Drill $drill, DrillEvaluation $evaluation): RedirectResponse
    {
        $this->authorizeCampusAccess($drill);
        $this->ensureBelongsToDrill($drill, $evaluation);

        $evaluation->update($this->validated($request, $drill, $evaluation));

        return redirect()
            ->route("{$this->routePrefix()}.drills.show", $drill)
            ->with('status', 'Evaluation updated.');
    }

    public function destroy(Drill $drill, DrillEvaluation $evaluation): RedirectResponse
    {
        $this->authorizeCampusAccess($drill);
        $this->ensureBelongsToDrill($drill, $evaluation);
        $evaluation->delete();

        return redirect()
            ->route("{$this->routePrefix()}.drills.show", $drill)
            ->with('status', 'Evaluation deleted.');
    }

    protected function validated(Request $request, Drill $drill, ?DrillEvaluation $evaluation = null): array
    {
        $uniqueRule = Rule::unique('drill_evaluations', 'personnel_id')->where('drill_id', $drill->drill_id);

        if ($evaluation) {
            $uniqueRule = $uniqueRule->ignore($evaluation->evaluation_id, 'evaluation_id');
        }

        return $request->validate([
            'personnel_id' => [
                'required',
                Rule::exists('personnel', 'personnel_id')->where('campus_id', $drill->campus_id),
                $uniqueRule,
            ],
            'pre_score' => ['required', 'numeric', 'between:0,100'],
            'post_score' => ['nullable', 'numeric', 'between:0,100'],
        ], [
            'personnel_id.unique' => 'This person already has an evaluation recorded for this drill.',
        ]);
    }

    protected function ensureBelongsToDrill(Drill $drill, DrillEvaluation $evaluation): void
    {
        if ($evaluation->drill_id !== $drill->drill_id) {
            abort(404);
        }
    }
}
