<?php

namespace App\Traits;

use App\Models\ActivityLog;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

trait LogsActivity
{
    /** Per model class: while true, this model's automatic log rows are skipped. */
    protected static bool $activityLogMuted = false;

    /**
     * Run $callback without this model's automatic created/updated/deleted
     * rows — for callers that write one clearer entry of their own instead
     * (e.g. drill workflow transitions, a bulk participant save).
     */
    public static function withoutActivityLog(callable $callback): mixed
    {
        $previous = static::$activityLogMuted;
        static::$activityLogMuted = true;

        try {
            return $callback();
        } finally {
            static::$activityLogMuted = $previous;
        }
    }

    public static function bootLogsActivity(): void
    {
        static::created(function ($model) {
            $model->recordActivityLog('created');
        });

        static::updated(function ($model) {
            // Soft-delete/restore already produce their own 'deleted'/'restored' log
            // entries; the deleted_at flip during those saves shouldn't also count
            // as a meaningful 'updated' change.
            $deletedAtColumn = method_exists($model, 'getDeletedAtColumn') ? $model->getDeletedAtColumn() : 'deleted_at';
            $meaningfulChanges = array_diff(array_keys($model->getChanges()), [$deletedAtColumn]);

            if (! empty($meaningfulChanges)) {
                $model->recordActivityLog('updated');
            }
        });

        static::deleted(function ($model) {
            $model->recordActivityLog('deleted');
        });

        if (method_exists(static::class, 'restored')) {
            static::restored(function ($model) {
                $model->recordActivityLog('restored');
            });
        }
    }

    /**
     * The field value that identifies this model in log text (e.g. a name or type).
     * Override in the model to point at a more readable field than the primary key.
     */
    public function getActivityLogDescriptor(): string
    {
        return (string) $this->getKey();
    }

    protected function recordActivityLog(string $action): void
    {
        if (static::$activityLogMuted) {
            return;
        }

        $label = Str::headline(class_basename($this));
        $descriptor = $this->getActivityLogDescriptor();
        $description = "{$label} '{$descriptor}' {$action}";

        ActivityLog::create([
            'personnel_id' => Auth::id(),
            'action' => $action,
            'subject_type' => class_basename($this),
            'subject_id' => $this->getKey(),
            'description' => mb_substr($description, 0, 255),
            'ip_address' => request()?->ip(),
        ]);
    }
}
