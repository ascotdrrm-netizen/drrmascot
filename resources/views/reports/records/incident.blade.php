@extends('reports.pdf.document')

@php
    $docCompact = true;
    $docTitle = 'Incident Report';
    $docSubtitle = $incident->hazard_type . ' — ' . $incident->date_occurred->format('F j, Y');
    $docCampus = $incident->campus;
    $docSchoolLabel = $incident->school->school_name ?? '—';
    $docReference = 'DRRMO-INC-' . $incident->date_occurred->format('Y') . '-' . str_pad($incident->incident_id, 5, '0', STR_PAD_LEFT);
@endphp

@section('content')
    <h2 class="section">Incident Details</h2>
    <table class="fields">
        <tr>
            <td><div class="field-label">Incident type</div><div class="field-value">{{ $incident->hazard_type }}</div></td>
            <td><div class="field-label">Status</div><div class="field-value"><x-pdf.tag :value="$incident->status" /></div></td>
        </tr>
        <tr>
            <td><div class="field-label">Date occurred</div><div class="field-value">{{ $incident->date_occurred->format('F d, Y') }}</div></td>
            <td><div class="field-label">Campus</div><div class="field-value"><x-pdf.value :value="$incident->campus->campus_name ?? null" /></div></td>
        </tr>
        <tr>
            <td class="field-wide" colspan="2">
                <div class="field-label">Geo-tagged location</div>
                <div class="field-value">
                    @if ($incident->hasLocation())
                        {{ $incident->latitude }}, {{ $incident->longitude }}
                    @else
                        <span class="not-specified">Not specified</span>
                    @endif
                </div>
            </td>
        </tr>
        <tr>
            <td class="field-wide" colspan="2">
                <div class="field-label">Description</div>
                <div class="field-text"><x-pdf.value :value="$incident->description" /></div>
            </td>
        </tr>
    </table>

    {{-- Measurements, as observed: a Disaster-related type only, in the field
         style above, two to a row. --}}
    @if ($incident->measurementFields())
        <h2 class="section">Measurements (as observed)</h2>
        @php $measurementLines = $incident->measurementLines(); @endphp
        <table class="fields" data-measurements>
            @forelse (array_chunk($measurementLines, 2, true) as $pair)
                <tr>
                    @foreach ($pair as $label => $value)
                        <td @if (count($pair) === 1) class="field-wide" colspan="2" @endif><div class="field-label">{{ $label }}</div><div class="field-value">{{ $value }}</div></td>
                    @endforeach
                </tr>
            @empty
                <tr><td class="field-wide" colspan="2"><div class="field-value"><span class="not-specified">None entered</span></div></td></tr>
            @endforelse
        </table>
    @endif

    @if ($photoDataUri)
        <x-pdf.photo :src="$photoDataUri" :caption="'Incident photo — ' . $incident->date_occurred->format('F d, Y')" :max-height="140" />
    @endif

    <h2 class="section">Damage Reports</h2>
    <p class="lead">
        {{ $totalBuildings }} {{ \Illuminate\Support\Str::plural('building', $totalBuildings) }} affected
        &mdash; total casualties: {{ $totalCasualties }},
        estimated cost: PHP {{ number_format($totalCost, 2) }}
    </p>

    <table class="data">
        <thead>
            <tr>
                <th>Building</th>
                <th style="width: 16%;">Damage level</th>
                <th class="num" style="width: 14%;">Casualties</th>
                <th class="num" style="width: 20%;">Est. cost</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($incident->damageReports as $report)
                <tr>
                    <td>{{ $report->building->building_name ?? '—' }}</td>
                    <td><x-pdf.tag :value="$report->damage_level" /></td>
                    <td class="num">{{ $report->casualty_count }}</td>
                    <td class="num">{{ $report->est_cost !== null ? 'PHP ' . number_format($report->est_cost, 2) : '—' }}</td>
                </tr>
            @empty
                <tr><td class="none" colspan="4">No damage reports recorded.</td></tr>
            @endforelse
        </tbody>
    </table>

    <h2 class="section">Linked Alerts</h2>
    <table class="data">
        <thead>
            <tr>
                <th style="width: 19%;">Sent at</th>
                <th>Message</th>
                <th class="num" style="width: 11%;">Recipients</th>
                <th style="width: 15%;">Delivery status</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($incident->alerts as $alert)
                <tr>
                    <td class="nowrap">{{ $alert->sent_at->format('M d, Y g:i A') }}</td>
                    <td class="wrap">{{ $alert->message }}</td>
                    <td class="num">{{ $alert->recipient_count }}</td>
                    <td><x-pdf.tag :value="$alert->delivery_status" /></td>
                </tr>
            @empty
                <tr><td class="none" colspan="4">No alerts sent.</td></tr>
            @endforelse
        </tbody>
    </table>
@endsection
