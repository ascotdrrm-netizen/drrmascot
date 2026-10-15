<?php

namespace App\Models;

use App\Models\Building\Building;
use App\Models\Drill\Drill;
use App\Models\Incident\Incident;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Campus extends Model
{
    use HasFactory;

    protected $primaryKey = 'campus_id';
    public $timestamps = false;

    protected $fillable = [
        'campus_name',
        'address',
    ];

    public function personnel()
    {
        return $this->hasMany(Personnel::class, 'campus_id', 'campus_id');
    }

    public function buildings()
    {
        return $this->hasMany(Building::class, 'campus_id', 'campus_id');
    }

    public function drills()
    {
        return $this->hasMany(Drill::class, 'campus_id', 'campus_id');
    }

    public function incidents()
    {
        return $this->hasMany(Incident::class, 'campus_id', 'campus_id');
    }

    public function schools()
    {
        return $this->hasMany(School::class, 'campus_id', 'campus_id')->orderBy('school_name');
    }
}
