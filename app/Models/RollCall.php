<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RollCall extends Model
{
    protected $primaryKey = 'roll_call_id';

    protected $fillable = ['campus_id', 'title', 'instructions', 'created_by', 'closed_by', 'started_at', 'closed_at'];

    protected $casts = ['started_at' => 'datetime', 'closed_at' => 'datetime'];

    public function campus()
    {
        return $this->belongsTo(Campus::class, 'campus_id', 'campus_id');
    }

    public function creator()
    {
        return $this->belongsTo(Personnel::class, 'created_by', 'personnel_id');
    }

    public function responses()
    {
        return $this->hasMany(RollCallResponse::class, 'roll_call_id', 'roll_call_id');
    }
}
