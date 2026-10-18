<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Personnel;
use App\Rules\ValidPassword;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class PasswordResetController extends Controller
{
    public function showForgotPassword(): View
    {
        return view('auth.forgot-password');
    }

    public function sendResetLink(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email'],
        ]);

        // The broker's own per-email 60s throttle (config/auth.php) silently
        // no-ops a too-soon repeat request rather than erroring — combined
        // with the route-level throttle:5,1 middleware below, which caps
        // attempts per IP regardless of which email(s) they target.
        // A mail outage is logged, never shown: the response stays identical.
        try { Password::sendResetLink($data); }
        catch (\Throwable $exception) {
            \Illuminate\Support\Facades\Log::warning('Password reset email could not be sent.', ['error' => $exception->getMessage()]);
        }

        $personnel = Personnel::where('email', $data['email'])->first();

        // Logged for both matched and unmatched emails — mirrors how
        // login_failed is already logged for unknown/incorrect logins. This
        // is an internal, Admin-only audit trail (Activity Log), never
        // reflected back in the HTTP response, so it doesn't undermine the
        // "identical response either way" guarantee below.
        ActivityLog::create([
            'personnel_id' => $personnel?->personnel_id,
            'action' => 'password_reset_requested',
            'subject_type' => $personnel ? 'Personnel' : null,
            'subject_id' => $personnel?->personnel_id,
            'description' => $personnel
                ? "Password reset requested for {$personnel->full_name} ({$data['email']})"
                : "Password reset requested for unregistered email ({$data['email']})",
            'ip_address' => $request->ip(),
        ]);

        // Always the same message regardless of whether the email matched an
        // account, so this endpoint can't be used to enumerate registered
        // accounts by observing a different response.
        return back()->with('status', 'If that email is registered, a reset link has been sent.');
    }

    public function showResetForm(Request $request, string $token): View
    {
        return view('auth.reset-password', [
            'token' => $token,
            'email' => $request->query('email', ''),
        ]);
    }

    public function resetPassword(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'token' => ['required', 'string'],
            'email' => ['required', 'email'],
            'password' => ['required', 'string', 'confirmed', new ValidPassword()],
        ]);

        $ip = $request->ip();

        $status = Password::reset(
            $data,
            function (Personnel $personnel, string $password) use ($ip) {
                $personnel->forceFill([
                    'password_hash' => Hash::make($password),
                ])->save();
                // Every existing sign-in ends, including "keep me signed in".
                \App\Support\Sessions::endOthers($personnel, null);

                ActivityLog::create([
                    'personnel_id' => $personnel->personnel_id,
                    'action' => 'password_reset_completed',
                    'subject_type' => 'Personnel',
                    'subject_id' => $personnel->personnel_id,
                    'description' => "{$personnel->full_name} completed a password reset",
                    'ip_address' => $ip,
                ]);
            }
        );

        if ($status !== Password::PASSWORD_RESET) {
            throw ValidationException::withMessages([
                'email' => [__($status)],
            ]);
        }

        return redirect()->route('login')->with('status', 'Your password has been reset. Please log in.');
    }
}
