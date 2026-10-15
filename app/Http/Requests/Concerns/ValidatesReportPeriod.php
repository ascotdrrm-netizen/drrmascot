<?php

namespace App\Http\Requests\Concerns;

use App\Models\School;
use App\Rules\SchoolOnCampus;
use App\Services\ReportPeriodResolver;
use Illuminate\Support\Facades\Auth;

/**
 * Shared period-filter validation (period_type + its per-type fields +
 * campus_id) for every report-download Form Request in the app —
 * ConsolidatedReportRequest (the Consolidated DRRM Report) and
 * ModuleReportRequest (each module's own period report). Keeping this in
 * one place means the two can never drift on what counts as a valid
 * period, and ReportPeriodResolver::TYPES stays the single source of
 * truth for allowed period types.
 */
trait ValidatesReportPeriod
{
    /**
     * First visit to a report page has no query string at all — default to
     * the current quarter rather than failing validation on a missing
     * period_type. Once period_type is present (the filter form was
     * actually submitted, even with only that one field), no defaults are
     * injected and every other field is validated for real.
     */
    protected function periodDefaultsForValidation(): void
    {
        if (! $this->has('period_type')) {
            $this->merge(app(ReportPeriodResolver::class)->defaultParams());
        }
    }

    public function periodRules(): array
    {
        return [
            'period_type' => ['required', 'in:' . implode(',', ReportPeriodResolver::TYPES)],
            'week_date' => ['required_if:period_type,weekly', 'nullable', 'date'],
            'month' => ['required_if:period_type,monthly', 'nullable', 'integer', 'between:1,12'],
            'year' => ['required_if:period_type,monthly,quarterly,yearly', 'nullable', 'integer', 'digits:4'],
            'quarter' => ['required_if:period_type,quarterly', 'nullable', 'integer', 'between:1,4'],
            'start_date' => ['required_if:period_type,custom', 'nullable', 'date'],
            'end_date' => ['required_if:period_type,custom', 'nullable', 'date', 'after_or_equal:start_date'],
            // "All Campuses" is submitted as an empty value; a Coordinator's
            // selection is ignored server-side regardless of what's posted
            // here (see each controller's resolveCampusId()).
            'campus_id' => ['nullable', 'integer', 'exists:campuses,campus_id'],
            // School VIEW filter ("none" = not assigned). It must sit on the
            // caller's campus scope — a Coordinator's own campus whatever they
            // send, or the Admin's chosen campus — or it's rejected: never
            // ignored, never able to reach another campus's records.
            'school_id' => ['nullable', new SchoolOnCampus($this->schoolScopeCampusId(), allowNotAssigned: true)],
        ];
    }

    protected function schoolScopeCampusId(): ?int
    {
        $user = Auth::user();

        if ($user?->role === 'Coordinator') {
            return (int) $user->campus_id;
        }

        return ctype_digit((string) $this->input('campus_id')) ? (int) $this->input('campus_id') : null;
    }

    /** null = all schools, "none" = not assigned, or a school_id. */
    public function schoolFilter(): int|string|null
    {
        $value = $this->validated('school_id');

        return match (true) {
            blank($value) => null,
            $value === School::NOT_ASSIGNED => School::NOT_ASSIGNED,
            default => (int) $value,
        };
    }

    /**
     * @return array<string, mixed>
     */
    public function periodParams(): array
    {
        return match ($this->validated('period_type')) {
            'weekly' => ['week_date' => $this->validated('week_date')],
            'monthly' => ['month' => $this->validated('month'), 'year' => $this->validated('year')],
            'quarterly' => ['quarter' => $this->validated('quarter'), 'year' => $this->validated('year')],
            'yearly' => ['year' => $this->validated('year')],
            'custom' => ['start_date' => $this->validated('start_date'), 'end_date' => $this->validated('end_date')],
        };
    }
}
