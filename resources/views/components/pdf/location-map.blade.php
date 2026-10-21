{{-- The map image under a PDF's "Geo-tagged location" (App\Support\StaticMap),
     with the tile provider's credit. Nothing when the map can't be made. --}}
@props(['latitude', 'longitude'])
@php $map = \App\Support\StaticMap::dataUri((float) $latitude, (float) $longitude); @endphp
@if ($map)
    <div class="location-map" data-location-map>
        <img src="{{ $map }}" alt="Map of {{ $latitude }}, {{ $longitude }}" style="width: 100%; border: 0.5pt solid #d1d5db; margin-top: 4pt;">
        <div style="font-size: 6.5pt; color: #6b7280; margin-top: 1pt;">Map: {{ config('map.pdf.attribution') }}. The red pin marks the recorded location.</div>
    </div>
@endif
