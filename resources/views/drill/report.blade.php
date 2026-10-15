<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Drill Report - {{ $drill->drill_type }}</title>
    <style>
        @include('reports._pdf-style')
    </style>
</head>
<body>
    @include('reports._pdf-header')

    <div class="title">Drill Completion Report</div>
    <div class="title-rule"></div>

    @include('reports._pdf-metadata', [
        'metaReference' => 'DRILL-' . str_pad($drill->drill_id, 5, '0', STR_PAD_LEFT),
        'metaCampus' => $drill->campus->campus_name ?? '—',
    ])

    <div class="section-heading">Drill Details</div>
    <table class="details-grid">
        <tr>
            <td class="label">Campus</td>
            <td class="value">{{ $drill->campus->campus_name ?? '—' }}</td>
            <td class="label">Drill Type</td>
            <td class="value">{{ $drill->drill_type }}</td>
        </tr>
        <tr>
            <td class="label">Date Conducted</td>
            <td class="value">{{ $drill->date_conducted->format('F d, Y') }}</td>
            <td class="label">Time Conducted</td>
            <td class="value">{{ $drill->time_conducted ? $drill->time_conducted->format('g:i A') : '—' }}</td>
        </tr>
        <tr>
            <td class="label">Venue</td>
            <td class="value">{{ $drill->venue ?? '—' }}</td>
            <td class="label">Partner Agency</td>
            <td class="value">{{ $drill->partner_agency ?? '—' }}</td>
        </tr>
    </table>

    @if ($drill->description)
        <div class="section-heading">Description</div>
        <div class="findings-box">
            {{ $drill->description }}
        </div>
    @endif

    <div class="section-heading">Participant Evaluation Results</div>
    <div class="summary-line">
        {{ $drill->evaluations->count() }} participant(s) evaluated
        &mdash; average pre-score: {{ $avgPre ?? '—' }},
        average post-score: {{ $avgPost ?? '—' }}
    </div>

    <table class="data-table">
        <thead>
            <tr>
                <th>Full name</th>
                <th>Pre-score</th>
                <th>Post-score</th>
                <th>Improvement</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($drill->evaluations as $evaluation)
                <tr>
                    <td>{{ $evaluation->personnel->full_name ?? '—' }}</td>
                    <td>{{ $evaluation->pre_score !== null ? number_format($evaluation->pre_score, 1) : '—' }}</td>
                    <td>{{ $evaluation->post_score !== null ? number_format($evaluation->post_score, 1) : '—' }}</td>
                    <td class="{{ $evaluation->improvement !== null && $evaluation->improvement < 0 ? 'improvement-negative' : 'improvement-positive' }}">
                        @if ($evaluation->improvement !== null)
                            {{ $evaluation->improvement >= 0 ? '+' : '' }}{{ number_format($evaluation->improvement, 1) }}
                        @else
                            —
                        @endif
                    </td>
                </tr>
            @empty
                <tr>
                    <td class="no-data" colspan="4">No evaluations recorded.</td>
                </tr>
            @endforelse
        </tbody>
    </table>

    @include('reports._pdf-signature')

    @include('reports._pdf-footer')
</body>
</html>
