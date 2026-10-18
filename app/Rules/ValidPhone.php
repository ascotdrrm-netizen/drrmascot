<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class ValidPhone implements ValidationRule
{
    /**
     * A Philippine mobile number in digits only: 09XXXXXXXXX, or 639XXXXXXXXX
     * with an optional leading +. Emergency SMS alerts are sent to it.
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || ! preg_match('/^(09\d{9}|\+?639\d{9})$/', $value)) {
            $fail('Enter a mobile number in digits only, like 09171234567.');
        }
    }
}
