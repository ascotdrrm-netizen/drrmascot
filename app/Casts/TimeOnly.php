<?php

namespace App\Casts;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Contracts\Database\Eloquent\SerializesCastableAttributes;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A time of day that belongs to a date column on the same row (a drill's
 * scheduled_time with its scheduled_date): read as a Carbon ON THAT DATE,
 * always written as "HH:MM:SS", serialized as "HH:MM" (as before).
 *
 * Laravel's 'datetime:H:i' cast read "09:00" as 9:00 TODAY, so anything that
 * compared the time — "has this drill been held yet?" — followed the current
 * date instead of the drill's own. The date comes from $dateColumn; with no
 * date the time is read on today, as before.
 */
class TimeOnly implements CastsAttributes, SerializesCastableAttributes
{
    public function __construct(protected string $dateColumn = 'scheduled_date')
    {
    }

    public function get(Model $model, string $key, mixed $value, array $attributes): ?Carbon
    {
        if ($value === null || $value === '') {
            return null;
        }
        $time = Carbon::parse($value)->format('H:i:s');
        $date = $attributes[$this->dateColumn] ?? null;

        return $date ? Carbon::parse(Carbon::parse($date)->toDateString() . ' ' . $time) : Carbon::parse($time);
    }

    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        return $value === null || $value === '' ? null : Carbon::parse($value)->format('H:i:s');
    }

    public function serialize(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        // Laravel hands over the value already serialized as a UTC ISO date; read it back in app time.
        return $value === null || $value === '' ? null : Carbon::parse($value)->setTimezone(config('app.timezone'))->format('H:i');
    }
}
