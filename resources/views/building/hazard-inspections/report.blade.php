<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Inspection Report - {{ $building->building_name }}</title>
    <style>
        @include('reports._pdf-style')
    </style>
</head>
<body>
    @include('reports._pdf-header')

    <div class="title">Hazard Inspection Report</div>
    <div class="title-rule"></div>

    @include('reports._pdf-metadata', [
        'metaReference' => 'INSP-' . str_pad($inspection->inspection_id, 5, '0', STR_PAD_LEFT),
        'metaCampus' => $building->campus->campus_name ?? '—',
    ])

    @if ($photoDataUri)
        <div class="photo-card">
            <img src="{{ $photoDataUri }}" alt="Inspection photo">
            <div class="photo-caption">Inspection photo &mdash; {{ $building->building_name }}, {{ $inspection->inspection_date->format('F d, Y') }}</div>
        </div>
    @endif

    <div class="section-heading">Inspection Details</div>
    <table class="details-grid">
        <tr>
            <td class="label">Building/Area</td>
            <td class="value">{{ $building->building_name }}</td>
            <td class="label">Campus</td>
            <td class="value">{{ $building->campus->campus_name ?? '—' }}</td>
        </tr>
        <tr>
            <td class="label">Inspection Date</td>
            <td class="value">{{ $inspection->inspection_date->format('F d, Y') }}</td>
            <td class="label">Risk Level</td>
            <td class="value">{{ $inspection->risk_level }}{{ $inspection->risk_breakdown ? ' — ' . $inspection->risk_breakdown : '' }}</td>
        </tr>
    </table>

    @if ($inspection->hasLocation())
        <div class="reference-line">
            <span class="reference-label">Geo-tagged Location:</span>
            {{ $inspection->latitude }}, {{ $inspection->longitude }}
            &mdash; <a href="{{ $inspection->map_url }}">View on map</a>
        </div>
    @endif

    <div class="section-heading">Findings</div>
    <div class="findings-box">
        {{ $inspection->findings ?: 'No findings recorded.' }}
    </div>

    @include('reports._pdf-signature')

    @include('reports._pdf-footer')
</body>
</html>
