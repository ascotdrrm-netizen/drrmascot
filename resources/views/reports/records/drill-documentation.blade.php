@extends('reports.pdf.document')

{{-- One drill's documentation. Never includes After Activity Report content. --}}
@php
    $docCompact = true;
    $docTitle = 'Drill Documentation';
    $docSubtitle = $drill->drill_type . ' drill — held ' . $record->date_conducted->format('F j, Y');
    $docCampus = $drill->campus;
    $docReference = 'DRRMO-DRL-DOC-' . $drill->scheduled_date->format('Y') . '-' . str_pad($drill->drill_id, 5, '0', STR_PAD_LEFT);
    $docPreparedBy = ['name' => $record->submitted_by_name, 'role' => 'Submitted by'];
@endphp

@section('styles')
    table.photos { width: 100%; border-collapse: collapse; }
    table.photos tr { page-break-inside: avoid; }
    table.photos td { width: 50%; vertical-align: top; text-align: center; padding: 0 4px 8px; }
@endsection

@section('content')
    @include('reports.records._drill-details')

    <h2 class="section">Documentation</h2>
    <table class="fields">
        <tr>
            <td><div class="field-label">Date and time held</div><div class="field-value">{{ $record->date_conducted->format('M j, Y') }}{{ $record->time_conducted ? ' · ' . $record->time_conducted->format('g:i A') : '' }}</div></td>
            <td><div class="field-label">Participants (approximate)</div><div class="field-value"><x-pdf.value :value="$record->participant_count" /></div></td>
        </tr>
        <tr>
            <td class="field-wide" colspan="2"><div class="field-label">Narrative / observations</div><div class="field-text">{!! nl2br(e($record->narrative)) !!}</div></td>
        </tr>
        <tr>
            <td class="field-wide" colspan="2"><div class="field-label">Notes</div><div class="field-text"><x-pdf.value :value="$record->notes" /></div></td>
        </tr>
    </table>

    @include('reports.records._drill-attachments')
@endsection
