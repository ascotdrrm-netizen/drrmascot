<?php

namespace App\Models;

use App\Models\Incident\Incident;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Traits\RecordsCreator;

class Alert extends Model
{
    use HasFactory, RecordsCreator;

    protected $primaryKey = 'alert_id';
    public $timestamps = false;

    protected $fillable = [
        'incident_id',
        'message',
        'sent_at',
        'recipient_count',
        'delivery_status',
    ];

    protected $casts = [
        'sent_at' => 'datetime',
    ];

    public function getDeliveryStatusBadgeClassAttribute(): string
    {
        return match ($this->delivery_status) {
            'Sent' => 'badge-sent',
            'Partial' => 'badge-partial',
            'Failed' => 'badge-failed',
            default => '',
        };
    }

    public function incident()
    {
        return $this->belongsTo(Incident::class, 'incident_id', 'incident_id');
    }

    /** Who it went to (alerts sent before October 22, 2026 have none recorded). */
    public function recipients()
    {
        return $this->hasMany(AlertRecipient::class, 'alert_id', 'alert_id')->orderBy('full_name');
    }
}
