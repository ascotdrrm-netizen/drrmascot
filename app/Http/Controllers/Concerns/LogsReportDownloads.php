<?php

namespace App\Http\Controllers\Concerns;

use App\Models\ActivityLog;
use Illuminate\Support\Facades\Auth;

/**
 * Shared activity-log write for every per-record PDF download (a single
 * building, hazard inspection, incident, drill, or alert) — so each
 * controller states only WHAT was downloaded, not how to write the log row.
 */
trait LogsReportDownloads
{
    protected function logReportDownload(string $description): void
    {
        ActivityLog::create([
            'personnel_id' => Auth::id(),
            'action' => 'report_exported',
            'subject_type' => null,
            'subject_id' => null,
            'description' => mb_substr($description, 0, 255),
            'ip_address' => request()?->ip(),
        ]);
    }
}
