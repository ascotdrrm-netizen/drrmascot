{{--
    Shared analytics body for both dashboards, laid out like the reference
    dashboards (docs/reference/inspo-dashboard-*.jpg): a KPI strip, the main
    chart, two smaller charts and a "needs attention" table on the left; a
    right column with the situation right now, quick actions, drills and
    recent activity. $campuses is non-null only for the DRRMO Admin (campus
    filter); the Coordinator is locked server-side to their own campus.
    drills_per_campus is null unless Admin + All Campuses.

    Colour: orange (accent) for actions and the one highlighted chart bar;
    red/amber/green only for status and risk, always with a word beside them.
--}}
@php
    $kpis = $analytics['kpis'];
    $charts = $analytics['charts'];
    $lists = $analytics['lists'];
    $period = $analytics['period'];
    $statusBadges = [
        'Active' => 'bg-red-100 text-red-700',
        'Under Response' => 'bg-amber-100 text-amber-700',
        'Resolved' => 'bg-green-100 text-green-700',
    ];
    $hasDrillsChart = $charts['drills_per_campus'] !== null;
    $isCoordinator = $routePrefix === 'coordinator';
    $rowMeta = 'block truncate text-xs leading-5 text-gray-500';
    $initialsOf = function (?string $name) {
        $parts = preg_split('/\s+/', trim((string) $name)) ?: [''];
        return strtoupper(mb_substr($parts[0], 0, 1) . (count($parts) > 1 ? mb_substr(end($parts), 0, 1) : '')) ?: '·';
    };
@endphp

<x-filter-bar :action="route($routePrefix . '.dashboard')" :summary="$filterSummary" :applied="$filtersApplied"
              :controls="array_values(array_filter([
                  ['kind' => 'select', 'name' => 'range', 'label' => 'Time range', 'asPeriod' => true, 'options' => $ranges, 'selected' => $range],
                  $campuses ? ['kind' => 'campus', 'campuses' => $campuses, 'selected' => $campusId] : null,
              ]))" />

<div class="grid grid-cols-1 gap-6 xl:grid-cols-[minmax(0,1fr)_22rem]">
    {{-- ================= Left: numbers, charts, what needs attention ================= --}}
    <div class="min-w-0 space-y-6">
        {{-- Needs attention: current state, regardless of the time range. --}}
        <div class="card-flush">
            <div class="flex flex-wrap items-center justify-between gap-4 px-6 pt-6">
                <div>
                    <h2 class="card-title">Needs attention</h2>
                    <p class="caption leading-6">Unresolved incidents, regardless of the time range</p>
                </div>
                <span class="chip border-red-200 bg-red-50 text-red-700">
                    <x-icon name="crisis_alert" class="h-4 w-4" /> {{ $lists['unresolved_incidents']['total'] }} unresolved
                </span>
            </div>
            @if (count($lists['unresolved_incidents']['items']))
                <div class="table-wrap mt-4">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th class="table-th">Incident type</th>
                                <th class="table-th">Campus</th>
                                <th class="table-th">Date occurred</th>
                                <th class="table-th">Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($lists['unresolved_incidents']['items'] as $incident)
                                <tr>
                                    <td class="table-td"><a href="{{ route("{$routePrefix}.incidents.show", $incident) }}" class="font-medium text-gray-900 hover:text-accent-ink">{{ $incident->hazard_type }}</a></td>
                                    <td class="table-td text-gray-600">{{ $incident->campus_name ?? '—' }}</td>
                                    <td class="table-td whitespace-nowrap text-gray-600">{{ $incident->date_occurred->format('M d, Y') }}</td>
                                    <td class="table-td"><x-badge :value="$incident->status" :map="$statusBadges" /></td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @else
                <div class="px-6 pb-6"><x-empty-state class="!py-4" icon="check" :message="$isCoordinator ? 'You have no unresolved incidents.' : 'No unresolved incidents.'" /></div>
            @endif

            <div class="border-t border-gray-100 px-6 py-6">
                <p class="eyebrow">Open high-risk findings ({{ $lists['open_high_risk']['total'] }})</p>
                <div class="mt-2 divide-y divide-gray-100">
                    @forelse ($lists['open_high_risk']['items'] as $finding)
                        <a href="{{ route("{$routePrefix}.buildings.show", $finding->building_id) }}" class="group flex items-center justify-between gap-4 py-3">
                            <span class="flex min-w-0 items-center gap-3">
                                <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-gray-50 text-gray-600"><x-icon name="apartment" /></span>
                                <span class="min-w-0">
                                    <span class="block truncate text-sm font-medium text-gray-900 group-hover:text-accent-ink">{{ $finding->building->building_name ?? '—' }}</span>
                                    <span class="{{ $rowMeta }}">Inspected {{ $finding->inspection_date->format('M d, Y') }} &middot; {{ $finding->building->campus->campus_name ?? '—' }}</span>
                                </span>
                            </span>
                            <x-badge value="High" :map="['High' => 'bg-red-100 text-red-700']" class="shrink-0" />
                        </a>
                    @empty
                        <x-empty-state class="!py-4" icon="check" :message="$isCoordinator ? 'None of your inspections has an open high-risk finding.' : 'No open high-risk findings.'" />
                    @endforelse
                </div>
            </div>
        </div>

        {{-- KPI strip: hairline-divided cells, the number leads, the change as a pill. --}}
        <div id="dashboard-kpis" data-live-refresh class="drrms-stagger grid grid-cols-2 gap-px overflow-hidden rounded-xl border border-gray-100 bg-gray-100 xl:grid-cols-5 [&>*:last-child]:col-span-2 xl:[&>*:last-child]:col-span-1" data-kpis>
            @foreach ($kpis as $kpi)
                @php
                    $isGood = $kpi['direction'] === 0 ? null : (($kpi['direction'] > 0) !== $kpi['higher_is_worse']);
                    $changeClass = in_array($kpi['change'], ['—', '0%'], true) || $isGood === null
                        ? 'border-gray-200 text-gray-600'
                        : ($isGood ? 'border-green-200 bg-green-50 text-green-700' : 'border-red-200 bg-red-50 text-red-700');
                @endphp
                {{-- Three things per card (UI audit): label, number, change. The
                     comparison and the glossary are in the hover/tap note. --}}
                @php
                    $compare = 'vs. ' . $kpi['previous'] . ' in the previous equal-length period';
                    $note = trim($kpi['subtitle'] . '. ' . $compare . '.' . (config('glossary.kpi.' . $kpi['key']) ? ' ' . config('glossary.kpi.' . $kpi['key']) : ''));
                @endphp
                <div class="flex min-w-0 flex-col bg-white p-4 sm:p-6" data-kpi="{{ $kpi['key'] }}">
                    <p class="flex items-start justify-between gap-2 text-xs font-semibold uppercase leading-5 tracking-wide text-gray-600">
                        <span class="break-words">{{ $kpi['label'] }}</span>
                        <x-info-tip :term="$kpi['label']" :text="$note" />
                    </p>
                    <div class="mt-4 flex items-end gap-2">
                        <p class="stat-value" data-kpi-value data-count-up>{{ $kpi['current'] }}</p>
                        <span class="mb-1 rounded-full border px-2 text-xs font-semibold leading-5 {{ $changeClass }}" title="{{ $compare }}" data-kpi-change>{{ $kpi['change'] }}</span>
                    </div>
                </div>
            @endforeach
        </div>

        <section id="dashboard-people" data-live-refresh class="card" data-people-metrics>
            <h2 class="card-title">People affected in this period</h2>
            <p class="caption mt-1">Recorded incident counts. A dash means no count was recorded; repeat involvement across incidents may be counted more than once.</p>
            <dl class="mt-4 grid grid-cols-2 gap-4 sm:grid-cols-4">
                @foreach ($analytics['people'] as $count)
                    <div><dt class="text-sm text-gray-600">{{ $count['label'] }}</dt><dd class="mt-1 text-2xl font-semibold">{{ $count['total'] ?? '—' }}</dd><dd class="text-xs text-gray-500">{{ $count['known'] }} incidents with a recorded count</dd></div>
                @endforeach
            </dl>
        </section>

        {{-- Main chart. --}}
        <x-card>
            <div class="card-header">
                <div>
                    <h2 class="card-title">Incidents over time</h2>
                    <p class="caption leading-6">By {{ $charts['incidents_over_time']['granularity'] }} &middot; {{ $period['label'] ?? 'this period' }}</p>
                </div>
                <a href="{{ route("{$routePrefix}.incidents.index") }}" class="btn btn-secondary btn-sm">All incidents</a>
            </div>
            @if ($charts['incidents_over_time']['has_data'])
                <div class="h-64 lg:h-72"><canvas id="incidentsOverTimeChart"></canvas></div>
            @else
                <x-empty-state icon="chart" data-empty="incidents_over_time" message="No data for this period" />
            @endif
        </x-card>

        {{-- Two smaller charts side by side. --}}
        <div class="grid grid-cols-1 gap-6 md:grid-cols-2">
            <x-card>
                <h2 class="card-title mb-4">Incidents by type</h2>
                @if ($charts['incidents_by_hazard']['has_data'])
                    <div class="h-56"><canvas id="incidentsByHazardChart"></canvas></div>
                @else
                    <x-empty-state icon="chart" data-empty="incidents_by_hazard" message="No data for this period" />
                @endif
            </x-card>

            <x-card>
                <h2 class="card-title mb-4">Hazard findings by risk level <x-info-tip term="Risk level" :text="config('glossary.risk_level')" /></h2>
                @if ($charts['findings_by_risk']['has_data'])
                    <div class="h-56"><canvas id="findingsByRiskChart"></canvas></div>
                @else
                    <x-empty-state icon="chart" data-empty="findings_by_risk" message="No data for this period" />
                @endif
            </x-card>

            @if ($hasDrillsChart)
                <x-card class="md:col-span-2">
                    <h2 class="card-title mb-4">Drills conducted per campus</h2>
                    @if ($charts['drills_per_campus']['has_data'])
                        <div class="h-56"><canvas id="drillsPerCampusChart"></canvas></div>
                    @else
                        <x-empty-state icon="chart" data-empty="drills_per_campus" message="No data for this period" />
                    @endif
                </x-card>
            @endif
        </div>

    </div>

    {{-- ================= Right: situation, actions, drills, activity ================= --}}
    <aside class="min-w-0 space-y-6" aria-label="Situation and shortcuts">
        {{-- Situation right now (not bound to the time range). --}}
        <div class="rounded-xl border border-gray-100 bg-white p-6" data-situation>
            <div class="flex items-center justify-between">
                <p class="eyebrow">Situation now</p>
                <span class="text-xs text-gray-500">{{ now()->timezone('Asia/Manila')->format('g:i A') }}</span>
            </div>
            @if ($liveAdvisories->isNotEmpty())
                <div class="mt-4 flex items-start gap-3 rounded-lg border border-amber-200 bg-amber-50 p-3 text-amber-800">
                    <x-icon name="warning" fill class="mt-0.5 h-5 w-5" />
                    <div class="min-w-0">
                        <p class="text-sm font-semibold">{{ $liveAdvisories->count() === 1 ? 'Advisory in effect' : $liveAdvisories->count() . ' advisories in effect' }}</p>
                        <p class="text-xs leading-5">{{ $liveAdvisories->first()->hazard_label }}{{ $liveAdvisories->first()->headline_measurement ? ' — ' . $liveAdvisories->first()->headline_measurement : '' }} · {{ $liveAdvisories->first()->campus_label }}</p>
                    </div>
                </div>
            @else
                <div class="mt-4 flex items-center gap-3 rounded-lg border border-green-200 bg-green-50 p-3 text-green-800">
                    <x-icon name="check_circle" fill class="h-5 w-5" />
                    <p class="text-sm font-semibold">No advisory in effect</p>
                </div>
            @endif
            <dl class="mt-5 grid grid-cols-2 gap-px overflow-hidden rounded-lg border border-gray-100 bg-gray-100">
                <div class="bg-white p-3">
                    <dt class="text-xs text-gray-500">Unresolved incidents</dt>
                    <dd class="font-display text-3xl font-semibold leading-tight text-gray-900">{{ $lists['unresolved_incidents']['total'] }}</dd>
                </div>
                <div class="bg-white p-3">
                    <dt class="text-xs text-gray-500">Open high-risk</dt>
                    <dd class="font-display text-3xl font-semibold leading-tight text-gray-900">{{ $lists['open_high_risk']['total'] }}</dd>
                </div>
                <div class="bg-white p-3">
                    <dt class="text-xs text-gray-500">Drills to document</dt>
                    <dd class="font-display text-3xl font-semibold leading-tight text-gray-900">{{ $lists['drills_to_document']['total'] }}</dd>
                </div>
                <div class="bg-white p-3">
                    <dt class="text-xs text-gray-500">Advisories in effect</dt>
                    <dd class="font-display text-3xl font-semibold leading-tight text-gray-900">{{ $liveAdvisories->count() }}</dd>
                </div>
            </dl>
        </div>

        {{-- Quick actions. --}}
        <div class="rounded-xl border border-gray-100 bg-white p-6">
            <p class="eyebrow mb-4">Quick actions</p>
            <div class="grid grid-cols-2 gap-3">
                <a href="{{ route("{$routePrefix}.incidents.create") }}" class="btn btn-primary justify-start px-3"><x-icon name="crisis_alert" class="h-4 w-4 text-red-600" /> Report incident</a>
                <a href="{{ route("{$routePrefix}.advisories.create") }}" class="btn btn-secondary justify-start px-3"><x-icon name="campaign" class="h-4 w-4" /> Post advisory</a>
                <a href="{{ route("{$routePrefix}.drills.create") }}" class="btn btn-secondary justify-start px-3"><x-icon name="event" class="h-4 w-4" /> Schedule drill</a>
                <a href="{{ route('reports.consolidated.index') }}" class="btn btn-secondary justify-start px-3"><x-icon name="summarize" class="h-4 w-4" /> Reports</a>
            </div>
        </div>

        {{-- Drills: past ones still missing a submission, then upcoming ones. --}}
        <div class="rounded-xl border border-gray-100 bg-white p-6" data-drills-to-document>
            <div class="flex items-center justify-between gap-4">
                <p class="eyebrow">Drills to document ({{ $lists['drills_to_document']['total'] }})</p>
                <a href="{{ route("{$routePrefix}.drills.index", ['view' => 'list']) }}" class="text-xs font-medium text-accent-ink hover:underline">View all</a>
            </div>
            <div class="mt-2 divide-y divide-gray-100">
                @forelse ($lists['drills_to_document']['items'] as $drill)
                    <a href="{{ route("{$routePrefix}.drills.show", $drill) }}" class="group block py-3">
                        <span class="block truncate text-sm font-medium text-gray-900 group-hover:text-accent-ink">{{ $drill->drill_type }}{{ $routePrefix === 'admin' ? ' · ' . ($drill->campus_name ?? '—') : '' }}</span>
                        <span class="{{ $rowMeta }}">{{ $drill->scheduled_date->format('M d, Y') }} &middot; {{ collect([$drill->documentation_exists ? null : 'Documentation', $drill->after_activity_report_exists ? null : 'Report'])->filter()->join(' + ') }} missing</span>
                    </a>
                @empty
                    <x-empty-state class="!py-4" icon="check" message="Every held drill is documented." hint="After a drill, open it and add its documentation and After Activity Report." />
                @endforelse
            </div>

            <p class="eyebrow mt-6">Upcoming drills</p>
            <div class="mt-2 divide-y divide-gray-100">
                @forelse ($lists['upcoming_drills'] as $drill)
                    <a href="{{ route("{$routePrefix}.drills.show", $drill) }}" class="group flex items-center justify-between gap-4 py-3">
                        <span class="min-w-0">
                            <span class="block truncate text-sm font-medium text-gray-900 group-hover:text-accent-ink">{{ $drill->drill_type }}</span>
                            <span class="{{ $rowMeta }}">
                                {{ $drill->scheduled_date->isToday() ? 'Today' : $drill->scheduled_date->format('M d, Y') }}
                                {{ $drill->scheduled_time ? '· ' . $drill->scheduled_time->format('g:i A') : '' }} &middot; {{ $routePrefix === 'admin' ? ($drill->campus_name ?? '—') : $drill->venue }}
                            </span>
                        </span>
                        <span class="h-2.5 w-2.5 shrink-0 rounded-full {{ $drill->typeColours()['dot'] }}" aria-hidden="true"></span>
                    </a>
                @empty
                    <x-empty-state icon="calendar" message="No drills scheduled." hint="Drills scheduled for the coming days show here." :action-href="route($routePrefix . '.drills.create')" action-label="+ Schedule Drill" />
                @endforelse
            </div>
        </div>

        {{-- Recent activity. --}}
        <div class="rounded-xl border border-gray-100 bg-white p-6">
            <p class="eyebrow mb-2">Recent activity</p>
            <ul class="divide-y divide-gray-100" data-recent-activity>
                @forelse ($lists['recent_activity'] as $log)
                    <li class="flex min-w-0 gap-3 py-3">
                        <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-gray-100 text-xs font-semibold text-gray-700" aria-hidden="true">{{ $initialsOf($log->actor_label) }}</span>
                        <div class="min-w-0">
                            <p class="break-words text-sm leading-5 text-gray-900">{{ $log->description }}</p>
                            <p class="caption leading-5">{{ $log->actor_label }} &middot; {{ $log->created_at?->diffForHumans() ?? '—' }}</p>
                        </div>
                    </li>
                @empty
                    <li><x-empty-state icon="activity" :message="$isCoordinator ? 'You have no recent activity yet.' : 'No recent activity.'" /></li>
                @endforelse
            </ul>
        </div>
    </aside>
</div>

@push('scripts')
    <script>
        (function () {
            var charts = @json($charts);
            var drawn = [];

            // Colours come from the active theme (resources/css/app.css): gray
            // bars with the largest one in the accent (inspo), the line in the
            // accent; risk levels keep their meaning (Low/Medium/High).
            function palette() {
                var css = getComputedStyle(document.documentElement);
                var v = function (name) { return css.getPropertyValue(name).trim(); };
                var dark = document.documentElement.getAttribute('data-theme') === 'dark';
                return {
                    accent: v('--accent'),
                    bar: dark ? '#3c3c41' : '#d6d3d1',
                    grid: v('--color-gray-100'),
                    tick: v('--color-gray-500'),
                    legend: v('--color-gray-600'),
                    fill: dark ? 'rgba(249, 115, 22, 0.12)' : 'rgba(194, 65, 12, 0.08)',
                    risk: dark ? ['#22c55e', '#f59e0b', '#ef4444'] : ['#16a34a', '#d97706', '#dc2626'],
                };
            }

            // Every bar gray except one: the largest (the latest of them on a
            // tie), which takes the accent — the reference dashboards' look.
            function highlightMax(data, p) {
                var max = Math.max.apply(null, data);
                var at = max > 0 ? data.lastIndexOf(max) : -1;
                return data.map(function (n, i) { return i === at ? p.accent : p.bar; });
            }

            function draw(id, config) {
                var el = document.getElementById(id);
                if (el) drawn.push(new Chart(el, config));
            }

            function render() {
                drawn.forEach(function (chart) { chart.destroy(); });
                drawn = [];

                var p = palette();
                var base = { responsive: true, maintainAspectRatio: false };
                var axes = {
                    x: { grid: { display: false }, border: { display: false }, ticks: { color: p.tick } },
                    y: { beginAtZero: true, grid: { color: p.grid }, border: { display: false }, ticks: { precision: 0, color: p.tick } },
                };

                if (charts.incidents_over_time.has_data) {
                    draw('incidentsOverTimeChart', {
                        type: 'bar',
                        data: { labels: charts.incidents_over_time.labels, datasets: [{ label: 'Incidents', data: charts.incidents_over_time.data, backgroundColor: highlightMax(charts.incidents_over_time.data, p), borderRadius: 6, maxBarThickness: 36 }] },
                        options: Object.assign({}, base, { plugins: { legend: { display: false } }, scales: axes }),
                    });
                }

                if (charts.incidents_by_hazard.has_data) {
                    draw('incidentsByHazardChart', {
                        type: 'bar',
                        data: { labels: charts.incidents_by_hazard.labels, datasets: [{ label: 'Incidents', data: charts.incidents_by_hazard.data, backgroundColor: highlightMax(charts.incidents_by_hazard.data, p), borderRadius: 6, maxBarThickness: 48 }] },
                        options: Object.assign({}, base, { plugins: { legend: { display: false } }, scales: axes }),
                    });
                }

                if (charts.findings_by_risk.has_data) {
                    draw('findingsByRiskChart', {
                        type: 'doughnut',
                        data: { labels: charts.findings_by_risk.labels, datasets: [{ data: charts.findings_by_risk.data, backgroundColor: p.risk, borderWidth: 0 }] },
                        options: Object.assign({}, base, { cutout: '68%', plugins: { legend: { position: 'bottom', labels: { color: p.legend, boxWidth: 12 } } } }),
                    });
                }

                if (charts.drills_per_campus && charts.drills_per_campus.has_data) {
                    draw('drillsPerCampusChart', {
                        type: 'bar',
                        data: { labels: charts.drills_per_campus.labels, datasets: [{ label: 'Drills', data: charts.drills_per_campus.data, backgroundColor: highlightMax(charts.drills_per_campus.data, p), borderRadius: 6, maxBarThickness: 64 }] },
                        options: Object.assign({}, base, { plugins: { legend: { display: false } }, scales: axes }),
                    });
                }
            }

            render();
            document.addEventListener('drrms:theme', render);
        })();
    </script>
@endpush
