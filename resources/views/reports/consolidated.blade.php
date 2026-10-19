@extends('layouts.app')

@section('title', 'Consolidated DRRM Report - ASCOT DRRMS')
@section('charts', 'yes')
@section('heading', 'Consolidated DRRM Report')
@section('subheading', Auth::user()->role === 'DRRMO Admin'
    ? 'Every module summed up for one period, per campus and per school. For one module\'s full list of records, use Generate Report on that module\'s page.'
    : 'Your own records in every module, summed up for one period. For one module\'s full list of records, use Generate Report on that module\'s page.')

@section('breadcrumb')
    <x-back-link href="{{ route((Auth::user()->role === 'DRRMO Admin' ? 'admin' : 'coordinator') . '.dashboard') }}">Back to dashboard</x-back-link>
@endsection

@section('actions')
    <a href="{{ route('reports.consolidated.pdf', $filters) }}" data-confirm="Download this summary as a PDF?" class="btn btn-secondary">Download PDF</a>
@endsection

@section('content')
    {{-- The shared filter bar: Period (the report's picker; the report always has
         a period, so no "All dates"), then Campus for the Admin, then School. The
         line names the period and what it covers, compared with the period before. --}}
    <x-filter-bar :action="route('reports.consolidated.index')" :summary="$filterSummary" :applied="$filtersApplied"
                  :controls="array_values(array_filter([
                      ['kind' => 'period', 'values' => $periodValues],
                      $campuses ? ['kind' => 'campus', 'campuses' => $campuses, 'selected' => $selectedCampusId] : null,
                      ['kind' => 'school', 'schools' => $schools, 'campusSource' => $campuses ? 'campus_id' : null,
                       'campusId' => $campuses ? $selectedCampusId : $userCampusId, 'selected' => $selectedSchoolId],
                  ]))" />

    <h2 class="section-title mb-4">Executive Summary</h2>
    {{-- Five rows: five across at the widest breakpoint (a 4 + 1 wrap reads as an accident). --}}
    <div class="drrms-stagger page-section grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-5">
        @foreach ($report['executive_summary'] as $row)
            <div class="card flex flex-col break-words">
                <p class="text-sm font-medium text-gray-600">{{ $row['label'] }}</p>
                <p class="stat-value mt-4">{{ $row['current'] }}</p>
                <div class="mt-auto pt-4">
                <p class="border-t border-gray-100 pt-4 text-xs text-gray-600">
                    @if ($row['change_pct'] === null)
                        @if ($row['previous'] === 0 && $row['current'] > 0)
                            <span class="font-medium text-gray-600">No data last period</span>
                        @else
                            <span class="text-gray-600">No data either period</span>
                        @endif
                    @else
                        <span class="font-medium {{ $row['change'] > 0 ? 'text-green-700' : ($row['change'] < 0 ? 'text-red-600' : 'text-gray-600') }}">
                            {{ $row['change'] > 0 ? '+' : '' }}{{ $row['change'] }} ({{ $row['change_pct'] > 0 ? '+' : '' }}{{ $row['change_pct'] }}%)
                        </span>
                        <span class="text-gray-600">vs. last period</span>
                    @endif
                </p>
                </div>
            </div>
        @endforeach
    </div>

    <div class="page-section grid grid-cols-1 gap-6 lg:grid-cols-2">
        <x-card>
            <h3 class="card-title mb-4">Incidents by type</h3>
            @if ($report['incidents']['has_data'])
                <div class="h-48 lg:h-56">
                    <canvas id="incidentsByTypeChart"></canvas>
                </div>
            @else
                <x-empty-state icon="chart" message="No records for this period." />
            @endif
        </x-card>
        <x-card>
            <h3 class="card-title mb-4">Hazard Inspections by Risk Level</h3>
            @if ($report['hazard_auditor']['has_data'])
                <div class="h-48 lg:h-56">
                    <canvas id="riskLevelChart"></canvas>
                </div>
            @else
                <x-empty-state icon="chart" message="No records for this period." />
            @endif
        </x-card>
    </div>

    <div class="page-section">
        <h2 class="section-title mb-4">Facility Hazard Auditor</h2>
        <x-card>
            @if (! $report['hazard_auditor']['has_data'])
                <x-empty-state icon="chart" message="No records for this period." />
            @else
                <div class="mb-4 grid grid-cols-2 gap-4 text-sm">
                    <div><span class="text-gray-600">Inspections conducted:</span> <span class="font-semibold text-gray-900">{{ $report['hazard_auditor']['inspections_conducted'] }}</span></div>
                    <div><span class="text-gray-600">Buildings inspected:</span> <span class="font-semibold text-gray-900">{{ $report['hazard_auditor']['buildings_inspected'] }}</span></div>
                </div>
                @php
                    $riskBadgeClasses = [
                        'Low' => 'bg-green-100 text-green-700',
                        'Moderate' => 'bg-amber-100 text-amber-700',
                        'High' => 'bg-orange-100 text-orange-800',
                        'Very High' => 'bg-red-100 text-red-700',
                    ];
                @endphp
                <div class="flex flex-wrap gap-4">
                    @foreach ($report['hazard_auditor']['by_risk_level'] as $level => $count)
                        <span class="inline-flex items-center rounded-full px-2 py-1 text-xs font-semibold leading-none {{ $riskBadgeClasses[$level] ?? 'bg-gray-100 text-gray-700' }}">
                            {{ $level }}: {{ $count }}
                        </span>
                    @endforeach
                </div>
            @endif
        </x-card>
    </div>

    <div class="page-section">
        <h2 class="section-title mb-4">Incident Reporting</h2>
        <x-card>
            @if (! $report['incidents']['has_data'])
                <x-empty-state icon="chart" message="No records for this period." />
            @else
                <div class="mb-4 grid grid-cols-2 gap-4 text-sm md:grid-cols-4">
                    <div><span class="text-gray-600">Total incidents:</span> <span class="font-semibold text-gray-900">{{ $report['incidents']['total'] }}</span></div>
                    <div><span class="text-gray-600">Total casualties:</span> <span class="font-semibold text-gray-900">{{ $report['incidents']['total_casualties'] }}</span></div>
                    <div class="md:col-span-2"><span class="text-gray-600">Estimated cost:</span> <span class="font-semibold text-gray-900">PHP {{ number_format($report['incidents']['total_est_cost'], 2) }}</span></div>
                </div>
                <div class="table-wrap -mx-6 border-t border-gray-100">
                    <table class="data-table text-sm">
                        <thead>
                            <tr>
                                <th class="table-th">By Status</th>
                                <th class="table-th num">Count</th>
                                <th class="table-th">By Damage Level</th>
                                <th class="table-th num">Count</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            @php
                                $statusKeys = array_keys($report['incidents']['by_status']);
                                $damageKeys = array_keys($report['incidents']['by_damage_level']);
                                $maxRows = max(count($statusKeys), count($damageKeys));
                            @endphp
                            @for ($i = 0; $i < $maxRows; $i++)
                                <tr>
                                    <td class="table-td">{{ $statusKeys[$i] ?? '' }}</td>
                                    <td class="table-td num">{{ isset($statusKeys[$i]) ? $report['incidents']['by_status'][$statusKeys[$i]] : '' }}</td>
                                    <td class="table-td">{{ $damageKeys[$i] ?? '' }}</td>
                                    <td class="table-td num">{{ isset($damageKeys[$i]) ? $report['incidents']['by_damage_level'][$damageKeys[$i]] : '' }}</td>
                                </tr>
                            @endfor
                        </tbody>
                    </table>
                </div>
            @endif
        </x-card>
    </div>

    @php $cases = $report['reporter_cases']; @endphp
    <div class="page-section">
        <h2 class="section-title mb-4">Reporter Reports &amp; SOS</h2>
        <x-card>
            @if (! $cases['has_data'])
                <x-empty-state icon="chart" message="No reports or SOS were sent from the Reporter app in this period." />
            @else
                <div class="mb-4 grid grid-cols-2 gap-4 text-sm md:grid-cols-4">
                    <div><span class="text-gray-600">Reports received:</span> <span class="font-semibold text-gray-900">{{ $cases['reports_total'] }}</span></div>
                    <div><span class="text-gray-600">Reports resolved:</span> <span class="font-semibold text-gray-900">{{ $cases['reports_by_status']['Resolved'] }}</span></div>
                    <div><span class="text-gray-600">SOS received:</span> <span class="font-semibold text-gray-900">{{ $cases['sos_total'] }}</span></div>
                    <div><span class="text-gray-600">SOS resolved:</span> <span class="font-semibold text-gray-900">{{ $cases['sos_by_status']['Resolved'] }}</span></div>
                </div>
                <div class="table-wrap -mx-6 border-t border-gray-100">
                    <table class="data-table text-sm">
                        <thead>
                            <tr>
                                <th class="table-th">Reports by status</th>
                                <th class="table-th num">Count</th>
                                <th class="table-th">SOS by status</th>
                                <th class="table-th num">Count</th>
                                <th class="table-th">Reports by type</th>
                                <th class="table-th num">Count</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            @php
                                $reportRows = array_map(null, array_keys($cases['reports_by_status']), array_values($cases['reports_by_status']));
                                $sosRows = array_map(null, array_keys($cases['sos_by_status']), array_values($cases['sos_by_status']));
                                $typeRows = array_map(null, array_keys($cases['reports_by_category']), array_values($cases['reports_by_category']));
                                $maxRows = max(count($reportRows), count($sosRows), count($typeRows));
                            @endphp
                            @for ($i = 0; $i < $maxRows; $i++)
                                <tr>
                                    <td class="table-td">{{ $reportRows[$i][0] ?? '' }}</td>
                                    <td class="table-td num">{{ $reportRows[$i][1] ?? '' }}</td>
                                    <td class="table-td">{{ $sosRows[$i][0] ?? '' }}</td>
                                    <td class="table-td num">{{ $sosRows[$i][1] ?? '' }}</td>
                                    <td class="table-td">{{ $typeRows[$i][0] ?? '' }}</td>
                                    <td class="table-td num">{{ $typeRows[$i][1] ?? '' }}</td>
                                </tr>
                            @endfor
                        </tbody>
                    </table>
                </div>
                @if ($cases['school_filtered'])
                    <p class="form-help mt-3">Filtered by the reporter's school.</p>
                @endif
            @endif
        </x-card>
    </div>

    <div class="page-section grid grid-cols-1 gap-6 lg:grid-cols-2">
        <div class="flex flex-col">
            <h2 class="section-title mb-4">Drill Tracker</h2>
            <x-card class="flex-1">
                @if (! $report['drills']['has_data'])
                    <x-empty-state icon="chart" message="No records for this period." />
                @else
                    <div class="mb-4 text-sm leading-7">
                        <div><span class="text-gray-600">Drills scheduled:</span> <span class="font-semibold text-gray-900">{{ $report['drills']['scheduled'] }}</span></div>
                        <div><span class="text-gray-600">Conducted (documented):</span> <span class="font-semibold text-gray-900">{{ $report['drills']['total'] }}</span></div>
                        <div><span class="text-gray-600">After Activity Reports:</span> <span class="font-semibold text-gray-900">{{ $report['drills']['after_activity_reports'] }}</span></div>
                        <div><span class="text-gray-600">Participants (approximate):</span> <span class="font-semibold text-gray-900">{{ $report['drills']['total_participants'] }}</span></div>
                    </div>
                    <ul class="divide-y divide-gray-100 text-sm">
                        @foreach ($report['drills']['by_type'] as $type => $count)
                            <li class="flex justify-between gap-4 py-4 first:pt-0 last:pb-0">
                                <span class="text-gray-600">{{ $type }}</span>
                                <span class="font-medium text-gray-900">{{ $count }}</span>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </x-card>
        </div>

        <div class="flex flex-col">
            <h2 class="section-title mb-4">SMS &amp; Broadcast Alert</h2>
            <x-card class="flex-1">
                @if (! $report['alerts']['has_data'])
                    <x-empty-state icon="chart" message="No records for this period." />
                @else
                    <div class="mb-4 grid grid-cols-2 gap-4 text-sm">
                        <div><span class="text-gray-600">Alerts sent:</span> <span class="font-semibold text-gray-900">{{ $report['alerts']['total'] }}</span></div>
                        <div><span class="text-gray-600">Total recipients:</span> <span class="font-semibold text-gray-900">{{ $report['alerts']['total_recipients'] }}</span></div>
                    </div>
                    <div class="h-48 lg:h-56">
                        <canvas id="alertsByStatusChart"></canvas>
                    </div>
                @endif
            </x-card>
        </div>
    </div>

    {{-- Disaster Advisories: issued in the period. "Followed" is the one figure a
         reader could misread, so its rule is stated where it's shown. --}}
    @php $advisories = $report['advisories']; @endphp
    <div class="page-section" data-report-advisories>
        <div class="mb-4">
            <h2 class="section-title">Disaster Advisories</h2>
            <p class="guide-note" data-advisories-caption>
                Advisories issued in the period. Followed by an incident: at least one incident was recorded from the advisory,
                whenever it was recorded — an advisory issued Sep 29 whose incident was recorded Oct 2 counts.
                @if ($advisories['campus_filtered']) Includes all-campus advisories. @endif
                @if ($advisories['school_filtered']) Advisories are issued per campus and are not attributed to a school: these are the campus’s figures. @endif
            </p>
        </div>
        <x-card>
            @if (! $advisories['has_data'])
                <x-empty-state icon="disasters" message="No advisories were issued in this period." />
            @else
                <div class="mb-4 grid grid-cols-1 gap-4 text-sm sm:grid-cols-3">
                    <div><span class="text-gray-600">Advisories issued:</span> <span class="font-semibold text-gray-900">{{ $advisories['total'] }}</span></div>
                    <div><span class="text-gray-600">Followed by an incident:</span> <span class="font-semibold text-gray-900">{{ $advisories['followed'] }}</span></div>
                    <div><span class="text-gray-600">Not followed:</span> <span class="font-semibold text-gray-900">{{ $advisories['not_followed'] }}</span></div>
                </div>
                <div class="grid grid-cols-1 gap-6 border-t border-gray-100 pt-4 md:grid-cols-2">
                    <div>
                        <h3 class="mb-2 text-sm font-semibold text-gray-900">By hazard type</h3>
                        <ul class="divide-y divide-gray-100 text-sm">
                            @foreach ($advisories['by_hazard_type'] as $hazard => $count)
                                <li class="flex justify-between gap-4 py-2"><span class="text-gray-600">{{ $hazard }}</span><span class="font-medium text-gray-900">{{ $count }}</span></li>
                            @endforeach
                        </ul>
                    </div>
                    <div>
                        <h3 class="mb-2 text-sm font-semibold text-gray-900">By source</h3>
                        <ul class="divide-y divide-gray-100 text-sm">
                            @foreach ($advisories['by_source'] as $source => $count)
                                <li class="flex justify-between gap-4 py-2"><span class="text-gray-600">{{ $source }}</span><span class="font-medium text-gray-900">{{ $count }}</span></li>
                            @endforeach
                        </ul>
                    </div>
                </div>
            @endif
        </x-card>
    </div>

    @if ($report['campus_breakdown'] !== null)
        <div class="page-section">
            <h2 class="section-title mb-4">Per-Campus Breakdown</h2>
            <x-card flush>
                <div class="table-wrap">
                    <table class="data-table text-sm">
                        <thead>
                            <tr>
                                <th class="table-th">Campus</th>
                                <th class="table-th num">Inspections</th>
                                <th class="table-th num">Incidents</th>
                                <th class="table-th num">Drills</th>
                                <th class="table-th num">Alerts</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            @foreach ($report['campus_breakdown'] as $row)
                                <tr>
                                    <td class="table-td font-medium text-gray-900">{{ $row['campus_name'] }}</td>
                                    <td class="table-td num">{{ $row['inspections'] }}</td>
                                    <td class="table-td num">{{ $row['incidents'] }}</td>
                                    <td class="table-td num">{{ $row['drills'] }}</td>
                                    <td class="table-td num">{{ $row['alerts'] }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </x-card>
        </div>
    @endif

    {{-- Per-school breakdown, alongside (not replacing) the per-campus one. "Not assigned" = records with no school. --}}
    <div class="page-section" data-school-breakdown>
        <div class="mb-4">
            <h2 class="section-title">Per-School Breakdown</h2>
            <p class="guide-note">{{ config('glossary.not_assigned') }} Drills are campus-wide and have no school, so they appear in the per-campus figures only.
                Disaster advisories aren’t broken down at all: an all-campus advisory belongs to every campus, so the rows would not add up to the total.</p>
        </div>
        <x-card flush>
            <div class="table-wrap">
                <table class="data-table text-sm">
                    <thead>
                        <tr>
                            <th class="table-th">School</th>
                            <th class="table-th">Campus</th>
                            <th class="table-th num">Inspections</th>
                            <th class="table-th num">Incidents</th>
                            <th class="table-th num">Alerts</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        @foreach ($report['school_breakdown'] as $row)
                            <tr>
                                <td class="table-td font-medium {{ $row['campus_name'] === null ? 'italic text-gray-600' : 'text-gray-900' }}">{{ $row['school_name'] }}</td>
                                <td class="table-td text-gray-600">{{ $row['campus_name'] ?? '—' }}</td>
                                <td class="table-td num">{{ $row['inspections'] }}</td>
                                <td class="table-td num">{{ $row['incidents'] }}</td>
                                <td class="table-td num">{{ $row['alerts'] }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </x-card>
    </div>

    @push('scripts')
        <script>
            (function () {
                var incidentsByType = @json($report['incidents']['by_hazard_type']);
                var riskLevels = @json($report['hazard_auditor']['by_risk_level']);
                var alertsByStatus = @json($report['alerts']['by_delivery_status']);

                if (Object.keys(incidentsByType).length) {
                    new Chart(document.getElementById('incidentsByTypeChart'), {
                        type: 'bar',
                        data: {
                            labels: Object.keys(incidentsByType),
                            datasets: [{
                                label: 'Incidents',
                                data: Object.values(incidentsByType),
                                backgroundColor: getComputedStyle(document.documentElement).getPropertyValue('--accent').trim() || '#c2410c',
                                borderRadius: 4,
                            }],
                        },
                        options: {
                            responsive: true,
                            maintainAspectRatio: false,
                            plugins: { legend: { display: false } },
                            scales: { y: { beginAtZero: true, ticks: { precision: 0 } } },
                        },
                    });
                }

                if (Object.values(riskLevels).some(function (v) { return v > 0; })) {
                    new Chart(document.getElementById('riskLevelChart'), {
                        type: 'doughnut',
                        data: {
                            labels: Object.keys(riskLevels),
                            datasets: [{
                                data: Object.values(riskLevels),
                                backgroundColor: @json(array_map(fn ($c) => $c[0], array_values(\App\Support\RiskMatrix::COLOURS))),
                            }],
                        },
                        options: {
                            responsive: true,
                            maintainAspectRatio: false,
                            plugins: { legend: { position: 'bottom' } },
                        },
                    });
                }

                var alertsChartEl = document.getElementById('alertsByStatusChart');
                if (alertsChartEl && Object.keys(alertsByStatus).length) {
                    new Chart(alertsChartEl, {
                        type: 'bar',
                        data: {
                            labels: Object.keys(alertsByStatus),
                            datasets: [{
                                label: 'Alerts',
                                data: Object.values(alertsByStatus),
                                backgroundColor: ['#16a34a', '#f59e0b', '#dc2626'],
                                borderRadius: 4,
                            }],
                        },
                        options: {
                            responsive: true,
                            maintainAspectRatio: false,
                            indexAxis: 'y',
                            plugins: { legend: { display: false } },
                            scales: { x: { beginAtZero: true, ticks: { precision: 0 } } },
                        },
                    });
                }
            })();
        </script>
    @endpush
@endsection
