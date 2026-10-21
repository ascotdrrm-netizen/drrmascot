<?php

namespace App\Http\Controllers\Command;

use App\Http\Controllers\Controller;
use App\Models\Reporter\CitizenReport;
use App\Models\Reporter\SosEvent;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class ResponseApiController extends Controller
{
    private function authorizeStaff(Request $request): void
    {
        abort_unless(in_array($request->user()->role, ['Coordinator', 'DRRMO Admin'], true), 403);
    }

    private function scoped($query, Request $request)
    {
        return $request->user()->isAdmin() ? $query : $query->where('campus_id', $request->user()->campus_id);
    }

    public function reports(Request $request): JsonResponse
    {
        $this->authorizeStaff($request);
        return response()->json($this->scoped(CitizenReport::with(['reporter', 'campus'])->latest(), $request)
            ->limit(100)->get());
    }

    public function updateReport(Request $request, CitizenReport $report): JsonResponse
    {
        $this->authorizeStaff($request);
        abort_unless($request->user()->isAdmin() || (int) $report->campus_id === (int) $request->user()->campus_id, 404);
        $data = $request->validate(['status' => ['required', Rule::in(['Verified', 'Responding', 'Resolved', 'Rejected'])],
            'note' => ['nullable', 'string', 'max:500']]);
        $transitions = ['New' => ['Verified', 'Rejected'], 'Verified' => ['Responding', 'Rejected'],
            'Responding' => ['Resolved']];
        DB::transaction(function () use ($report, $data, $request, $transitions) {
            $locked = CitizenReport::whereKey($report->getKey())->lockForUpdate()->firstOrFail();
            abort_unless(in_array($data['status'], $transitions[$locked->status] ?? [], true), 422);
            $locked->update(['status' => $data['status']]);
            if (in_array($data['status'], ['Resolved', 'Rejected'], true)) {
                \App\Models\Assignment::where('report_id', $locked->getKey())->whereIn('status', \App\Models\Assignment::ACTIVE)
                    ->update(['status' => 'Cancelled', 'cancelled_at' => now(), 'notes' => 'Case closed by the DRRM Office.']);
            }
            $locked->events()->create(['actor_id' => $request->user()->personnel_id,
                'status' => $data['status'], 'note' => $data['note'] ?? $data['status'] . ' by the DRRM Office.',
                'created_at' => now()]);
        });

        return response()->json(['id' => $report->report_id, 'status' => $data['status']]);
    }

    public function sos(Request $request): JsonResponse
    {
        $this->authorizeStaff($request);
        return response()->json($this->scoped(SosEvent::with(['reporter', 'campus'])
            ->whereNotIn('status', ['Resolved', 'Rejected'])->oldest(), $request)
            ->limit(100)->get());
    }

    public function updateSos(Request $request, SosEvent $sos): JsonResponse
    {
        $this->authorizeStaff($request);
        abort_unless($request->user()->isAdmin() || (int) $sos->campus_id === (int) $request->user()->campus_id, 404);
        $data = $request->validate(['status' => ['required', Rule::in(['Acknowledged', 'Responding', 'Resolved', 'Rejected'])],
            'note' => ['nullable', 'string', 'max:500']]);
        // Rejected: a false alarm or prank, found out at any point before the case is closed.
        $transitions = ['New' => ['Acknowledged', 'Rejected'], 'Acknowledged' => ['Responding', 'Rejected'],
            'Responding' => ['Resolved', 'Rejected']];
        DB::transaction(function () use ($sos, $data, $request, $transitions) {
            $locked = SosEvent::whereKey($sos->getKey())->lockForUpdate()->firstOrFail();
            abort_unless(in_array($data['status'], $transitions[$locked->status] ?? [], true), 422);
            $updates = ['status' => $data['status']];
            if ($data['status'] === 'Acknowledged') {
                $updates += ['acknowledged_by' => $request->user()->personnel_id, 'acknowledged_at' => now()];
            }
            if (in_array($data['status'], ['Resolved', 'Rejected'], true)) {
                $updates['resolved_at'] = now();
            }
            $locked->update($updates);
            if (in_array($data['status'], ['Resolved', 'Rejected'], true)) {
                $this->cancelSosAssignments($locked);
            }
            if ($data['status'] === 'Rejected') {
                $this->log($request, $locked, 'updated', 'rejected SOS #' . $locked->getKey() . ' as a false alarm'
                    . (filled($data['note'] ?? null) ? ': ' . $data['note'] : ''));
            }
        });

        return response()->json(['id' => $sos->sos_id, 'status' => $data['status']]);
    }

    /** Moves an SOS to the Archive (Trash); restoreSos() brings it back. */
    public function destroySos(Request $request, SosEvent $sos): JsonResponse
    {
        $this->authorizeStaff($request);
        abort_unless($request->user()->isAdmin() || (int) $sos->campus_id === (int) $request->user()->campus_id, 404);
        DB::transaction(function () use ($sos, $request) {
            $locked = SosEvent::whereKey($sos->getKey())->lockForUpdate()->firstOrFail();
            $this->cancelSosAssignments($locked);
            $locked->delete();
            $this->log($request, $locked, 'deleted', 'deleted SOS #' . $locked->getKey());
        });

        return response()->json(['id' => $sos->sos_id, 'message' => 'SOS moved to the Archive. You can restore it from there.']);
    }

    public function restoreSos(Request $request, int $sos)
    {
        $this->authorizeStaff($request);
        $sos = SosEvent::onlyTrashed()->findOrFail($sos);
        abort_unless($request->user()->isAdmin() || (int) $sos->campus_id === (int) $request->user()->campus_id, 404);
        $sos->restore();
        $this->log($request, $sos, 'restored', 'restored SOS #' . $sos->getKey());

        return back()->with('status', 'SOS #' . $sos->getKey() . ' restored.');
    }

    private function cancelSosAssignments(SosEvent $sos): void
    {
        \App\Models\Assignment::where('sos_id', $sos->getKey())->whereIn('status', \App\Models\Assignment::ACTIVE)
            ->update(['status' => 'Cancelled', 'cancelled_at' => now(), 'notes' => 'Case closed by the DRRM Office.']);
    }

    private function log(Request $request, SosEvent $sos, string $action, string $description): void
    {
        \App\Models\ActivityLog::create(['personnel_id' => $request->user()->personnel_id, 'subject_type' => 'sos', 'subject_id' => $sos->getKey(),
            'action' => $action, 'description' => mb_substr($request->user()->full_name . ' ' . $description, 0, 255), 'ip_address' => $request->ip()]);
    }
}
