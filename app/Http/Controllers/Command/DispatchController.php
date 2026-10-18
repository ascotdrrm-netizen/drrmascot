<?php

namespace App\Http\Controllers\Command;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Assignment;
use App\Models\Campus;
use App\Models\Reporter\CitizenReport;
use App\Models\Reporter\SosEvent;
use App\Models\Responder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class DispatchController extends Controller
{
    /** Cases a responder can still be sent to; Resolved and Rejected are closed. */
    private const OPEN_REPORT = ['New', 'Verified', 'Responding'];

    private const OPEN_SOS = ['New', 'Acknowledged', 'Responding'];

    private function scoped($query, Request $request)
    {
        return $request->user()->isAdmin() ? $query : $query->where('campus_id', $request->user()->campus_id);
    }

    private function campus(Request $request, int $campus): void
    {
        abort_unless($request->user()->isAdmin() || (int) $request->user()->campus_id === $campus, 404);
    }

    public function index(Request $request)
    {
        $responders = $this->scoped(Responder::with(['campus', 'activeAssignment']), $request)->orderBy('full_name')->get();
        $query = $this->scoped(Assignment::with(['responder', 'report', 'sos']), $request);
        $assignments = (clone $query)->whereIn('status', Assignment::ACTIVE)->oldest('assigned_at')->get()
            ->concat((clone $query)->whereNotIn('status', Assignment::ACTIVE)->latest('assigned_at')->limit(20)->get());
        $arrivals = $this->scoped(Assignment::query(), $request)->whereNotNull('arrived_at')->where('assigned_at', '>=', now()->subDays(30))->get();
        $minutes = $arrivals->map(fn ($a) => max(0, $a->assigned_at->diffInSeconds($a->arrived_at)) / 60);

        return view('command.dispatch', compact('responders', 'assignments') + [
            'campuses' => $request->user()->isAdmin() ? Campus::orderBy('campus_name')->get() : Campus::whereKey($request->user()->campus_id)->get(),
            // New cases too: everything from the Reporter app starts as New,
            // and dispatching one verifies (or acknowledges) it (see assign()).
            'reports' => $this->scoped(CitizenReport::query(), $request)->whereIn('status', self::OPEN_REPORT)->latest()->limit(100)->get(),
            'sosEvents' => $this->scoped(SosEvent::query(), $request)->whereIn('status', self::OPEN_SOS)->oldest()->limit(100)->get(),
            'arrivalMinutes' => $minutes->isEmpty() ? null : round($minutes->avg(), 1),
            'arrivalSample' => $minutes->count(),
            'routePrefix' => $request->user()->isAdmin() ? 'admin' : 'coordinator',
        ]);
    }

    public function storeResponder(Request $request)
    {
        $data = $request->validate(['campus_id' => ['required', 'integer', 'exists:campuses,campus_id'],
            'full_name' => ['required', 'string', 'max:150'], 'team' => ['required', 'string', 'max:100'],
            'contact_number' => ['required', 'string', 'max:20']]);
        $this->campus($request, (int) $data['campus_id']);
        $responder = Responder::create($data);
        $this->log($request, 'responder', $responder->getKey(), 'created', 'added responder '.$responder->full_name);

        return response()->json(['message' => 'Responder added.', 'id' => $responder->getKey()], 201);
    }

    public function updateResponder(Request $request, Responder $responder)
    {
        $this->campus($request, (int) $responder->campus_id);
        $data = $request->validate(['is_on_duty' => ['required', 'boolean'], 'is_active' => ['sometimes', 'boolean']]);
        DB::transaction(function () use ($responder, $data) {
            $locked = Responder::whereKey($responder->getKey())->lockForUpdate()->firstOrFail();
            if (isset($data['is_active']) && ! $data['is_active'] && $locked->activeAssignment()->exists()) {
                throw ValidationException::withMessages(['is_active' => 'Complete or cancel the active assignment before deactivating this responder.']);
            }
            $locked->update($data);
        });
        $this->log($request, 'responder', $responder->getKey(), 'updated', 'updated duty status for '.$responder->full_name);

        return response()->json(['message' => 'Duty status updated.']);
    }

    public function assign(Request $request)
    {
        if (is_string($request->input('target')) && preg_match('/^(report|sos):([0-9]+)$/', $request->input('target'), $parts)) {
            $request->merge(['kind' => $parts[1], 'subject_id' => (int) $parts[2]]);
        }
        $data = $request->validate(['responder_id' => ['required', 'integer'],
            'kind' => ['required', Rule::in(['report', 'sos'])], 'subject_id' => ['required', 'integer', 'min:1'],
            'notes' => ['nullable', 'string', 'max:500']]);
        $assignment = DB::transaction(function () use ($data, $request) {
            $subject = ($data['kind'] === 'report' ? CitizenReport::query() : SosEvent::query())
                ->whereKey($data['subject_id'])->lockForUpdate()->firstOrFail();
            $this->campus($request, (int) $subject->campus_id);
            $responder = Responder::whereKey($data['responder_id'])->lockForUpdate()->firstOrFail();
            abort_unless((int) $responder->campus_id === (int) $subject->campus_id, 422, 'The responder must belong to the same campus as this emergency.');
            $allowed = $data['kind'] === 'report' ? self::OPEN_REPORT : self::OPEN_SOS;
            if (! in_array($subject->status, $allowed, true)) {
                throw ValidationException::withMessages(['subject_id' => 'This case is closed and cannot receive assignments.']);
            }
            if (! $responder->is_active || ! $responder->is_on_duty) {
                throw ValidationException::withMessages(['responder_id' => 'This responder is off duty or inactive.']);
            }
            $active = $responder->activeAssignment()->first();
            if ($active) {
                // A retried request after a lost response returns the existing assignment.
                if ((int) $active->{$data['kind'] === 'report' ? 'report_id' : 'sos_id'} === (int) $subject->getKey()) {
                    return $active;
                }
                throw ValidationException::withMessages(['responder_id' => 'This responder already has an active assignment.']);
            }
            $assignment = Assignment::create(['campus_id' => $subject->campus_id, 'responder_id' => $responder->getKey(),
                $data['kind'] === 'report' ? 'report_id' : 'sos_id' => $subject->getKey(),
                'assigned_by' => $request->user()->personnel_id, 'status' => 'Assigned', 'notes' => $data['notes'] ?? null, 'assigned_at' => now()]);
            if ($subject->status !== 'Responding') {
                // Dispatching a New case is the staff member verifying (or
                // acknowledging) it, so that step stays on the record.
                if ($subject->status === 'New' && $data['kind'] === 'report') {
                    $subject->events()->create(['actor_id' => $request->user()->personnel_id,
                        'status' => 'Verified', 'note' => 'Verified by the DRRM Office when a responder was dispatched.', 'created_at' => now()]);
                }
                $subject->update(['status' => 'Responding'] + ($subject->status === 'New' && $data['kind'] === 'sos'
                    ? ['acknowledged_by' => $request->user()->personnel_id, 'acknowledged_at' => now()] : []));
                if ($data['kind'] === 'report') {
                    $subject->events()->create(['actor_id' => $request->user()->personnel_id,
                        'status' => 'Responding', 'note' => 'A campus responder was assigned.', 'created_at' => now()]);
                }
            }
            $this->log($request, 'assignment', $assignment->getKey(), 'responder_dispatched',
                "assigned {$responder->full_name} to {$data['kind']} #{$subject->getKey()}");

            return $assignment;
        });

        return response()->json(['message' => 'Responder assigned.', 'id' => $assignment->getKey()]);
    }

    public function updateAssignment(Request $request, Assignment $assignment)
    {
        $this->campus($request, (int) $assignment->campus_id);
        $data = $request->validate(['status' => ['required', Rule::in(['On scene', 'Completed', 'Cancelled'])],
            'notes' => ['nullable', 'string', 'max:500']]);
        DB::transaction(function () use ($assignment, $request, $data) {
            // Subject first, matching assignment creation and command status updates.
            ($assignment->report_id ? CitizenReport::query() : SosEvent::query())
                ->whereKey($assignment->report_id ?: $assignment->sos_id)->lockForUpdate()->firstOrFail();
            $locked = Assignment::whereKey($assignment->getKey())->lockForUpdate()->firstOrFail();
            if ($locked->status === $data['status']) {
                return;
            }
            $allowed = ['Assigned' => ['On scene', 'Cancelled'], 'On scene' => ['Completed', 'Cancelled']];
            if (! in_array($data['status'], $allowed[$locked->status] ?? [], true)) {
                throw ValidationException::withMessages(['status' => 'This assignment has already changed. Reload its latest status.']);
            }
            $column = ['On scene' => 'arrived_at', 'Completed' => 'completed_at', 'Cancelled' => 'cancelled_at'][$data['status']];
            $locked->update($data + [$column => now()]);
            $this->log($request, 'assignment', $locked->getKey(), 'dispatch_updated', "marked assignment #{$locked->getKey()} {$data['status']}");
        });

        return response()->json(['message' => 'Assignment updated.']);
    }

    private function log(Request $request, string $type, int $id, string $action, string $description): void
    {
        ActivityLog::create(['personnel_id' => $request->user()->personnel_id, 'subject_type' => $type, 'subject_id' => $id,
            'action' => $action, 'description' => mb_substr($request->user()->full_name.' '.$description, 0, 255), 'ip_address' => $request->ip()]);
    }
}
