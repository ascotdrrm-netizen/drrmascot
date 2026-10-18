<?php

namespace App\Support;

/**
 * A filename-safe segment that keeps title-case (e.g. "All Campuses" ->
 * "All-Campuses", "ASCOT Zabali Campus" -> "ASCOT-Zabali-Campus"), unlike
 * Str::slug() which would lowercase everything. Shared by every
 * report/record download filename in the app so they stay visually
 * consistent (ASCOT-DRRMS-Report_Q3-2026_All-Campuses.pdf,
 * ASCOT-Drill-Report_Fire_2026-09-06_ASCOT-Zabali-Campus.pdf, ...).
 */
class FilenameSlug
{
    public static function titleCase(string $value): string
    {
        return trim(preg_replace('/[^A-Za-z0-9]+/', '-', $value), '-');
    }
}
