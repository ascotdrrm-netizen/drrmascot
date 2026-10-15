<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BroadcastDelivery extends Model
{
    protected $primaryKey = 'delivery_id';

    protected $fillable = ['broadcast_id', 'personnel_id', 'channel', 'destination', 'status', 'attempts', 'error', 'started_at', 'sent_at'];

    protected $casts = ['started_at' => 'datetime', 'sent_at' => 'datetime'];

    public function broadcast()
    {
        return $this->belongsTo(Broadcast::class, 'broadcast_id', 'broadcast_id');
    }
}
