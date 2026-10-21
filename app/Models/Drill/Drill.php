<?php

namespace App\Models\Drill;

use App\Models\Campus;
use App\Traits\LogsActivity;
use App\Traits\RecordsCreator;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Drill Tracker: a drill is a schedule (type, date, time, venue, campus,
 * partner agency, objectives) scheduled by the DRRMO Admin or a campus
 * coordinator. After it is held it gets two SEPARATE submissions, at most one
 * of each, never merged:
 *   documentation()        what happened (DrillDocumentation)
 *   afterActivityReport()  ASCOT's After Activity Report (DrillAfterActivityReport)
 * There is no status: whether a drill is documented is simply whether its
 * documentation exists.
 *
 * created_by (RecordsCreator) decides who may edit or delete the drill —
 * see App\Support\RecordAccess::canManageDrill().
 */
class Drill extends Model
{
    use HasFactory, SoftDeletes, LogsActivity, RecordsCreator;

    public const DRILL_TYPES = ['Earthquake', 'Fire', 'Evacuation'];

    /**
     * Calendar colour per drill type (any custom type is "Other"). Kept off
     * the status palette — red and amber mean incident states, green means
     * actions and "resolved" — so a pill never reads as a status.
     */
    public const TYPE_COLOURS = [
        'Earthquake' => ['pill' => 'border-violet-500 bg-violet-50 text-violet-900', 'dot' => 'bg-violet-500'],
        'Fire' => ['pill' => 'border-fuchsia-500 bg-fuchsia-50 text-fuchsia-900', 'dot' => 'bg-fuchsia-500'],
        'Evacuation' => ['pill' => 'border-sky-500 bg-sky-50 text-sky-900', 'dot' => 'bg-sky-500'],
        'Other' => ['pill' => 'border-slate-500 bg-slate-100 text-slate-800', 'dot' => 'bg-slate-500'],
    ];

    protected $primaryKey = 'drill_id';
    public $timestamps = false;

    protected $fillable = [
        'campus_id',
        'drill_type',
        'scheduled_date',
        'scheduled_time',
        'venue',
        'description',
        'partner_agency',
    ];

    protected $casts = [
        'scheduled_date' => \App\Casts\DateOnly::class,
        // On the drill's own date, not today's (App\Casts\TimeOnly).
        'scheduled_time' => \App\Casts\TimeOnly::class . ':scheduled_date',
    ];

    /** Attachment files to remove once a permanent delete has gone through, by model instance. */
    protected static array $filesToRemove = [];

    /**
     * Attachments are deleted on PERMANENT delete only, in Building's shape:
     * the database cascades the documentation, the After Activity Report and
     * the attachment rows without model events, so the file paths are
     * collected just before the delete, and the files (then the drill's
     * emptied drill-attachments/{id}/ folder) go once the delete has committed.
     */
    protected static function booted(): void
    {
        static::forceDeleting(function (Drill $drill) {
            static::$filesToRemove[spl_object_id($drill)] = $drill->storedFiles();
        });
        static::forceDeleted(function (Drill $drill) {
            $paths = static::$filesToRemove[spl_object_id($drill)] ?? [];
            unset(static::$filesToRemove[spl_object_id($drill)]);
            \App\Support\TrashPurge::deleteFilesAfterCommit(\App\Support\Files::diskName(), $paths, $drill->attachmentFolder());
        });
    }

    /** Its attachments' files (the records disk, App\Support\Files): what a permanent delete removes. */
    public function storedFiles(): array
    {
        return array_values(array_filter($this->attachments()->pluck('path')->all()));
    }

    /** Where this drill's attachments are uploaded (DrillSubmissionController). */
    public function attachmentFolder(): string
    {
        return "drill-attachments/{$this->drill_id}";
    }

    public function getActivityLogDescriptor(): string
    {
        return $this->drill_type;
    }

    public function campus()
    {
        return $this->belongsTo(Campus::class, 'campus_id', 'campus_id');
    }

    public function documentation()
    {
        return $this->hasOne(DrillDocumentation::class, 'drill_id', 'drill_id');
    }

    public function afterActivityReport()
    {
        return $this->hasOne(DrillAfterActivityReport::class, 'drill_id', 'drill_id');
    }

    public function attachments()
    {
        return $this->hasMany(DrillAttachment::class, 'drill_id', 'drill_id')->orderBy('attachment_id');
    }

    /** When the drill starts: its date at its time (start of the day when no time is set), Asia/Manila. */
    public function scheduledAt(): \Carbon\CarbonImmutable
    {
        $at = $this->scheduled_time ?? $this->scheduled_date;

        return \Carbon\CarbonImmutable::parse($at->format('Y-m-d H:i:s'), 'Asia/Manila');
    }

    /** True once the scheduled start has passed — not merely once its date has come. */
    public function hasStarted(): bool
    {
        return $this->scheduledAt()->lte(now('Asia/Manila'));
    }

    /** "Earthquake", "Fire", "Evacuation", or "Other" for a custom type. */
    public function typeGroup(): string
    {
        return in_array($this->drill_type, self::DRILL_TYPES, true) ? $this->drill_type : 'Other';
    }

    /** @return array{pill: string, dot: string} */
    public function typeColours(): array
    {
        return self::TYPE_COLOURS[$this->typeGroup()];
    }
}
