<?php

namespace App\Support;

use App\Models\Hotline\HotlineGroup;
use Throwable;

/**
 * The emergency hotlines, in the shape config/hotlines.php always had
 * (groups of entries with 'numbers' or 'radio'), read from the database the
 * DRRMO edits. Before the hotline tables exist (a fresh install mid-migrate),
 * the config file is the fallback, so a page never loses its numbers.
 */
class Hotlines
{
    protected static ?array $groups = null;

    public static function groups(): array
    {
        if (static::$groups !== null) {
            return static::$groups;
        }

        try {
            $groups = HotlineGroup::with('hotlines')->orderBy('sort_order')->get();
        } catch (Throwable) {
            return config('hotlines.groups', []);
        }

        if ($groups->isEmpty()) {
            return config('hotlines.groups', []);
        }

        return static::$groups = $groups->map(fn (HotlineGroup $group) => array_filter([
            'title' => $group->title,
            'subtitle' => $group->subtitle,
            'note' => $group->note,
            'entries' => $group->hotlines->map(fn ($h) => $h->radio !== null
                ? ['label' => $h->label, 'radio' => $h->radio]
                : ['label' => $h->label, 'numbers' => $h->numbers ?? []])->all(),
        ], fn ($value) => $value !== null))->all();
    }

    /** Forget the per-request copy (after an edit, and between tests). */
    public static function flush(): void
    {
        static::$groups = null;
    }
}
