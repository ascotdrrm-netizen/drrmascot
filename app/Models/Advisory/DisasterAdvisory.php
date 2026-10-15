<?php

namespace App\Models\Advisory;

use App\Models\Campus;
use App\Models\Incident\Incident;
use App\Traits\LogsActivity;
use App\Traits\RecordsCreator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Disaster Advisory: a warning typed in from a PAGASA / PHIVOLCS / DRRMO
 * bulletin, before or during an event. campus_id NULL means every campus.
 * measurements holds the hazard-specific figures, keyed as in
 * config/hazard_measurements.php.
 *
 * Status is set by people (Active, Passed, Cancelled). "Live" — shown as the
 * dashboard warning — is Active AND not past valid_until; an Active advisory
 * past its valid-until time drops off the dashboards on its own and the list
 * says so, until someone marks it Passed.
 *
 * Users never see the stored word "Active": it is shown as "In effect" (an
 * incident's "Active" means something else), or "Expired" once valid_until
 * has passed. Every display goes through STATUS_LABELS / status_label.
 *
 * Campus-wide, not per person: every coordinator of the campus sees it.
 * Editing and deleting: the Admin, or the coordinator who issued it
 * (App\Support\RecordAccess::canManageAdvisory()).
 */
class DisasterAdvisory extends Model
{
    use SoftDeletes, LogsActivity, RecordsCreator;

    public const STATUSES = ['Active', 'Passed', 'Cancelled'];

    /** What each stored status is called on screen (the stored values never change). */
    public const STATUS_LABELS = ['Active' => 'In effect', 'Passed' => 'Passed', 'Cancelled' => 'Cancelled'];

    /** Shown instead of "In effect" once valid_until has passed and nobody has marked it Passed. */
    public const EXPIRED_LABEL = 'Expired';

    protected $primaryKey = 'advisory_id';

    protected $fillable = [
        'hazard_type',
        'hazard_type_other',
        'campus_id',
        'headline',
        'measurements',
        'source',
        'source_other',
        'bulletin_reference',
        'issued_at',
        'expected_at',
        'valid_until',
        'status',
        'instructions',
    ];

    protected $casts = [
        'measurements' => 'array',
        'issued_at' => 'datetime',
        'expected_at' => 'datetime',
        'valid_until' => 'datetime',
    ];

    public function getActivityLogDescriptor(): string
    {
        return $this->headline;
    }

    public function campus()
    {
        return $this->belongsTo(Campus::class, 'campus_id', 'campus_id');
    }

    public function incidents()
    {
        return $this->hasMany(Incident::class, 'advisory_id', 'advisory_id');
    }

    /** Active and not yet past valid_until: what the dashboard warning shows. */
    public function scopeLive(Builder $query): Builder
    {
        return $query->where($query->qualifyColumn('status'), 'Active')->where($query->qualifyColumn('valid_until'), '>', now());
    }

    public function isLive(): bool
    {
        return $this->status === 'Active' && $this->valid_until->isFuture();
    }

    /** "Record what happened" is offered on Active and Passed advisories, never Cancelled ones. */
    public function canRecordIncident(): bool
    {
        return in_array($this->status, ['Active', 'Passed'], true);
    }

    public function getHazardLabelAttribute(): string
    {
        return $this->hazard_type === 'Other' ? ($this->hazard_type_other ?: 'Other') : $this->hazard_type;
    }

    public function getSourceLabelAttribute(): string
    {
        return $this->source === 'Other' ? ($this->source_other ?: 'Other') : $this->source;
    }

    public function getCampusLabelAttribute(): string
    {
        if ($this->campus_id === null) {
            return 'All campuses';
        }

        // Lists that join the campus name (the dashboard) skip the relation.
        if (isset($this->attributes['campus_name'])) {
            return $this->attributes['campus_name'];
        }

        return $this->campus->campus_name ?? '—';
    }

    /** "In effect", "Expired", "Passed" or "Cancelled" — what users see, never the stored "Active". */
    public function getStatusLabelAttribute(): string
    {
        if ($this->status === 'Active' && ! $this->isLive()) {
            return self::EXPIRED_LABEL;
        }

        return self::STATUS_LABELS[$this->status] ?? $this->status;
    }

    /** The figure the warning is built around, worded by the config's 'headline' pattern ("Wind Signal No. 2"). */
    public function getHeadlineMeasurementAttribute(): ?string
    {
        foreach ($this->measurementFields() as $key => $field) {
            if (isset($field['headline']) && filled($this->measurements[$key] ?? null)) {
                return str_replace(':value', (string) $this->measurements[$key], $field['headline']);
            }
        }

        return null;
    }

    /**
     * Every entered measurement as label => shown value ("Maximum sustained
     * winds" => "120 km/h"), in config order. Empty optional fields are left out.
     *
     * @return array<string, string>
     */
    public function measurementLines(): array
    {
        return \App\Support\HazardMeasurements::lines($this->measurementFields(), $this->measurements);
    }

    /** @return array<string, array> the config fields for this advisory's hazard type */
    public function measurementFields(): array
    {
        return \App\Support\HazardMeasurements::forAdvisory($this->hazard_type);
    }

    /**
     * What the incident form is pre-filled with. Incidents keep their own
     * types (config/incident_types.php), which include every advisory hazard
     * spelled identically (IncidentTypesTest holds that), so a hazard goes in
     * as the type of the same name; an advisory's Other goes in as "Other"
     * with the name in the specify box.
     */
    public function incidentHazardPrefill(): array
    {
        return in_array($this->hazard_type, \App\Models\Incident\Incident::types(), true)
            ? ['hazard_type' => $this->hazard_type, 'hazard_type_other' => '']
            : ['hazard_type' => 'Other', 'hazard_type_other' => $this->hazard_label];
    }
}
