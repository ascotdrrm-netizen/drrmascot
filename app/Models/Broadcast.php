<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Broadcast extends Model
{
    protected $primaryKey = 'broadcast_id';

    protected $fillable = ['campus_id', 'created_by', 'published_by', 'client_key', 'title', 'body', 'sms_text', 'send_email', 'send_sms', 'status', 'published_at'];

    protected $casts = ['send_email' => 'boolean', 'send_sms' => 'boolean', 'published_at' => 'datetime'];

    public function campus()
    {
        return $this->belongsTo(Campus::class, 'campus_id', 'campus_id');
    }

    public function deliveries()
    {
        return $this->hasMany(BroadcastDelivery::class, 'broadcast_id', 'broadcast_id');
    }
}
