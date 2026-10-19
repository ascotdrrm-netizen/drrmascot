@extends('layouts.mobile')

@section('title', 'Map - ASCOT DRRMS')

@section('content')
    <h1 class="page-title">Campus map</h1>
    <p class="text-sm text-gray-600">Your campus, its hazardous areas and your location.</p>
    @php
        // Areas rated Moderate or above on the 5×5 risk matrix (App\Support\RiskMatrix), worst first.
        $pinned = array_values(array_filter($hazards, fn ($h) => $h['lat'] !== null && $h['lng'] !== null));
    @endphp
    <div id="app-map" class="mt-4 h-[55vh] overflow-hidden rounded-xl border border-gray-100 bg-gray-50"
         data-centres='@json($centres)' data-tiles='@json(config("map.layers.street.tiles"))'
         data-hazards='@json($pinned)' data-colours='@json($riskColours)'></div>
    <button type="button" id="locate-me" class="btn btn-secondary mt-3 w-full"><x-icon name="my_location" /> Show where I am</button>

    <section id="hazard-areas" class="mt-8 scroll-mt-20" data-reporter-hazards>
        <h2 class="section-title">Hazardous areas</h2>
        <p class="mt-1 text-sm text-gray-600">Rated by the DRRM Office's latest inspection. Keep away, or take care, especially in bad weather.</p>
        <ul class="mt-3 flex flex-wrap gap-x-4 gap-y-1 text-xs text-gray-600" aria-label="Legend">
            @foreach (array_slice(\App\Support\RiskMatrix::LEVELS, 1) as $level)
                <li class="flex items-center gap-1"><span class="inline-block h-3 w-3 rounded-full" style="background: {{ $riskColours[$level] }}"></span> {{ $level }}</li>
            @endforeach
        </ul>
        <div class="mt-3 space-y-2">
            @forelse ($hazards as $area)
                <article class="flex items-start justify-between gap-3 rounded-xl border border-gray-100 bg-white p-4" data-hazard-area="{{ $area['id'] }}">
                    <div class="min-w-0">
                        <h3 class="text-sm font-semibold">{{ $area['name'] }}</h3>
                        <p class="text-xs text-gray-600">{{ $area['hazard'] }}{{ $area['inspected'] ? ' · inspected ' . $area['inspected']->format('M j, Y') : '' }}</p>
                        <p class="mt-1 text-xs leading-5 text-gray-700">{{ config('glossary.risk_levels')[$area['level']] ?? '' }}</p>
                    </div>
                    <x-badge :value="$area['level']" class="shrink-0" />
                </article>
            @empty
                <p class="rounded-xl border border-gray-100 bg-white p-4 text-sm text-gray-600">No hazardous areas reported on your campus.</p>
            @endforelse
        </div>
    </section>
@endsection

@push('scripts')
    <link rel="stylesheet" href="{{ asset('vendor/leaflet/leaflet.css') }}">
    <script src="{{ asset('vendor/leaflet/leaflet.js') }}"></script>
    <script>
        (function () {
            var el = document.getElementById('app-map');
            if (! window.L || ! el) return;
            var centres = JSON.parse(el.dataset.centres);
            var hazards = JSON.parse(el.dataset.hazards);
            var colours = JSON.parse(el.dataset.colours);
            var map = L.map(el, { zoomControl: true });
            // Tiles from config/map.php (the one place tile URLs live).
            JSON.parse(el.dataset.tiles).forEach(function (t) {
                L.tileLayer(t.url, { attribution: t.attribution, maxNativeZoom: t.max_native_zoom, maxZoom: t.max_zoom }).addTo(map);
            });
            var bounds = [];
            Object.keys(centres).forEach(function (name) {
                var c = centres[name];
                L.circleMarker(c, { radius: 9, color: '#f97316', weight: 3, fillColor: '#f97316', fillOpacity: 0.35 }).addTo(map).bindPopup('ASCOT ' + name + ' Campus');
                if (! hazards.length) bounds.push(c);
            });
            hazards.forEach(function (h) {
                var colour = colours[h.level] || '#6b7280';
                var popup = document.createElement('div');
                var title = document.createElement('strong');
                title.textContent = h.name;
                popup.appendChild(title);
                popup.appendChild(document.createElement('br'));
                popup.appendChild(document.createTextNode(h.hazard + ' · ' + h.level + ' risk'));
                L.circleMarker([h.lat, h.lng], { radius: 10, color: colour, weight: 3, fillColor: colour, fillOpacity: 0.45 }).addTo(map).bindPopup(popup);
                bounds.push([h.lat, h.lng]);
            });
            if (bounds.length === 1) map.setView(bounds[0], 17);
            else map.fitBounds(bounds, { padding: [30, 30], maxZoom: 17 });
            document.getElementById('locate-me').addEventListener('click', function () {
                if (! navigator.geolocation) return;
                navigator.geolocation.getCurrentPosition(function (p) {
                    var here = [p.coords.latitude, p.coords.longitude];
                    L.circleMarker(here, { radius: 8, color: '#3b82f6', weight: 3, fillColor: '#3b82f6', fillOpacity: 0.6 }).addTo(map).bindPopup('You are here').openPopup();
                    map.setView(here, 17);
                });
            });
        })();
    </script>
@endpush
