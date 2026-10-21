<?php

/*
|--------------------------------------------------------------------------
| Map tiles and place search for the location picker
|--------------------------------------------------------------------------
|
| The "Pick on map" dialog on the building, inspection and incident forms
| (resources/views/building/_location-fields.blade.php) reads everything
| about tiles and search from here; no tile URL is written anywhere else.
|
| LICENSING POSITION
| - Attribution is displayed exactly as each service's own metadata gives it
|   (the copyrightText of each Esri MapServer; OpenStreetMap's required
|   credit), for every layer that is on screen.
| - Use is non-commercial and institutional: ASCOT's DRRM Office recording
|   where its own buildings and incidents are.
| - The provider is configuration, so it can be swapped for a licensed Esri
|   key or plain OpenStreetMap without touching code. The Esri layers are
|   used keyless and are governed by Esri's terms (Esri Master License
|   Agreement); nothing here claims they are free for any use.
|
| LAYERS
| Each entry is one choice in the Satellite/Street switch: a label and one
| or more tile layers drawn bottom to top. Removing a layer, or emptying the
| list, only removes the map: the latitude/longitude fields always work, and
| with no layers at all the "Pick on map" button is simply not shown.
|
| Esri imagery is sharp to zoom 18 over all three campuses (measured
| 2026-10-10); from zoom 19 Esri serves a "Map data not yet available" tile,
| so max_native_zoom 18 lets Leaflet enlarge the zoom-18 tiles instead.
|
*/

return [

    'layers' => [
        'satellite' => [
            'label' => 'Satellite',
            'tiles' => [
                [
                    'url' => 'https://server.arcgisonline.com/ArcGIS/rest/services/World_Imagery/MapServer/tile/{z}/{y}/{x}',
                    'attribution' => 'Powered by <a href="https://www.esri.com">Esri</a> | Source: Esri, Vantor, Earthstar Geographics, and the GIS User Community',
                    'max_native_zoom' => 18,
                    'max_zoom' => 20,
                ],
                [
                    // Place names and boundaries, transparent, drawn over the imagery.
                    'url' => 'https://server.arcgisonline.com/ArcGIS/rest/services/Reference/World_Boundaries_and_Places/MapServer/tile/{z}/{y}/{x}',
                    'attribution' => 'Esri, HERE, Garmin, (c) OpenStreetMap contributors, and the GIS user community',
                    'max_native_zoom' => 17,
                    'max_zoom' => 20,
                ],
                [
                    // Roads, transparent, drawn over the imagery.
                    'url' => 'https://server.arcgisonline.com/ArcGIS/rest/services/Reference/World_Transportation/MapServer/tile/{z}/{y}/{x}',
                    'attribution' => 'Esri, HERE, Garmin, (c) OpenStreetMap contributors',
                    'max_native_zoom' => 17,
                    'max_zoom' => 20,
                ],
            ],
        ],
        'street' => [
            'label' => 'Street',
            'tiles' => [
                [
                    'url' => 'https://tile.openstreetmap.org/{z}/{x}/{y}.png',
                    'attribution' => '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors',
                    'max_native_zoom' => 19,
                    'max_zoom' => 20,
                ],
            ],
        ],
    ],

    /*
    | The map image under "Geo-tagged location" in the incident, building and
    | inspection PDFs (App\Support\StaticMap). A few OpenStreetMap street tiles
    | per PDF, cached, with an identifying User-Agent (OpenStreetMap's tile
    | usage policy) and its credit printed under the image. MAP_PDF_ENABLED=false
    | leaves the coordinates alone, as the tests do.
    */
    'pdf' => [
        'enabled' => (bool) env('MAP_PDF_ENABLED', true),
        'tile_url' => 'https://tile.openstreetmap.org/{z}/{x}/{y}.png',
        'attribution' => '© OpenStreetMap contributors',
        'user_agent' => 'ASCOT-DRRMS/1.0 (Aurora State College of Technology DRRM Office)',
        // 16 shows the nearby roads, rivers and names; 17 is often bare outside town.
        'zoom' => 16,
        'width' => 640,
        'height' => 260,
        'timeout_seconds' => 6,
        'cache_days' => 30,
    ],

    // Where a record with no saved pin and no known campus opens: the whole of
    // Aurora province (APPROXIMATE bounds, [[south, west], [north, east]]).
    // Also biases place search towards the province.
    'province_bounds' => [[15.25, 121.15], [16.6, 122.3]],

    /*
    | Place search through our own server (MapSearchController), never from
    | the browser directly, to follow Nominatim's usage policy
    | (operations.osmfoundation.org/policies/nominatim): an identifying
    | User-Agent, at most one request per second, cached results, no
    | search-as-you-type, OSM attribution shown, and a switch to turn it off.
    */
    'search' => [
        'enabled' => (bool) env('MAP_SEARCH_ENABLED', true),
        'url' => 'https://nominatim.openstreetmap.org/search',
        // Sent in the User-Agent so OSMF can reach whoever runs this server.
        'contact' => env('MAP_SEARCH_CONTACT'),
        'user_agent' => 'ASCOT-DRRMS/1.0 (Aurora State College of Technology DRRM Office)',
        'country_codes' => 'ph',
        'limit' => 5,
        'cache_days' => 30,
        'max_query_length' => 100,
        'timeout_seconds' => 8,
        'attribution' => 'Search &copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors',
    ],

];
