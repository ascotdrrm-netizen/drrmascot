<?php

namespace App\Support;

use App\Models\Campus;
use App\Models\School;

/**
 * Pieces of the filter bar's "Showing <what> for <who>." line, so every list
 * words campus and school the same way. Callers pass the filters the query
 * ACTUALLY USED — never the raw request — so the line can't name a filter
 * that isn't applied (see x-filter-bar).
 */
class FilterSummary
{
    /**
     * $period: a list's period clause (App\Support\ListPeriod::phrase()), or null where it has none.
     * $compare: the period compared against — the Dashboard (and the report) only; lists don't compare.
     */
    public static function make(string $what, string $who, ?string $period = null, ?string $compare = null): array
    {
        return array_filter(['what' => $what, 'who' => $who, 'period' => $period, 'compare' => $compare], fn ($part) => $part !== null);
    }

    /** '' (all schools), ' with no school set', or ' <preposition> School of …'. */
    public static function school(int|string|null $filter, string $preposition = 'from'): string
    {
        return match (true) {
            $filter === null => '',
            $filter === School::NOT_ASSIGNED => ' with no school set',
            default => " {$preposition} " . School::filterLabel($filter),
        };
    }

    /**
     * The campus the list covers: the applied campus; else the campus of an
     * applied school (a school picked under "All campuses" still belongs to
     * one); else $default.
     */
    public static function who(?Campus $campus, int|string|null $schoolFilter = null, string $default = 'all campuses'): string
    {
        if ($campus) {
            return $campus->campus_name;
        }

        if (is_int($schoolFilter)) {
            $name = School::whereKey($schoolFilter)->join('campuses', 'campuses.campus_id', '=', 'schools.campus_id')->value('campuses.campus_name');
            if ($name) {
                return $name;
            }
        }

        return $default;
    }
}
