<?php

namespace App\Http\Controllers\Concerns;

use App\Support\RecordAccess;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

trait HandlesCampusScoping
{
    protected function routePrefix(): string
    {
        return Auth::user()->role === 'DRRMO Admin' ? 'admin' : 'coordinator';
    }

    /**
     * Campus filter for aggregate views (dashboards): a Coordinator is always
     * locked to their own campus whatever was requested; a DRRMO Admin gets
     * the requested (already validated) campus, or null for All Campuses.
     */
    protected function scopedCampusId(?int $requestedCampusId): ?int
    {
        $user = Auth::user();

        return $user->role === 'Coordinator' ? $user->campus_id : $requestedCampusId;
    }

    /**
     * The campus a list is filtered to: for a DRRMO Admin, the requested
     * campus_id if it is one of $campuses; anything else (unknown value, or
     * any Coordinator, whose lists are already their own campus) is null.
     * Unknown values are ignored, not matched literally — see x-filter-bar.
     */
    protected function adminCampusFilter(\Illuminate\Http\Request $request, iterable $campuses): ?\App\Models\Campus
    {
        $id = $request->input('campus_id');
        if (Auth::user()->role !== 'DRRMO Admin' || ! ctype_digit((string) $id)) {
            return null;
        }

        return collect($campuses)->firstWhere('campus_id', (int) $id);
    }

    /**
     * The "for <who>" of a list's summary line: a Coordinator's own campus
     * (their lists never cover more), else the Admin's applied campus (or the
     * campus of an applied school), else "all campuses".
     */
    protected function campusCovered(?\App\Models\Campus $campus, int|string|null $schoolFilter = null): string
    {
        $user = Auth::user();

        return $user->role === 'Coordinator'
            ? ($user->campus?->campus_name ?? 'your campus')
            : \App\Support\FilterSummary::who($campus, $schoolFilter);
    }

    /**
     * @param  Model&object{campus_id: int}  $campusScoped  Any model with a campus_id column (Building, Incident, ...).
     */
    protected function authorizeCampusAccess(Model $campusScoped): void
    {
        $user = Auth::user();

        if ($user->role === 'Coordinator' && $campusScoped->campus_id !== $user->campus_id) {
            abort(403, "You don't have access to this page.");
        }
    }

    /**
     * Campus first, then per person (App\Support\RecordAccess): a Coordinator
     * may open only their own incidents and inspections, and the Admin's for
     * their campus. Buildings and drills stay campus-wide. Any URL to someone
     * else's record is a 403.
     */
    protected function authorizeRecordAccess(Model $record): void
    {
        if (! RecordAccess::allows($record, Auth::user())) {
            abort(403, "You don't have access to this page.");
        }
    }

    /**
     * Changing an incident or inspection (or its damage reports): only whoever
     * recorded it, or the Admin (RecordAccess::canManage()). A coordinator can
     * see the Admin's records but not change them.
     */
    protected function authorizeRecordManage(Model $record): void
    {
        $this->authorizeRecordAccess($record);

        if (! RecordAccess::canManage($record, Auth::user())) {
            abort(403, 'Only the person who recorded this, or the DRRMO, can change it.');
        }
    }
}
