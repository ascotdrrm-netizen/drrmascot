@extends('reports.pdf.document')

@php
    $docCompact = true;
    $docTitle = 'Building/Area Hazard Report';
    $docSubtitle = $building->building_name;
    $docCampus = $building->campus;
    // No natural date on a building record, so the reference is ID-based only.
    $docReference = 'DRRMO-BLDG-' . str_pad($building->building_id, 5, '0', STR_PAD_LEFT);
@endphp

@section('content')
    @if ($photoDataUri)
        <x-pdf.photo :src="$photoDataUri" :caption="'Photo of ' . $building->building_name" :max-height="170" />
    @endif

    <h2 class="section">Building/Area Details</h2>
    <table class="fields">
        <tr>
            <td><div class="field-label">Building/Area name</div><div class="field-value">{{ $building->building_name }}</div></td>
            <td><div class="field-label">Campus</div><div class="field-value"><x-pdf.value :value="$building->campus->campus_name ?? null" /></div></td>
        </tr>
        <tr>
            <td><div class="field-label">Hazard type</div><div class="field-value"><x-pdf.value :value="$building->hazard_type" /></div></td>
            <td><div class="field-label">Latest risk level</div><div class="field-value">@if ($building->risk_level)<x-pdf.tag :value="$building->risk_level" />@else<span class="not-specified">Not yet assessed</span>@endif</div></td>
        </tr>
        <tr>
            <td class="field-wide" colspan="2">
                <div class="field-label">Geo-tagged location</div>
                <div class="field-value">
                    @if ($building->hasLocation())
                        {{ $building->latitude }}, {{ $building->longitude }}
                    @else
                        <span class="not-specified">Not specified</span>
                    @endif
                </div>
            </td>
        </tr>
    </table>

    @if ($building->description)
        <h2 class="section">Building/Area Description</h2>
        <p class="lead" style="white-space: pre-line">{{ $building->description }}</p>
    @endif

    <h2 class="section">Inspection History</h2>
    <p class="lead">
        {{ $building->hazardInspections->count() }} inspection(s) recorded
        &mdash; latest risk level: {{ $building->risk_level ?? 'not yet assessed' }}
    </p>

    <table class="data">
        <thead>
            <tr>
                <th style="width: 18%;">Date</th>
                <th style="width: 14%;">Risk level</th>
                <th>Findings</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($building->hazardInspections as $inspection)
                <tr>
                    <td class="nowrap">{{ $inspection->inspection_date->format('M d, Y') }}</td>
                    <td><x-pdf.tag :value="$inspection->risk_level" />@if ($inspection->risk_score)<br><span class="sub">score {{ $inspection->risk_score }}</span>@endif</td>
                    <td class="wrap">{{ $inspection->findings ?: '—' }}</td>
                </tr>
            @empty
                <tr><td class="none" colspan="3">No inspections recorded.</td></tr>
            @endforelse
        </tbody>
    </table>
@endsection
