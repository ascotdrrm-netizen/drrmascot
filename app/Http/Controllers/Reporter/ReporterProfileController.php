<?php

namespace App\Http\Controllers\Reporter;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Rules\ValidPhone;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * A Reporter (student or faculty/staff) edits their own photo and mobile
 * number on the Me screen. Name, ID number, campus and email come from
 * registration and stay as the DRRMO checked them. A new number is no longer
 * verified (Personnel resets phone_verified_at), so the Me screen asks for
 * its code before broadcast texts go to it.
 */
class ReporterProfileController extends Controller
{
    public function update(Request $request): RedirectResponse
    {
        $person = Auth::user();

        $data = $request->validate([
            'contact_number' => ['required', 'string', 'max:20', new ValidPhone()],
            'photo' => ['nullable', 'image', 'mimes:jpeg,png,jpg,webp', 'max:5120'],
        ], [
            'contact_number.required' => 'Enter your mobile number: emergency SMS alerts are sent to it.',
            'photo.image' => 'The photo must be a JPG, PNG or WebP image.',
            'photo.mimes' => 'The photo must be a JPG, PNG or WebP image.',
            'photo.max' => 'The photo must be 5 MB or smaller.',
        ]);

        $person->contact_number = $data['contact_number'];
        if ($request->hasFile('photo')) {
            $person->photo_path = $person->storePhoto($request->file('photo'));
        }
        $numberChanged = $person->isDirty('contact_number');
        $person->save();

        ActivityLog::create([
            'personnel_id' => $person->personnel_id,
            'action' => 'updated',
            'subject_type' => 'Personnel',
            'subject_id' => $person->personnel_id,
            'description' => "{$person->full_name} updated their profile",
            'ip_address' => $request->ip(),
        ]);

        return redirect()->route('app.me')->with('status', $numberChanged
            ? 'Profile saved. Verify your new number below so SMS alerts reach it.'
            : 'Profile saved.');
    }
}
