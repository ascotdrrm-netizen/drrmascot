<?php

namespace App\Http\Controllers\Reporter;

use App\Http\Controllers\Controller;
use App\Models\RollCall;
use App\Support\EmergencyNotices;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class RollCallResponseController extends Controller
{
    public function store(Request $request, RollCall $rollCall)
    {
        $data = $request->validate(['answer' => ['required', Rule::in(['Safe', 'Help'])],
            'note' => ['nullable', 'string', 'max:1000'],
            'latitude' => ['nullable', 'numeric', 'between:-90,90', 'required_with:longitude'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180', 'required_with:latitude'],
            'accuracy_m' => ['nullable', 'integer', 'between:0,100000']]);
        $response = DB::transaction(function () use ($rollCall, $request, $data) {
            $locked = RollCall::whereKey($rollCall->getKey())->lockForUpdate()->firstOrFail();
            abort_unless((int) $locked->campus_id === (int) $request->user()->campus_id, 404);
            $response = $locked->responses()->where('reporter_id', $request->user()->personnel_id)->firstOrFail();
            if ($locked->closed_at) {
                throw ValidationException::withMessages(['answer' => 'This roll call has closed. Use SOS or the hotlines if you need help now.']);
            }
            $newHelp = $data['answer'] === 'Help' && $response->answer !== 'Help';
            $response->update($data + ['latitude' => null, 'longitude' => null, 'accuracy_m' => null,
                'note' => null, 'responded_at' => now()]);
            if ($newHelp) {
                EmergencyNotices::record(EmergencyNotices::recipients((int) $locked->campus_id), 'roll_call', (int) $locked->getKey(),
                    (int) $locked->campus_id, $response->full_name." needs help in roll call: {$locked->title}");
            }

            return $response;
        });

        return response()->json(['message' => 'Your safety check-in is saved.', 'answer' => $response->answer]);
    }
}
