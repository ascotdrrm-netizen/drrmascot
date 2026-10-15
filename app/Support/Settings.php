<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * System-wide settings the DRRMO Admin edits on the Settings page, read once
 * per request. Before the settings table exists every key reads as its
 * default, so nothing that prints a PDF can fail on a fresh install.
 */
class Settings
{
    /** key => default */
    public const DEFAULTS = [
        // Thesis panel (Engineering): "Prepared by" and "Noted by" must be different people.
        'report.noted_by_name' => null,
        'report.noted_by_position' => 'DRRMO Administrator',
    ];

    protected static ?array $values = null;

    public static function get(string $key): ?string
    {
        if (static::$values === null) {
            try {
                static::$values = DB::table('settings')->pluck('value', 'key')->all();
            } catch (Throwable) {
                static::$values = [];
            }
        }

        $value = static::$values[$key] ?? null;

        return filled($value) ? $value : (static::DEFAULTS[$key] ?? null);
    }

    public static function set(string $key, ?string $value): void
    {
        DB::table('settings')->updateOrInsert(['key' => $key], ['value' => $value, 'updated_at' => now(), 'created_at' => now()]);
        static::flush();
    }

    public static function flush(): void
    {
        static::$values = null;
    }
}
