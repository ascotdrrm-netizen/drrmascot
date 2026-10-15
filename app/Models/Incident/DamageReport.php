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
        'damage_level',
        'casualty_count',
        'est_cost',
    ];

    public function incident()
    {
        return $this->belongsTo(Incident::class, 'incident_id', 'incident_id');
    }

    public function building()
    {
        return $this->belongsTo(Building::class, 'building_id', 'building_id');
    }
}
