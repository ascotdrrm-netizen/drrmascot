{{--
    A small map with the record's pin, under its coordinates (thesis panel,
    Engineering: "add map below the geo tagging coordinates"). Read-only;
    tiles from config/map.php, Leaflet from public/vendor. Without Leaflet
    (blocked, offline) the coordinates line still shows.

        <x-location-map :lat="$incident->latitude" :lng="$incident->longitude" />
--}}
@props(['lat', 'lng'])
<div {{ $attributes->merge(['class' => 'overflow-hidden rounded-lg border border-gray-100']) }} data-location-map-wrap>
    <div class="h-48 w-full bg-gray-50 sm:h-56" data-location-map data-lat="{{ $lat }}" data-lng="{{ $lng }}" role="img"
         aria-label="Map of the location, {{ number_format((float) $lat, 5) }}, {{ number_format((float) $lng, 5) }}"></div>
    <p class="flex items-center gap-2 border-t border-gray-100 bg-white px-3 py-2 text-xs text-gray-600">
        <x-icon name="location_on" fill class="h-4 w-4 text-accent-ink" />
        <span class="tabular-nums">{{ number_format((float) $lat, 5) }}, {{ number_format((float) $lng, 5) }}</span>
    </p>
</div>

@once
    @push('scripts')
        <link rel="stylesheet" href="{{ asset('vendor/leaflet/leaflet.css') }}">
        <script src="{{ asset('vendor/leaflet/leaflet.js') }}"></script>
        <script>
            (function () {
                if (! window.L) return;
                var tiles = @json(config('map.layers.street.tiles'));
                document.querySelectorAll('[data-location-map]').forEach(function (el) {
                    var at = [parseFloat(el.dataset.lat), parseFloat(el.dataset.lng)];
                    var map = L.map(el, { zoomControl: false, dragging: false, scrollWheelZoom: false, doubleClickZoom: false, boxZoom: false, keyboard: false, touchZoom: false, attributionControl: true });
                    tiles.forEach(function (t) { L.tileLayer(t.url, { attribution: t.attribution, maxNativeZoom: t.max_native_zoom, maxZoom: t.max_zoom }).addTo(map); });
                    L.circleMarker(at, { radius: 9, color: '#f97316', weight: 3, fillColor: '#f97316', fillOpacity: 0.4 }).addTo(map);
                    map.setView(at, 17);
                });
            })();
        </script>
    @endpush
@endonce
