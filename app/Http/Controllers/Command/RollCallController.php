<?php

namespace App\Http\Controllers\Command;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Campus;
use App\Models\Personnel;
use App\Models\RollCall;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class RollCallController extends Controller
{
    private function authorizeCampus(Request $request, RollCall $rollCall): void
    {
        abort_unless($request->user()->isAdmin() || (int) $rollCall->campus_id === (int) $request->user()->campus_id, 404);
    }

    private function prefix(Request $request): string
    {
        return $request->user()->isAdmin() ? 'admin' : 'coordinator';
    }

    public function index(Request $request)
    {
        return view('command.roll-calls.index', [
            'rollCalls' => RollCall::with('campus')->withCount('responses')
                ->when(! $request->user()->isAdmin(), fn ($q) => $q->where('campus_id', $request->user()->campus_id))
                ->latest('started_at')->paginate(20),
            'campuses' => Campus::when(! $request->user()->isAdmin(), fn ($q) => $q->whereKey($request->user()->campus_id))->orderBy('campus_name')->get(),
            'routePrefix' => $this->prefix($request),
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate(['title' => ['required', 'string', 'max:150'],
            'instructions' => ['nullable', 'string', 'max:2000'],
            'campus_id' => ['required', 'integer', 'exists:campuses,campus_id']]);
        abort_unless($request->user()->isAdmin() || (int) $data['campus_id'] === (int) $request->user()->campus_id, 403);
        $rollCall = DB::transaction(function () use ($data, $request) {
            Campus::whereKey($data['campus_id'])->lockForUpdate()->firstOrFail();
            $existing = RollCall::where('campus_id', $data['campus_id'])->whereNull('closed_at')->first();
            if ($existing) {
                return $existing;
            }
            $people = Personnel::where('campus_id', $data['campus_id'])->where('role', 'Reporter')
                ->where('is_approved', true)->whereNotNull('email_verified_at')->get();
            if ($people->isEmpty()) {
                throw ValidationException::withMessages(['campus_id' => 'This campus has no approved, email-verified Reporters to check on.']);
            }
            $rollCall = RollCall::create($data + ['created_by' => $request->user()->personnel_id, 'started_at' => now()]);
            // Freeze the expected roster at start; later account changes cannot alter the total.
            foreach ($people as $person) {
                $rollCall->responses()->create(['reporter_id' => $person->personnel_id,
                    'full_name' => $person->full_name, 'contact_number' => $person->contact_number]);
            }
            $this->log($request, $rollCall, 'roll_call_started', 'started');

            return $rollCall;
        });

        return redirect()->route($this->prefix($request).'.roll-calls.show', $rollCall)->with('status', 'Roll call is open. The expected roster is fixed at the time it started.');
    }

    public function show(Request $request, RollCall $rollCall)
    {
        $this->authorizeCampus($request, $rollCall);
        $rollCall->load('campus');
        $responses = $rollCall->responses()->orderBy('full_name')->get();

        return view('command.roll-calls.show', compact('rollCall', 'responses') + ['routePrefix' => $this->prefix($request)]);
    }

    public function close(Request $request, RollCall $rollCall)
    {
        $this->authorizeCampus($request, $rollCall);
        DB::transaction(function () use ($rollCall, $request) {
            $locked = RollCall::whereKey($rollCall->getKey())->lockForUpdate()->firstOrFail();
            if ($locked->closed_at) {
                return;
            }
            $locked->update(['closed_at' => now(), 'closed_by' => $request->user()->personnel_id]);
            $this->log($request, $locked, 'roll_call_closed', 'closed');
        });

        return back()->with('status', 'Roll call closed. Responses are preserved.');
    }

    public function export(Request $request, RollCall $rollCall)
    {
        $this->authorizeCampus($request, $rollCall);
        $this->log($request, $rollCall, 'roll_call_exported', 'exported');

        return response()->streamDownload(function () use ($rollCall) {
            $out = fopen('php://output', 'wb');
            fputcsv($out, ['Name', 'Phone', 'Answer', 'Note', 'Responded at', 'Latitude', 'Longitude']);
            foreach ($rollCall->responses()->orderBy('full_name')->cursor() as $row) {
                $values = [$row->full_name, $row->contact_number, $row->answer ?: 'No response', $row->note,
                    $row->responded_at?->format('Y-m-d H:i:s'), $row->latitude, $row->longitude];
                fputcsv($out, array_map(fn ($v) => preg_match('/^[\s=+\-@]/u', (string) $v) ? "'".$v : $v, $values));
            }
            fclose($out);
        }, 'Roll-Call-'.$rollCall->getKey().'.csv', ['Content-Type' => 'text/csv']);
    }

    private function log(Request $request, RollCall $rollCall, string $action, string $verb): void
    {
        ActivityLog::create(['personnel_id' => $request->user()->personnel_id, 'action' => $action,
            'subject_type' => 'roll_call', 'subject_id' => $rollCall->getKey(),
            'description' => mb_substr($request->user()->full_name." {$verb} roll call: {$rollCall->title}", 0, 255), 'ip_address' => $request->ip()]);
    }
}
