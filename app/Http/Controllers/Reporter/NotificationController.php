<?php

namespace App\Http\Controllers\Reporter;

use App\Http\Controllers\Controller;
use App\Models\StaffNotification;
use Illuminate\Http\Request;

class NotificationController extends Controller
{
    public function summary(Request $request)
    {
        return response()->json(['unread' => StaffNotification::where('personnel_id', $request->user()->getKey())
            ->where('campus_id', $request->user()->campus_id)->whereNull('read_at')->count()]);
    }

    public function open(Request $request, StaffNotification $notification)
    {
        abort_unless((int) $notification->personnel_id === (int) $request->user()->getKey()
            && (int) $notification->campus_id === (int) $request->user()->campus_id, 404);
        $notification->update(['read_at' => $notification->read_at ?? now()]);

        return match ($notification->kind) {
            'broadcast' => redirect()->route('app.broadcasts.show', $notification->subject_id),
            'report_update' => redirect()->route('app.reports.show', $notification->subject_id),
            'sos_update' => redirect()->route('app.sos.status', ['sos' => $notification->subject_id]),
            default => redirect()->route('app.alerts'),
        };
    }
}
