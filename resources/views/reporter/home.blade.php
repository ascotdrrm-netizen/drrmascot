@extends('layouts.mobile')

@section('title', 'Home - ASCOT DRRM')

@section('content')
    @php
        $mine = collect($statuses)->first(fn ($s) => str_contains($reporter['campus'], $s['campus']));
        $tone = [
            'normal' => ['border-green-200 bg-green-50 text-green-800', 'check_circle'],
            'advisory' => ['border-amber-200 bg-amber-50 text-amber-800', 'warning'],
            'suspended' => ['border-red-200 bg-red-50 text-red-800', 'warning'],
            'evacuate' => ['border-red-200 bg-red-50 text-red-800', 'emergency'],
        ];
        $firstName = strtok($reporter['name'], ' ');
    @endphp

    <h1 class="text-base font-semibold text-gray-700">Good day, {{ $firstName }}</h1>

    {{-- Your campus right now, in one line: status in words + icon, never
         colour alone. Tap for the advisory behind it. --}}
    <a href="{{ route('app.alerts') }}" class="mt-4 flex items-center gap-3 rounded-xl border px-4 py-3 {{ $tone[$mine['status']][0] }}" data-campus-status="{{ $mine['status'] }}">
        <x-icon :name="$tone[$mine['status']][1]" fill class="h-6 w-6 shrink-0" />
        <span class="min-w-0 flex-1">
            <span class="block text-xs font-semibold uppercase tracking-wide">{{ $reporter['campus'] }}</span>
            <span class="block truncate font-semibold">{{ $mine['label'] }}</span>
        </span>
        <x-icon name="chevron_right" class="shrink-0" />
    </a>

    {{-- SOS: the first big thing on the screen, full width. One press opens a
         5-second countdown you can cancel (next screen). --}}
    <a href="{{ route('app.sos') }}" class="mt-4 flex min-h-[5.5rem] items-center gap-4 rounded-2xl bg-red-600 p-4 text-snow active:scale-[.99]" data-sos-entry>
        <span class="flex h-16 w-16 shrink-0 items-center justify-center rounded-full bg-snow/15 ring-4 ring-snow/20">
            <x-icon name="sos" class="h-10 w-10" />
        </span>
        <span class="min-w-0">
            <span class="block text-xl font-bold">Emergency? Press SOS</span>
            <span class="block text-sm text-snow">Sends your location to the DRRM Office and campus security.</span>
        </span>
    </a>

    {{-- A roll call is running: one tap to answer it. --}}
    <div id="reporter-roll-call" @if (auth()->user()?->role === 'Reporter') data-live-refresh @endif>
    @if (($activeRollCall ?? null) || auth()->user()?->role !== 'Reporter')
    <a href="{{ $activeRollCall ? route('app.roll-calls.show', $activeRollCall) : route('app.check-in') }}" class="mt-4 flex items-center justify-between gap-3 rounded-xl border border-accent-line/40 bg-accent-soft p-4" data-roll-call-banner>
        <span class="flex items-center gap-3"><x-icon name="checklist" class="h-6 w-6 shrink-0 text-gray-700" /><span><span class="block text-sm font-semibold">Roll call: are you safe?</span><span class="block text-xs text-gray-600">The DRRM Office is checking on everyone. Tap to answer.</span></span></span>
        <x-icon name="chevron_right" class="shrink-0 text-gray-600" />
    </a>
    @endif
    </div>

    {{-- Report something. --}}
    <section class="mt-8">
        <div class="mb-4 flex items-center justify-between">
            <h2 class="section-title">Report a hazard</h2>
            <a href="{{ route('app.report') }}" class="link inline-flex min-h-[2.75rem] items-center text-sm">All types</a>
        </div>
        <div class="grid grid-cols-3 gap-2">
            @foreach ($categories as $category)
                <a href="{{ route('app.report', ['type' => $category['key']]) }}" class="flex min-h-[5.5rem] flex-col items-center justify-center gap-2 rounded-xl border border-gray-100 bg-white p-2 text-center text-xs font-medium hover:border-gray-300">
                    <x-icon :name="$category['icon']" class="h-7 w-7 text-gray-700" />
                    {{ $category['label'] }}
                </a>
            @endforeach
        </div>
    </section>

    {{-- Advisories. --}}
    <section class="mt-8">
        <div class="mb-4 flex items-center justify-between">
            <h2 class="section-title">Advisories</h2>
            <a href="{{ route('app.alerts') }}" class="link inline-flex min-h-[2.75rem] items-center text-sm">See all</a>
        </div>
        <div class="space-y-2">
            @foreach ($advisories as $advisory)
                <a href="{{ route('app.alerts') }}#advisory-{{ $advisory['id'] }}" class="block rounded-xl border border-gray-100 bg-white p-4">
                    <span class="chip {{ $advisory['tone'] === 'red' ? 'border-red-200 bg-red-50 text-red-700' : 'border-amber-200 bg-amber-50 text-amber-700' }}">
                        <x-icon name="warning" class="h-3.5 w-3.5" /> {{ $advisory['hazard'] }} · {{ $advisory['level'] }}
                    </span>
                    <span class="mt-2 block text-sm font-semibold">{{ $advisory['title'] }}</span>
                    <span class="block text-xs text-gray-600">{{ $advisory['campus'] }} · {{ $advisory['issued']->diffForHumans() }}</span>
                </a>
            @endforeach
        </div>
    </section>

    {{-- Your reports. --}}
    <section class="mt-8">
        <div class="mb-4 flex items-center justify-between">
            <h2 class="section-title">Your reports</h2>
            <a href="{{ route('app.reports') }}" class="link inline-flex min-h-[2.75rem] items-center text-sm">See all</a>
        </div>
        <div class="divide-y divide-gray-100 rounded-xl border border-gray-100 bg-white">
            @foreach ($reports as $report)
                @include('reporter._report-row', ['report' => $report])
            @endforeach
        </div>
    </section>

    <a href="{{ route('app.hotlines') }}" class="mt-8 flex min-h-[3.5rem] items-center justify-between rounded-xl border border-gray-100 bg-white px-4">
        <span class="flex items-center gap-3"><x-icon name="call" class="h-6 w-6 text-gray-700" /><span class="text-sm font-semibold">Emergency hotlines</span></span>
        <x-icon name="chevron_right" class="text-gray-600" />
    </a>
@endsection
