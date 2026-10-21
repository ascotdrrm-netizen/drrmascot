@extends('layouts.mobile')

@section('title', 'Me - ASCOT DRRMS')

@section('content')
    <div class="flex items-center gap-4">
        @if (! empty($reporter['photo']))
            <img src="{{ $reporter['photo'] }}" alt="Your profile photo" class="h-14 w-14 shrink-0 rounded-full object-cover">
        @else
            <span class="flex h-14 w-14 shrink-0 items-center justify-center rounded-full bg-accent-soft text-lg font-semibold text-accent-ink">{{ $reporter['initials'] }}</span>
        @endif
        <div>
            <h1 class="page-title">{{ $reporter['name'] }}</h1>
            <p class="text-sm text-gray-600">{{ $reporter['role'] }} · {{ $reporter['id_number'] }}</p>
        </div>
    </div>

    <dl class="mt-6 divide-y divide-gray-100 rounded-xl border border-gray-100 bg-white text-sm">
        <div class="flex justify-between gap-4 p-4"><dt class="text-gray-600">Campus</dt><dd class="text-right font-medium">{{ $reporter['campus'] }}</dd></div>
        <div class="flex justify-between gap-4 p-4"><dt class="text-gray-600">School</dt><dd class="text-right font-medium">{{ $reporter['school'] }}</dd></div>
        <div class="flex justify-between gap-4 p-4"><dt class="text-gray-600">Phone</dt><dd class="flex items-center gap-1 font-medium">{{ $reporter['phone'] }} @if ($reporter['phone_verified'])<span class="chip border-green-200 bg-green-50 text-green-700"><x-icon name="verified_user" class="h-3.5 w-3.5" /> Verified</span>@endif</dd></div>
        <div class="flex justify-between gap-4 p-4"><dt class="text-gray-600">Email</dt><dd class="text-right font-medium">{{ $reporter['email'] }}</dd></div>
    </dl>

    @if(session('status'))<p role="status" class="mt-3 text-sm text-green-700">{{ session('status') }}</p>@endif

    @auth
        @php $editErrors = $errors->hasAny(['photo', 'contact_number']); @endphp
        <details class="mt-6 rounded-xl border border-gray-100 bg-white" @if ($editErrors) open @endif>
            <summary class="flex min-h-[3.25rem] cursor-pointer items-center justify-between px-4 text-sm font-medium">
                <span class="flex items-center gap-3"><x-icon name="edit" class="text-gray-600" /> Edit profile</span>
                <x-icon name="chevron_right" class="text-gray-400" />
            </summary>
            <form method="POST" action="{{ route('app.me.update') }}" enctype="multipart/form-data" class="space-y-4 border-t border-gray-100 p-4">
                @csrf
                <div>
                    <label for="photo" class="form-label">Profile photo</label>
                    <input id="photo" name="photo" type="file" accept="image/jpeg,image/png,image/webp"
                           class="block w-full text-sm text-gray-700 file:mr-4 file:rounded-full file:border-0 file:bg-accent-soft file:px-4 file:py-2 file:text-sm file:font-medium file:text-accent-ink">
                    <p class="form-help">JPG, PNG or WebP, up to 5 MB. Leave empty to keep your current photo.</p>
                    @error('photo')<p class="form-error">{{ $message }}</p>@enderror
                </div>
                <div>
                    <label for="contact_number" class="form-label">Mobile number</label>
                    <input id="contact_number" name="contact_number" type="tel" inputmode="numeric" maxlength="13" pattern="(09\d{9}|\+?639\d{9})" data-digits
                           autocomplete="tel" value="{{ old('contact_number', $reporter['contact_number'] ?? '') }}" class="form-input" required placeholder="09171234567">
                    <p class="form-help">Emergency SMS alerts go to this number. A new number must be verified again below.</p>
                    @error('contact_number')<p class="form-error">{{ $message }}</p>@enderror
                </div>
                <button type="submit" class="btn btn-primary w-full">Save</button>
            </form>
        </details>
    @endauth

    <x-phone-verification />
    <div class="mt-6 divide-y divide-gray-100 rounded-xl border border-gray-100 bg-white">
        <a href="{{ route('app.reports') }}" class="flex min-h-[3.25rem] items-center justify-between px-4 text-sm font-medium"><span class="flex items-center gap-3"><x-icon name="assignment" class="text-gray-600" /> Your reports</span><x-icon name="chevron_right" class="text-gray-400" /></a>
        <a href="{{ route('app.hotlines') }}" class="flex min-h-[3.25rem] items-center justify-between px-4 text-sm font-medium"><span class="flex items-center gap-3"><x-icon name="call" class="text-gray-600" /> Emergency hotlines</span><x-icon name="chevron_right" class="text-gray-400" /></a>
        <a href="{{ route('app.help') }}" class="flex min-h-[3.25rem] items-center justify-between px-4 text-sm font-medium"><span class="flex items-center gap-3"><x-icon name="help" class="text-gray-600" /> How to use the app</span><x-icon name="chevron_right" class="text-gray-400" /></a>
        <div class="px-2 py-1"><x-theme-toggle label /></div>
    </div>
    @auth
        <form method="POST" action="{{ route('logout') }}" class="mt-6" data-confirm-form="You'll need to sign in again to send reports or an SOS from this phone." data-confirm-title="Log out?" data-confirm-button="Log out" data-confirm-focus="cancel">@csrf<button type="submit" class="btn btn-secondary w-full"><x-icon name="logout" /> Log out</button></form>
    @endauth
    <p class="mt-6 text-center text-xs text-gray-600">Your location is only sent when you press SOS, send a report, or share it in a safety check-in.</p>
@endsection
