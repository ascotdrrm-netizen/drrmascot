{{--
    $moduleData: ['summary' => ..., 'data' => rowsForPdf()]
    PRIVACY: no recipient phone numbers or contact details are queried or
    printed here — only the integer recipient_count.
--}}
<h2 class="section">SMS &amp; Broadcast Alert</h2>
@if ($moduleData['summary']['total'] === 0)
    <p class="empty">No records for this period.</p>
@else
    <table class="fields">
        <tr>
            <td><div class="field-label">Alerts sent</div><div class="field-value">{{ $moduleData['summary']['total'] }}</div></td>
            <td><div class="field-label">Total recipients</div><div class="field-value">{{ $moduleData['summary']['total_recipients'] }}</div></td>
        </tr>
    </table>

    <table class="data" style="width: 45%;">
        <thead>
            <tr>
                <th>Delivery status</th>
                <th class="num" style="width: 30%;">Count</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($moduleData['summary']['by_delivery_status'] as $status => $count)
                <tr>
                    <td><x-pdf.tag :value="$status" /></td>
                    <td class="num">{{ $count }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    @if ($moduleData['data']['truncated'])
        @include('reports.pdf-partials._truncated', ['limit' => \App\Services\DetailedReportService::PDF_ROW_LIMIT])
    @endif

    <table class="data">
        <thead>
            <tr>
                <th style="width: 14%;">Sent at</th>
                <th style="width: 17%;">Campus / school (via incident)</th>
                <th style="width: 16%;">Linked incident</th>
                <th>Message</th>
                <th style="width: 13%;">Delivery</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($moduleData['data']['rows'] as $row)
                <tr>
                    <td class="nowrap">{{ optional($row->sent_at)->format('M d, Y g:i A') ?? '—' }}</td>
                    <td>{{ $row->incident->campus->campus_name ?? '—' }}@if ($row->incident?->school?->school_name)<br><span class="sub">{{ $row->incident->school->school_name }}</span>@endif</td>
                    <td>{{ $row->incident ? $row->incident->hazard_type . ' — ' . optional($row->incident->date_occurred)->format('M d, Y') : '—' }}</td>
                    <td class="wrap">{{ \Illuminate\Support\Str::limit($row->message, 150) }}</td>
                    <td><x-pdf.tag :value="$row->delivery_status" /><br><span class="sub">{{ $row->recipient_count }} {{ \Illuminate\Support\Str::plural('recipient', $row->recipient_count) }}</span></td>
                </tr>
            @endforeach
        </tbody>
    </table>
@endif
