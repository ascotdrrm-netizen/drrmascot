<?php

namespace App\Services;

use Carbon\CarbonImmutable;
use InvalidArgumentException;

/**
 * Resolves a period-type selection (weekly/monthly/quarterly/yearly/custom)
 * into a concrete start/end datetime range plus the immediately preceding
 * period of the same length, so the Consolidated DRRM Report can show a
 * "vs. previous period" comparison. All dates are treated as Asia/Manila
 * calendar dates — start is always 00:00:00, end is always 23:59:59.
 */
class ReportPeriodResolver
{
    public const TYPES = ['weekly', 'monthly', 'quarterly', 'yearly', 'custom'];

    /**
     * @param  array<string, mixed>  $params
     * @return array{
     *     start: CarbonImmutable,
     *     end: CarbonImmutable,
     *     label: string,
     *     previous_start: CarbonImmutable,
     *     previous_end: CarbonImmutable,
     *     previous_label: string,
     * }
     */
    public function resolve(string $periodType, array $params): array
    {
        return match ($periodType) {
            'weekly' => $this->resolveWeekly($params),
            'monthly' => $this->resolveMonthly($params),
            'quarterly' => $this->resolveQuarterly($params),
            'yearly' => $this->resolveYearly($params),
            'custom' => $this->resolveCustom($params),
            default => throw new InvalidArgumentException("Unknown period type [{$periodType}]."),
        };
    }

    /**
     * The default period when the report page is first loaded with no
     * filters applied: the current quarter.
     */
    public function defaultParams(): array
    {
        $now = CarbonImmutable::now('Asia/Manila');

        return [
            'period_type' => 'quarterly',
            'quarter' => (int) ceil($now->month / 3),
            'year' => $now->year,
        ];
    }

    protected function resolveWeekly(array $params): array
    {
        $anchor = CarbonImmutable::parse($params['week_date'], 'Asia/Manila')->startOfDay();

        $start = $anchor->startOfWeek(CarbonImmutable::MONDAY);
        $end = $anchor->endOfWeek(CarbonImmutable::SUNDAY);

        $previousStart = $start->subWeek();
        $previousEnd = $end->subWeek();

        return [
            'start' => $start,
            'end' => $end,
            'label' => 'Week of ' . $start->format('M j') . ' – ' . $end->format('M j, Y'),
            'previous_start' => $previousStart,
            'previous_end' => $previousEnd,
            'previous_label' => 'Week of ' . $previousStart->format('M j') . ' – ' . $previousEnd->format('M j, Y'),
        ];
    }

    protected function resolveMonthly(array $params): array
    {
        $month = (int) $params['month'];
        $year = (int) $params['year'];

        $start = CarbonImmutable::create($year, $month, 1, 0, 0, 0, 'Asia/Manila')->startOfMonth();
        $end = $start->endOfMonth();

        $previousStart = $start->subMonthNoOverflow()->startOfMonth();
        $previousEnd = $previousStart->endOfMonth();

        return [
            'start' => $start,
            'end' => $end,
            'label' => $start->format('F Y'),
            'previous_start' => $previousStart,
            'previous_end' => $previousEnd,
            'previous_label' => $previousStart->format('F Y'),
        ];
    }

    protected function resolveQuarterly(array $params): array
    {
        $quarter = (int) $params['quarter'];
        $year = (int) $params['year'];

        [$start, $end] = $this->quarterRange($year, $quarter);

        $previousQuarter = $quarter - 1;
        $previousYear = $year;

        if ($previousQuarter < 1) {
            $previousQuarter = 4;
            $previousYear--;
        }

        [$previousStart, $previousEnd] = $this->quarterRange($previousYear, $previousQuarter);

        return [
            'start' => $start,
            'end' => $end,
            'label' => "Q{$quarter} {$year} (" . $start->format('F j') . ' – ' . $end->format('F j, Y') . ')',
            'previous_start' => $previousStart,
            'previous_end' => $previousEnd,
            'previous_label' => "Q{$previousQuarter} {$previousYear} (" . $previousStart->format('F j') . ' – ' . $previousEnd->format('F j, Y') . ')',
        ];
    }

    protected function resolveYearly(array $params): array
    {
        $year = (int) $params['year'];

        $start = CarbonImmutable::create($year, 1, 1, 0, 0, 0, 'Asia/Manila')->startOfDay();
        $end = CarbonImmutable::create($year, 12, 31, 23, 59, 59, 'Asia/Manila');

        $previousStart = $start->subYear();
        $previousEnd = $end->subYear();

        return [
            'start' => $start,
            'end' => $end,
            'label' => (string) $year,
            'previous_start' => $previousStart,
            'previous_end' => $previousEnd,
            'previous_label' => (string) ($year - 1),
        ];
    }

    protected function resolveCustom(array $params): array
    {
        $start = CarbonImmutable::parse($params['start_date'], 'Asia/Manila')->startOfDay();
        $end = CarbonImmutable::parse($params['end_date'], 'Asia/Manila')->endOfDay();

        // "The immediately preceding period of the same length" — a custom
        // range spanning N days is compared against the N days right before it.
        // diffInDays() returns a float (end is 23:59:59.999999, so the raw
        // elapsed time is a hair under a whole number of days) — cast to int
        // first, or the fractional remainder throws subDays() off by a day.
        $lengthInDays = (int) $start->diffInDays($end) + 1;
        $previousEnd = $start->subDay()->endOfDay();
        $previousStart = $previousEnd->subDays($lengthInDays - 1)->startOfDay();

        return [
            'start' => $start,
            'end' => $end,
            'label' => $start->isSameDay($end)
                ? $start->format('F j, Y')
                : $start->format('F j') . ' – ' . $end->format('F j, Y'),
            'previous_start' => $previousStart,
            'previous_end' => $previousEnd,
            'previous_label' => $previousStart->isSameDay($previousEnd)
                ? $previousStart->format('F j, Y')
                : $previousStart->format('F j') . ' – ' . $previousEnd->format('F j, Y'),
        ];
    }

    /**
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    protected function quarterRange(int $year, int $quarter): array
    {
        $startMonth = (($quarter - 1) * 3) + 1;

        $start = CarbonImmutable::create($year, $startMonth, 1, 0, 0, 0, 'Asia/Manila')->startOfMonth();
        $end = $start->addMonthsNoOverflow(2)->endOfMonth();

        return [$start, $end];
    }
}
