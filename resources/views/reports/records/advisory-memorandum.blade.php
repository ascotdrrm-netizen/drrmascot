@extends('reports.pdf.document')

{{--
    Disaster Advisory memo: posted on boards or shared in group chats, the
    same shape as the drill announcement. It deliberately carries NO personal
    data — no personnel names, phone numbers or emails — so the metadata table
    and the signature block are switched off.
--}}
@php
    $docTitle = 'Disaster Advisory';
    $docCampus = $advisory->campus;
    $docCampusLabel = $advisory->campus_label;
    $docShowMeta = false;
    $docShowSignature = false;
    $docFooterNote = 'Issued by the ASCOT Disaster Risk Reduction and Management Office for posting and sharing with students and staff.';
    $lines = $advisory->measurementLines();
@endphp

@section('styles')
    .announce-type { text-align: center; font-size: 22pt; font-weight: bold; color: #102a1c; text-transform: uppercase;
        letter-spacing: 2px; margin: 8px 0 2px 0; }
    .announce-headline { text-align: center; font-size: 12pt; font-weight: bold; color: #1f2933; margin: 0 0 10px 0; }
    table.when { width: 100%; border-collapse: collapse; margin-bottom: 10px; border: 1.2px solid #1d6b3a; }
    table.when td { padding: 5px 10px; border: .5px solid #b8c4bd; vertical-align: top; }
    table.when .label { width: 30%; font-size: 8pt; font-weight: bold; text-transform: uppercase; letter-spacing: .5px;
        color: #52606d; background-color: #eef3ef; }
    table.when .value { font-size: 12pt; font-weight: bold; color: #1f2933; }
    .source-note { font-size: 8.5pt; color: #52606d; margin: -4px 0 10px 0; }
    .body-text { font-size: 10pt; line-height: 1.45; margin: 4px 0; }
    .status-note { margin-top: 8px; padding: 8px 10px; border: .75px dashed #3e4c59; font-size: 9.5pt; text-align: center; }
    .issued { margin-top: 8px; font-size: 8pt; color: #52606d; text-align: right; }
@endsection

@section('content')
    <div class="announce-type">{{ $advisory->hazard_label }} Advisory</div>
    <p class="announce-headline">{{ $advisory->headline }}</p>

    <table class="when">
        @foreach ($lines as $label => $value)
            <tr><td class="label">{{ $label }}</td><td class="value">{{ $value }}</td></tr>
        @endforeach
        <tr><td class="label">Expected to affect the campus</td><td class="value">{{ $advisory->expected_at?->format('l, F j, Y · g:i A') ?? 'Not forecast' }}</td></tr>
        <tr><td class="label">Valid until</td><td class="value">{{ $advisory->valid_until->format('l, F j, Y · g:i A') }}</td></tr>
        <tr><td class="label">Campus</td><td class="value">{{ $advisory->campus_label }}</td></tr>
        <tr><td class="label">Source</td><td class="value">{{ $advisory->source_label }}{{ $advisory->bulletin_reference ? ' · ' . $advisory->bulletin_reference : '' }}, issued {{ $advisory->issued_at->format('M j, Y · g:i A') }}</td></tr>
    </table>
    <p class="source-note">Figures are as stated in the {{ $advisory->source_label }} bulletin. Conditions change: follow the latest official bulletin.</p>

    <h2 class="section">What students and staff should do</h2>
    <p class="body-text">{!! nl2br(e($advisory->instructions)) !!}</p>

    @if ($advisory->status !== 'Active')
        <div class="status-note"><strong>This advisory is {{ $advisory->status_label }}.</strong> It is printed for the record and is no longer in effect.</div>
    @elseif (! $advisory->isLive())
        <div class="status-note"><strong>This advisory is past its valid-until time.</strong> Check for a newer bulletin before acting on it.</div>
    @endif

    <p class="issued">Printed {{ now()->format('F j, Y') }} · ASCOT Disaster Risk Reduction and Management Office</p>
@endsection
