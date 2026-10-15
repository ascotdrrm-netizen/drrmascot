<?php

namespace App\Models\Reporter;

use App\Models\Campus;
use App\Models\Personnel;
use Illuminate\Database\Eloquent\Model;

class CitizenReport extends Model
{
    protected $primaryKey = 'report_id';

    protected $fillable = ['reporter_id', 'campus_id', 'client_key', 'category', 'place',
        'latitude', 'longitude', 'accuracy_m', 'description', 'people_injured', 'urgent', 'status', 'proof_path'];

    protected $casts = ['urgent' => 'boolean', 'people_injured' => 'integer'];

    protected static function booted(): void
    {
        static::updated(function (CitizenReport $report) {
            if ($report->wasChanged('status')) \App\Models\StaffNotification::create([
                'personnel_id' => $report->reporter_id, 'campus_id' => $report->campus_id,
                'kind' => 'report_update', 'subject_id' => $report->getKey(),
                'message' => "Report #{$report->getKey()} is now {$report->status}.",
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

    public function photos()
    {
        return $this->hasMany(ReportPhoto::class, 'report_id', 'report_id');
    }

    public function events()
    {
        return $this->hasMany(ReportEvent::class, 'report_id', 'report_id')->orderBy('event_id');
    }
}
