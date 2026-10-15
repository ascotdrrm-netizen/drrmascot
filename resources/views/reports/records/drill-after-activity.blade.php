@extends('reports.pdf.document')

{{--
    One drill's After Activity Report (ASCOT's term). Never includes the
    documentation's content. Field set PROVISIONAL, as agreed with the client.
--}}
@php
    $docCompact = true;
    $docTitle = 'After Activity Report';
    $docSubtitle = $record->activity_title . ' — ' . $record->date_conducted->format('F j, Y');
    $docCampus = $drill->campus;
    $docReference = 'DRRMO-DRL-AAR-' . $drill->scheduled_date->format('Y') . '-' . str_pad($drill->drill_id, 5, '0', STR_PAD_LEFT);
    $docPreparedBy = ['name' => $record->submitted_by_name, 'role' => 'Submitted by'];
    $time = trim(($record->start_time?->format('g:i A') ?? '') . ($record->end_time ? ' – ' . $record->end_time->format('g:i A') : ''));
    $text = fn ($value) => $value !== null && $value !== '' ? nl2br(e($value)) : '<span class="not-specified">Not specified</span>';
@endphp

@section('styles')
    table.photos { width: 100%; border-collapse: collapse; }
    table.photos tr { page-break-inside: avoid; }
    table.photos td { width: 50%; vertical-align: top; text-align: center; padding: 0 4px 8px; }
    table.counts td { width: 25%; }
@endsection

@section('content')
    @include('reports.records._drill-details')

    <h2 class="section">Activity</h2>
    <table class="fields">
        <tr>
            <td><div class="field-label">Title of activity</div><div class="field-value">{{ $record->activity_title }}</div></td>
            <td><div class="field-label">Date and time conducted</div><div class="field-value">{{ $record->date_conducted->format('M j, Y') }}{{ $time !== '' ? ' · ' . $time : '' }}</div></td>
        </tr>
        <tr><td class="field-wide" colspan="2"><div class="field-label">Objectives</div><div class="field-text">{!! $text($record->objectives) !!}</div></td></tr>
        <tr><td class="field-wide" colspan="2"><div class="field-label">Activities conducted</div><div class="field-text">{!! $text($record->activities_conducted) !!}</div></td></tr>
        <tr><td class="field-wide" colspan="2"><div class="field-label">Participating offices and agencies</div><div class="field-text">{!! $text($record->participating_offices) !!}</div></td></tr>
    </table>

    <h2 class="section">Participants and response</h2>
    <table class="fields counts">
        <tr>
            <td><div class="field-label">Students</div><div class="field-value"><x-pdf.value :value="$record->participants_students" /></div></td>
            <td><div class="field-label">Faculty and staff</div><div class="field-value"><x-pdf.value :value="$record->participants_employees" /></div></td>
            <td><div class="field-label">Others</div><div class="field-value"><x-pdf.value :value="$record->participants_others" /></div></td>
            <td><div class="field-label">Total</div><div class="field-value"><x-pdf.value :value="$record->totalParticipants()" /></div></td>
        </tr>
        <tr>
            <td class="field-wide" colspan="4"><div class="field-label">Response time (minutes)</div><div class="field-value"><x-pdf.value :value="$record->response_time_minutes" /></div></td>
        </tr>
    </table>

    <h2 class="section">Assessment</h2>
    <table class="fields">
        <tr><td class="field-wide" colspan="2"><div class="field-label">Results (against the objectives)</div><div class="field-text">{!! $text($record->results) !!}</div></td></tr>
        <tr><td class="field-wide" colspan="2"><div class="field-label">Problems encountered</div><div class="field-text">{!! $text($record->problems_encountered) !!}</div></td></tr>
        <tr><td class="field-wide" colspan="2"><div class="field-label">Recommendations</div><div class="field-text">{!! $text($record->recommendations) !!}</div></td></tr>
    </table>

    @include('reports.records._drill-attachments')
@endsection
