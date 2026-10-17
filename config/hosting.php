<?php

return [
    'serverless' => (bool) env('SERVERLESS_HOSTING', false),
    'direct_uploads' => (bool) env('DIRECT_UPLOADS', false),
    'cron_secret' => env('CRON_SECRET'),
    // Set only in the host's dashboard, for one deploy: start-up creates the
    // demo accounts with it (app:create-demo-accounts --from-env).
    'demo_accounts_password' => env('DEMO_ACCOUNTS_PASSWORD'),
    'upload_max_bytes' => 10 * 1024 * 1024,
];
