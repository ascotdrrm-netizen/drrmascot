<?php

namespace App\Models\Drill;

use Illuminate\Database\Eloquent\Model;

/**
 * ASCOT's After Activity Report for a drill: a summary of the drill as an
 * accomplishment, with its own attachments (section "after_activity"). One
 * per drill. Kept entirely separate from the documentation — two different
 * submissions, never merged.
 *
 * The field set is PROVISIONAL (agreed with the client): only the form, the
 * two PDFs and the tests depend on it.
 */
class DrillAfterActivityReport extends Model
{
    public const SECTION = 'after_activity';

    protected $primaryKey = 'report_id';

    protected $fillable = [
        'activity_title',
        'date_conducted',
        'start_time',
        'end_time',
        'objectives',
        'activities_conducted',
        'participating_offices',
        'participants_students',
        'participants_employees',
        'results',
        'problems_encountered',
        'recommendations',
    ];

    protected $casts = [
        'date_conducted' => \App\Casts\DateOnly::class,
        'start_time' => 'datetime:H:i',
        'end_time' => 'datetime:H:i',
        'participants_students' => 'integer',
        'participants_employees' => 'integer',
    ];

    public function drill()
    {
        return $this->belongsTo(Drill::class, 'drill_id', 'drill_id');
    }

    public function attachments()
    {
        return $this->hasMany(DrillAttachment::class, 'drill_id', 'drill_id')
            ->where('section', self::SECTION)
            ->orderBy('attachment_id');
    }

    /** Sum of the student and employee counts, or null when none was given. */
    public function totalParticipants(): ?int
    {
        $counts = array_filter([$this->participants_students, $this->participants_employees], fn ($n) => $n !== null);

        return $counts === [] ? null : array_sum($counts);
    }
}
