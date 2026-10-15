<?php

/*
|--------------------------------------------------------------------------
| Disaster advisory measurements, per hazard type
|--------------------------------------------------------------------------
|
| The fields a Disaster Advisory asks for, by hazard type. The form shows
| only the chosen type's fields and the server validates against the same
| list, so a field for one type (a magnitude) is rejected on another (a
| typhoon). Change the set here; no code changes needed.
|
| Each field: key => [
|   'label'    shown on the form, memo and banner
|   'type'     number | select | text
|   'unit'     printed after a number (km/h, km, m)
|   'options'  for select
|   'min', 'max', 'step'  for number
|   'required' true makes a figure mandatory. None is, by the thesis panel's
|              request: an advisory can be posted before every figure is known
|              (the headline simply leaves the figure out).
|   'headline' how the dashboard banner and the list name that figure;
|              :value is replaced by what was entered
|   'observed_label'  optional: the label on an INCIDENT, where the label
|              above is worded as a forecast. Same key, same unit, same
|              validation — only the wording changes
| ]
|
| Incidents read this same file (App\Support\HazardMeasurements): a
| Disaster-related incident (config/incident_types.php) records the
| measurements of its type as OBSERVED, every one optional. Campus
| emergencies and Other record none.
|
| Terms follow PAGASA (tropical cyclones, rainfall) and PHIVOLCS
| (earthquakes) bulletin wording. The DRRMO types them in from the bulletin
| it receives — there is no automatic feed.
|
*/

$colours = ['Yellow' => 'Yellow', 'Orange' => 'Orange', 'Red' => 'Red'];

return [

    'Typhoon' => [
        'storm_name' => ['label' => 'Storm name', 'type' => 'text', 'max' => 60],
        'wind_signal' => ['label' => 'Wind Signal No.', 'type' => 'select', 'options' => ['1' => '1', '2' => '2', '3' => '3', '4' => '4', '5' => '5'], 'headline' => 'Wind Signal No. :value'],
        'max_winds' => ['label' => 'Maximum sustained winds', 'type' => 'number', 'unit' => 'km/h', 'min' => 0, 'max' => 400, 'step' => 1],
        'gustiness' => ['label' => 'Gustiness', 'type' => 'number', 'unit' => 'km/h', 'min' => 0, 'max' => 500, 'step' => 1],
    ],

    'Earthquake' => [
        'magnitude' => ['label' => 'Magnitude', 'type' => 'number', 'min' => 0, 'max' => 10, 'step' => 0.1, 'headline' => 'Magnitude :value'],
        'depth' => ['label' => 'Depth', 'type' => 'number', 'unit' => 'km', 'min' => 0, 'max' => 800, 'step' => 1],
        'epicentre' => ['label' => 'Epicentre', 'type' => 'text', 'max' => 150],
        'intensity' => ['label' => 'PHIVOLCS Intensity (at the campus)', 'type' => 'select',
            'options' => array_combine($roman = ['I', 'II', 'III', 'IV', 'V', 'VI', 'VII', 'VIII', 'IX', 'X'], $roman)],
    ],

    'Flood' => [
        'water_level' => ['label' => 'Water level', 'type' => 'number', 'unit' => 'm', 'min' => 0, 'max' => 50, 'step' => 0.1],
        'rainfall_warning' => ['label' => 'Rainfall warning (Yellow/Orange/Red)', 'type' => 'select', 'options' => $colours, 'headline' => ':value rainfall warning'],
    ],

    'Landslide' => [
        'rainfall_warning' => ['label' => 'Rainfall warning (Yellow/Orange/Red)', 'type' => 'select', 'options' => $colours, 'headline' => ':value rainfall warning'],
    ],

    'Storm Surge' => [
        'surge_height' => ['label' => 'Expected surge height', 'observed_label' => 'Surge height', 'type' => 'number', 'unit' => 'm', 'min' => 0, 'max' => 20, 'step' => 0.1, 'headline' => 'Storm surge up to :value m'],
    ],

    'Other' => [
        'details' => ['label' => 'Measurement or details', 'type' => 'text', 'max' => 300, 'headline' => ':value'],
    ],

];
