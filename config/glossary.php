<?php

/*
|--------------------------------------------------------------------------
| Plain-language glossary
|--------------------------------------------------------------------------
|
| One sentence per term, shown where the term appears: helper text under form
| fields, info tips next to badges and column headings, and the Help page.
| Change a definition here and every place that shows it follows.
|
| Presentation only: nothing here is read by validation, queries or access
| rules.
|
*/

return [

    // The 5×5 risk matrix (App\Support\RiskMatrix holds the scales and bands).
    'risk_level' => 'Likelihood (1–5) × impact (1–5) gives a score of 1 to 25: Low 1–4, Moderate 5–9, High 10–16, Very High 17–25.',
    'risk_level_from_inspection' => 'A building or area takes the risk level of its latest hazard inspection.',
    'likelihood' => 'How likely the hazard is to cause harm here.',
    'impact' => 'How bad the harm would be if it happened.',
    'risk_levels' => [
        'Low' => 'Score 1–4. Keep watching it; fix it during routine maintenance.',
        'Moderate' => 'Score 5–9. Plan a fix within the semester and tell the people who use the area.',
        'High' => 'Score 10–16. Fix it within 30 days; limit use of the area and post warnings.',
        'Very High' => 'Score 17–25. Act now: close or evacuate the area until it is made safe.',
    ],

    'building_hazard_type' => 'The main hazard this building or area is exposed to.',
    // The incident's type (stored in incidents.hazard_type): config/incident_types.php.
    'incident_hazard_type' => 'What happened: a disaster-related event (a typhoon, earthquake, flood, fire, landslide or storm surge) or a campus emergency (an accident, an injury, a power failure, a laboratory incident, a structural failure). Other is for anything neither list covers.',

    'incident_status' => [
        'Active' => 'The incident is ongoing and still needs a response.',
        'Under Response' => 'People are already responding to it.',
        'Resolved' => 'It is over and needs no further action.',
    ],
    'incident_status_hint' => 'Update the status as the situation changes.',

    'damage_level' => [
        'Minor' => 'Small damage; the building or area can still be used safely.',
        'Major' => 'Serious damage; part or all of it cannot be used until repaired.',
        'Total' => 'Destroyed or unsafe to enter; it must be rebuilt or replaced.',
    ],
    'casualty_count' => 'People killed, injured or missing at this building or area because of the incident.',
    'est_cost' => 'A rough cost in pesos to repair or replace what was damaged. Leave it blank if unknown.',

    'delivery_status' => [
        'Sent' => 'The SMS went out to every recipient.',
        'Partial' => 'The SMS reached some recipients but failed for others.',
        'Failed' => 'The SMS could not be sent to anyone.',
    ],
    'recipients' => 'How many people the SMS was sent to: every account on the incident’s campus with a contact number.',

    'drill_documentation' => 'What happened at the drill, written after it was held: when, a narrative, roughly how many took part, with photos or PDFs.',
    'after_activity_report' => 'ASCOT’s After Activity Report: the drill summarised as an accomplishment — objectives, activities, participants, results and recommendations.',
    'partner_agency' => 'An outside agency taking part in the drill, if any.',
    'announcement' => 'A printable notice of the drill’s date, time and venue for students and staff.',
    'school' => 'The school a record is attributed to. It is used for reports only and never changes who can see the record.',
    'not_assigned' => '“Not assigned” means records saved without a school.',

    // Disaster Advisories. Statuses and the source list are the system's own
    // wording. Keyed by the DISPLAY label: the stored "Active" is shown as
    // "In effect", or "Expired" once its valid-until time has passed.
    'advisory_status' => [
        'In effect' => 'It shows as a warning on the dashboards of its campus until its valid-until time.',
        'Expired' => 'Its valid-until time has passed without anyone marking it Passed, so it no longer shows on the dashboards. Mark it Passed, or extend Valid until if the bulletin was renewed.',
        'Passed' => 'The hazard is over. It stays in the list for the record.',
        'Cancelled' => 'Withdrawn, or issued by mistake. It stays in the list, but no incident can be recorded from it.',
    ],
    'advisory_source' => 'Who issued the bulletin the advisory was typed in from. The system has no automatic feed.',
    'advisory_valid_until' => 'When the advisory stops showing on the dashboards, even if nobody marks it Passed. Take it from the bulletin, or from when the next bulletin is due.',

    // FOR DRRMO REVIEW (Mr. Abordo) — official terms. These don't define the
    // terms themselves; they point at the agency that does. Confirm each, or
    // replace it with the agency's own wording, before relying on it.
    'measurement_terms' => [
        'Wind Signal No.' => 'PAGASA’s Tropical Cyclone Wind Signal (1 to 5), as given in its Tropical Cyclone Bulletin. See PAGASA for what each number means.',
        'Maximum sustained winds' => 'As stated in PAGASA’s Tropical Cyclone Bulletin.',
        'Gustiness' => 'As stated in PAGASA’s Tropical Cyclone Bulletin.',
        'Magnitude' => 'As reported in the PHIVOLCS earthquake information bulletin.',
        'Depth' => 'Depth of focus, as reported in the PHIVOLCS earthquake information bulletin.',
        'Epicentre' => 'As reported in the PHIVOLCS earthquake information bulletin.',
        'PHIVOLCS Intensity (at the campus)' => 'The PHIVOLCS Earthquake Intensity Scale (PEIS) value reported for the campus’s area. See PHIVOLCS for what each level means.',
        'Rainfall warning (Yellow/Orange/Red)' => 'PAGASA’s colour-coded rainfall warning for the area, as issued. See PAGASA for what each colour means.',
        'Water level' => 'As reported by the issuing agency or local DRRMO.',
        'Expected surge height' => 'As stated in PAGASA’s storm surge warning.',
        // An incident's observed surge_height (its observed_label in config/hazard_measurements.php).
        // FOR DRRMO REVIEW (Mr. Abordo), like the rest of this list.
        'Surge height' => 'The height of the surge at the campus, as observed or as reported by the local DRRMO.',
    ],

    'trash' =>'Deleted incidents, buildings/areas, disaster advisories and drills wait in Trash, where they can be restored. After the retention period they are removed permanently, with their photos and files; each Trash page says how long that is, when each item goes, and whether automatic removal is running.',

    'kpi' => [
        'total_incidents' => 'Incidents whose date falls in the selected period.',
        'unresolved_incidents' => 'Incidents in the period still marked Active or Under Response.',
        'inspections' => 'Hazard inspections dated in the selected period.',
        'open_high_risk' => 'Inspections rated High or Very High that are still the latest inspection of their building — a newer inspection closes them.',
        'drills' => 'Drills documented as held in the period, counted by the date they were held.',
    ],
    'kpi_coordinator_scope' => 'Your dashboard counts only the incidents and inspections you recorded, and your campus’s drills.',

];
