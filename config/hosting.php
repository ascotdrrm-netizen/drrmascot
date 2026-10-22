<?php

return [
    'serverless' => (bool) env('SERVERLESS_HOSTING', false),
    'direct_uploads' => (bool) env('DIRECT_UPLOADS', false),
    'cron_secret' => env('CRON_SECRET'),
    // Header holding the visitor's real IP when behind a proxy that sets it
    // (Render/Cloudflare: CF-Connecting-IP). Empty: use the connecting IP.
    'client_ip_header' => env('CLIENT_IP_HEADER'),
    'upload_max_bytes' => 10 * 1024 * 1024,
];
