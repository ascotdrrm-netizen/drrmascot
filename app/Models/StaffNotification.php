<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StaffNotification extends Model
{
    protected $primaryKey = 'notification_id';
    protected $fillable = ['personnel_id', 'campus_id', 'kind', 'subject_id', 'message', 'read_at'];
    protected $casts = ['read_at' => 'datetime'];
}
