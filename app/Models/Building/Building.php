<?php

namespace App\Models\Building;

use App\Models\Campus;
use App\Models\Incident\DamageReport;
use App\Traits\HasLocation;
use App\Traits\LogsActivity;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Storage;
use App\Traits\RecordsCreator;

class Building extends Model
{
    use HasFactory, SoftDeletes, LogsActivity, HasLocation, RecordsCreator;

    protected $primaryKey = 'building_id';
    public $timestamps = false;

    protected $fillable = [
        'campus_id',
        'building_name',
        'description',
        'hazard_type',
        'photo_path',
        'photo_caption',
        'latitude',
        'longitude',
        'risk_level',
    ];

    /** Photo files to remove once a permanent delete has gone through, by model instance. */
    protected static array $photosToRemove = [];

    /**
     * Photos are deleted on PERMANENT delete only: moving a building to Trash
     * keeps its photo (and its inspections' photos), so a restore brings it
     * back whole. The database cascades the inspections without model events,
     * so their photo paths are collected just before the delete — after it,
     * the inspection rows are gone and there is nothing left to read them from.
     * The files go once the delete has committed: a rolled-back delete keeps
     * its photos.
     */
    protected static function booted(): void
    {
        static::forceDeleting(function (Building $building) {
            static::$photosToRemove[spl_object_id($building)] = $building->storedFiles();
        });
        static::forceDeleted(function (Building $building) {
            $paths = static::$photosToRemove[spl_object_id($building)] ?? [];
            unset(static::$photosToRemove[spl_object_id($building)]);
            \App\Support\TrashPurge::deleteFilesAfterCommit(\App\Support\Files::diskName(), $paths);
        });
    }

    /**
     * Sets the building's risk level to its latest inspection's (by date,
     * then the newest record); with no inspection left it is not yet
     * assessed (null). A building never inspected keeps the level it was
     * given by hand before the risk matrix.
     */
    public static function refreshRiskLevelOf(?int $buildingId): void
    {
        if (! $buildingId) {
            return;
        }
        $latest = HazardInspection::where('building_id', $buildingId)
            ->orderByDesc('inspection_date')->orderByDesc('inspection_id')->value('risk_level');
        static::withTrashed()->whereKey($buildingId)->update(['risk_level' => $latest]);
    }

    /** Its photo and its inspections' photos (the records disk, App\Support\Files): what a permanent delete removes. */
    public function storedFiles(): array
    {
        return array_values(array_filter([
            $this->photo_path,
            ...$this->hazardInspections()->pluck('photo_path')->all(),
        ]));
    }

    public function getPhotoUrlAttribute(): ?string
    {
        return $this->photo_path ? \App\Support\Files::url($this->photo_path) : null;
    }

    public function getActivityLogDescriptor(): string
    {
        return $this->building_name;
    }

    public function campus()
    {
        return $this->belongsTo(Campus::class, 'campus_id', 'campus_id');
    }

    public function hazardInspections()
    {
        return $this->hasMany(HazardInspection::class, 'building_id', 'building_id');
    }

    public function damageReports()
    {
        return $this->hasMany(DamageReport::class, 'building_id', 'building_id');
    }
}
