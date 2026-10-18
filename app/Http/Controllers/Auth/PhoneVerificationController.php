<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\Personnel;
use App\Services\Sms\SmsDriver;
use App\Services\Sms\SmsGatewayInterface;
use App\Support\PhoneNumber;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

class PhoneVerificationController extends Controller
{
    public function send(Request $request, SmsGatewayInterface $sms)
    {
        abort_unless($request->user()->is_approved, 403);
        if (! SmsDriver::isReady()) {
            throw ValidationException::withMessages(['phone' => 'SMS verification is unavailable until the SMS service is configured.']);
        }
        $key = 'phone-code:'.$request->user()->getKey();
        if (RateLimiter::tooManyAttempts($key, 5)) {
            throw ValidationException::withMessages(['phone' => 'Please wait an hour before requesting more codes.']);
        }
        RateLimiter::hit($key, 3600);
        DB::transaction(function () use ($request, $sms) {
            $person = Personnel::whereKey($request->user()->getKey())->lockForUpdate()->firstOrFail();
            if ($person->phone_verified_at) {
                return;
            }
            $phone = PhoneNumber::normalize($person->contact_number);
            if (! $phone) {
                throw ValidationException::withMessages(['phone' => 'Save a valid Philippine mobile number first.']);
            }
            $previous = DB::table('phone_verifications')->where('personnel_id', $person->getKey())->first();
            if ($previous && now()->diffInSeconds($previous->last_sent_at, true) < 60) {
                throw ValidationException::withMessages(['phone' => 'Wait 60 seconds before requesting another code.']);
            }
            $code = (string) random_int(100000, 999999);
            // Save a hash only. Never expose OTPs to the log driver or the browser.
            DB::table('phone_verifications')->updateOrInsert(['personnel_id' => $person->getKey()], [
                'phone_number' => $phone, 'code_hash' => Hash::make($code), 'attempts' => 0,
                'expires_at' => now()->addMinutes(5), 'last_sent_at' => now(),
            ]);
            try {
                $accepted = $sms->send($phone, "ASCOT DRRMS verification code: {$code}. Expires in 5 minutes. Do not share this code.");
            } catch (\Throwable $exception) {
                $accepted = false;
            }
            if (! $accepted) {
                throw ValidationException::withMessages(['phone' => 'The SMS request was not confirmed. Please try again later.']);
            }
        });

        return back()->with('status', 'Verification code requested. Enter the code sent to your saved mobile number.');
    }

    public function verify(Request $request)
    {
        abort_unless($request->user()->is_approved, 403);
        $code = $request->validate(['phone_code' => ['required', 'string', 'regex:/^\d{6}$/']])['phone_code'];
        $error = DB::transaction(function () use ($request, $code) {
            $person = Personnel::whereKey($request->user()->getKey())->lockForUpdate()->firstOrFail();
            $challenge = DB::table('phone_verifications')->where('personnel_id', $person->getKey())->lockForUpdate()->first();
            if (! $challenge || now()->greaterThan($challenge->expires_at) || $challenge->attempts >= 5
                || PhoneNumber::normalize($person->contact_number) !== $challenge->phone_number) {
                return 'The code expired or cannot be used. Request a new code.';
            }
            if (! Hash::check($code, $challenge->code_hash)) {
                DB::table('phone_verifications')->where('personnel_id', $person->getKey())->increment('attempts');

                return 'Incorrect code. Five failed attempts require a new code.';
            }
            $person->forceFill(['phone_verified_at' => now()])->save();
            DB::table('phone_verifications')->where('personnel_id', $person->getKey())->delete();

            return null;
        });
        if ($error) {
            throw ValidationException::withMessages(['phone_code' => $error]);
        }

        return back()->with('status', 'Mobile number verified. You can now receive campus broadcast texts.');
    }
}
