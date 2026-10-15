<?php

namespace App\Models\Drill;

use App\Models\Personnel;
use App\Traits\LogsActivity;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class DrillEvaluation extends Model
{
    use HasFactory, LogsActivity;

    protected $primaryKey = 'evaluation_id';
    public $timestamps = false;

    protected $fillable = [
        'drill_id',
        'personnel_id',
        'pre_score',
        'post_score',
    ];

    protected $casts = [
        'pre_score' => 'float',
        'post_score' => 'float',
    ];

    public function getImprovementAttribute(): ?float
    {
        if ($this->pre_score === null || $this->post_score === null) {
            return null;
        }

        return $this->post_score - $this->pre_score;
    }

    public function drill()
    {
        return $this->belongsTo(Drill::class, 'drill_id', 'drill_id');
    }

    public function personnel()
    {
        return $this->belongsTo(Personnel::class, 'personnel_id', 'personnel_id');
    }
}
