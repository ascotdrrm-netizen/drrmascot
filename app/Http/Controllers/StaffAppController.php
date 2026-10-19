<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Start page of the installed staff app (public/manifest-staff.webmanifest):
 * one fixed URL for every role. A signed-in Admin or Coordinator lands on
 * their dashboard, or on a home-screen shortcut's page (?to=sos|inbox|drills);
 * a Reporter goes to the phone app; anyone else signs in first.
 */
class StaffAppController extends Controller
{
    /** Home-screen shortcut => route name after the role prefix. */
    public const SHORTCUTS = ['sos' => 'command.sos', 'inbox' => 'command.inbox', 'drills' => 'drills.index'];

    public function __invoke(Request $request): RedirectResponse
    {
        $user = $request->user();
        if (! $user) {
            return redirect()->route('login');
        }

        $prefix = match ($user->role) {
            'DRRMO Admin' => 'admin',
            'Coordinator' => 'coordinator',
            default => null,
        };
        if ($prefix === null) {
            return redirect('/app');
        }

        $shortcut = self::SHORTCUTS[(string) $request->query('to')] ?? 'dashboard';

        return redirect()->route("{$prefix}.{$shortcut}");
    }
}
