<?php

namespace App\Http\Controllers\Command;

use App\Http\Controllers\Controller;
use App\Models\StaffNotification;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class StaffNotificationController extends Controller
{
    public function summary(Request $request): \Illuminate\Http\JsonResponse
    {
        return response()->json(['unread' => StaffNotification::where('personnel_id', $request->user()->personnel_id)
            ->whereNull('read_at')->count()]);
    }

    public function index(Request $request): View
    {
        return view('command.notifications', [
            'notifications' => StaffNotification::where('personnel_id', $request->user()->personnel_id)
                ->latest()->limit(100)->get(),
            'routePrefix' => $request->user()->isAdmin() ? 'admin' : 'coordinator',
        ]);
    }

    public function open(Request $request, StaffNotification $notification): RedirectResponse
    {
        abort_unless((int) $notification->personnel_id === (int) $request->user()->personnel_id, 404);
        if ($notification->read_at === null) {
            $notification->update(['read_at' => now()]);
        }
        $prefix = $request->user()->isAdmin() ? 'admin' : 'coordinator';

        if ($notification->kind === 'broadcast') {
            return redirect()->route($prefix . '.broadcasts.show', $notification->subject_id);
        }

        if ($notification->kind === 'campus_status') {
            return redirect()->route($prefix . '.command.campus-status');
        }

        if ($notification->kind === 'roll_call') {
            return redirect()->route($prefix . '.roll-calls.show', $notification->subject_id);
        }
        if ($notification->kind === 'incident') {
            $incident = \App\Models\Incident\Incident::findOrFail($notification->subject_id);
            // Coordinators can open only incidents they or the Admin recorded; keep other notices on their list.
            if (! \App\Support\RecordAccess::allows($incident, $request->user())) {
                return redirect()->route($prefix . '.incidents.index');
            }
            return redirect()->route($prefix . '.incidents.show', $incident);
        }
        return redirect()->route($prefix . ($notification->kind === 'sos' ? '.command.sos' : '.command.inbox'));
    }
}
