<?php

namespace App\Casts;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A calendar date with no time: read as a Carbon at the start of that day
 * (like Laravel's 'date' cast), always written as "YYYY-MM-DD".
 *
 * Laravel's own 'date' cast writes "YYYY-MM-DD 00:00:00". PostgreSQL's date
 * column drops the time, but SQLite (the test database) keeps the text as
 * given, so a filter such as whereBetween('scheduled_date', ['2026-09-15',
 * '2026-09-15']) would miss the row there. Writing the bare date makes every
 * database store, compare and sort these columns the same way.
 */
class DateOnly implements CastsAttributes
{
    public function get(Model $model, string $key, mixed $value, array $attributes): ?Carbon
    {
        return $value === null || $value === '' ? null : Carbon::parse($value)->startOfDay();
    }

    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        return $value === null || $value === '' ? null : Carbon::parse($value)->toDateString();
    }
}
