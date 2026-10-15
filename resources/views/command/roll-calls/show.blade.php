@extends('layouts.app')
@section('title', $rollCall->title . ' - ASCOT DRRMS')
@section('heading', $rollCall->title)
@section('subheading', $rollCall->campus->campus_name . ' · expected roster saved when this roll call started')
@section('breadcrumb')<x-back-link :href="route($routePrefix . '.roll-calls.index')">All roll calls</x-back-link>@endsection
@section('actions')<a href="{{ route($routePrefix . '.roll-calls.export', $rollCall) }}" class="btn btn-secondary">Download CSV</a>@endsection
@section('content')
    <div id="roll-call-results" data-live-refresh>
        <div class="mb-6 grid grid-cols-2 gap-4 lg:grid-cols-4" aria-live="polite">
            <x-stat-card label="Expected" :value="$responses->count()" />
            <x-stat-card label="Safe" :value="$responses->where('answer', 'Safe')->count()" tone="success" />
            <x-stat-card label="Need help" :value="$responses->where('answer', 'Help')->count()" tone="danger" />
            <x-stat-card label="No response" :value="$responses->whereNull('answer')->count()" subtitle="Their safety is not yet known" />
        </div>
        <div class="mb-6 flex flex-wrap items-center justify-between gap-4">
            <p class="text-sm text-gray-600">{{ $rollCall->closed_at ? 'Closed ' . $rollCall->closed_at->format('M j, g:i A') : 'Open: responses update automatically while this page is visible.' }}</p>
            @unless ($rollCall->closed_at)
                <form method="POST" action="{{ route($routePrefix . '.roll-calls.close', $rollCall) }}" data-ajax data-ajax-refresh="#roll-call-results" data-confirm-form="Close this roll call? Reporters will no longer be able to change their responses.">@csrf<button type="submit" class="btn btn-secondary">Close roll call</button></form>
            @endunless
        </div>
        <x-card flush><div class="table-wrap"><table class="data-table">
            <thead><tr><th class="table-th">Name</th><th class="table-th">Response</th><th class="table-th">Note / location</th><th class="table-th">Contact</th><th class="table-th">Last answered</th></tr></thead>
            <tbody>@foreach ($responses->sortBy(fn ($row) => ['Help' => 0, '' => 1, 'Safe' => 2][$row->answer ?? '']) as $response)
                <tr><td class="table-td font-medium">{{ $response->full_name }}</td>
                    <td class="table-td"><span class="chip {{ $response->answer === 'Help' ? 'border-red-200 text-red-700' : ($response->answer === 'Safe' ? 'border-green-200 text-green-700' : 'border-gray-200 text-gray-600') }}">{{ $response->answer === 'Help' ? 'Need help' : ($response->answer ?: 'No response') }}</span></td>
                    <td class="table-td"><p class="whitespace-pre-line">{{ $response->note ?: '—' }}</p>@if ($response->latitude !== null)<a href="https://maps.google.com/?q={{ $response->latitude }},{{ $response->longitude }}" target="_blank" rel="noopener" class="link">Shared GPS location</a>@endif</td>
                    <td class="table-td">@if ($response->contact_number)<a class="link" href="tel:{{ preg_replace('/[^0-9+]/', '', $response->contact_number) }}">{{ $response->contact_number }}</a>@else — @endif</td>
                    <td class="table-td">{{ $response->responded_at?->format('M j, g:i A') ?: '—' }}</td>
                </tr>
            @endforeach</tbody>
        </table></div></x-card>
    </div>
@endsection
