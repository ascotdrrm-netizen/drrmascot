<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\HandlesCampusScoping;
use App\Models\ActivityLog;
use App\Models\Personnel;
use App\Rules\ValidName;
use App\Rules\ValidPassword;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class ProfileController extends Controller
{
    use HandlesCampusScoping;

    public function show(): View
    {
        return view('profile.show', [
            'personnel' => Auth::user(),
            'routePrefix' => $this->routePrefix(),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $personnel = Auth::user();

        $data = $request->validate([
            'full_name' => ['required', 'string', 'max:150', new ValidName()],
            'contact_number' => ['nullable', 'string', 'max:20'],
            'photo' => ['nullable', 'image', 'mimes:jpeg,png,jpg,webp', 'max:5120'],
        ]);

        $attributes = [
            'full_name' => $data['full_name'],
            'contact_number' => $data['contact_number'] ?? null,
        ];

        if ($request->hasFile('photo')) {
            if ($personnel->photo_path) {
                \App\Support\Files::delete($personnel->photo_path);
            }

            $attributes['photo_path'] = $this->storePhoto($request->file('photo'), $personnel);
        }

        $personnel->update($attributes);

        ActivityLog::create([
            'personnel_id' => $personnel->personnel_id,
            'action' => 'updated',
            'subject_type' => 'Personnel',
            'subject_id' => $personnel->personnel_id,
            'description' => "{$personnel->full_name} updated their profile",
            'ip_address' => $request->ip(),
        ]);

        return redirect()
            ->route("{$this->routePrefix()}.profile.show")
            ->with('status', 'Profile updated.');
    }

    public function updatePassword(Request $request): RedirectResponse
    {
        $personnel = Auth::user();

        $data = $request->validate([
            'current_password' => ['required', 'string'],
            'password' => ['required', 'string', 'confirmed', new ValidPassword()],
        ]);

        if (! Hash::check($data['current_password'], $personnel->password_hash)) {
            throw ValidationException::withMessages([
                'current_password' => 'Your current password is incorrect.',
            ]);
        }

        $personnel->update([
            'password_hash' => Hash::make($data['password']),
        ]);

        ActivityLog::create([
            'personnel_id' => $personnel->personnel_id,
            'action' => 'updated',
            'subject_type' => 'Personnel',
            'subject_id' => $personnel->personnel_id,
            'description' => "{$personnel->full_name} changed their password",
            'ip_address' => $request->ip(),
        ]);

        return redirect()
            ->route("{$this->routePrefix()}.profile.show")
            ->with('status', 'Password changed.');
    }

    protected function storePhoto(UploadedFile $photo, Personnel $personnel): string
    {
        $slug = Str::slug($personnel->full_name);

        if ($slug === '') {
            $slug = 'personnel';
        }

        $filename = sprintf(
            '%s-%d-%s.%s',
            $slug,
            $personnel->personnel_id,
            Str::random(6),
            $photo->getClientOriginalExtension()
        );

        return $photo->storeAs('personnel', $filename, \App\Support\Files::diskName());
    }
}
