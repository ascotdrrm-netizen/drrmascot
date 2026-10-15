<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Data Privacy Password
    |--------------------------------------------------------------------------
    |
    | A second, system-wide secret required (independently of a DRRMO Admin's
    | own login) before the full database can be downloaded or restored via
    | the Backup & Restore page. Store only a bcrypt HASH here — generate it
    | with `php -r "echo password_hash('yourpassword', PASSWORD_BCRYPT);"`
    | and put the result in DATA_PRIVACY_PASSWORD_HASH in .env. Never put the
    | raw password itself in .env or anywhere in the codebase.
    |
    */

    'data_privacy_password_hash' => env('DATA_PRIVACY_PASSWORD_HASH'),

];
