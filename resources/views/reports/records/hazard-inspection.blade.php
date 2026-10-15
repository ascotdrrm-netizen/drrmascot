@extends('reports.pdf.document')

@php
    $docCompact = true;
    $docTitle = 'Hazard Inspection Report';
    $docSubtitle = $building->building_name . ' — inspected ' . $inspection->inspection_date->format('F j, Y');
    $docCampus = $building->campus;
    $docSchoolLabel = $inspection->school->school_name ?? '—';
    $docReference = 'DRRMO-INSP-' . $inspection->inspection_date->format('Y') . '-' . str_pad($inspection->inspection_id, 5, '0', STR_PAD_LEFT);
@endphp

@section('content')
    <h2 class="section">Inspection Details</h2>
    <table class="fields">
        <tr>
            <td><div class="field-label">Building/Area</div><div class="field-value">{{ $building->building_name }}</div></td>
            <td><div class="field-label">Campus</div><div class="field-value"><x-pdf.value :value="$building->campus->campus_name ?? null" /></div></td>
        </tr>
        <tr>
            <td><div class="field-label">Inspection date</div><div class="field-value">{{ $inspection->inspection_date->format('F d, Y') }}</div></td>
            <td><div class="field-label">Risk level</div><div class="field-value"><x-pdf.tag :value="$inspection->risk_level" /></div></td>
        </tr>
        <tr>
            <td><div class="field-label">Hazards found (building hazard type)</div><div class="field-value"><x-pdf.value :value="$building->hazard_type" /></div></td>
            <td>
                <div class="field-label">Geo-tagged location</div>
                <div class="field-value">
                    @if ($inspection->hasLocation())
                        {{ $inspection->latitude }}, {{ $inspection->longitude }}
                    @else
                        <span class="not-specified">Not specified</span>
                    @endif
                </div>
            </td>
        </tr>
        <tr>
            <td class="field-wide" colspan="2">
                <div class="field-label">Findings / Recommendations</div>
                <div class="field-text">
                    @if (filled($inspection->findings))
                        {{ $inspection->findings }}
                    @else
                        <span class="not-specified">No findings recorded.</span>
                    @endif
                </div>
            </td>
        </tr>
    </table>

    @if ($photoDataUri)
        <h2 class="section">Inspection Photo</h2>
        <x-pdf.photo :src="$photoDataUri" :caption="'Inspection photo — ' . $building->building_name . ', ' . $inspection->inspection_date->format('F d, Y')" :max-height="210" />
    @endif
@endsection
