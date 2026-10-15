<?php

namespace App\Models;

use App\Traits\LogsActivity;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Requisition extends Model
{
    use HasFactory, SoftDeletes, LogsActivity;

    protected $primaryKey = 'requisition_id';
    public $timestamps = false;

    protected $fillable = [
        'campus_id',
        'status',
        'date_requested',
        'letter_path',
        'submitted_by',
    ];

    protected $casts = [
        'date_requested' => 'date',
    ];

    public function getStatusBadgeClassAttribute(): string
    {
        return match ($this->status) {
            'Pending' => 'badge-pending',
            'Approved' => 'badge-approved',
            'Dispatched' => 'badge-dispatched',
            'Denied' => 'badge-denied',
            default => '',
        };
    }

    public function campus()
    {
        return $this->belongsTo(Campus::class, 'campus_id', 'campus_id');
    }

    public function items()
    {
        return $this->hasMany(RequisitionItem::class, 'requisition_id', 'requisition_id');
    }

    public function submittedBy()
    {
        return $this->belongsTo(Personnel::class, 'submitted_by', 'personnel_id');
    }

    public function hasLetter(): bool
    {
        return $this->letter_path !== null;
    }
}
