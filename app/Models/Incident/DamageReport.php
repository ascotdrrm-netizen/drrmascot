<?php

namespace App\Models\Incident;

use App\Models\Building\Building;
use App\Traits\LogsActivity;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Traits\RecordsCreator;

class DamageReport extends Model
{
    use HasFactory, LogsActivity, RecordsCreator;

    protected $primaryKey = 'report_id';
    public $timestamps = false;

    protected $fillable = [
        'incident_id',
        'building_id',
        'area_name',
        'damage_level',
        'casualty_count',
        'est_cost',
    ];

    /** The building/area as typed; older reports fall back to their building's name. */
    public function areaLabel(): string
    {
        return $this->area_name ?: ($this->building->building_name ?? '—');
    }

    /** Lower-cased and single-spaced, so "Main  Gym" and "main gym" are one area. */
    public static function normalizeArea(?string $name): string
    {
        return mb_strtolower(preg_replace('/\s+/u', ' ', trim((string) $name)));
    }

    public function incident()
    {
        return $this->belongsTo(Incident::class, 'incident_id', 'incident_id');
    }

    public function building()
    {
        return $this->belongsTo(Building::class, 'building_id', 'building_id');
    }
}
