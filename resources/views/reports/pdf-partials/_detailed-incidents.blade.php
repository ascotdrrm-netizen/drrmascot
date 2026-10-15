{{-- $moduleData: ['summary' => ..., 'data' => rowsForPdf(), 'damage_data' => rowsForPdf()] --}}
<h2 class="section">Incident Reporting</h2>
@if ($moduleData['summary']['total'] === 0)
    <p class="empty">No records for this period.</p>
@else
    <table class="fields">
        <tr>
            <td><div class="field-label">Total incidents</div><div class="field-value">{{ $moduleData['summary']['total'] }}</div></td>
            <td><div class="field-label">Total casualties</div><div class="field-value">{{ $moduleData['summary']['total_casualties'] }}</div></td>
        </tr>
        <tr>
            <td class="field-wide" colspan="2"><div class="field-label">Total estimated cost</div><div class="field-value">PHP {{ number_format($moduleData['summary']['total_est_cost'], 2) }}</div></td>
        </tr>
    </table>

    @if ($moduleData['data']['truncated'])
        @include('reports.pdf-partials._truncated', ['limit' => \App\Services\DetailedReportService::PDF_ROW_LIMIT])
    @endif

    <table class="data">
        <thead>
            <tr>
                <th style="width: 11%;">Date occurred</th>
                <th style="width: 24%;">Campus / school</th>
                <th style="width: 12%;">Incident type</th>
                <th style="width: 13%;">Status</th>
                <th>Description</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($moduleData['data']['rows'] as $row)
                <tr>
                    <td class="nowrap">{{ optional($row->date_occurred)->format('M d, Y') ?? '—' }}</td>
                    <td>{{ $row->campus->campus_name ?? '—' }}@if ($row->school?->school_name)<br><span class="sub">{{ $row->school->school_name }}</span>@endif</td>
                    <td>{{ $row->hazard_type }}</td>
                    <td><x-pdf.tag :value="$row->status" /></td>
                    <td class="wrap">{{ \Illuminate\Support\Str::limit($row->description, 150) ?: '—' }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <h3 class="subsection">Linked Damage Reports</h3>
    @if ($moduleData['damage_data']['rows']->isEmpty())
        <p class="empty">No damage reports for this period.</p>
    @else
        @if ($moduleData['damage_data']['truncated'])
            @include('reports.pdf-partials._truncated', ['limit' => \App\Services\DetailedReportService::PDF_ROW_LIMIT])
        @endif
        <table class="data">
            <thead>
                <tr>
                    <th style="width: 11%;">Incident date</th>
                    <th style="width: 24%;">Campus / school</th>
                    <th>Building / Area affected</th>
                    <th style="width: 12%;">Damage level</th>
                    <th class="num" style="width: 10%;">Casualties</th>
                    <th class="num" style="width: 15%;">Est. cost (PHP)</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($moduleData['damage_data']['rows'] as $row)
                    <tr>
                        <td class="nowrap">{{ optional($row->incident->date_occurred ?? null)->format('M d, Y') ?? '—' }}</td>
                        <td>{{ $row->incident->campus->campus_name ?? '—' }}@if ($row->incident?->school?->school_name)<br><span class="sub">{{ $row->incident->school->school_name }}</span>@endif</td>
                        <td>{{ $row->building->building_name ?? '—' }}</td>
                        <td><x-pdf.tag :value="$row->damage_level" /></td>
                        <td class="num">{{ $row->casualty_count }}</td>
                        <td class="num">{{ $row->est_cost !== null ? number_format($row->est_cost, 2) : '—' }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif
@endif
