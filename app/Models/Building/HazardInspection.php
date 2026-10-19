<?php

namespace App\Models\Building;

use App\Support\RiskMatrix;
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
        'likelihood',
        'impact',
        'risk_score',
        'photo_path',
        'photo_caption',
        'latitude',
        'longitude',
    ];

    protected $casts = [
        'inspection_date' => \App\Casts\DateOnly::class,
        'likelihood' => 'integer',
        'impact' => 'integer',
        'risk_score' => 'integer',
    ];

    /**
     * The building shows the level of its latest inspection: any change to
     * an inspection (new, edited, deleted) recomputes it.
     */
    protected static function booted(): void
    {
        static::saved(fn (HazardInspection $i) => Building::refreshRiskLevelOf($i->building_id));
        static::deleted(fn (HazardInspection $i) => Building::refreshRiskLevelOf($i->building_id));
    }

    /** Rate with the 5×5 matrix: sets the factors, the score and the level. */
    public function rate(int $likelihood, int $impact): static
    {
        $score = RiskMatrix::score($likelihood, $impact);

        return $this->fill([
            'likelihood' => $likelihood,
            'impact' => $impact,
            'risk_score' => $score,
            'risk_level' => RiskMatrix::level($score),
        ]);
    }

    /** "Likely (4) × Major (4) = 16" — empty for inspections rated before the matrix. */
    public function getRiskBreakdownAttribute(): ?string
    {
        if (! $this->likelihood || ! $this->impact) {
            return null;
        }

        return sprintf('%s (%d) × %s (%d) = %d',
            RiskMatrix::LIKELIHOOD[$this->likelihood]['label'], $this->likelihood,
            RiskMatrix::IMPACT[$this->impact]['label'], $this->impact, $this->risk_score);
    }

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
