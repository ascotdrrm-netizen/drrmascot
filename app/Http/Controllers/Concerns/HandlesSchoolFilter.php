<?php

namespace App\Http\Controllers\Concerns;

use App\Models\School;
use App\Rules\SchoolOnCampus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * The school VIEW filter on list pages. Campus scoping is applied first by
 * the caller (HandlesCampusScoping, unchanged); this only narrows within it.
 *
 * Values: null = all schools, "none" = not assigned, or a school_id. A school
 * that isn't on $campusScope (the coordinator's own campus, or the Admin's
 * selected campus) is rejected with a validation error — never ignored, and
 * never able to widen what the caller can see.
 */
trait HandlesSchoolFilter
{
    protected function validatedSchoolFilter(Request $request, ?int $campusScope): int|string|null
    {
        $request->validate([
            'school_id' => ['nullable', new SchoolOnCampus($campusScope, allowNotAssigned: true)],
        ]);

        $value = $request->input('school_id');

        return match (true) {
            blank($value) => null,
            $value === School::NOT_ASSIGNED => School::NOT_ASSIGNED,
            default => (int) $value,
        };
    }

    /**
     * The campus a school filter must belong to: a Coordinator's own campus
     * (always — whatever campus_id they send), or the Admin's selected
     * campus filter (null = All Campuses, any school allowed).
     */
    protected function schoolFilterCampusScope(Request $request): ?int
    {
        $user = Auth::user();

        if ($user->role === 'Coordinator') {
            return (int) $user->campus_id;
        }

        return ctype_digit((string) $request->input('campus_id')) ? (int) $request->input('campus_id') : null;
    }

    /** validatedSchoolFilter() for the caller's own campus scope. */
    protected function schoolFilterFor(Request $request): int|string|null
    {
        return $this->validatedSchoolFilter($request, $this->schoolFilterCampusScope($request));
    }

    protected function applySchoolFilter(Builder $query, int|string|null $filter, string $column = 'school_id'): Builder
    {
        return School::applyFilter($query, $filter, $column);
    }
}
