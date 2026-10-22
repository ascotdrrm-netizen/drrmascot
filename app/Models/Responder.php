<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Responder extends Model
{
    // Deleted from Dispatch into the Archive; restored from there.
    use SoftDeletes;

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

    /** The name, as the Archive and the permanent-delete log entry show it. */
    public function getActivityLogDescriptor(): string
    {
        return $this->full_name;
    }
}
