<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class ValidName implements ValidationRule
{
    /**
     * Only letters (Unicode-aware), spaces, periods, hyphens, and apostrophes are allowed.
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || ! preg_match("/^[\p{L}\s.'-]+$/u", $value)) {
            $fail('Name must contain only letters (no numbers or special characters).');
        }
    }
}
