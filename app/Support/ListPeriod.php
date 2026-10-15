<?php

namespace App\Support;

use App\Services\ReportPeriodResolver;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;

/**
 * The period filter on a LIST page (Incidents, Advisories): the Consolidated
 * Report's picker and parameter names (period_type, week_date, month,
 * quarter, year, start_date, end_date) and ReportPeriodResolver, with one
 * difference — the default is ALL DATES. A list never starts narrowed:
 * someone who doesn't notice the control sees everything they may see.
 *
 * - period_type empty or absent: all dates (null).
 * - period_type not a known type: ignored, all dates — like every unknown
 *   filter value (see x-filter-bar).
 * - a known type: its fields are validated with the report's rules (a
 *   missing quarter, an end date before the start …), and errors go back to
 *   the page as with any form.
 */
class ListPeriod
{
    /**
     * Null for all dates, else ['type', 'params', 'start', 'end', 'label'].
     */
    public static function fromRequest(Request $request): ?array
    {
        $type = $request->input('period_type');
        if (! is_string($type) || ! in_array($type, ReportPeriodResolver::TYPES, true)) {
            return null;
        }

        $request->validate(array_intersect_key([
            'week_date' => ['required', 'date'],
            'month' => ['required', 'integer', 'between:1,12'],
            'quarter' => ['required', 'integer', 'between:1,4'],
            'year' => ['required', 'integer', 'digits:4'],
            'start_date' => ['required', 'date'],
            'end_date' => ['required', 'date', 'after_or_equal:start_date'],
        ], array_flip(self::FIELDS[$type])));

        $params = $request->only(self::FIELDS[$type]);
        $period = app(ReportPeriodResolver::class)->resolve($type, $params);

        return ['type' => $type, 'params' => $params, 'start' => $period['start'], 'end' => $period['end'], 'label' => $period['label']];
    }

    /** Each period type's fields, as the report names them. */
    public const FIELDS = [
        'weekly' => ['week_date'],
        'monthly' => ['month', 'year'],
        'quarterly' => ['quarter', 'year'],
        'yearly' => ['year'],
        'custom' => ['start_date', 'end_date'],
    ];

    /** The summary's period clause: "all dates", or "dated Q3 2026 (July 1 – September 30, 2026)". */
    public static function phrase(?array $period): string
    {
        if ($period === null) {
            return 'all dates';
        }

        // "Week of Oct 5 – Oct 11, 2026" reads as "dated the week of …".
        return 'dated ' . ($period['type'] === 'weekly' ? 'the w' . substr($period['label'], 1) : $period['label']);
    }

    /**
     * What the period control shows: the applied period's values, or — for
     * all dates — sensible starting values for each type (today's week,
     * month, quarter and year; this month so far for a custom range).
     */
    public static function controlValues(?array $period): array
    {
        $now = CarbonImmutable::now('Asia/Manila');

        return array_merge([
            'period_type' => '',
            'week_date' => $now->toDateString(),
            'month' => $now->month,
            'quarter' => (int) ceil($now->month / 3),
            'year' => $now->year,
            'start_date' => $now->startOfMonth()->toDateString(),
            'end_date' => $now->toDateString(),
        ], $period ? ['period_type' => $period['type']] + $period['params'] : []);
    }
}
