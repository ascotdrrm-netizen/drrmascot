@extends('reports.pdf.document')

@php
    $docTitle = 'Activity Log Export';
    $docSubtitle = $totalCount . ' entr' . ($totalCount === 1 ? 'y' : 'ies') . ' — date range covered: '
        . ($dateFrom ?? 'earliest record') . ' to ' . ($dateTo ?? 'latest record');
    $docCampusLabel = 'All Campuses';
    $docReferenceNote = 'Not assigned — log extract, not a single record';
@endphp

{{-- Portrait page: the IP address is shown on the on-screen log only. --}}
@section('content')
    <table class="data">
        <thead>
            <tr>
                <th style="width: 14%;">Timestamp</th>
                <th style="width: 17%;">User</th>
                <th style="width: 13%;">Action</th>
                <th>Description</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($logs as $log)
                <tr>
                    <td>{{ $log->created_at?->format('M d, Y g:i A') ?? '—' }}</td>
                    <td>{{ $log->actor_label }}</td>
                    <td>{{ $log->action_label }}</td>
                    <td class="wrap">{{ $log->description }}</td>
                </tr>
            @empty
                <tr><td class="none" colspan="4">No activity recorded for the selected filters.</td></tr>
            @endforelse
        </tbody>
    </table>
@endsection
