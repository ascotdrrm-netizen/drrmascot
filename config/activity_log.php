<?php

// Activity Log retention (App\Models\ActivityLog::prunable, run daily).
// FOR DRRMO REVIEW (Mr. Abordo): how long the office must keep its audit trail.
return [
    'retention_days' => max(30, (int) env('ACTIVITY_LOG_RETENTION_DAYS', 730)),
    'failed_login_days' => max(1, (int) env('FAILED_LOGIN_LOG_DAYS', 30)),
];
