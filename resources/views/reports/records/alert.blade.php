@extends('reports.pdf.document')

@php
    $docCompact = true;
    $docTitle = 'SMS & Broadcast Alert Report';
    $docSubtitle = 'Alert sent ' . $alert->sent_at->format('F j, Y g:i A');
    $docCampus = $incident->campus;
    // Alerts have no school of their own: attributed via their incident.
    $docSchoolLabel = $incident->school->school_name ?? '—';
    $docReference = 'DRRMO-ALR-' . $alert->sent_at->format('Y') . '-' . str_pad($alert->alert_id, 5, '0', STR_PAD_LEFT);
@endphp

@section('content')
    <h2 class="section">Alert Details</h2>
    <table class="fields">
        <tr>
            <td><div class="field-label">Sent at</div><div class="field-value">{{ $alert->sent_at->format('F d, Y g:i A') }}</div></td>
            <td><div class="field-label">Delivery status</div><div class="field-value"><x-pdf.tag :value="$alert->delivery_status" /></div></td>
        </tr>
        <tr>
            <td>
                <div class="field-label">Linked incident</div>
                <div class="field-value">{{ $incident->hazard_type }} &mdash; {{ $incident->date_occurred->format('F d, Y') }} (INC-{{ str_pad($incident->incident_id, 5, '0', STR_PAD_LEFT) }})</div>
            </td>
            <td><div class="field-label">Recipient count</div><div class="field-value">{{ $alert->recipient_count }}</div></td>
        </tr>
        <tr>
            <td class="field-wide" colspan="2">
                <div class="field-label">Message</div>
                <div class="field-text">{{ $alert->message }}</div>
            </td>
        </tr>
    </table>
@endsection
