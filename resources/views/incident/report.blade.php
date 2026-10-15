<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Incident Report - {{ $incident->hazard_type }}</title>
    <style>
        @include('reports._pdf-style')

        /*
         * Incident reports can carry a long tail (description + a
         * damage-reports table + an alert-history table), and this
         * report must always render as exactly one page without ever
         * truncating data. These overrides come AFTER the shared
         * stylesheet on purpose so the tighter values here win the
         * cascade for this report only — Buildings/Drills/Hazard
         * Inspections keep the shared partial's normal sizing.
         */
        @page {
            margin: 78px 45px 60px 45px;
        }

        body {
            font-size: 9.5px;
        }

        .header {
            padding-bottom: 8px;
            margin-bottom: 2px;
        }

        .header .logo-cell img {
            height: 42px;
        }

        .institution-name {
            font-size: 12px;
        }

        .institution-office {
            font-size: 9px;
        }

        .institution-tagline {
            font-size: 7.5px;
        }

        .title {
            font-size: 15px;
            margin: 10px 0 4px 0;
        }

        .title-rule {
            margin-bottom: 10px;
        }

        .meta-strip {
            margin-bottom: 10px;
        }

        .meta-strip td {
            padding: 5px 10px;
        }

        .meta-label {
            font-size: 7px;
        }

        .meta-value {
            font-size: 9px;
        }

        .photo-card {
            margin-bottom: 8px;
        }

        .photo-card img {
            max-width: 220px;
            max-height: 130px;
        }

        .section-heading {
            font-size: 10.5px;
            margin: 8px 0 4px 0;
            padding-bottom: 2px;
        }

        table.details-grid td {
            padding: 4px 6px;
        }

        table.details-grid td.value {
            font-size: 9.5px;
        }

        .findings-box {
            padding: 6px 8px;
            margin-bottom: 8px;
            font-size: 9px;
        }

        .reference-line {
            font-size: 8px;
            margin: 0 0 8px 0;
        }

        .summary-line {
            font-size: 8.5px;
            margin-bottom: 6px;
        }

        table.data-table {
            margin-bottom: 8px;
        }

        table.data-table th {
            padding: 4px 5px;
            font-size: 8px;
        }

        table.data-table td {
            padding: 4px 5px;
            font-size: 8.5px;
        }

        table.data-table td.no-data {
            padding: 8px;
        }

        .signature-block {
            margin-top: 12px;
        }

        .signature-underline {
            margin: 42px auto 6px auto;
        }

        .signature-heading,
        .signature-field {
            font-size: 8.5px;
        }

        .footer {
            bottom: -44px;
        }

        .footer td,
        .footer-attribution {
            font-size: 7px;
        }
    </style>
</head>
<body>
    @include('reports._pdf-header')

    <div class="title">Incident Report</div>
    <div class="title-rule"></div>

    @include('reports._pdf-metadata', [
        'metaReference' => 'INC-' . str_pad($incident->incident_id, 5, '0', STR_PAD_LEFT),
        'metaCampus' => $incident->campus->campus_name ?? '—',
    ])

    @if ($photoDataUri)
        <div class="photo-card">
            <img src="{{ $photoDataUri }}" alt="Incident photo">
            <div class="photo-caption">Incident photo &mdash; {{ $incident->date_occurred->format('F d, Y') }}</div>
        </div>
    @endif

    <div class="section-heading">Incident Details</div>
    <table class="details-grid">
        <tr>
            <td class="label">Hazard Type</td>
            <td class="value">{{ $incident->hazard_type }}</td>
            <td class="label">Status</td>
            <td class="value">{{ $incident->status }}</td>
        </tr>
        <tr>
            <td class="label">Date Occurred</td>
            <td class="value">{{ $incident->date_occurred->format('F d, Y') }}</td>
            <td class="label">Campus</td>
            <td class="value">{{ $incident->campus->campus_name ?? '—' }}</td>
        </tr>
    </table>

    @if ($incident->description)
        <div class="section-heading">Description</div>
        <div class="findings-box">
            {{ $incident->description }}
        </div>
    @endif

    @if ($incident->hasLocation())
        <div class="reference-line">
            <span class="reference-label">Geo-tagged Location:</span>
            {{ $incident->latitude }}, {{ $incident->longitude }}
            &mdash; <a href="{{ $incident->map_url }}">View on map</a>
        </div>
    @endif

    <div class="section-heading">Damage Reports</div>
    <div class="summary-line">
        {{ $totalBuildings }} building(s) affected
        &mdash; total casualties: {{ $totalCasualties }},
        estimated cost: PHP {{ number_format($totalCost, 2) }}
    </div>

    <table class="data-table">
        <thead>
            <tr>
                <th>Building</th>
                <th>Damage level</th>
                <th>Casualties</th>
                <th>Est. cost</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($incident->damageReports as $report)
                <tr>
                    <td>{{ $report->building->building_name ?? '—' }}</td>
                    <td>{{ $report->damage_level }}</td>
                    <td>{{ $report->casualty_count }}</td>
                    <td>{{ $report->est_cost !== null ? 'PHP ' . number_format($report->est_cost, 2) : '—' }}</td>
                </tr>
            @empty
                <tr>
                    <td class="no-data" colspan="4">No damage reports recorded.</td>
                </tr>
            @endforelse
        </tbody>
    </table>

    <div class="section-heading">Alert History</div>
    <table class="data-table">
        <thead>
            <tr>
                <th>Sent at</th>
                <th>Recipients</th>
                <th>Delivery status</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($incident->alerts as $alert)
                <tr>
                    <td>{{ $alert->sent_at->format('M d, Y g:i A') }}</td>
                    <td>{{ $alert->recipient_count }}</td>
                    <td>{{ $alert->delivery_status }}</td>
                </tr>
            @empty
                <tr>
                    <td class="no-data" colspan="3">No alerts sent.</td>
                </tr>
            @endforelse
        </tbody>
    </table>

    @include('reports._pdf-signature')

    @include('reports._pdf-footer')
</body>
</html>
