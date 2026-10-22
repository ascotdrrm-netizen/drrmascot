@extends('layouts.mobile')

@section('title', 'SOS status - ASCOT DRRMS')

@section('content')
    <div class="rounded-2xl border border-red-200 bg-red-50 p-5 text-red-800" role="status">
        <p class="flex items-center gap-2 text-xs font-semibold uppercase tracking-wide"><x-icon name="sos" class="h-5 w-5" /> SOS sent · {{ $sos['type'] }}</p>
        <p class="mt-1 text-2xl font-semibold">{{ match ($sos['status'] ?? 'Responding') {
            'New' => 'SOS received', 'Acknowledged' => 'SOS acknowledged',
            'Resolved' => 'Response completed', 'Rejected' => 'Marked as a false alarm',
            'Closed' => 'Closed by the DRRM Office', default => 'Help is coming',
        } }}</p>
        @php
            // Closed (resolved, false alarm, closed by the office): the clock stops at that moment.
            $endedAt = $sos['ended_at'] ?? null;
            $took = $endedAt ? max(0, $endedAt->getTimestamp() - $sos['sent_at']->getTimestamp()) : null;
        @endphp
        @if ($endedAt)
            <p class="text-sm">This SOS is closed. If you need help again, press SOS or call a hotline.</p>
            <p class="mt-3 font-display text-4xl font-semibold tabular-nums" data-sos-duration aria-label="How long the SOS was open">{{ sprintf('%02d:%02d', intdiv($took, 60), $took % 60) }}</p>
            <p class="text-xs">from pressing SOS to {{ ($sos['status'] ?? null) === 'Resolved' ? 'response completed' : 'closed' }} ({{ $endedAt->format('M j, g:i A') }})</p>
        @else
            <p class="text-sm">Stay where you are if it is safe. Keep your phone with you.</p>
            <p class="mt-3 font-display text-4xl font-semibold tabular-nums" x-data="{ start: {{ $sos['sent_at']->getTimestamp() }}, now: Math.floor(Date.now() / 1000) }" x-init="setInterval(() => now = Math.floor(Date.now() / 1000), 1000)"
               x-text="String(Math.floor((now - start) / 60)).padStart(2, '0') + ':' + String((now - start) % 60).padStart(2, '0')" aria-label="Time since you sent the SOS">03:00</p>
            <p class="text-xs">since you pressed SOS</p>
        @endif
    </div>

    <div class="mt-4 rounded-xl border border-gray-100 bg-white p-4">
        <p class="flex items-center gap-2 text-sm font-semibold"><x-icon name="my_location" class="text-gray-700" /> {{ $sos['location'] === 'Location not shared' ? 'Location not available' : 'Your location was sent' }}</p>
        <p class="text-sm text-gray-600">{{ $sos['location'] }}@if (is_numeric($sos['accuracy'])) · accurate to about {{ $sos['accuracy'] }} m @endif</p>
    </div>

    <h2 class="mt-6 text-base font-semibold">What’s happening</h2>
    @include('reporter._timeline', ['steps' => $sos['timeline']])

    <div class="mt-6 grid grid-cols-2 gap-3">
        <a href="{{ route('app.hotlines') }}" class="btn btn-secondary"><x-icon name="call" /> Call for help</a>
        <a href="{{ route('app.home') }}" class="btn btn-secondary">Back to home</a>
    </div>
@endsection

@if (auth()->user()?->role === 'Reporter')
    @push('scripts')
        <script>
            // Refresh the timeline when staff changes this SOS, while the page is open.
            setInterval(async function () {
                if (document.visibilityState !== 'visible') return;
                try {
                    var response = await fetch(@js(route('api.reporter.sos')), { credentials: 'same-origin', headers: { Accept: 'application/json' } });
                    if (! response.ok) return;
                    var current = (await response.json()).find(function (event) { return event.sos_id === @js($sos['id']); });
                    if (current && current.status !== @js($sos['status'])) window.location.reload();
                } catch (_) { /* The last known state stays visible without a connection. */ }
            }, 10000);
        </script>
    @endpush
@endif
