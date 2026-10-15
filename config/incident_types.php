<?php

/*
|--------------------------------------------------------------------------
| Incident types
|--------------------------------------------------------------------------
|
| What an incident can be, in two groups shown as two <optgroup>s wherever
| a type is chosen or filtered. The values are stored as written in
| incidents.hazard_type (a free-text column: no migration), so renaming one
| here would orphan the incidents that hold the old name.
|
| "Other" is in NEITHER group: it means "neither list covers this", and the
| incident holds the typed text itself (e.g. "Tsunami"). It is offered on
| its own after both groups, and the two "All …" group filters leave it
| out, so neither group claims an incident it can't vouch for.
|
| Disaster advisories keep their own list (config/hazard_measurements.php):
| they warn about hazards; incidents record events that happened. The
| advisory's "Record what happened" prefills an advisory hazard as the
| incident type of the same name (DisasterAdvisory::incidentHazardPrefill()),
| and an advisory's Other as Other plus its name.
|
*/

return [

    // Typhoon, Earthquake, Flood and Fire are the original four; every
    // existing incident holds one of them, so they must stay as written.
    //
    // FOR DRRMO REVIEW (Mr. Abordo): Fire sits here because the existing Fire
    // incidents are disaster-related and the BFP treats fire as a hazard, but
    // a laboratory fire and a typhoon aren't the same thing to a DRRMO — he
    // may want Fire split (e.g. a "Fire" here and a campus-emergency one).
    //
    // FOR DRRMO REVIEW (Mr. Abordo): Fire records no measurements (there is
    // no Fire advisory, so config/hazard_measurements.php has no entry for
    // it). Should a Fire incident record the BFP's fire alarm level? If so,
    // he names the levels; we don't invent them.
    // Every advisory hazard (config/hazard_measurements.php, except Other) is
    // here, SPELLED IDENTICALLY, so an advisory can always be recorded as an
    // incident of the matching type; IncidentTypesTest fails if they drift.
    'Disaster-related' => ['Typhoon', 'Earthquake', 'Flood', 'Fire', 'Landslide', 'Storm Surge'],

    // PROVISIONAL — FOR DRRMO REVIEW (Mr. Abordo): this list needs the
    // client's confirmation before it is final; it is not ours to write.
    'Campus emergency' => [
        'Vehicular accident',
        'Injury or medical emergency',
        'Electrical or power failure',
        'Chemical or laboratory incident',
        'Structural failure',
    ],

];
