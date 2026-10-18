@extends('reports.pdf.document')

@php
    $docTitle = 'Consolidated DRRM Report';
    $docSubtitle = $period['label'];
    $docCampusLabel = $report['campus']['name'];
    $docSchoolLabel = $report['school']['name'];
    $docCampus = $report['campus']['id'] ? \App\Models\Campus::find($report['campus']['id']) : null;
    $docReferenceNote = 'Not assigned — period reports summarise many records';
    $docFiguresNote = true;
@endphp

@section('content')
    <h2 class="section">Executive Summary</h2>
    <table class="data">
        <thead>
            <tr>
                <th>Metric</th>
                <th class="num" style="width: 15%;">Current period</th>
                <th class="num" style="width: 15%;">Previous period</th>
                <th class="num" style="width: 12%;">Change</th>
                <th class="num" style="width: 12%;">% Change</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($report['executive_summary'] as $row)
                <tr>
                    <td>{{ $row['label'] }}</td>
                    <td class="num">{{ $row['current'] }}</td>
                    <td class="num">{{ $row['previous'] }}</td>
                    <td class="num">{{ $row['change'] > 0 ? '+' : '' }}{{ $row['change'] }}</td>
                    <td class="num">
                        @if ($row['change_pct'] === null)
                            @if ($row['previous'] === 0 && $row['current'] > 0)
                                New
                            @else
                                —
                            @endif
                        @else
                            {{ $row['change_pct'] > 0 ? '+' : '' }}{{ $row['change_pct'] }}%
                        @endif
                    </td>
                </tr>
            @endforeach
        </tbody>
    </table>
    <p class="caption">Compared with {{ $period['previous_label'] }}. Signs (+/−) show direction, so the table reads correctly in black and white.</p>

    <h2 class="section">Facility Hazard Auditor</h2>
    @if (! $report['hazard_auditor']['has_data'])
        <p class="empty">No records for this period.</p>
    @else
        <table class="fields">
            <tr>
                <td><div class="field-label">Inspections conducted</div><div class="field-value">{{ $report['hazard_auditor']['inspections_conducted'] }}</div></td>
                <td><div class="field-label">Buildings inspected</div><div class="field-value">{{ $report['hazard_auditor']['buildings_inspected'] }}</div></td>
            </tr>
        </table>

        @php $riskTotal = array_sum($report['hazard_auditor']['by_risk_level']); @endphp
        <div class="keep-together">
            <table class="data">
                <thead>
                    <tr>
                        <th style="width: 20%;">Risk level</th>
                        <th class="num" style="width: 14%;">Inspections</th>
                        <th class="num" style="width: 12%;">Share</th>
                        <th>Distribution</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($report['hazard_auditor']['by_risk_level'] as $level => $count)
                        @php $pct = $riskTotal > 0 ? round($count / $riskTotal * 100) : 0; @endphp
                        <tr>
                            <td><x-pdf.tag :value="$level" /></td>
                            <td class="num">{{ $count }}</td>
                            <td class="num">{{ $pct }}%</td>
                            <td><div class="bar-track"><div class="bar-fill" style="width: {{ $pct }}%;"></div></div></td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif

    <h2 class="section">Incident Reporting</h2>
    @if (! $report['incidents']['has_data'])
        <p class="empty">No records for this period.</p>
    @else
        <table class="fields">
            <tr>
                <td><div class="field-label">Total incidents</div><div class="field-value">{{ $report['incidents']['total'] }}</div></td>
                <td><div class="field-label">Total casualties</div><div class="field-value">{{ $report['incidents']['total_casualties'] }}</div></td>
            </tr>
            <tr>
                <td class="field-wide" colspan="2"><div class="field-label">Total estimated cost</div><div class="field-value">PHP {{ number_format($report['incidents']['total_est_cost'], 2) }}</div></td>
            </tr>
        </table>

        @php
            $hazardRows = $report['incidents']['by_hazard_type'];
            $statusRows = $report['incidents']['by_status'];
            $maxRows = max(count($hazardRows), count($statusRows));
            $hazardKeys = array_keys($hazardRows);
            $statusKeys = array_keys($statusRows);
        @endphp
        <div class="keep-together">
            <table class="data">
                <thead>
                    <tr>
                        <th>By incident type</th>
                        <th class="num" style="width: 12%;">Count</th>
                        <th>By status</th>
                        <th class="num" style="width: 12%;">Count</th>
                    </tr>
                </thead>
                <tbody>
                    @for ($i = 0; $i < $maxRows; $i++)
                        <tr>
                            <td>{{ $hazardKeys[$i] ?? '' }}</td>
                            <td class="num">{{ isset($hazardKeys[$i]) ? $hazardRows[$hazardKeys[$i]] : '' }}</td>
                            <td>@isset($statusKeys[$i])<x-pdf.tag :value="$statusKeys[$i]" />@endisset</td>
                            <td class="num">{{ isset($statusKeys[$i]) ? $statusRows[$statusKeys[$i]] : '' }}</td>
                        </tr>
                    @endfor
                </tbody>
            </table>
        </div>

        <div class="keep-together">
            <table class="data">
                <thead>
                    <tr>
                        <th>Damage level (from damage reports)</th>
                        <th class="num" style="width: 12%;">Count</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($report['incidents']['by_damage_level'] as $level => $count)
                        <tr>
                            <td><x-pdf.tag :value="$level" /></td>
                            <td class="num">{{ $count }}</td>
                        </tr>
                    @empty
                        <tr><td class="none" colspan="2">No damage reports recorded.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    @endif

    @php $cases = $report['reporter_cases']; @endphp
    <h2 class="section">Reporter Reports &amp; SOS</h2>
    @if (! $cases['has_data'])
        <p class="empty">No reports or SOS were sent from the Reporter app in this period.</p>
    @else
        <table class="fields">
            <tr>
                <td><div class="field-label">Reports received</div><div class="field-value">{{ $cases['reports_total'] }}</div></td>
                <td><div class="field-label">Reports resolved</div><div class="field-value">{{ $cases['reports_by_status']['Resolved'] }}</div></td>
            </tr>
            <tr>
                <td><div class="field-label">SOS received</div><div class="field-value">{{ $cases['sos_total'] }}</div></td>
                <td><div class="field-label">SOS resolved</div><div class="field-value">{{ $cases['sos_by_status']['Resolved'] }}</div></td>
            </tr>
        </table>
        @php
            $reportRows = array_map(null, array_keys($cases['reports_by_status']), array_values($cases['reports_by_status']));
            $sosRows = array_map(null, array_keys($cases['sos_by_status']), array_values($cases['sos_by_status']));
        @endphp
        <div class="keep-together">
            <table class="data">
                <thead>
                    <tr>
                        <th>Reports by status</th>
                        <th class="num" style="width: 12%;">Count</th>
                        <th>SOS by status</th>
                        <th class="num" style="width: 12%;">Count</th>
                    </tr>
                </thead>
                <tbody>
                    @for ($i = 0; $i < max(count($reportRows), count($sosRows)); $i++)
                        <tr>
                            <td>{{ $reportRows[$i][0] ?? '' }}</td>
                            <td class="num">{{ $reportRows[$i][1] ?? '' }}</td>
                            <td>{{ $sosRows[$i][0] ?? '' }}</td>
                            <td class="num">{{ $sosRows[$i][1] ?? '' }}</td>
                        </tr>
                    @endfor
                </tbody>
            </table>
        </div>
    @endif

    <h2 class="section">Drill Tracker</h2>
    @if (! $report['drills']['has_data'])
        <p class="empty">No records for this period.</p>
    @else
        <table class="fields">
            <tr>
                <td><div class="field-label">Drills scheduled</div><div class="field-value">{{ $report['drills']['scheduled'] }}</div></td>
                <td><div class="field-label">Conducted (documented)</div><div class="field-value">{{ $report['drills']['total'] }}</div></td>
            </tr>
            <tr>
                <td><div class="field-label">After Activity Reports</div><div class="field-value">{{ $report['drills']['after_activity_reports'] }}</div></td>
                <td><div class="field-label">Participants (approximate)</div><div class="field-value">{{ $report['drills']['total_participants'] }}</div></td>
            </tr>
        </table>

        <div class="keep-together">
            <table class="data">
                <thead>
                    <tr>
                        <th>Drill type</th>
                        <th class="num" style="width: 12%;">Count</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($report['drills']['by_type'] as $type => $count)
                        <tr>
                            <td>{{ $type }}</td>
                            <td class="num">{{ $count }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif

    <h2 class="section">SMS &amp; Broadcast Alert</h2>
    @if (! $report['alerts']['has_data'])
        <p class="empty">No records for this period.</p>
    @else
        <table class="fields">
            <tr>
                <td><div class="field-label">Alerts sent</div><div class="field-value">{{ $report['alerts']['total'] }}</div></td>
                <td><div class="field-label">Total recipients</div><div class="field-value">{{ $report['alerts']['total_recipients'] }}</div></td>
            </tr>
        </table>

        <div class="keep-together">
            <table class="data">
                <thead>
                    <tr>
                        <th>Delivery status</th>
                        <th class="num" style="width: 12%;">Count</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($report['alerts']['by_delivery_status'] as $status => $count)
                        <tr>
                            <td><x-pdf.tag :value="$status" /></td>
                            <td class="num">{{ $count }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif

    {{-- Disaster Advisories: issued in the period, with the "followed" rule stated. --}}
    @php $advisories = $report['advisories']; @endphp
    <h2 class="section">Disaster Advisories</h2>
    <p class="lead" data-advisories-caption>
        Advisories issued in the period. Followed by an incident: at least one incident was recorded from the advisory,
        whenever it was recorded — an advisory issued Sep 29 whose incident was recorded Oct 2 counts.
        @if ($advisories['campus_filtered']) Includes all-campus advisories. @endif
        @if ($advisories['school_filtered']) Advisories are issued per campus and are not attributed to a school: these are the campus’s figures. @endif
    </p>
    @if (! $advisories['has_data'])
        <p class="empty">No advisories were issued in this period.</p>
    @else
        <table class="fields">
            <tr>
                <td><div class="field-label">Advisories issued</div><div class="field-value">{{ $advisories['total'] }}</div></td>
                <td><div class="field-label">Followed by an incident</div><div class="field-value">{{ $advisories['followed'] }}</div></td>
            </tr>
            <tr>
                <td class="field-wide" colspan="2"><div class="field-label">Not followed</div><div class="field-value">{{ $advisories['not_followed'] }}</div></td>
            </tr>
        </table>
        <div class="keep-together">
            <table class="data">
                <thead>
                    <tr>
                        <th>By hazard type</th>
                        <th class="num" style="width: 12%;">Count</th>
                        <th>By source</th>
                        <th class="num" style="width: 12%;">Count</th>
                    </tr>
                </thead>
                <tbody>
                    @php
                        $hazardRows = array_map(null, array_keys($advisories['by_hazard_type']), array_values($advisories['by_hazard_type']));
                        $sourceRows = array_map(null, array_keys($advisories['by_source']), array_values($advisories['by_source']));
                    @endphp
                    @for ($i = 0; $i < max(count($hazardRows), count($sourceRows)); $i++)
                        <tr>
                            <td>{{ $hazardRows[$i][0] ?? '' }}</td>
                            <td class="num">{{ $hazardRows[$i][1] ?? '' }}</td>
                            <td>{{ $sourceRows[$i][0] ?? '' }}</td>
                            <td class="num">{{ $sourceRows[$i][1] ?? '' }}</td>
                        </tr>
                    @endfor
                </tbody>
            </table>
        </div>
    @endif

    @if ($report['campus_breakdown'] !== null)
        <h2 class="section">Per-Campus Breakdown</h2>
        <div class="keep-together">
            <table class="data">
                <thead>
                    <tr>
                        <th>Campus</th>
                        <th class="num" style="width: 13%;">Inspections</th>
                        <th class="num" style="width: 13%;">Incidents</th>
                        <th class="num" style="width: 13%;">Drills</th>
                        <th class="num" style="width: 13%;">Alerts</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($report['campus_breakdown'] as $row)
                        <tr>
                            <td>{{ $row['campus_name'] }}</td>
                            <td class="num">{{ $row['inspections'] }}</td>
                            <td class="num">{{ $row['incidents'] }}</td>
                            <td class="num">{{ $row['drills'] }}</td>
                            <td class="num">{{ $row['alerts'] }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif

    {{-- Per-school breakdown, alongside the per-campus one. Drills have no school, so they aren't in it. --}}
    <h2 class="section">Per-School Breakdown</h2>
    <div class="keep-together">
        <table class="data">
            <thead>
                <tr>
                    <th>School</th>
                    <th style="width: 20%;">Campus</th>
                    <th class="num" style="width: 11%;">Inspections</th>
                    <th class="num" style="width: 11%;">Incidents</th>
                    <th class="num" style="width: 11%;">Alerts</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($report['school_breakdown'] as $row)
                    <tr>
                        <td>{{ $row['school_name'] }}</td>
                        <td><x-pdf.value :value="$row['campus_name']" empty="—" /></td>
                        <td class="num">{{ $row['inspections'] }}</td>
                        <td class="num">{{ $row['incidents'] }}</td>
                        <td class="num">{{ $row['alerts'] }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
@endsection
