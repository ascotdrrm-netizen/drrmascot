<?php

namespace App\Models\Incident;

use App\Models\Alert;
use App\Models\Campus;
use App\Traits\HasLocation;
use App\Traits\LogsActivity;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Storage;
use App\Traits\RecordsCreator;

class Incident extends Model
{
    use HasFactory, SoftDeletes, LogsActivity, HasLocation, RecordsCreator;

    /** The stored statuses (the incidents table's enum). */
    public const STATUSES = ['Active', 'Under Response', 'Resolved'];

    /** Stored as the typed text, outside both groups: "neither list covers this". */
    public const OTHER_TYPE = 'Other';

    /** The group whose incidents record hazard measurements (App\Support\HazardMeasurements). */
    public const DISASTER_GROUP = 'Disaster-related';

    /** config/incident_types.php: group label => its types. "Other" is in neither group. */
    public static function typeGroups(): array
    {
        return config('incident_types');
    }

    /** Every configured type, both groups, in order ("Other" not included). */
    public static function types(): array
    {
        return array_merge(...array_values(static::typeGroups()));
    }

    /** How each group reads in the Incident type filter and its summary line. */
    protected const GROUP_WORDING = [
        'Disaster-related' => ['option' => 'All disaster-related', 'noun' => 'disaster-related incidents'],
        'Campus emergency' => ['option' => 'All campus emergencies', 'noun' => 'campus emergencies'],
    ];

    /**
     * The Incident type filter's whole-group values: "group:<slug>" => the
     * group's label, its "All …" option text and how the summary names it.
     * A group filter takes exactly that group's types — never Other.
     */
    public static function typeGroupFilters(): array
    {
        $filters = [];
        foreach (array_keys(static::typeGroups()) as $group) {
            $wording = static::GROUP_WORDING[$group] ?? ['option' => 'All ' . lcfirst($group), 'noun' => lcfirst($group) . ' incidents'];
            $filters['group:' . \Illuminate\Support\Str::slug($group)] = ['group' => $group] + $wording;
        }

        return $filters;
    }

    /** The group a stored value belongs to, or null — "Other" text belongs to neither. */
    public static function groupOf(?string $type): ?string
    {
        foreach (static::typeGroups() as $group => $types) {
            if (in_array($type, $types, true)) {
                return $group;
            }
        }

        return null;
    }

    protected $primaryKey = 'incident_id';
    public $timestamps = false;

    protected $fillable = [
        'client_key',
        'campus_id',
        'school_id',
        'advisory_id',
        'hazard_type',
        'measurements',
        'date_occurred',
        'status',
        'description',
        'people_affected',
        'people_injured',
        'people_missing',
        'people_dead',
        'photo_path',
        'photo_caption',
        'latitude',
        'longitude',
    ];

    /**
     * People counts (thesis panel, Arts & Sciences), column => label. Each is
     * optional: null means not known, which is not the same as 0.
     */
    public const PEOPLE = [
        'people_affected' => 'Affected',
        'people_injured' => 'Injured',
        'people_missing' => 'Missing',
        'people_dead' => 'Dead',
    ];

    public function hasPeopleCounts(): bool
    {
        return collect(array_keys(self::PEOPLE))->contains(fn ($column) => $this->{$column} !== null);
    }

    protected $casts = [
        'date_occurred' => \App\Casts\DateOnly::class,
        // Observed measurements, keyed as in config/hazard_measurements.php.
        'measurements' => 'array',
        'people_affected' => 'integer',
        'people_injured' => 'integer',
        'people_missing' => 'integer',
        'people_dead' => 'integer',
    ];

    /** This incident's measurement fields, as observed (none unless Disaster-related). */
    public function measurementFields(): array
    {
        return \App\Support\HazardMeasurements::forIncident($this->hazard_type);
    }

    /** label => value (and unit), filled fields only. */
    public function measurementLines(): array
    {
        return \App\Support\HazardMeasurements::lines($this->measurementFields(), $this->measurements);
    }

    public function getStatusBadgeClassAttribute(): string
    {
        return match ($this->status) {
            'Active' => 'badge-active',
            'Under Response' => 'badge-underresponse',
            'Resolved' => 'badge-resolved',
            default => '',
        };
    }

    /**
     * The photo is deleted on PERMANENT delete only: moving an incident to
     * Trash keeps it, so a restore brings the incident back whole. It goes
     * once the delete has committed: a rolled-back delete keeps its photo.
     * (Damage reports and alerts carry no files.)
     */
    protected static function booted(): void
    {
        static::forceDeleted(function (Incident $incident) {
            \App\Support\TrashPurge::deleteFilesAfterCommit(\App\Support\Files::diskName(), $incident->storedFiles());
        });
    }

    /** Its photo (the records disk, App\Support\Files): what a permanent delete removes. */
    public function storedFiles(): array
    {
        return array_values(array_filter([$this->photo_path]));
    }

    public function getPhotoUrlAttribute(): ?string
    {
        return $this->photo_path ? \App\Support\Files::url($this->photo_path) : null;
    }

    public function getActivityLogDescriptor(): string
    {
        return $this->hazard_type;
    }

    public function campus()
    {
        return $this->belongsTo(Campus::class, 'campus_id', 'campus_id');
    }

    /** Attribution only (see App\Models\School) — never used for access control. */
    /** The Disaster Advisory this incident was recorded from ("Record what happened"), if any. */
    public function advisory()
    {
        return $this->belongsTo(\App\Models\Advisory\DisasterAdvisory::class, 'advisory_id', 'advisory_id');
    }

    public function school()
    {
        return $this->belongsTo(\App\Models\School::class, 'school_id', 'school_id');
    }

    public function damageReports()
    {
        return $this->hasMany(DamageReport::class, 'incident_id', 'incident_id');
    }

    public function alerts()
    {
        return $this->hasMany(Alert::class, 'incident_id', 'incident_id');
    }
}
