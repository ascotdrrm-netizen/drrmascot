@extends('reports.pdf.document')

@php
    $docCompact = true;
    $docTitle = 'Drill Completion Report';
    $docSubtitle = $drill->drill_type . ' drill — ' . $drill->date_conducted->format('F j, Y');
    $docCampus = $drill->campus;
    $docReference = 'DRRMO-DRL-' . $drill->date_conducted->format('Y') . '-' . str_pad($drill->drill_id, 5, '0', STR_PAD_LEFT);
@endphp

@section('content')
    <h2 class="section">Drill Details</h2>
    <table class="fields">
        <tr>
            <td><div class="field-label">Drill type</div><div class="field-value">{{ $drill->drill_type }}</div></td>
            <td><div class="field-label">Campus</div><div class="field-value"><x-pdf.value :value="$drill->campus->campus_name ?? null" /></div></td>
        </tr>
        <tr>
            <td><div class="field-label">Date conducted</div><div class="field-value">{{ $drill->date_conducted->format('F d, Y') }}</div></td>
            <td><div class="field-label">Time conducted</div><div class="field-value"><x-pdf.value :value="$drill->time_conducted ? $drill->time_conducted->format('g:i A') : null" /></div></td>
        </tr>
        <tr>
            <td><div class="field-label">Venue</div><div class="field-value"><x-pdf.value :value="$drill->venue" /></div></td>
            <td><div class="field-label">Partner agency</div><div class="field-value"><x-pdf.value :value="$drill->partner_agency" /></div></td>
        </tr>
        <tr>
            <td class="field-wide" colspan="2">
                <div class="field-label">Scenario / Objectives / Remarks</div>
                <div class="field-text"><x-pdf.value :value="$drill->description" /></div>
            </td>
        </tr>
    </table>

    <h2 class="section">Participant Evaluation Results</h2>
    <p class="lead">
        {{ $drill->evaluations->count() }} participant(s) evaluated
        &mdash; average pre-score: {{ $avgPre ?? '—' }},
        average post-score: {{ $avgPost ?? '—' }}
    </p>

    <table class="data">
        <thead>
            <tr>
                <th>Full name</th>
                <th class="num" style="width: 16%;">Pre-score</th>
                <th class="num" style="width: 16%;">Post-score</th>
                <th class="num" style="width: 16%;">Improvement</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($drill->evaluations as $evaluation)
                <tr>
                    <td>{{ $evaluation->personnel->full_name ?? '—' }}</td>
                    <td class="num">{{ $evaluation->pre_score !== null ? number_format($evaluation->pre_score, 1) : '—' }}</td>
                    <td class="num">{{ $evaluation->post_score !== null ? number_format($evaluation->post_score, 1) : '—' }}</td>
                    <td class="num">
                        @if ($evaluation->improvement !== null)
                            {{ $evaluation->improvement >= 0 ? '+' : '' }}{{ number_format($evaluation->improvement, 1) }}
                        @else
                            —
                        @endif
                    </td>
                </tr>
            @empty
                <tr><td class="none" colspan="4">No evaluations recorded.</td></tr>
            @endforelse
        </tbody>
    </table>
@endsection
