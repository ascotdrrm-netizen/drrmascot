<?php

namespace App\Support;

use App\Models\Hotline\HotlineGroup;
use Illuminate\Support\Facades\DB;
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

    /**
     * Link each campus hotline group ("Serves Zabali Campus") to its campus,
     * so that campus's coordinator can edit it. A fresh install creates the
     * groups before any campus exists, so this runs again once campuses do
     * (DatabaseSeeder, and a migration for existing databases). Groups already
     * linked are left as they are.
     */
    public static function linkCampuses(): int
    {
        $campuses = DB::table('campuses')->get(['campus_id', 'campus_name']);
        $linked = 0;
        foreach (DB::table('hotline_groups')->whereNull('campus_id')->get(['hotline_group_id', 'subtitle']) as $group) {
            if (! preg_match('/Serves (\w+) Campus/', $group->subtitle ?? '', $m)) {
                continue;
            }
            $campusId = $campuses->first(fn ($c) => str_contains($c->campus_name, $m[1]))?->campus_id;
            if ($campusId !== null) {
                $linked += DB::table('hotline_groups')->where('hotline_group_id', $group->hotline_group_id)->update(['campus_id' => $campusId]);
            }
        }
        static::flush();

        return $linked;
    }

    /** Forget the per-request copy (after an edit, and between tests). */
    public static function flush(): void
    {
        static::$groups = null;
    }
}
