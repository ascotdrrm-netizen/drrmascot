<?php

namespace App\Support;

use App\Models\ActivityLog;
use App\Models\Drill\Drill;
use App\Models\Personnel;

/**
 * One activity-log row naming the drill, its campus and scheduled date, e.g.
 * "Drill 'Fire' (ASCOT Zabali Campus, Oct 5, 2026) documentation submitted".
 *
 * Actions written here:
 *   drill_scheduled                 a drill was created (one row per campus)
 *   drill_documented                documentation submitted or updated
 *   drill_after_activity_submitted  After Activity Report submitted or updated
 *   updated                         an attachment was removed
 * Prints and downloads go through LogsReportDownloads ('report_exported').
 */
final class DrillLog
{
    public static function record(Personnel $actor, Drill $drill, string $action, string $what): void
    {
        $drill->loadMissing('campus');

        ActivityLog::create([
            'personnel_id' => $actor->personnel_id,
            'action' => $action,
            'subject_type' => 'Drill',
            'subject_id' => $drill->getKey(),
            'description' => mb_substr(sprintf(
                "Drill '%s' (%s, %s) %s",
                mb_strimwidth($drill->drill_type, 0, 40, '…'),
                $drill->campus->campus_name ?? 'Unknown campus',
                $drill->scheduled_date->format('M j, Y'),
                $what
            ), 0, 255),
            'ip_address' => request()?->ip(),
        ]);
    }
}
