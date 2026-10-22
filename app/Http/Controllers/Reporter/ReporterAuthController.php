<?php

namespace App\Http\Controllers\Reporter;

use App\Http\Controllers\Controller;
use App\Models\Personnel;
use App\Rules\SchoolOnCampus;
use App\Rules\ValidName;
use App\Rules\ValidPassword;
use App\Support\PersonName;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;

class ReporterAuthController extends Controller
{
    public function register(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'reporter_kind' => ['required', Rule::in(['Student', 'Staff'])],
            'first_name' => ['required', 'string', 'max:100', new ValidName()],
            'middle_name' => ['nullable', 'string', 'max:100', new ValidName()],
            'last_name' => ['required', 'string', 'max:100', new ValidName()],
            'id_number' => ['required', 'string', 'max:40'],
            'campus_id' => ['required', 'exists:campuses,campus_id'],
            'school_id' => ['required', new SchoolOnCampus((int) $request->input('campus_id'))],
            'contact_number' => ['required', 'string', 'max:20', new \App\Rules\ValidPhone()],
            'email' => ['required', 'email', 'max:100', 'unique:personnel,email'],
            'recovery_email' => ['nullable', 'email', 'max:100', 'different:email'],
            'password' => ['required', 'confirmed', new ValidPassword()],
            'privacy_consent' => ['accepted'],
        ]);
        $name = PersonName::withMiddleName($data['first_name'], $data['middle_name'] ?? null, $data['last_name']);
        if (mb_strlen($name) > PersonName::MAX_LENGTH) {
            return back()->withErrors(['last_name' => 'The full name is too long.'])->withInput();
        }

        $reporter = (new Personnel([
            'campus_id' => $data['campus_id'], 'school_id' => $data['school_id'],
            'full_name' => $name, 'role' => 'Reporter', 'email' => $data['email'],
            'password_hash' => Hash::make($data['password']), 'contact_number' => $data['contact_number'],
        ]))->forceFill([
            'is_approved' => false, 'reporter_kind' => $data['reporter_kind'],
            'id_number' => $data['id_number'], 'recovery_email' => $data['recovery_email'] ?? null,
            'privacy_consented_at' => now(),
        ]);
        $reporter->save();
        try { $reporter->sendEmailVerificationNotification(); }
        catch (\Throwable $exception) {
            \Illuminate\Support\Facades\Log::warning('Reporter verification email could not be sent.', ['personnel_id' => $reporter->getKey()]);
            return redirect()->route('app.login')->with('status', 'Account created. The verification email could not be sent. Use Resend verification email below to try again.');
        }

        return redirect()->route('app.register')->with('status', 'Check your email for a verification link.');
    }

    public function resend(Request $request): RedirectResponse
    {
        $email = $request->validate(['email' => ['required', 'email', 'max:100']])['email'];
        $key = 'verify-email:'.hash('sha256', mb_strtolower(trim($email)));
        if (! \Illuminate\Support\Facades\RateLimiter::tooManyAttempts($key, 1)) {
            \Illuminate\Support\Facades\RateLimiter::hit($key, 60);
            $person = Personnel::where('email', mb_strtolower(trim($email)))->where('role', 'Reporter')->whereNull('email_verified_at')->first();
            if ($person) {
                try { $person->sendEmailVerificationNotification(); }
                catch (\Throwable $exception) { \Illuminate\Support\Facades\Log::warning('Reporter verification resend failed.', ['personnel_id' => $person->getKey()]); }
            }
        }
        return redirect()->route('app.login')->with('status', 'If an unverified Reporter account uses that email, a verification link has been requested. Check your inbox and spam folder.');
    }

    public function verify(Request $request, int $id, string $hash): RedirectResponse
    {
        $reporter = Personnel::findOrFail($id);
        abort_unless(hash_equals(sha1($reporter->getEmailForVerification()), $hash), 403);
        if (! $reporter->hasVerifiedEmail()) {
            $reporter->markEmailAsVerified();
        }

        if ($reporter->role !== 'Reporter') {
            return redirect()->route('login')->with('status', $reporter->is_approved
                ? 'Email verified. You can sign in now.'
                : 'Email verified. You can sign in once the DRRMO approves your account.');
        }
        return redirect()->route('app.login')->with('status', 'Email verified. Your campus coordinator and the DRRMO Admin still need to approve your account.');
    }

    public function sendOwnVerification(Request $request): RedirectResponse
    {
        $person = $request->user();
        abort_unless($person->is_approved, 403);
        if (! $person->hasVerifiedEmail()) {
            $key = 'verify-email:'.hash('sha256', $person->email);
            if (\Illuminate\Support\Facades\RateLimiter::tooManyAttempts($key, 1)) return back()->with('status', 'Please wait a minute before requesting another link.');
            \Illuminate\Support\Facades\RateLimiter::hit($key, 60);
            try { $person->sendEmailVerificationNotification(); }
            catch (\Throwable $exception) { return back()->withErrors(['verification_email' => 'The verification email could not be sent. Please try again later.']); }
        }
        return back()->with('status', 'Check your account email for its verification link.');
    }
}
