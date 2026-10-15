{{--
    Drill Tracker — schedule. $moduleData: ['summary' => drillsSection(), 'data' => rowsForPdf()]
    Drills scheduled in the period, with whether each has been documented and
    reported. The submissions' contents are in their own consolidated reports.
--}}
<h2 class="section">Drill Tracker — Schedule</h2>
<p class="caption">Drills scheduled in the period. A drill counts as conducted once its documentation is submitted.</p>
@if ($moduleData['data']['total'] === 0)
    <p class="empty">No drills scheduled in this period.</p>
@else
    <table class="fields">
        <tr>
            <td><div class="field-label">Drills scheduled</div><div class="field-value">{{ $moduleData['summary']['scheduled'] }}</div></td>
            <td><div class="field-label">Conducted (documented)</div><div class="field-value">{{ $moduleData['summary']['total'] }}</div></td>
            <td><div class="field-label">After Activity Reports</div><div class="field-value">{{ $moduleData['summary']['after_activity_reports'] }}</div></td>
        </tr>
    </table>

    @if ($moduleData['data']['truncated'])
        @include('reports.pdf-partials._truncated', ['limit' => \App\Services\DetailedReportService::PDF_ROW_LIMIT, 'drill' => true])
    @endif

    {{-- Portrait page: objectives print on each drill's memorandum and show on its page. --}}
    <table class="data">
        <thead>
            <tr>
                <th style="width: 15%;">Date / time</th>
                <th style="width: 17%;">Campus</th>
                <th style="width: 14%;">Drill type</th>
                <th>Venue</th>
                <th style="width: 17%;">Partner agency</th>
                <th style="width: 19%;">Reports filed</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($moduleData['data']['rows'] as $row)
                <tr>
                    <td class="nowrap">{{ $row->scheduled_date->format('M d, Y') }}<br><span class="sub">{{ $row->scheduled_time?->format('g:i A') ?? '—' }}</span></td>
                    <td>{{ $row->campus->campus_name ?? '—' }}</td>
                    <td>{{ $row->drill_type }}</td>
                    <td class="wrap">{{ $row->venue }}</td>
                    <td>{{ $row->partner_agency ?? '—' }}</td>
                    <td class="nowrap">Documentation: {{ $row->documentation_exists ? 'Yes' : 'Not yet' }}<br>After Activity: {{ $row->after_activity_report_exists ? 'Yes' : 'Not yet' }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>
@endif
