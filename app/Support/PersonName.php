<?php

namespace App\Support;

/**
 * The one place a person's name parts become personnel.full_name.
 *
 *   combine('Justine', 't', 'Virrey')   -> "Justine T. Virrey"
 *   combine(' Cindy ', null, 'Pagunaling') -> "Cindy Pagunaling"
 */
class PersonName
{
    /** personnel.full_name is VARCHAR(150). */
    public const MAX_LENGTH = 150;

    public static function combine(?string $first, ?string $middleInitial, ?string $last): string
    {
        $parts = array_filter([
            self::clean($first),
            self::normalizeInitial($middleInitial),
            self::clean($last),
        ], fn ($part) => $part !== '');

        return implode(' ', $parts);
    }

    /** "t", "T" or "T." -> "T."; blank -> "". */
    public static function normalizeInitial(?string $initial): string
    {
        $letter = rtrim(self::clean($initial), '.');

        return $letter === '' ? '' : mb_strtoupper(mb_substr($letter, 0, 1)) . '.';
    }

    /** Trim and collapse runs of whitespace to a single space. */
    public static function clean(?string $value): string
    {
        return trim(preg_replace('/\s+/u', ' ', (string) $value));
    }
}
