<?php

namespace App\Models;

use App\Traits\LogsActivity;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * An academic unit, belonging to exactly one campus. Attribution only:
 * a record's school says which school recorded it; it never affects who can
 * see or change it (campus scoping does that, unchanged).
 */
class School extends Model
{
    use LogsActivity;

    protected $primaryKey = 'school_id';

    public function getActivityLogDescriptor(): string
    {
        return (string) $this->school_name;
    }

    protected $fillable = ['campus_id', 'school_name'];

    /** Filter value meaning "records with no school" (school_id is null). */
    public const NOT_ASSIGNED = 'none';

    /** Official list (campus name keyword => schools), used by SchoolSeeder. */
    public const CATALOG = [
        'Zabali' => [
            'School of Engineering',
            'School of Arts and Sciences',
            'School of Information Technology',
            'School of Forestry and Environmental Sciences',
            'School of Education',
            'School of Industrial Technology',
        ],
        'Bazal' => ['School of Agricultural and Science'],
        'Casiguran' => ['School of Fisheries and Ocean Sciences'],
    ];

    public function campus()
    {
        return $this->belongsTo(Campus::class, 'campus_id', 'campus_id');
    }

    public function personnel()
    {
        return $this->hasMany(Personnel::class, 'school_id', 'school_id');
    }

    /** All schools, ordered by campus then name — for the campus-filtered dropdowns. */
    public static function forDropdowns(): Collection
    {
        return static::query()
            ->join('campuses', 'campuses.campus_id', '=', 'schools.campus_id')
            ->orderBy('campuses.campus_name')
            ->orderBy('schools.school_name')
            ->get(['schools.school_id', 'schools.campus_id', 'schools.school_name', 'campuses.campus_name']);
    }

    /**
     * Narrow a query by a school filter value: null = no change, "none" =
     * school_id is null, otherwise that school. Works on Eloquent and query builders.
     */
    public static function applyFilter($query, int|string|null $filter, string $column = 'school_id')
    {
        return match (true) {
            $filter === null => $query,
            $filter === self::NOT_ASSIGNED => $query->whereNull($column),
            default => $query->where($column, (int) $filter),
        };
    }

    /** Human label for a filter value, e.g. for report headers. */
    public static function filterLabel(int|string|null $filter): string
    {
        return match (true) {
            $filter === null => 'All schools',
            $filter === self::NOT_ASSIGNED => 'Not assigned',
            default => static::whereKey((int) $filter)->value('school_name') ?? 'Unknown school',
        };
    }

    /**
     * The school a new record is attributed to: the acting person's own
     * school, but only if it sits on the record's campus (an Admin with no
     * school, or recording for another campus, gives null).
     */
    public static function attributedTo(?Personnel $person, int $campusId): ?int
    {
        if (! $person || $person->school_id === null) {
            return null;
        }

        return (int) $person->school?->campus_id === $campusId ? (int) $person->school_id : null;
    }
}
