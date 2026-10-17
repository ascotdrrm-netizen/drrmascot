<?php

namespace App\Http\Controllers\Reporter;

use App\Http\Controllers\Controller;
use App\Models\Reporter\CitizenReport;
use App\Models\Reporter\SosEvent;
use App\Support\Files;
use App\Support\EmergencyNotices;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ResponseApiController extends Controller
{
    public function syncReport(Request $request): JsonResponse
    {
        $owner = $request->validate(['owner_id' => ['required', 'integer'], 'owner_campus_id' => ['required', 'integer']]);
        if ((int) $owner['owner_id'] !== (int) $request->user()->getKey()
            || (int) $owner['owner_campus_id'] !== (int) $request->user()->campus_id) {
            return response()->json(['message' => 'This saved report belongs to a different account or campus. Sign in with its original account to resume.'], 409);
        }

        return $this->storeReport($request);
    }


    public function reports(Request $request): JsonResponse
    {
        return response()->json(CitizenReport::where('reporter_id', $request->user()->personnel_id)
            ->latest()->limit(100)->get());
    }

    public function report(Request $request, CitizenReport $report): JsonResponse
    {
        abort_unless((int) $report->reporter_id === (int) $request->user()->personnel_id, 404);

        return response()->json($report->load(['events', 'photos']));
    }

    public function storeReport(Request $request): JsonResponse
    {
        $data = $request->validate([
            'client_key' => ['required', 'uuid'],
            'category' => ['required', Rule::in(array_column(config('report_categories'), 'key'))],
            'place' => ['nullable', 'string', 'max:255'],
            'latitude' => ['nullable', 'numeric', 'between:-90,90', 'required_with:longitude'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180', 'required_with:latitude'],
            'accuracy_m' => ['nullable', 'integer', 'min:0', 'max:100000'],
            'description' => ['required', 'string', 'min:10', 'max:5000'],
            'people_injured' => ['nullable', 'integer', 'min:0', 'max:99999'],
            'urgent' => ['sometimes', 'boolean'],
            'photos' => ['sometimes', 'array', 'max:3'],
            'photos.*' => ['required', 'image', 'max:10240'],
            'captions' => ['sometimes', 'array', 'max:3'],
            'captions.*' => ['nullable', 'string', 'max:150'],
            'proof' => ['nullable', 'file', 'mimes:jpg,jpeg,png,webp,pdf', 'max:10240'],
        ]);
        if (blank($data['place'] ?? null) && (! isset($data['latitude']) || ! isset($data['longitude']))) {
            throw ValidationException::withMessages(['place' => 'Describe the place or share your GPS location.']);
        }

        $user = $request->user();
        $existing = CitizenReport::where('reporter_id', $user->personnel_id)->where('client_key', $data['client_key'])->first();
        if ($existing) {
            return response()->json(['id' => $existing->report_id, 'status' => $existing->status], 200);
        }

        $stored = [];
        try {
            $report = DB::transaction(function () use ($data, $request, $user, &$stored) {
                $report = CitizenReport::create([
                    'reporter_id' => $user->personnel_id,
                    'campus_id' => $user->campus_id,
                    'client_key' => $data['client_key'],
                    'category' => $data['category'],
                    'place' => $data['place'] ?? null,
                    'latitude' => $data['latitude'] ?? null,
                    'longitude' => $data['longitude'] ?? null,
                    'accuracy_m' => $data['accuracy_m'] ?? null,
                    'description' => $data['description'],
                    'people_injured' => $data['people_injured'] ?? null,
                    'urgent' => $data['urgent'] ?? false,
                    'status' => 'New',
                ]);
                foreach ($request->file('photos', []) as $index => $photo) {
                    $path = Files::store($photo, 'reports');
                    $stored[] = $path;
                    $report->photos()->create(['path' => $path, 'caption' => $data['captions'][$index] ?? null]);
                }
                if ($request->hasFile('proof')) {
                    $path = Files::store($request->file('proof'), 'attachments');
                    $stored[] = $path;
                    $report->update(['proof_path' => $path]);
                }
                $report->events()->create(['actor_id' => $user->personnel_id,
                    'status' => 'New', 'note' => 'Your report reached the DRRM Office.', 'created_at' => now()]);
                EmergencyNotices::record(EmergencyNotices::recipients((int) $user->campus_id),
                    'report', (int) $report->report_id, (int) $user->campus_id,
                    ($report->urgent ? 'Urgent report' : 'New report') . " #{$report->report_id}: {$user->full_name} reported {$report->category} at " . ($report->place ?: 'a GPS location') . '.');

                return $report;
            });
        } catch (\Throwable $exception) {
            Files::delete($stored);
            if ($exception instanceof QueryException) {
                $existing = CitizenReport::where('reporter_id', $user->personnel_id)
                    ->where('client_key', $data['client_key'])->first();
                if ($existing) {
                    return response()->json(['id' => $existing->report_id, 'status' => $existing->status]);
                }
            }
            throw $exception;
        }

        return response()->json(['id' => $report->report_id, 'status' => $report->status], 201);
    }

    public function sos(Request $request): JsonResponse
    {
        return response()->json(SosEvent::where('reporter_id', $request->user()->personnel_id)
            ->latest()->limit(20)->get());
    }

    public function storeSos(Request $request): JsonResponse
    {
        $data = $request->validate([
            'client_key' => ['required', 'uuid'],
            'type' => ['nullable', Rule::in(['Medical', 'Fire', 'Trapped', 'Security', 'Other'])],
            'place' => ['nullable', 'string', 'max:255'],
            'latitude' => ['nullable', 'numeric', 'between:-90,90', 'required_with:longitude'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180', 'required_with:latitude'],
            'accuracy_m' => ['nullable', 'integer', 'min:0', 'max:100000'],
        ]);

        $user = $request->user();
        $existing = SosEvent::where('reporter_id', $user->personnel_id)->where('client_key', $data['client_key'])->first();
        if ($existing) {
            return response()->json(['id' => $existing->sos_id, 'status' => $existing->status], 200);
        }
        $recipients = EmergencyNotices::recipients((int) $user->campus_id);
        try {
            $sos = DB::transaction(function () use ($data, $user, $recipients) {
                $sos = SosEvent::create($data + ['reporter_id' => $user->personnel_id, 'campus_id' => $user->campus_id, 'status' => 'New']);
                EmergencyNotices::record($recipients, 'sos', (int) $sos->sos_id, (int) $user->campus_id,
                    "SOS #{$sos->sos_id}: {$user->full_name} needs help at " . ($sos->place ?: 'a GPS location') . '.');

                return $sos;
            });
        } catch (QueryException $exception) {
            $existing = SosEvent::where('reporter_id', $user->personnel_id)->where('client_key', $data['client_key'])->first();
            if ($existing) {
                return response()->json(['id' => $existing->sos_id, 'status' => $existing->status]);
            }
            throw $exception;
        }
        EmergencyNotices::textSos($recipients, (int) $sos->sos_id,
            $user->campus?->campus_name ?: 'ASCOT campus', $user->full_name);

        return response()->json(['id' => $sos->sos_id, 'status' => $sos->status], 201);
    }
}
