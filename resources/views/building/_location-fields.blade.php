{{--
    Optional location for buildings, hazard inspections and incidents.

    The latitude/longitude inputs are the source of truth (validated
    server-side): always visible, always editable, outside the map. "Pick on
    map" opens a near-full-screen map (a <dialog>) that writes into them:
    tap to drop a pin, drag it, search a place. With no internet, a blocked
    CDN or tiles that fail, the inputs work exactly as before and saving is
    never blocked. Tiles and search come from config/map.php only; with no
    layers configured the button and dialog are simply not rendered.

    While open the dialog is moved to <body> (as in the report modal): the
    page's entrance animation leaves a transform on an ancestor, which would
    otherwise position its fixed box and backdrop against that ancestor.
    A placeholder keeps its spot so it goes back on close.

    Params: model (the record being edited, or null), campusId (optional; the
    record's campus, else the signed-in user's). Admin forms with a
    #campus_id select open on the chosen campus while no pin is set.
--}}
@php
    $latitudeValue = old('latitude', $model->latitude ?? '');
    $longitudeValue = old('longitude', $model->longitude ?? '');
    $isCreateForm = $model === null;
    $mapCentres = \App\Models\Campus::all(['campus_id', 'campus_name'])->mapWithKeys(function ($campus) {
        foreach (config('campus_map.centres') as $keyword => $centre) {
            if (stripos($campus->campus_name, $keyword) !== false) {
                return [$campus->campus_id => $centre];
            }
        }

        return [];
    });
    // Only layers with at least one tile URL count. None at all = no picker.
    $mapLayers = collect(config('map.layers') ?? [])
        ->map(fn ($layer, $key) => [
            'label' => $layer['label'] ?? \Illuminate\Support\Str::headline($key),
            'tiles' => collect($layer['tiles'] ?? [])
                ->filter(fn ($tile) => filled($tile['url'] ?? null))
                ->map(fn ($tile) => [
                    'url' => $tile['url'],
                    'attribution' => $tile['attribution'] ?? '',
                    'maxNativeZoom' => $tile['max_native_zoom'] ?? null,
                    'maxZoom' => $tile['max_zoom'] ?? 19,
                ])->values()->all(),
        ])
        ->filter(fn ($layer) => $layer['tiles'] !== [])
        ->values()
        ->all();
    $searchEnabled = (bool) config('map.search.enabled');
    $mapConfig = [
        'layers' => $mapLayers,
        'centres' => $mapCentres,
        'provinceBounds' => config('map.province_bounds'),
        'campusId' => $campusId ?? ($model->campus_id ?? \Illuminate\Support\Facades\Auth::user()?->campus_id),
        'campusZoom' => config('campus_map.campus_zoom'),
        'pinZoom' => config('campus_map.pin_zoom'),
        'searchUrl' => $searchEnabled ? route('map.search') : null,
    ];
@endphp

<div data-location-fields>
    <label class="form-label">Location (optional)</label>

    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
        <div>
            <label for="latitude" class="block text-xs leading-6 text-gray-600">Latitude</label>
            <input type="number" id="latitude" name="latitude" step="any" min="-90" max="90"
                   value="{{ $latitudeValue }}" placeholder="e.g. 15.7594" inputmode="decimal"
                   class="form-input">
        </div>
        <div>
            <label for="longitude" class="block text-xs leading-6 text-gray-600">Longitude</label>
            <input type="number" id="longitude" name="longitude" step="any" min="-180" max="180"
                   value="{{ $longitudeValue }}" placeholder="e.g. 121.5624" inputmode="decimal"
                   class="form-input">
        </div>
    </div>

    <div class="mt-4 flex flex-col gap-4 sm:flex-row sm:items-center">
        @if ($mapLayers)
            {{-- Shown only once Leaflet has loaded (see script); without it the fields above are the way in. --}}
            <button type="button" data-map-picker-open hidden class="btn btn-secondary btn-sm w-full sm:w-auto">
                Pick on map
            </button>
        @endif
        <button type="button" onclick="captureCurrentLocationForGeoFields(false)"
                class="btn btn-secondary btn-sm w-full sm:w-auto">
            Use my current location
        </button>
        <button type="button" data-location-clear class="link-muted inline-flex min-h-[2.75rem] items-center justify-center text-sm sm:min-h-0">
            Clear location
        </button>
    </div>
    <p id="location-status" class="text-xs leading-6 text-gray-600" role="status" aria-live="polite" hidden></p>

    @if ($mapLayers)
        {{-- No form fields in here: it moves to <body> while open. --}}
        <dialog data-map-picker data-map-config='@json($mapConfig)' aria-labelledby="map-picker-title"
                class="m-0 h-[100dvh] max-h-[100dvh] w-full max-w-full bg-white p-0 backdrop:bg-night-900/60 sm:m-auto sm:h-[90vh] sm:max-h-[90vh] sm:w-[92vw] sm:max-w-6xl sm:rounded-xl">
            <div class="flex h-full flex-col">
                <div class="flex flex-col gap-2 border-b border-gray-200 p-3 sm:flex-row sm:items-center">
                    <h2 id="map-picker-title" class="text-base font-semibold text-gray-900 sm:mr-2">Pick the location</h2>
                    @if ($searchEnabled)
                        <div class="flex min-w-0 flex-1 gap-2" role="search">
                            <label for="map-picker-search" class="sr-only">Search for a place</label>
                            <input type="search" id="map-picker-search" data-map-search-input autocomplete="off" enterkeyhint="search"
                                   maxlength="{{ config('map.search.max_query_length') }}" placeholder="Search a place, e.g. Casiguran"
                                   class="form-input min-w-0 flex-1">
                            <button type="button" data-map-search-button class="btn btn-secondary">Search</button>
                        </div>
                    @endif
                </div>
                @if ($searchEnabled)
                    <div data-map-search-panel hidden class="border-b border-gray-200 px-3 py-2">
                        <p data-map-search-message class="text-sm text-gray-700" role="status" aria-live="polite"></p>
                        <ul data-map-search-results class="max-h-40 overflow-y-auto"></ul>
                        <p class="mt-1 text-xs text-gray-600">{!! config('map.search.attribution') !!}</p>
                    </div>
                @endif
                <div class="relative min-h-0 flex-1">
                    <div data-map-picker-map class="absolute inset-0"
                         role="application" aria-label="Map: tap to drop the pin, drag the pin to adjust. You can also type the coordinates in the form."></div>
                    <p data-map-tiles-failed hidden role="alert"
                       class="absolute inset-x-3 top-3 z-[1000] rounded-lg border border-amber-300 bg-amber-50 p-3 text-sm text-amber-900">
                        Map tiles couldn't load (no internet?). Close the map and type the coordinates — the form still saves.
                    </p>
                </div>
                <div class="flex flex-col gap-3 border-t border-gray-200 p-3 sm:flex-row sm:items-center sm:justify-between">
                    <p data-map-picker-readout class="min-w-0 text-sm text-gray-700" aria-live="polite">Tap the map to drop a pin, then drag it to adjust.</p>
                    <div class="flex flex-wrap gap-2 sm:shrink-0 sm:flex-nowrap">
                        <button type="button" onclick="captureCurrentLocationForGeoFields(false)" class="btn btn-secondary">Use my current location</button>
                        {{-- Focus starts here, not in the search box: on a phone that would open the keyboard over the map. --}}
                        <button type="button" data-map-picker-cancel autofocus class="btn btn-secondary">Cancel</button>
                        <button type="button" data-map-picker-done class="btn btn-primary">Done</button>
                    </div>
                </div>
            </div>
        </dialog>
    @endif
</div>

@once
    @push('scripts')
        {{-- Leaflet 1.9.4 (pinned in package.json), served from our own host by
             scripts/copy-assets.mjs. If it can't load, the map simply never appears. --}}
        <link rel="stylesheet" href="{{ asset('vendor/leaflet/leaflet.css') }}">
        <script src="{{ asset('vendor/leaflet/leaflet.js') }}"></script>
        <style>
            /* Every tile layer's attribution stays visible, wrapping on a phone. */
            [data-map-picker] .leaflet-control-attribution { white-space: normal; max-width: 100%; font-size: 10px; line-height: 1.35; }
        </style>
    @endpush
@endonce

@push('scripts')
    <script>
        (function () {
            var latInput = document.getElementById('latitude');
            var lngInput = document.getElementById('longitude');
            var status = document.getElementById('location-status');
            var dialog = document.querySelector('[data-map-picker]'); // absent when no tile layers are configured
            var openButton = document.querySelector('[data-map-picker-open]');
            var config = dialog ? JSON.parse(dialog.dataset.mapConfig || '{}') : {};
            var map = null, marker = null;

            function readout(message) {
                var el = dialog && dialog.querySelector('[data-map-picker-readout]');
                if (el) el.textContent = message;
            }

            // The form's status line sits behind the open dialog, so mirror it there.
            function showStatus(message) {
                status.textContent = message;
                status.hidden = false;
                if (dialog && dialog.open) readout(message);
            }

            // The typed values, if both are valid numbers in range.
            function readFields() {
                var lat = parseFloat(latInput.value), lng = parseFloat(lngInput.value);
                if (isFinite(lat) && isFinite(lng) && lat >= -90 && lat <= 90 && lng >= -180 && lng <= 180) {
                    return [lat, lng];
                }
                return null;
            }

            function writeFields(latlng) {
                latInput.value = latlng.lat.toFixed(7);
                lngInput.value = latlng.lng.toFixed(7);
            }

            // The record's campus (admin forms: the campus chosen in the select), or null.
            function campusCentre() {
                var select = document.getElementById(@js($campusSelectId ?? 'campus_id'));
                var id = select && select.value ? select.value : config.campusId;
                return (config.centres && config.centres[id]) || null;
            }

            function describe(latlng) {
                return 'Pin at ' + latlng.lat.toFixed(6) + ', ' + latlng.lng.toFixed(6) + '. Drag it to adjust, then Done.';
            }

            // The pin exists only while the fields hold a location.
            function setPin(latlng) {
                if (! map) return;
                if (marker) {
                    marker.setLatLng(latlng);
                    return;
                }
                marker = L.marker(latlng, { draggable: true, autoPan: true, title: 'Location pin — drag to move', alt: 'Location pin' }).addTo(map);
                marker.on('drag dragend', function () {
                    var position = marker.getLatLng();
                    writeFields(position); // live: the form's fields follow the pin
                    readout(describe(position));
                });
            }

            function removePin() {
                if (marker) {
                    marker.remove();
                    marker = null;
                }
            }

            window.captureCurrentLocationForGeoFields = function (isAutomatic) {
                if (! window.isSecureContext) {
                    if (! isAutomatic) showStatus('Your browser only shares your location over a secure (https) connection. Drag the pin or type the coordinates instead.');
                    return;
                }
                if (! ('geolocation' in navigator)) {
                    if (! isAutomatic) showStatus('This browser can\'t share your location. Drag the pin or type the coordinates instead.');
                    return;
                }
                if (! isAutomatic) showStatus('Getting your current location…');

                navigator.geolocation.getCurrentPosition(
                    function (position) {
                        // A starting point, never a lock: the fields stay editable
                        // (GPS can drift indoors) and the pin can still be moved.
                        var latlng = { lat: position.coords.latitude, lng: position.coords.longitude };
                        writeFields(latlng);
                        if (map && dialog.open) {
                            setPin(latlng);
                            map.setView([latlng.lat, latlng.lng], config.pinZoom || 18);
                        }
                        showStatus((isAutomatic ? 'Location captured automatically' : 'Location captured')
                            + ' (accurate to about ' + Math.round(position.coords.accuracy) + ' m). Drag the pin to adjust.');
                    },
                    function (error) {
                        var messages = {
                            1: 'Location permission was denied. To use it, allow location for this site in your browser settings — or drag the pin / type the coordinates instead.',
                            2: 'Your location couldn\'t be determined right now. Drag the pin or type the coordinates instead.',
                            3: 'Getting your location took too long. Try again outdoors, or drag the pin / type the coordinates.',
                        };
                        // A silent automatic attempt only reports a denial, so the form isn't noisy.
                        if (! isAutomatic || error.code === 1) {
                            showStatus(messages[error.code] || messages[2]);
                        }
                    },
                    { enableHighAccuracy: true, timeout: 15000, maximumAge: 0 }
                );
            };

            document.querySelector('[data-location-clear]').addEventListener('click', function () {
                latInput.value = '';
                lngInput.value = '';
                removePin();
                showStatus('Location cleared — it is optional.');
            });

            // ---------------------------------------------------- the map picker
            // Everything below needs a configured layer and Leaflet; without
            // them the fields above are the whole feature.
            if (dialog && window.L) {
                var failedNotice = dialog.querySelector('[data-map-tiles-failed]');
                var tileLoads = 0, tileErrors = 0, failTimer = null;
                var before = null;       // the fields' values when the picker opened (Cancel restores them)
                var closingKeep = null;  // set while history.back() closes the picker

                openButton.hidden = false;

                function tilesFailed() {
                    failedNotice.hidden = false;
                }

                function buildMap() {
                    map = L.map(dialog.querySelector('[data-map-picker-map]'));
                    var choices = {};
                    config.layers.forEach(function (layer) {
                        choices[layer.label] = L.layerGroup(layer.tiles.map(function (tile) {
                            var options = { attribution: tile.attribution, maxZoom: tile.maxZoom || 19 };
                            if (tile.maxNativeZoom) options.maxNativeZoom = tile.maxNativeZoom;
                            return L.tileLayer(tile.url, options)
                                .on('tileload', function () { tileLoads++; failedNotice.hidden = true; })
                                .on('tileerror', function () { tileErrors++; if (tileLoads === 0 && tileErrors >= 4) tilesFailed(); });
                        }));
                    });
                    var labels = Object.keys(choices);
                    choices[labels[0]].addTo(map); // the first configured layer (Satellite) is the default
                    if (labels.length > 1) L.control.layers(choices, null, { collapsed: false }).addTo(map);

                    map.on('click', function (e) {
                        setPin(e.latlng);
                        writeFields(e.latlng);
                        readout(describe(e.latlng));
                    });
                }

                function openPicker() {
                    before = [latInput.value, lngInput.value];
                    // To <body> while open (see the comment at the top of this file).
                    if (dialog.parentNode !== document.body) {
                        dialog.drrmsHome = document.createComment('map-picker');
                        dialog.parentNode.insertBefore(dialog.drrmsHome, dialog);
                        document.body.appendChild(dialog);
                    }
                    dialog.showModal();
                    document.body.classList.add('overflow-hidden');
                    // The phone's Back button closes the picker instead of leaving the form.
                    history.pushState({ drrmsMapPicker: true }, '');

                    if (! map) buildMap(); // built once, after the dialog has a size
                    map.invalidateSize();

                    // Opens on the saved/typed pin, else the campus, else Aurora province.
                    var start = readFields();
                    var campus = campusCentre();
                    if (start) {
                        setPin(start);
                        map.setView(start, config.pinZoom || 18);
                        readout(describe(L.latLng(start)));
                    } else {
                        removePin();
                        if (campus) map.setView(campus, config.campusZoom || 16);
                        else map.fitBounds(config.provinceBounds);
                        readout('Tap the map to drop a pin, then drag it to adjust.');
                    }

                    // Nothing loaded after a while (offline, blocked): say so, keep the form usable.
                    clearTimeout(failTimer);
                    failTimer = setTimeout(function () { if (tileLoads === 0) tilesFailed(); }, 10000);
                }

                function finishClosing(keep) {
                    if (! keep && before) {
                        latInput.value = before[0];
                        lngInput.value = before[1];
                    }
                    clearTimeout(failTimer);
                    dialog.close();
                    document.body.classList.remove('overflow-hidden');
                    if (dialog.drrmsHome) {
                        dialog.drrmsHome.parentNode.replaceChild(dialog, dialog.drrmsHome);
                        dialog.drrmsHome = null;
                    }
                    var set = readFields();
                    showStatus(keep
                        ? (set ? 'Location set from the map: ' + set[0].toFixed(6) + ', ' + set[1].toFixed(6) + '.' : 'No pin placed — the location is optional.')
                        : 'Map closed — the location was not changed.');
                    openButton.focus();
                }

                // Done/Cancel pop the history entry openPicker() added; popstate finishes.
                function closePicker(keep) {
                    if (history.state && history.state.drrmsMapPicker) {
                        closingKeep = keep;
                        history.back();
                    } else {
                        finishClosing(keep);
                    }
                }

                window.addEventListener('popstate', function () {
                    if (! dialog.open) return;
                    var keep = closingKeep === null ? true : closingKeep; // Back keeps the pin, like Done
                    closingKeep = null;
                    finishClosing(keep);
                });
                dialog.addEventListener('cancel', function (e) { // Escape
                    e.preventDefault();
                    closePicker(false);
                });
                openButton.addEventListener('click', openPicker);
                dialog.querySelector('[data-map-picker-done]').addEventListener('click', function () { closePicker(true); });
                dialog.querySelector('[data-map-picker-cancel]').addEventListener('click', function () { closePicker(false); });

                // Place search: on Enter or the button only, never while typing
                // (Nominatim's policy), through our own server.
                var searchInput = dialog.querySelector('[data-map-search-input]');
                if (searchInput && config.searchUrl) {
                    var searchButton = dialog.querySelector('[data-map-search-button]');
                    var panel = dialog.querySelector('[data-map-search-panel]');
                    var message = dialog.querySelector('[data-map-search-message]');
                    var list = dialog.querySelector('[data-map-search-results]');
                    var searching = false;

                    function say(text) {
                        panel.hidden = false;
                        message.textContent = text;
                    }

                    function runSearch() {
                        var q = searchInput.value.trim();
                        if (searching) return;
                        if (q.length < 2) {
                            say('Type at least two letters of a place name.');
                            return;
                        }
                        searching = true;
                        searchButton.disabled = true;
                        list.innerHTML = '';
                        say('Searching…');
                        fetch(config.searchUrl + '?q=' + encodeURIComponent(q), {
                            headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                            credentials: 'same-origin',
                        })
                            .then(function (response) {
                                return response.json().catch(function () { return {}; }).then(function (body) {
                                    return { ok: response.ok, body: body };
                                });
                            })
                            .then(function (result) {
                                if (! result.ok) {
                                    say(result.body.message || 'Search didn\'t work. Pan the map, or type the coordinates.');
                                    return;
                                }
                                var places = result.body.results || [];
                                say(places.length ? 'Choose a place to move the map there:' : 'No places found. Try a town or barangay name.');
                                places.forEach(function (place) {
                                    var item = document.createElement('li');
                                    var button = document.createElement('button');
                                    button.type = 'button';
                                    button.className = 'link block w-full py-2 text-left text-sm sm:py-1';
                                    button.textContent = place.name;
                                    button.addEventListener('click', function () {
                                        map.setView([place.lat, place.lng], config.campusZoom || 16);
                                        panel.hidden = true;
                                        readout('Moved to ' + place.name.split(',')[0] + '. Tap the map to drop the pin.');
                                    });
                                    item.appendChild(button);
                                    list.appendChild(item);
                                });
                            })
                            .catch(function () {
                                say('Search needs an internet connection. Pan the map, or type the coordinates.');
                            })
                            .finally(function () {
                                searching = false;
                                searchButton.disabled = false;
                            });
                    }

                    searchInput.addEventListener('keydown', function (e) {
                        if (e.key === 'Enter') {
                            e.preventDefault();
                            runSearch();
                        }
                    });
                    searchButton.addEventListener('click', runSearch);
                }
            }

            @if ($isCreateForm)
                // Auto-capture only on the CREATE form. An edit form may already hold a
                // deliberately-set location (typed manually, or the building's actual
                // physical location rather than wherever the editor is standing right
                // now) — auto-running this there could silently overwrite correct data,
                // so the edit form only ever captures location on an explicit button click.
                document.addEventListener('DOMContentLoaded', function () {
                    if (! readFields()) captureCurrentLocationForGeoFields(true);
                });
            @endif
        })();
    </script>
@endpush
