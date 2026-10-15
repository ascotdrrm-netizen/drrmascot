<?php

namespace App\Models\Hotline;

use App\Traits\LogsActivity;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One office or agency on the hotline list: its phone numbers, or a radio frequency. */
class Hotline extends Model
{
    use LogsActivity;

    protected $primaryKey = 'hotline_id';

    protected $fillable = ['hotline_group_id', 'label', 'numbers', 'radio', 'sort_order'];

    protected $casts = ['numbers' => 'array'];

    public function group(): BelongsTo
    {
        return $this->belongsTo(HotlineGroup::class, 'hotline_group_id');
    }

    public function getActivityLogDescriptor(): string
    {
        return $this->label;
    }
}
