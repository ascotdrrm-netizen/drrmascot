<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RollCallResponse extends Model
{
    protected $primaryKey = 'response_id';

    protected $fillable = ['roll_call_id', 'reporter_id', 'full_name', 'contact_number', 'answer', 'note',
        'latitude', 'longitude', 'accuracy_m', 'responded_at'];

    protected $casts = ['responded_at' => 'datetime'];

    public function rollCall()
    {
        return $this->belongsTo(RollCall::class, 'roll_call_id', 'roll_call_id');
    }
}
