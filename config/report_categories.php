<?php

/*
|--------------------------------------------------------------------------
| Reporter report categories
|--------------------------------------------------------------------------
|
| What a student or staff member can report from the phone app ("Report"),
| in the order shown. The key is stored in citizen_reports.category, so a
| key must never be renamed once reports use it; labels and icons may change.
| The app home shows the first six as shortcuts. Icons are Material Symbols
| names and must also be listed in resources/icons/extra.json.
|
| Safety and security (bullying, harassment, violence, threats): human-
| induced hazards under the school safety framework. Reports go to the same
| staff inbox, name shown, like every other report.
| FOR DRRMO REVIEW (Mr. Abordo): confirm these four, and whether Guidance /
| Student Affairs should also be notified of them.
|
*/

return [
    ['key' => 'fire', 'label' => 'Fire', 'icon' => 'local_fire_department'],
    ['key' => 'flood', 'label' => 'Flood', 'icon' => 'flood'],
    ['key' => 'medical', 'label' => 'Injury / medical', 'icon' => 'medical_services'],
    ['key' => 'structure', 'label' => 'Damaged structure', 'icon' => 'apartment'],
    ['key' => 'landslide', 'label' => 'Landslide', 'icon' => 'landslide'],
    ['key' => 'electrical', 'label' => 'Power / electrical', 'icon' => 'bolt'],
    ['key' => 'tree', 'label' => 'Fallen tree / blocked road', 'icon' => 'park'],
    ['key' => 'vehicle', 'label' => 'Vehicle / transport', 'icon' => 'directions_car'],
    ['key' => 'bullying', 'label' => 'Bullying', 'icon' => 'front_hand'],
    ['key' => 'harassment', 'label' => 'Harassment (Safe Spaces Act)', 'icon' => 'do_not_touch'],
    ['key' => 'violence', 'label' => 'Violence / fight', 'icon' => 'sports_kabaddi'],
    ['key' => 'threat', 'label' => 'Threat / security concern', 'icon' => 'local_police'],
    ['key' => 'other', 'label' => 'Something else', 'icon' => 'more_horiz'],
];
