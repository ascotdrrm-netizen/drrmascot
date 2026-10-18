@auth
<section class="mt-6 rounded-xl border border-gray-100 bg-white p-4">
    <h2 class="text-base font-semibold">Mobile verification</h2>
    @if(auth()->user()->phone_verified_at)
        <p class="mt-2 text-sm text-green-700">{{ auth()->user()->contact_number }} is verified for campus broadcast texts.</p>
    @else
        <p class="mt-2 text-sm text-gray-600">Verify your saved mobile number to receive campus broadcast texts. {{ auth()->user()->contact_number ?: 'Ask the DRRM Office to update your mobile number first.' }}</p>
        @if(\App\Services\Sms\SmsDriver::isReady())
            <form method="POST" action="{{ route('phone.send') }}" class="mt-3">@csrf<button class="btn btn-secondary" type="submit">Request verification code</button></form>
            <form method="POST" action="{{ route('phone.verify') }}" class="mt-3 flex flex-wrap gap-2">@csrf
                <label class="form-label w-full" for="phone_code">Six-digit code</label>
                <input id="phone_code" name="phone_code" inputmode="numeric" autocomplete="one-time-code" pattern="[0-9]{6}" maxlength="6" required class="form-input" style="max-width: 10rem">
                <button type="submit" class="btn btn-primary">Verify</button>
            </form>
        @else
            <p class="mt-2 text-sm text-gray-500">SMS verification is not configured yet.</p>
        @endif
        @error('phone')<p class="mt-2 text-sm text-red-700">{{ $message }}</p>@enderror
        @error('phone_code')<p class="mt-2 text-sm text-red-700">{{ $message }}</p>@enderror
    @endif
</section>
@endauth
