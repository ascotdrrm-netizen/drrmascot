<?php

namespace App\Http\Controllers\Command;

use App\Http\Controllers\Controller;
use App\Models\Campus;
use App\Models\CampusStatus;
use App\Models\Personnel;
use App\Support\EmergencyNotices;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class CampusStatusController extends Controller
{
    public function update(Request $request, Campus $campus): JsonResponse
    {
        abort_unless(in_array($request->user()->role, ['Coordinator', 'DRRMO Admin'], true), 403);
        abort_unless($request->user()->isAdmin() || (int) $request->user()->campus_id === (int) $campus->campus_id, 404);
        $data = $request->validate([
            'status' => ['required', Rule::in(['normal', 'advisory', 'suspended', 'evacuate'])],
            'detail' => ['nullable', 'string', 'max:200'],
        ]);
        if ($data['status'] !== 'normal' && blank($data['detail'] ?? null)) {
            throw \Illuminate\Validation\ValidationException::withMessages(['detail' => 'Give people a clear instruction for this status.']);
        }

        $previous = CampusStatus::find($campus->campus_id);
        $status = CampusStatus::updateOrCreate(['campus_id' => $campus->campus_id], [
            'status' => $data['status'],
            'detail' => $data['status'] === 'normal' ? null : $data['detail'],
            'updated_by' => $request->user()->personnel_id,
        ]);

        // A real change reaches the campus's Reporters and staff as a notice
        // that opens the status itself; saving it again unchanged does not.
        if ($previous?->status !== $status->status || $previous?->detail !== $status->detail) {
            $reporters = Personnel::where('role', 'Reporter')->where('campus_id', $campus->campus_id)->where('is_approved', true)->get();
            $recipients = $reporters->concat(EmergencyNotices::recipients($campus->campus_id))
                ->reject(fn (Personnel $person) => $person->is($request->user()))->unique('personnel_id');
            EmergencyNotices::record($recipients, 'campus_status', $campus->campus_id, $campus->campus_id,
                $campus->campus_name.': '.CampusStatus::LABELS[$status->status].($status->detail ? '. '.$status->detail : ''));
        }

        return response()->json(['campus_id' => $campus->campus_id, 'status' => $status->status, 'detail' => $status->detail]);
    }
}
