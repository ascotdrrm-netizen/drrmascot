<?php

namespace App\Models;

use App\Traits\LogsActivity;
use Illuminate\Database\Eloquent\Model;

class CampusStatus extends Model
{
    // An emergency status (e.g. Evacuate) is the most consequential change
    // staff make, so every change is in the Activity Log.
    use LogsActivity;

    /** What each status is called on screens and in notices. */
    public const LABELS = ['normal' => 'Normal', 'advisory' => 'Advisory in effect',
        'suspended' => 'Classes suspended', 'evacuate' => 'Evacuate'];

    protected $primaryKey = 'campus_id';
    public $incrementing = false;
    protected $fillable = ['campus_id', 'status', 'detail', 'updated_by'];

    public function campus()
    {
        return $this->belongsTo(Campus::class, 'campus_id', 'campus_id');
    }

    public function getActivityLogDescriptor(): string
    {
        return ($this->campus?->campus_name ?? 'Campus').' → '.ucfirst((string) $this->status);
    }
}
