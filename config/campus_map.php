<?php

/*
 * Where the "Pick on map" dialog (building, inspection and incident forms)
 * opens when a record has no saved pin yet: the campus's centre. Keyed by a
 * word in the campus name, like App\Models\School::CATALOG. These only set
 * where the map opens — they are never saved as a record's location. With no
 * campus known, it opens on Aurora province (config/map.php province_bounds).
 *
 * Zabali: coordinates already captured on campus in this system's records.
 * Bazal and Casiguran: APPROXIMATE town centres (Maria Aurora, Casiguran) —
 * replace them with the campus gates' real coordinates when available.
 */
return [
    'centres' => [
        'Zabali' => [15.7440842, 121.5773613],
        'Bazal' => [15.7966, 121.4733],
        'Casiguran' => [16.2836, 122.1236],
    ],

    // Zoom when opening on a campus centre / on a saved pin.
    'campus_zoom' => 16,
    'pin_zoom' => 18,
];
