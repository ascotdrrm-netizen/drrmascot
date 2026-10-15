<?php

namespace App\Rules;

use App\Models\School;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * A school_id must be a real school on the given campus — the server-side
 * twin of the campus-filtered school dropdown, since the browser can be
 * bypassed. With $allowNotAssigned (filters), the value "none" is also
 * accepted and means "records with no school".
 *
 * $campusId null means "any campus" (e.g. an Admin filtering All Campuses).
 */
class SchoolOnCampus implements ValidationRule
{
    public function __construct(
        protected ?int $campusId,
        protected bool $allowNotAssigned = false,
    ) {
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if ($this->allowNotAssigned && $value === School::NOT_ASSIGNED) {
            return;
        }

        if (! is_scalar($value) || ! ctype_digit((string) $value)) {
            $fail('The selected school is invalid.');

            return;
        }

        $school = School::find((int) $value);

        if (! $school) {
            $fail('The selected school is invalid.');
        } elseif ($this->campusId !== null && (int) $school->campus_id !== $this->campusId) {
            $fail('The selected school is not part of the selected campus.');
        }
    }
}
