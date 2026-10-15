<?php

namespace App\Models\Reporter;

use Illuminate\Database\Eloquent\Model;

class ReportEvent extends Model
{
    protected $primaryKey = 'event_id';
    public $timestamps = false;
    protected $fillable = ['actor_id', 'status', 'note', 'created_at'];
    protected $casts = ['created_at' => 'datetime'];
}
