@extends('layouts.mobile')

@section('title', 'Map - ASCOT DRRM')

@section('content')
    <h1 class="page-title">Campus map</h1>
    <p class="text-sm text-gray-600">Campuses and your location. Hazard zones and assembly points come with the DRRM Office data.</p>
    <div id="app-map" class="mt-4 h-[60vh] overflow-hidden rounded-xl border border-gray-100 bg-gray-50" data-centres='@json($centres)' data-tiles='@json(config("map.layers.street.tiles"))'></div>
    <button type="button" id="locate-me" class="btn btn-secondary mt-3 w-full"><x-icon name="my_location" /> Show where I am</button>
@endsection

@push('scripts')
    <link rel="stylesheet" href="{{ asset('vendor/leaflet/leaflet.css') }}">
    <script src="{{ asset('vendor/leaflet/leaflet.js') }}"></script>
    <script>
        (function () {
            var el = document.getElementById('app-map');
            if (! window.L || ! el) return;
            var centres = JSON.parse(el.dataset.centres);
            var map = L.map(el, { zoomControl: true });
            // Tiles from config/map.php (the one place tile URLs live).
            JSON.parse(el.dataset.tiles).forEach(function (t) {
                L.tileLayer(t.url, { attribution: t.attribution, maxNativeZoom: t.max_native_zoom, maxZoom: t.max_zoom }).addTo(map);
            });
            var bounds = [];
            Object.keys(centres).forEach(function (name) {
                var c = centres[name];
                L.circleMarker(c, { radius: 9, color: '#f97316', weight: 3, fillColor: '#f97316', fillOpacity: 0.35 }).addTo(map).bindPopup('ASCOT ' + name + ' Campus');
                bounds.push(c);
            });
            map.fitBounds(bounds, { padding: [30, 30] });
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
