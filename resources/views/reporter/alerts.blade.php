@extends('layouts.mobile')

@section('title', 'Alerts - ASCOT DRRM')

@section('content')
    <h1 class="page-title">Alerts</h1>
    <p class="text-sm text-gray-600">Advisories and notices from the ASCOT DRRM Office.</p>
    {{-- Your campus's status and its instruction: where the home banner and
         every campus-status notice lead. --}}
    @php
        $mine = $statuses[0] ?? null;
        $tone = ['normal' => 'border-gray-100 bg-white text-gray-900', 'advisory' => 'border-amber-200 bg-amber-50 text-amber-800',
            'suspended' => 'border-red-200 bg-red-50 text-red-800', 'evacuate' => 'border-red-200 bg-red-50 text-red-800'];
    @endphp
    @if ($mine)
        <article id="campus-status" class="mt-4 scroll-mt-20 rounded-xl border p-4 {{ $tone[$mine['status']] ?? $tone['normal'] }}">
            <p class="text-xs font-semibold uppercase tracking-wide">{{ $mine['campus'] }} · Campus status</p>
            <h2 class="mt-1 text-base font-semibold">{{ $mine['label'] }}</h2>
            <p class="mt-1 text-sm leading-6">{{ $mine['detail'] }}</p>
            @if (! empty($mine['updated']))
                <p class="mt-1 text-xs">Updated {{ $mine['updated']->format('M j, g:i A') }}</p>
            @endif
        </article>
    @endif
    <div id="reporter-notices" data-live-refresh class="mt-4 space-y-3">
        @foreach($notifications as $notification)
            <article class="rounded-xl border border-gray-100 bg-white p-4">
                <p class="text-sm font-semibold">{{ $notification->message }}</p>
                <p class="mt-1 text-xs text-gray-600">{{ $notification->created_at->format('M j, g:i A') }} · {{ $notification->read_at ? 'Read' : 'New' }}</p>
                <form method="POST" action="{{ route('app.notifications.open', $notification) }}" class="mt-2">@csrf<button type="submit" class="text-sm font-semibold text-accent-ink">Open notice</button></form>
            </article>
        @endforeach
    </div>
    <div id="reporter-advisories" data-live-refresh class="mt-4 space-y-3">
        @foreach ($advisories as $advisory)
            <article id="advisory-{{ $advisory['id'] }}" class="rounded-xl border border-gray-100 bg-white p-4">
                <span class="chip {{ $advisory['tone'] === 'red' ? 'border-red-200 bg-red-50 text-red-700' : 'border-amber-200 bg-amber-50 text-amber-700' }}">
                    <x-icon name="warning" class="h-3.5 w-3.5" /> {{ $advisory['hazard'] }} · {{ $advisory['level'] }}
                </span>
                <h2 class="mt-2 text-base font-semibold">{{ $advisory['title'] }}</h2>
                <p class="text-xs text-gray-600">{{ $advisory['campus'] }} · {{ $advisory['issued']->format('M j, g:i A') }}</p>
                <p class="mt-2 text-sm leading-6 text-gray-700">{{ $advisory['body'] }}</p>
            </article>
        @endforeach
    </div>
@endsection
