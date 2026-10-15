<?php

namespace Tests\Unit;

use App\Services\ReportPeriodResolver;
use Tests\TestCase;

class ReportPeriodResolverTest extends TestCase
{
    protected ReportPeriodResolver $resolver;

    protected function setUp(): void
    {
        parent::setUp();

        $this->resolver = new ReportPeriodResolver();
    }

    public function test_quarterly_resolves_to_the_correct_calendar_quarter_and_previous_quarter(): void
    {
        $period = $this->resolver->resolve('quarterly', ['quarter' => 3, 'year' => 2026]);

        $this->assertSame('2026-07-01 00:00:00', $period['start']->format('Y-m-d H:i:s'));
        $this->assertSame('2026-09-30 23:59:59', $period['end']->format('Y-m-d H:i:s'));
        $this->assertSame('2026-04-01 00:00:00', $period['previous_start']->format('Y-m-d H:i:s'));
        $this->assertSame('2026-06-30 23:59:59', $period['previous_end']->format('Y-m-d H:i:s'));
        $this->assertSame('Q3 2026 (July 1 – September 30, 2026)', $period['label']);
    }

    public function test_quarterly_q1_rolls_the_previous_quarter_back_into_the_prior_year(): void
    {
        $period = $this->resolver->resolve('quarterly', ['quarter' => 1, 'year' => 2026]);

        $this->assertSame('2026-01-01 00:00:00', $period['start']->format('Y-m-d H:i:s'));
        $this->assertSame('2026-03-31 23:59:59', $period['end']->format('Y-m-d H:i:s'));
        $this->assertSame('2025-10-01 00:00:00', $period['previous_start']->format('Y-m-d H:i:s'));
        $this->assertSame('2025-12-31 23:59:59', $period['previous_end']->format('Y-m-d H:i:s'));
    }

    public function test_monthly_resolves_full_month_and_rolls_previous_month_across_year_boundary(): void
    {
        $period = $this->resolver->resolve('monthly', ['month' => 1, 'year' => 2026]);

        $this->assertSame('2026-01-01 00:00:00', $period['start']->format('Y-m-d H:i:s'));
        $this->assertSame('2026-01-31 23:59:59', $period['end']->format('Y-m-d H:i:s'));
        $this->assertSame('2025-12-01 00:00:00', $period['previous_start']->format('Y-m-d H:i:s'));
        $this->assertSame('2025-12-31 23:59:59', $period['previous_end']->format('Y-m-d H:i:s'));
    }

    public function test_yearly_resolves_january_first_to_december_thirty_first(): void
    {
        $period = $this->resolver->resolve('yearly', ['year' => 2026]);

        $this->assertSame('2026-01-01 00:00:00', $period['start']->format('Y-m-d H:i:s'));
        $this->assertSame('2026-12-31 23:59:59', $period['end']->format('Y-m-d H:i:s'));
        $this->assertSame('2025-01-01 00:00:00', $period['previous_start']->format('Y-m-d H:i:s'));
        $this->assertSame('2025-12-31 23:59:59', $period['previous_end']->format('Y-m-d H:i:s'));
    }

    public function test_weekly_resolves_monday_to_sunday_regardless_of_which_day_is_picked(): void
    {
        // 2026-09-24 is a Thursday.
        $period = $this->resolver->resolve('weekly', ['week_date' => '2026-09-24']);

        $this->assertSame('2026-09-21', $period['start']->toDateString());
        $this->assertSame(1, $period['start']->dayOfWeekIso, 'Start must be a Monday.');
        $this->assertSame('2026-09-27', $period['end']->toDateString());
        $this->assertSame(7, $period['end']->dayOfWeekIso, 'End must be a Sunday.');
        $this->assertSame('00:00:00', $period['start']->format('H:i:s'));
        $this->assertSame('23:59:59', $period['end']->format('H:i:s'));

        $this->assertSame('2026-09-14', $period['previous_start']->toDateString());
        $this->assertSame('2026-09-20', $period['previous_end']->toDateString());
    }

    public function test_custom_range_previous_period_is_the_same_length_immediately_before(): void
    {
        // A 10-day custom range (Sep 1 through Sep 10 inclusive).
        $period = $this->resolver->resolve('custom', ['start_date' => '2026-09-01', 'end_date' => '2026-09-10']);

        $this->assertSame('2026-09-01 00:00:00', $period['start']->format('Y-m-d H:i:s'));
        $this->assertSame('2026-09-10 23:59:59', $period['end']->format('Y-m-d H:i:s'));

        // Previous 10 days: Aug 22 through Aug 31 inclusive.
        $this->assertSame('2026-08-22 00:00:00', $period['previous_start']->format('Y-m-d H:i:s'));
        $this->assertSame('2026-08-31 23:59:59', $period['previous_end']->format('Y-m-d H:i:s'));
    }

    public function test_default_params_is_the_current_quarter(): void
    {
        $defaults = $this->resolver->defaultParams();

        $this->assertSame('quarterly', $defaults['period_type']);
        $this->assertSame((int) ceil(now('Asia/Manila')->month / 3), $defaults['quarter']);
        $this->assertSame((int) now('Asia/Manila')->year, $defaults['year']);
    }
}
