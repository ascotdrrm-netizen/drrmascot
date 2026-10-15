<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

class ActivityLog extends Model
{
    protected $primaryKey = 'activity_log_id';

    public $timestamps = true;

    const UPDATED_AT = null;

    protected $fillable = [
        'personnel_id',
        'actor_name',
        'action',
        'subject_type',
        'subject_id',
        'description',
        'ip_address',
    ];

    /**
     * Stamp the actor's name on every new row, whichever of the app's log
     * writers created it, so the audit trail still names the person if their
     * account is deleted later (personnel_id is nullOnDelete).
     */
    protected static function booted(): void
    {
        static::creating(function (ActivityLog $log) {
            if ($log->personnel_id === null || $log->actor_name !== null) {
                return;
            }

            $user = Auth::user();
            $log->actor_name = $user && $user->getKey() === $log->personnel_id
                ? $user->full_name
                : Personnel::whereKey($log->personnel_id)->value('full_name');
        });
    }

    /**
     * actor_name of entries the system writes on its own schedule (the Trash
     * purge): no account, but not a deleted one either.
     */
    public const SYSTEM_ACTOR = 'System (automatic)';

    public function actor()
    {
        return $this->belongsTo(Personnel::class, 'personnel_id', 'personnel_id');
    }

    /**
     * Who to show for this row: the live account's current name; the name
     * stored at write time if that account has since been deleted;
     * "System (automatic)" for the scheduled Trash purge; or "System" when no
     * user was signed in (seeders, scripts, failed logins).
     */
    public function getActorLabelAttribute(): string
    {
        if ($this->actor) {
            return $this->actor->full_name;
        }

        if ($this->actor_name === self::SYSTEM_ACTOR && $this->personnel_id === null) {
            return self::SYSTEM_ACTOR;
        }

        if ($this->actor_name) {
            return "{$this->actor_name} (deleted account)";
        }

        return 'System';
    }

    /**
     * Readable names for the action keys — the on-screen log's filter and
     * badges and the Activity Log PDF all use these, never the raw keys.
     */
    public const ACTION_LABELS = [
        'login' => 'Login',
        'logout' => 'Logout',
        'login_failed' => 'Failed Login',
        'created' => 'Created',
        'updated' => 'Updated',
        'deleted' => 'Deleted',
        'restored' => 'Restored',
        'permanently_deleted' => 'Permanently Deleted',
        'data_privacy_denied' => 'Data Privacy Denied',
        'report_generated' => 'Report Generated',
        'report_exported' => 'Report Exported',
        'password_reset_requested' => 'Password Reset Requested',
        'password_reset_completed' => 'Password Reset Completed',
        'drill_scheduled' => 'Drill Scheduled',
        'drill_documented' => 'Drill Documented',
        'drill_after_activity_submitted' => 'After Activity Report',
        'drill_acknowledged' => 'Drill Acknowledged',
        'drill_submitted' => 'Drill Submitted',
        'drill_returned' => 'Drill Returned',
        'drill_completed' => 'Drill Completed',
        'drill_released' => 'Drill Released',
        'records_cleared' => 'Records Cleared',
        'account_registered' => 'Account Registered',
        'account_approved' => 'Account Approved',
        'account_rejected' => 'Account Rejected',
        'backup_downloaded' => 'Database Backup Downloaded',
    ];

    /** This row's action as a readable label; an unlisted key is title-cased rather than shown raw. */
    public function getActionLabelAttribute(): string
    {
        return self::ACTION_LABELS[$this->action] ?? Str::headline((string) $this->action);
    }
}
