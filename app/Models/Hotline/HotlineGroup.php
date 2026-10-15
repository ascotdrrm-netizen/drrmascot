<?php

namespace App\Models\Hotline;

use App\Models\Campus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A heading on the hotline list (a province, a municipality, ASCOT itself). */
class HotlineGroup extends Model
{
    protected $primaryKey = 'hotline_group_id';

    protected $fillable = ['title', 'subtitle', 'note', 'campus_id', 'sort_order'];

    public function hotlines(): HasMany
    {
        return $this->hasMany(Hotline::class, 'hotline_group_id')->orderBy('sort_order');
    }

    public function campus(): BelongsTo
    {
        return $this->belongsTo(Campus::class, 'campus_id', 'campus_id');
    }

    /** The Admin edits every group; a coordinator, the group that serves their campus. */
    public function editableBy($user): bool
    {
        return $user->role === 'DRRMO Admin'
            || ($this->campus_id !== null && (int) $this->campus_id === (int) $user->campus_id);
    }
}
