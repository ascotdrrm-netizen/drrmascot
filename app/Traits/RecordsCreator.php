<?php

namespace App\Traits;

use App\Models\Personnel;
use Illuminate\Support\Facades\Auth;

/**
 * Stamps who created a record, from the signed-in user — never from request
 * input (created_by / created_by_name are not $fillable). The name copy keeps
 * the attribution readable if the account is deleted later (the FK is
 * nulled then), the same pattern as the drill workflow's *_by_name columns.
 *
 * Visibility rules built on this live in App\Support\RecordAccess.
 */
trait RecordsCreator
{
    public static function bootRecordsCreator(): void
    {
        static::creating(function ($model) {
            if ($model->created_by !== null) {
                return; // already set explicitly (seeders, tests, migrations)
            }

            $user = Auth::user();

            if ($user instanceof Personnel) {
                $model->created_by = $user->personnel_id;
                $model->created_by_name = $user->full_name;
            }
        });
    }

    public function creator()
    {
        return $this->belongsTo(Personnel::class, 'created_by', 'personnel_id');
    }

    /** The live account's name, the stored name if it was deleted, or an em dash. */
    public function getCreatorLabelAttribute(): string
    {
        if ($this->creator) {
            return $this->creator->full_name;
        }

        return $this->created_by_name ? "{$this->created_by_name} (deleted account)" : '—';
    }
}
