<?php

namespace App\Models\Building;

use App\Traits\HasLocation;
use App\Traits\LogsActivity;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;
use App\Traits\RecordsCreator;

class HazardInspection extends Model
{
    use HasFactory, LogsActivity, HasLocation, RecordsCreator;

    protected $primaryKey = 'inspection_id';
    public $timestamps = false;

    protected $fillable = [
        'building_id',
        'school_id',
        'inspection_date',
        'findings',
        'risk_level',
        'photo_path',
        'photo_caption',
        'latitude',
        'longitude',
    ];

    protected $casts = [
        'inspection_date' => \App\Casts\DateOnly::class,
    ];

    public function getPhotoUrlAttribute(): ?string
    {
        return $this->photo_path ? \App\Support\Files::url($this->photo_path) : null;
    }

    public function building()
    {
        return $this->belongsTo(Building::class, 'building_id', 'building_id');
    }

    /**
     * The school that recorded the inspection — attribution only. Kept even if
     * the building later moves campus: it records who inspected, not where.
     */
    public function school()
    {
        return $this->belongsTo(\App\Models\School::class, 'school_id', 'school_id');
    }
}
