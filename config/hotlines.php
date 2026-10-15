<?php

/*
|--------------------------------------------------------------------------
| Emergency hotlines on the public landing page
|--------------------------------------------------------------------------
|
| The single source for every number in the landing page's Emergency
| Hotlines section — corrections go here, never in the view.
|
| Sources:
| - Aurora Province, Baler and ASCOT: transcribed from the two hotline
|   posters on the DRRMO office wall. Where the two posters disagree, BOTH
|   numbers are listed deliberately until the DRRMO (Mr. Abordo) confirms
|   which is current.
| - Maria Aurora and Casiguran: supplied directly, NOT transcribed from the
|   office posters (which list only Baler's municipal numbers).
|
| These are public agency and office lines only. Never put a personnel
| account's contact_number here: those are personal numbers used for SMS
| alerts and must not reach a public page (LandingOfficerTest guards this).
|
| Each entry:
|   'label'   => what it is
|   'numbers' => phone numbers as printed (each becomes a tap-to-call link);
|                an empty list renders as "to be provided"
|   'radio'   => a radio frequency instead (shown as text, never a link)
|
*/

return [

    'groups' => [

        [
            'title' => 'Aurora Province',
            'entries' => [
                ['label' => 'PDRRMO', 'numbers' => ['0939-325-7838', '0963-742-7777']],
                ['label' => 'Philippine Army', 'numbers' => ['0945-457-7339', '0917-108-2418']],
                ['label' => 'Philippine Coast Guard', 'numbers' => ['0998-585-6227', '0956-417-0107']],
            ],
        ],

        [
            'title' => 'Baler',
            'subtitle' => 'Serves Zabali Campus',
            'note' => 'These are the Baler municipality numbers, nearest to Zabali Campus.',
            'entries' => [
                ['label' => 'MDRRMO Baler', 'numbers' => ['0920-594-1906']],
                ['label' => 'MDRRMO radio frequency', 'radio' => '147.110 MHz'],
                ['label' => 'Baler PNP', 'numbers' => ['0908-526-4029', '0998-598-5340']],
                ['label' => 'Baler BFP', 'numbers' => ['0917-119-2794', '0939-216-6377']],
                ['label' => 'RHU Baler', 'numbers' => ['0930-668-3981', '0927-304-2828']],
                ['label' => 'AURELCO (power)', 'numbers' => ['0999-977-2735', '0921-869-1859']],
                ['label' => 'Baler Tourism Office', 'numbers' => ['0928-593-7663']],
            ],
        ],

        [
            'title' => 'Maria Aurora',
            'subtitle' => 'Serves Bazal Campus',
            'entries' => [
                ['label' => 'Maria Aurora MDRRMO', 'numbers' => ['0928-483-0082', '0927-547-9307']],
            ],
        ],

        [
            'title' => 'Casiguran',
            'subtitle' => 'Serves Casiguran Campus',
            'entries' => [
                ['label' => 'Casiguran MDRRMO', 'numbers' => ['0921-760-1240']],
            ],
        ],

        [
            'title' => 'ASCOT',
            'note' => 'The DRRMO office is at Ermita Hill, Zabali Campus.',
            'entries' => [
                ['label' => 'ASCOT DRRMO Office', 'numbers' => ['0917-152-9432']],
                ['label' => 'Campus Security', 'numbers' => []],
            ],
        ],

    ],

];
