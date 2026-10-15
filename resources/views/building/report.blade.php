<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Building Report - {{ $building->building_name }}</title>
    <style>
        @include('reports._pdf-style')
    </style>
</head>
<body>
    @include('reports._pdf-header')

    <div class="title">Building/Area Hazard Report</div>
    <div class="title-rule"></div>

    @include('reports._pdf-metadata', [
        'metaReference' => 'BLDG-' . str_pad($building->building_id, 5, '0', STR_PAD_LEFT),
        'metaCampus' => $building->campus->campus_name ?? '—',
    ])

    @if ($photoDataUri)
        <div class="photo-card">
            <img src="{{ $photoDataUri }}" alt="{{ $building->building_name }}">
            <div class="photo-caption">Photo of {{ $building->building_name }}</div>
        </div>
    @endif

    <div class="section-heading">Building/Area Details</div>
    <table class="details-grid">
        <tr>
            <td class="label">Building/Area Name</td>
            <td class="value">{{ $building->building_name }}</td>
            <td class="label">Campus</td>
            <td class="value">{{ $building->campus->campus_name ?? '—' }}</td>
        </tr>
        <tr>
            <td class="label">Hazard Type</td>
            <td class="value">{{ $building->hazard_type ?? '—' }}</td>
            <td class="label">Risk Level</td>
            <td class="value">{{ $building->risk_level }}</td>
        </tr>
    </table>

    @if ($building->hasLocation())
        <div class="reference-line">
            <span class="reference-label">Geo-tagged Location:</span>
            {{ $building->latitude }}, {{ $building->longitude }}
            &mdash; <a href="{{ $building->map_url }}">View on map</a>
        </div>
    @endif

    @include('reports._pdf-signature')

    @include('reports._pdf-footer')
</body>
</html>
