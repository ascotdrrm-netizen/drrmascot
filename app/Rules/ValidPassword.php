<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class ValidPassword implements ValidationRule
{
    /**
     * At least 8 characters, one uppercase letter, one number, and one symbol.
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $valid = is_string($value)
            && strlen($value) >= 8
            && preg_match('/[A-Z]/', $value)
            && preg_match('/[0-9]/', $value)
            && preg_match('/[^A-Za-z0-9]/', $value);

        if (! $valid) {
            $fail('Password must be at least 8 characters and include an uppercase letter, a number, and a symbol.');
        }
    }
}
