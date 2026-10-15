<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CampusStatus extends Model
{
    protected $primaryKey = 'campus_id';
    public $incrementing = false;
    protected $fillable = ['campus_id', 'status', 'detail', 'updated_by'];

    public function campus()
    {
        return $this->belongsTo(Campus::class, 'campus_id', 'campus_id');
    }
}
