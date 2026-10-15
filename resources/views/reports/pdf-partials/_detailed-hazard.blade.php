{{-- $moduleData: ['summary' => ..., 'data' => rowsForPdf(), 'high_risk_data' => rowsForPdf()] --}}
<h2 class="section">Facility Hazard Auditor</h2>
@if ($moduleData['summary']['inspections_conducted'] === 0)
    <p class="empty">No records for this period.</p>
@else
    <table class="fields">
        <tr>
            <td><div class="field-label">Inspections conducted</div><div class="field-value">{{ $moduleData['summary']['inspections_conducted'] }}</div></td>
            <td><div class="field-label">Buildings inspected</div><div class="field-value">{{ $moduleData['summary']['buildings_inspected'] }}</div></td>
        </tr>
    </table>

    @if ($moduleData['data']['truncated'])
        @include('reports.pdf-partials._truncated', ['limit' => \App\Services\DetailedReportService::PDF_ROW_LIMIT])
    @endif

    <table class="data">
        <thead>
            <tr>
                <th style="width: 11%;">Inspection date</th>
                <th style="width: 21%;">Campus / school</th>
                <th style="width: 14%;">Building / Area</th>
                <th style="width: 10%;">Hazard type</th>
                <th style="width: 9%;">Risk level</th>
                <th>Findings / Remarks</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($moduleData['data']['rows'] as $row)
                <tr>
                    <td class="nowrap">{{ optional($row->inspection_date)->format('M d, Y') ?? '—' }}</td>
                    <td>{{ $row->building->campus->campus_name ?? '—' }}@if ($row->school?->school_name)<br><span class="sub">{{ $row->school->school_name }}</span>@endif</td>
                    <td>{{ $row->building->building_name ?? '—' }}</td>
                    <td>{{ $row->building->hazard_type ?? '—' }}</td>
                    <td><x-pdf.tag :value="$row->risk_level" /></td>
                    <td class="wrap">{{ $row->findings ?? '—' }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <h3 class="subsection">High-Risk Findings</h3>
    @if ($moduleData['high_risk_data']['rows']->isEmpty())
        <p class="empty">No high-risk findings for this period.</p>
    @else
        @if ($moduleData['high_risk_data']['truncated'])
            @include('reports.pdf-partials._truncated', ['limit' => \App\Services\DetailedReportService::PDF_ROW_LIMIT])
        @endif
        <table class="data">
            <thead>
                <tr>
                    <th style="width: 11%;">Inspection date</th>
                    <th style="width: 22%;">Campus / school</th>
                    <th style="width: 14%;">Building / Area</th>
                    <th>Findings / Remarks</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($moduleData['high_risk_data']['rows'] as $row)
                    <tr>
                        <td class="nowrap">{{ optional($row->inspection_date)->format('M d, Y') ?? '—' }}</td>
                        <td>{{ $row->building->campus->campus_name ?? '—' }}@if ($row->school?->school_name)<br><span class="sub">{{ $row->school->school_name }}</span>@endif</td>
                        <td>{{ $row->building->building_name ?? '—' }}</td>
                        <td class="wrap">{{ $row->findings ?? '—' }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif
@endif
