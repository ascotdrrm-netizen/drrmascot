@extends('layouts.mobile')

@section('title', 'Me - ASCOT DRRMS')

@section('content')
    <div class="flex items-center gap-4">
        <span class="flex h-14 w-14 items-center justify-center rounded-full bg-accent-soft text-lg font-semibold text-accent-ink">{{ $reporter['initials'] }}</span>
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

    <x-phone-verification />
    @if(session('status'))<p role="status" class="mt-3 text-sm text-green-700">{{ session('status') }}</p>@endif
    <div class="mt-6 divide-y divide-gray-100 rounded-xl border border-gray-100 bg-white">
        <a href="{{ route('app.reports') }}" class="flex min-h-[3.25rem] items-center justify-between px-4 text-sm font-medium"><span class="flex items-center gap-3"><x-icon name="assignment" class="text-gray-600" /> Your reports</span><x-icon name="chevron_right" class="text-gray-400" /></a>
        <a href="{{ route('app.hotlines') }}" class="flex min-h-[3.25rem] items-center justify-between px-4 text-sm font-medium"><span class="flex items-center gap-3"><x-icon name="call" class="text-gray-600" /> Emergency hotlines</span><x-icon name="chevron_right" class="text-gray-400" /></a>
        <div class="px-2 py-1"><x-theme-toggle label /></div>
    </div>
    @auth
        <form method="POST" action="{{ route('logout') }}" class="mt-6">@csrf<button type="submit" class="btn btn-secondary w-full"><x-icon name="logout" /> Log out</button></form>
    @endauth
    <p class="mt-6 text-center text-xs text-gray-600">Your location is only sent when you press SOS, send a report, or share it in a safety check-in.</p>
@endsection
