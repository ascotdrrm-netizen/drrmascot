<?php

namespace App\Models\Drill;

use Illuminate\Database\Eloquent\Model;

/**
 * What happened at a drill, written after it was held: when it was actually
 * held, a narrative, an approximate number of participants (a count, never
 * names) and notes, plus its own attachments (section "documentation").
 * One per drill. Kept entirely separate from the After Activity Report.
 */
class DrillDocumentation extends Model
{
    public const SECTION = 'documentation';

    protected $primaryKey = 'documentation_id';

    protected $fillable = ['date_conducted', 'time_conducted', 'narrative', 'participant_count', 'notes'];

    protected $casts = [
        'date_conducted' => \App\Casts\DateOnly::class,
        'time_conducted' => 'datetime:H:i',
        'participant_count' => 'integer',
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
}
