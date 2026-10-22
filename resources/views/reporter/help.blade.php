@extends('layouts.mobile')

@section('title', 'Help - ASCOT DRRMS')

{{--
    How to use the phone app (thesis panel round 2). Public and stored for
    offline use by public/sw.js, like the hotlines: it must never contain an
    account name, campus status or unread count.
--}}
@php
    $guides = [
        ['id' => 'report', 'icon' => 'campaign', 'title' => 'Report a hazard', 'steps' => [
            'Tap Report, then what is happening: a fallen tree, exposed wiring, flooding, a fire, bullying and so on.',
            'Say where it is. Use your GPS location or Pick on map, and add a short description and photos.',
            'Tap Send. With no signal, the report waits on your phone and is sent by itself when you are back online.',
            'Follow it under Your reports: Submitted → Verified → Responding → Resolved. The DRRM Office may reject a false or duplicate report and tell you why.',
        ]],
        ['id' => 'sos', 'icon' => 'sos', 'title' => 'SOS: when you need help now', 'steps' => [
            'Press and hold the SOS button. Your location is sent only when you press SOS.',
            'The DRRM Office sees it at once and acknowledges it, so you know someone saw it.',
            'Keep your phone with you and answer when they call. The SOS screen shows when help is on the way.',
            'For a life-threatening emergency, also call 911 or a number in Emergency hotlines.',
        ]],
        ['id' => 'check-in', 'icon' => 'checklist', 'title' => 'Roll call: tell the office you are safe', 'steps' => [
            'After an earthquake, during a typhoon or at the end of a drill, the DRRM Office may start a roll call. You get a notice.',
            'Open it and tap "I am safe", or "I need help" with a short note. You can share your location.',
            'You can change your answer until the roll call is closed.',
        ]],
        ['id' => 'alerts', 'icon' => 'notifications', 'title' => 'Alerts, advisories and drills', 'steps' => [
            'Alerts shows your campus status (Normal, Advisory in effect, Classes suspended or Evacuate) and what to do.',
            'Advisories from the DRRM Office (typhoon signals, rainfall warnings) are listed while they are in effect.',
            'Upcoming drills for your campus, with the date, time and venue, are listed under Upcoming drills; the next one is also on Home.',
            'A number on the Alerts tab means you have new notices.',
        ]],
        ['id' => 'map', 'icon' => 'map', 'title' => 'Map and hazardous areas', 'steps' => [
            'Map shows your campus and the areas the DRRM Office rated Moderate Risk or higher, with a coloured pin.',
            '🟡 Moderate Risk: take care. 🟠 High Risk: limit your use of the area. 🔴 Critical Risk: keep away until it is made safe.',
            'Tap Show where I am to see your own position.',
        ]],
        ['id' => 'hotlines', 'icon' => 'call', 'title' => 'Emergency hotlines', 'steps' => [
            'Emergency hotlines lists the campus, municipal and national numbers. Tap a number to call.',
            'Open it once while online; afterwards it opens even with no signal.',
        ]],
        ['id' => 'install', 'icon' => 'mobile_arrow_down', 'title' => 'Install the app', 'steps' => [
            'Android (Chrome): tap Install on the welcome screen, or the browser menu → Install app.',
            'iPhone (Safari): tap Share → Add to Home Screen.',
            'The app then opens from your home screen like any other app.',
        ]],
        ['id' => 'privacy', 'icon' => 'lock', 'title' => 'Your information', 'steps' => [
            'Only the ASCOT DRRM Office and your campus coordinators see your reports, SOS and roll-call answers.',
            'Your location is sent only with a report, an SOS or a roll-call answer that you choose to share it with.',
        ]],
    ];
@endphp

@section('content')
    <h1 class="page-title">How to use the app</h1>
    <p class="text-sm text-gray-600">Short guides. They work even without signal once opened.</p>
    <div class="mt-4 space-y-2" data-reporter-help>
        @foreach ($guides as $guide)
            <details id="{{ $guide['id'] }}" class="group scroll-mt-20 rounded-xl border border-gray-100 bg-white" @if ($loop->first) open @endif>
                <summary class="flex min-h-[3.25rem] cursor-pointer list-none items-center justify-between gap-3 px-4 text-sm font-semibold">
                    <span class="flex items-center gap-3"><x-icon :name="$guide['icon']" class="h-5 w-5 text-gray-700" /> {{ $guide['title'] }}</span>
                    <x-icon name="keyboard_arrow_down" class="text-gray-600 transition-transform group-open:rotate-180" />
                </summary>
                <ol class="list-decimal space-y-2 px-4 pb-4 pl-9 text-sm leading-6 text-gray-700">
                    @foreach ($guide['steps'] as $step)
                        <li>{{ $step }}</li>
                    @endforeach
                </ol>
            </details>
        @endforeach
    </div>
    <a href="{{ route('app.hotlines') }}" class="mt-6 flex min-h-[3.5rem] items-center justify-between rounded-xl border border-gray-100 bg-white px-4">
        <span class="flex items-center gap-3"><x-icon name="call" class="h-6 w-6 text-gray-700" /><span class="text-sm font-semibold">Emergency hotlines</span></span>
        <x-icon name="chevron_right" class="text-gray-600" />
    </a>
@endsection
