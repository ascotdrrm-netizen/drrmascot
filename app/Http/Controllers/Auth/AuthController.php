<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Campus;
use App\Models\Personnel;
use App\Models\School;
use App\Http\Requests\RegisterRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class AuthController extends Controller
{
    public function showLogin(): View
    {
        return view('auth.login');
    }

    public function login(Request $request): RedirectResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        $throttleKey = Str::lower($credentials['email']).'|'.$request->ip();

        if (RateLimiter::tooManyAttempts($throttleKey, 5)) {
            $seconds = RateLimiter::availableIn($throttleKey);

            throw ValidationException::withMessages([
                'email' => "Too many login attempts. Please try again in {$seconds} seconds.",
            ]);
        }

        if (! Auth::attempt($credentials, $request->boolean('remember'))) {
            RateLimiter::hit($throttleKey, 60);

            ActivityLog::create([
                'personnel_id' => null,
                'action' => 'login_failed',
                'subject_type' => null,
                'subject_id' => null,
                // Masked: people sometimes type a password into the email box.
                'description' => 'Failed login attempt for '.self::maskEmail($credentials['email']),
                'ip_address' => $request->ip(),
            ]);

            throw ValidationException::withMessages([
                'email' => 'These credentials do not match our records.',
            ]);
        }

        // Reporters and Coordinators verify their email first. The DRRMO Admin
        // doesn't, so the office can never be locked out of its own system.
        if (in_array(Auth::user()->role, ['Reporter', 'Coordinator'], true) && ! Auth::user()->hasVerifiedEmail()) {
            $message = Auth::user()->role === 'Coordinator'
                ? 'Please verify your email before signing in. Use the link we emailed you, or request a new one below.'
                : 'Please verify your email before signing in.';
            Auth::logout();

            throw ValidationException::withMessages(['email' => $message]);
        }

        // Correct password, but the account's approval stages are incomplete.
        if (! Auth::user()->is_approved) {
            $message = Auth::user()->role === 'Reporter' && Auth::user()->validated_by_coordinator_at === null
                ? 'Your account is waiting for your campus coordinator to validate it.'
                : 'Your account is waiting for DRRMO approval.';
            Auth::logout();

            throw ValidationException::withMessages(['email' => $message]);
        }

        RateLimiter::clear($throttleKey);
        $request->session()->regenerate();

        ActivityLog::create([
            'personnel_id' => Auth::id(),
            'action' => 'login',
            'subject_type' => null,
            'subject_id' => null,
            'description' => Auth::user()->full_name . ' logged in',
            'ip_address' => $request->ip(),
        ]);

        return $this->redirectForRole(Auth::user());
    }

    public function showRegister(): View
    {
        return view('auth.register', [
            'campuses' => Campus::orderBy('campus_name')->get(),
            'schools' => School::forDropdowns(),
        ]);
    }

    public function register(RegisterRequest $request): RedirectResponse
    {
        $data = $request->validated();

        // Public self-registration can only ever create a Coordinator — role
        // is never read from request input. DRRMO Admin accounts are created
        // by an existing Admin via User Management, not by self-registering.
        // It waits for DRRMO approval (User Management) before it can sign in,
        // so registering does not sign the person in.
        $personnel = (new Personnel([
            'campus_id' => $data['campus_id'],
            'school_id' => $data['school_id'] ?? null,
            'full_name' => $request->fullName(),
            'role' => 'Coordinator',
            'email' => $data['email'],
            'password_hash' => Hash::make($data['password']),
            'contact_number' => $data['contact_number'] ?? null,
        ]))->forceFill(['is_approved' => false]);
        $personnel->save();

        ActivityLog::create([
            'personnel_id' => null,
            'action' => 'account_registered',
            'subject_type' => 'personnel',
            'subject_id' => $personnel->personnel_id,
            'description' => "{$personnel->full_name} ({$personnel->email}) registered and is waiting for approval",
            'ip_address' => $request->ip(),
        ]);

        // The email must be verified before signing in (login()). A failed
        // send never blocks registration; the login page can request another link.
        try { $personnel->sendEmailVerificationNotification(); }
        catch (\Throwable $exception) {
            \Illuminate\Support\Facades\Log::warning('Staff verification email could not be sent.', ['personnel_id' => $personnel->getKey()]);
        }

        return redirect()->route('login')
            ->with('status', 'Registration received. Verify your email with the link we sent you, then sign in once the DRRMO approves your account.');
    }

    /** Login page "Resend verification email" for a Coordinator. Says the same whether or not the account exists. */
    public function resendVerification(Request $request): RedirectResponse
    {
        $email = mb_strtolower(trim($request->validate(['email' => ['required', 'email', 'max:100']])['email']));
        $key = 'verify-email:'.hash('sha256', $email);
        if (! RateLimiter::tooManyAttempts($key, 1)) {
            RateLimiter::hit($key, 60);
            $person = Personnel::where('email', $email)->where('role', 'Coordinator')->whereNull('email_verified_at')->first();
            if ($person) {
                try { $person->sendEmailVerificationNotification(); }
                catch (\Throwable $exception) { \Illuminate\Support\Facades\Log::warning('Staff verification resend failed.', ['personnel_id' => $person->getKey()]); }
            }
        }

        return redirect()->route('login')->with('status', 'If an unverified Coordinator account uses that email, a verification link has been requested. Check your inbox and spam folder.');
    }

    public function logout(Request $request): RedirectResponse
    {
        ActivityLog::create([
            'personnel_id' => Auth::id(),
            'action' => 'logout',
            'subject_type' => null,
            'subject_id' => null,
            'description' => Auth::user()->full_name . ' logged out',
            'ip_address' => $request->ip(),
        ]);

        Auth::logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect('/')->with('logged_out', true);
    }

    /** "juan.cruz@gmail.com" → "ju***@gmail.com": enough to spot a pattern, not to read the address. */
    protected static function maskEmail(string $email): string
    {
        [$local, $domain] = array_pad(explode('@', $email, 2), 2, '');

        return mb_substr($local, 0, 2).'***'.($domain !== '' ? '@'.$domain : '');
    }

    protected function redirectForRole(Personnel $personnel): RedirectResponse
    {
        return match ($personnel->role) {
            'DRRMO Admin' => redirect('/admin/dashboard'),
            'Coordinator' => redirect('/coordinator/dashboard'),
            'Reporter' => redirect('/app'),
            default => redirect('/login'),
        };
    }
}
