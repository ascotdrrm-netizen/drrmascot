<?php

namespace App\Http\Controllers;

use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

/**
 * The Help page, for both roles (linked from the sidebar). Presentation only.
 */
class GuidanceController extends Controller
{
    public function help(): View
    {
        $user = Auth::user();

        return view('help.index', [
            'isAdmin' => $user->role === 'DRRMO Admin',
            'routePrefix' => $user->role === 'DRRMO Admin' ? 'admin' : 'coordinator',
        ]);
    }
}
