<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Responder extends Model
{
    protected $primaryKey = 'responder_id';

    protected $fillable = ['campus_id', 'full_name', 'position', 'team', 'assigned_post', 'contact_number', 'is_on_duty', 'is_active'];

    protected $casts = ['is_on_duty' => 'boolean', 'is_active' => 'boolean'];

    public function campus()
    {
        return $this->belongsTo(Campus::class, 'campus_id', 'campus_id');
    }

    public function assignments()
    {
        return $this->hasMany(Assignment::class, 'responder_id', 'responder_id');
    }

    public function activeAssignment()
    {
        return $this->hasOne(Assignment::class, 'responder_id', 'responder_id')->whereIn('status', Assignment::ACTIVE);
    }
}
