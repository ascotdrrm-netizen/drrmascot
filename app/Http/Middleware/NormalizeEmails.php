<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Lower-cases and trims every e-mail address a form sends, before validation
 * and login see it. PostgreSQL compares text case-sensitively, so without
 * this "Juan@ASCOT.edu.ph" and "juan@ascot.edu.ph" would be two accounts, and
 * signing in with different capitals would fail. Personnel also lower-cases
 * on save (Personnel::email()), so stored addresses are always lower-case.
 */
class NormalizeEmails
{
    /** Request fields that hold an e-mail address. */
    public const FIELDS = ['email', 'recovery_email', 'identifier'];

    public function handle(Request $request, Closure $next): Response
    {
        $changes = [];
        foreach (self::FIELDS as $field) {
            $value = $request->input($field);
            if (is_string($value) && str_contains($value, '@')) {
                $changes[$field] = mb_strtolower(trim($value));
            }
        }
        if ($changes) {
            $request->merge($changes);
        }

        return $next($request);
    }
}
