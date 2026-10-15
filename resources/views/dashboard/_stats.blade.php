<div class="mt-6">
    <div class="mb-4 flex items-center justify-between">
        <h2 class="text-lg font-semibold text-gray-900">Statistics &amp; Trends</h2>
        <div class="inline-flex rounded-full border border-gray-200 bg-white p-1" id="stats-period-toggle">
            @foreach (['week' => 'Week', 'month' => 'Month', 'year' => 'Year'] as $value => $label)
                <button type="button" data-period="{{ $value }}"
                        class="stats-period-btn rounded-full px-4 py-1.5 text-sm font-medium transition {{ $value === 'month' ? 'bg-accent text-on-accent' : 'text-gray-600 hover:bg-gray-50' }}">
                    {{ $label }}
                </button>
            @endforeach
        </div>
    </div>

    <div class="grid grid-cols-1 gap-4">
        <x-card>
            <h3 class="mb-4 text-sm font-semibold text-gray-700">Incidents Reported Over Time</h3>
            <canvas id="incidentsChart" height="100"></canvas>
        </x-card>
    </div>
</div>

@push('scripts')
    <script>
        (function () {
            const statsUrl = @json(route($statsRouteName));
            const initialStats = @json($initialStats);

            const incidentsChart = new Chart(document.getElementById('incidentsChart'), {
                type: 'line',
                data: {
                    labels: initialStats.incidents.labels,
                    datasets: [{
                        label: 'Incidents',
                        data: initialStats.incidents.data,
                        borderColor: '#16a34a',
                        backgroundColor: 'rgba(22, 163, 74, 0.1)',
                        tension: 0.3,
                        fill: true,
                        pointRadius: 3,
                    }],
                },
                options: {
                    responsive: true,
                    plugins: { legend: { display: false } },
                    scales: { y: { beginAtZero: true, ticks: { precision: 0 } } },
                },
            });

            document.querySelectorAll('.stats-period-btn').forEach(function (btn) {
                btn.addEventListener('click', function () {
                    const period = this.dataset.period;

                    document.querySelectorAll('.stats-period-btn').forEach(function (b) {
                        b.classList.remove('bg-accent', 'text-on-accent');
                        b.classList.add('text-gray-600');
                    });
                    this.classList.remove('text-gray-600');
                    this.classList.add('bg-accent', 'text-on-accent');

                    fetch(statsUrl + '?period=' + period, { headers: { 'Accept': 'application/json' } })
                        .then(function (res) { return res.json(); })
                        .then(function (stats) {
                            incidentsChart.data.labels = stats.incidents.labels;
                            incidentsChart.data.datasets[0].data = stats.incidents.data;
                            incidentsChart.update();
                        });
                });
            });
        })();
    </script>
@endpush
