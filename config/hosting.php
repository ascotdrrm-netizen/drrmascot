<?php

return [
    'serverless' => (bool) env('SERVERLESS_HOSTING', false),
    'direct_uploads' => (bool) env('DIRECT_UPLOADS', false),
    'cron_secret' => env('CRON_SECRET'),
    'upload_max_bytes' => 10 * 1024 * 1024,
];
