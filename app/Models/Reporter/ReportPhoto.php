<?php

namespace App\Models\Reporter;

use Illuminate\Database\Eloquent\Model;

class ReportPhoto extends Model
{
    protected $primaryKey = 'photo_id';
    public $timestamps = false;
    protected $fillable = ['path', 'caption'];

    public function report()
    {
        return $this->belongsTo(CitizenReport::class, 'report_id', 'report_id');
    }
}
