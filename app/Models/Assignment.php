<?php

namespace App\Models;

use App\Models\Reporter\CitizenReport;
use App\Models\Reporter\SosEvent;
use Illuminate\Database\Eloquent\Model;

class Assignment extends Model
{
    public const ACTIVE = ['Assigned', 'On scene'];

    protected $primaryKey = 'assignment_id';

    protected $fillable = ['campus_id', 'responder_id', 'report_id', 'sos_id', 'assigned_by', 'status', 'notes',
        'assigned_at', 'arrived_at', 'completed_at', 'cancelled_at'];

    protected $casts = ['assigned_at' => 'datetime', 'arrived_at' => 'datetime', 'completed_at' => 'datetime', 'cancelled_at' => 'datetime'];

    public function responder()
    {
        // A responder in the Archive still did this assignment.
        return $this->belongsTo(Responder::class, 'responder_id', 'responder_id')->withTrashed();
    }

    public function report()
    {
        return $this->belongsTo(CitizenReport::class, 'report_id', 'report_id');
    }

    public function sos()
    {
        return $this->belongsTo(SosEvent::class, 'sos_id', 'sos_id');
    }
}
