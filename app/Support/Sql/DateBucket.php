<?php

namespace App\Support\Sql;

use Illuminate\Support\Facades\DB;

/**
 * SQL that groups a date column into day, week (starting Monday) or month
 * buckets, written for whichever database is connected: PostgreSQL in use
 * (Supabase), SQLite in the tests. Every bucket comes back as text —
 * "2026-09-14" for a day or a week's Monday, "2026-09" for a month — so the
 * PHP that fills in empty buckets reads the same keys on every database.
 *
 * $column must be a trusted column name, never user input.
 */
class DateBucket
{
    public static function day(string $column): string
    {
        return match (self::driver()) {
            'pgsql' => "to_char({$column}, 'YYYY-MM-DD')",
            'mysql', 'mariadb' => "DATE_FORMAT({$column}, '%Y-%m-%d')",
            default => "date({$column})",
        };
    }

    /** The Monday of the column's week. */
    public static function week(string $column): string
    {
        return match (self::driver()) {
            // date_trunc('week') is ISO: weeks start on Monday.
            'pgsql' => "to_char(date_trunc('week', {$column}), 'YYYY-MM-DD')",
            'mysql', 'mariadb' => "DATE_FORMAT(DATE_SUB({$column}, INTERVAL WEEKDAY({$column}) DAY), '%Y-%m-%d')",
            // %w is 0 for Sunday … 6 for Saturday; (w + 6) % 7 days back is Monday.
            default => "date({$column}, '-' || ((cast(strftime('%w', {$column}) as integer) + 6) % 7) || ' days')",
        };
    }

    public static function month(string $column): string
    {
        return match (self::driver()) {
            'pgsql' => "to_char({$column}, 'YYYY-MM')",
            'mysql', 'mariadb' => "DATE_FORMAT({$column}, '%Y-%m')",
            default => "strftime('%Y-%m', {$column})",
        };
    }

    protected static function driver(): string
    {
        return DB::connection()->getDriverName();
    }
}
