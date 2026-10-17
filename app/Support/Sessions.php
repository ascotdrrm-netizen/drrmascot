<?php

namespace App\Support;

use App\Models\Personnel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Ending an account's other sign-ins after its password changes. */
class Sessions
{
    /**
     * Signs the account out everywhere except $keepSessionId (null: everywhere):
     * database sessions are deleted and "keep me signed in" cookies stop
     * working (a new remember token).
     */
    public static function endOthers(Personnel $person, ?string $keepSessionId): void
    {
        if (config('session.driver') === 'database') {
            DB::connection(config('session.connection'))->table(config('session.table', 'sessions'))
                ->where('user_id', $person->getKey())
                ->when($keepSessionId !== null, fn ($q) => $q->where('id', '!=', $keepSessionId))
                ->delete();
        }
        $person->forceFill(['remember_token' => Str::random(60)])->saveQuietly();
    }
}
