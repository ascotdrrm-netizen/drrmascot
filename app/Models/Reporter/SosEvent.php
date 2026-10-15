<?php

namespace App\Models\Reporter;

use App\Models\Campus;
use App\Models\Personnel;
use Illuminate\Database\Eloquent\Model;

class SosEvent extends Model
{
    protected $primaryKey = 'sos_id';
    protected $fillable = ['reporter_id', 'campus_id', 'client_key', 'type', 'place',
        'latitude', 'longitude', 'accuracy_m', 'status', 'acknowledged_by', 'acknowledged_at', 'resolved_at'];
    protected $casts = ['acknowledged_at' => 'datetime', 'resolved_at' => 'datetime'];

    protected static function booted(): void
    {
        static::updated(function (SosEvent $sos) {
            if ($sos->wasChanged('status')) \App\Models\StaffNotification::create([
                'personnel_id' => $sos->reporter_id, 'campus_id' => $sos->campus_id,
                'kind' => 'sos_update', 'subject_id' => $sos->getKey(),
                'message' => "SOS #{$sos->getKey()} is now {$sos->status}.",
            ]);
        });
    }

    public function reporter()
    {
        return $this->belongsTo(Personnel::class, 'reporter_id', 'personnel_id');
    }

    public function campus()
    {
        return $this->belongsTo(Campus::class, 'campus_id', 'campus_id');
    }
}
