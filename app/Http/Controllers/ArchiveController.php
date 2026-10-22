<?php

namespace App\Http\Controllers;

use App\Models\Advisory\DisasterAdvisory;
use App\Models\Building\Building;
use App\Models\Campus;
use App\Models\Drill\Drill;
use App\Models\Incident\Incident;
use App\Models\Reporter\SosEvent;
use App\Support\RecordAccess;
use App\Support\TrashPurge;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

/**
 * One Archive for everything deleted (thesis panel, IT experts): buildings,
 * incidents, drills and advisories together, filtered by type, campus and
 * when they were deleted. Each module keeps its own Trash page and its own
 * restore action; this page lists what each of those would show this person
 * (the same RecordAccess rules) and restores through the same routes.
 *
 * The Admin may also delete a record permanently from here, through the same
 * TrashPurge::purge() the retention job uses (children, files and the log
 * entry included). SOS alerts are not offered: they are kept until restored.
 */
class ArchiveController extends Controller
{
    public const TYPES = [
        'buildings' => 'Buildings/Areas',
        'incidents' => 'Incidents',
        'drills' => 'Drills',
        'advisories' => 'Advisories',
        'sos' => 'SOS alerts',
    ];

    /** The types the Admin may delete permanently, and their models (TrashPurge::MODELS). */
    public const PERMANENT = [
        'buildings' => Building::class,
        'incidents' => Incident::class,
        'drills' => Drill::class,
        'advisories' => DisasterAdvisory::class,
    ];

    public const PERIODS = ['7' => 'Last 7 days', '30' => 'Last 30 days', '90' => 'Last 90 days'];

    public function index(Request $request): View
    {
        $user = Auth::user();
        $isAdmin = RecordAccess::isAdmin($user);
        $prefix = $isAdmin ? 'admin' : 'coordinator';

        $type = array_key_exists($request->query('type'), self::TYPES) ? $request->query('type') : null;
        $period = array_key_exists($request->query('period'), self::PERIODS) ? $request->query('period') : null;
        $campuses = $isAdmin ? Campus::orderBy('campus_name')->get() : null;
        $campusId = $isAdmin && $campuses->contains('campus_id', (int) $request->query('campus')) ? (int) $request->query('campus') : null;

        $since = $period ? now()->subDays((int) $period) : null;
        $scope = function ($query, string $campusColumn = 'campus_id') use ($since, $campusId) {
            $query->when($since, fn ($q) => $q->where('deleted_at', '>=', $since))
                ->when($campusId, fn ($q) => $q->where($campusColumn, $campusId));

            return $query;
        };

        $rows = new Collection;
        // Only the Admin gets a permanent delete link, and only for the PERMANENT types.
        $deleteUrl = fn (string $type, $record) => $isAdmin ? route('admin.archive.destroy', [$type, $record->getKey()]) : null;

        if (! $type || $type === 'buildings') {
            $q = $scope(Building::onlyTrashed()->with('campus'));
            RecordAccess::applyToBuildings($q, $user);
            $rows = $rows->concat($q->get()->map(fn ($b) => $this->row($b, 'Building/Area', 'apartment', $b->building_name, route("{$prefix}.buildings.restore", $b->building_id), $deleteUrl('buildings', $b))));
        }
        if (! $type || $type === 'incidents') {
            $q = $scope(Incident::onlyTrashed()->with('campus'));
            RecordAccess::applyToOwnIncidents($q, $user);
            $rows = $rows->concat($q->get()->map(fn ($i) => $this->row($i, 'Incident', 'crisis_alert', $i->hazard_type . ' · ' . $i->date_occurred->format('M j, Y'), route("{$prefix}.incidents.restore", $i->incident_id), $deleteUrl('incidents', $i))));
        }
        if (! $type || $type === 'drills') {
            $q = $scope(Drill::onlyTrashed()->with('campus'));
            RecordAccess::applyToDrills($q, $user);
            $rows = $rows->concat($q->get()->map(fn ($d) => $this->row($d, 'Drill', 'fact_check', $d->drill_type . ' · ' . $d->scheduled_date->format('M j, Y'), route("{$prefix}.drills.restore", $d->drill_id), $deleteUrl('drills', $d))));
        }
        if (! $type || $type === 'advisories') {
            $q = $scope(DisasterAdvisory::onlyTrashed()->with('campus'));
            RecordAccess::applyToAdvisories($q, $user);
            $rows = $rows->concat($q->get()->map(fn ($a) => $this->row($a, 'Advisory', 'campaign', $a->headline, route("{$prefix}.advisories.restore", $a->advisory_id), $deleteUrl('advisories', $a))));
        }

        if (! $type || $type === 'sos') {
            // SOS alerts are campus records, not anyone's own: a coordinator sees their campus's.
            $q = $scope(SosEvent::onlyTrashed()->with(['campus', 'reporter']))
                ->when(! $isAdmin, fn ($q) => $q->where('campus_id', $user->campus_id));
            $rows = $rows->concat($q->get()->map(fn ($s) => $this->row($s, 'SOS alert', 'sos',
                'SOS #' . $s->sos_id . ' · ' . ($s->reporter?->full_name ?: 'Former reporter') . ' · ' . $s->created_at->format('M j, Y'),
                route("{$prefix}.sos.restore", $s->sos_id))));
        }

        return view('archive.index', [
            'rows' => $rows->sortByDesc('deleted_at')->values(),
            'types' => self::TYPES,
            'periods' => self::PERIODS,
            'type' => $type,
            'period' => $period,
            'campuses' => $campuses,
            'campusId' => $campusId,
            'retentionRunning' => TrashPurge::isRunning(),
            'routePrefix' => $prefix,
        ]);
    }

    /**
     * Admin only (the route is). Refuses what TrashPurge refuses: a building
     * that damage reports still point at stays, and the reason is shown.
     */
    public function destroy(string $type, int $id): RedirectResponse
    {
        $record = self::PERMANENT[$type]::onlyTrashed()->findOrFail($id);
        $result = TrashPurge::purge($record, Auth::user());

        $back = back(fallback: route('admin.archive.index'));

        return match ($result['outcome']) {
            'deleted' => $back->with('status', 'Permanently deleted ' . $result['description'] . '.'),
            'kept' => $back->withErrors(['archive' => 'Not deleted. ' . $result['reason']]),
            default => $back->withErrors(['archive' => 'Not deleted: it is no longer in the archive.']),
        };
    }

    protected function row($record, string $kind, string $icon, string $title, string $restoreUrl, ?string $deleteUrl = null): array
    {
        return [
            'record' => $record,
            'kind' => $kind,
            'icon' => $icon,
            'title' => $title,
            'campus' => $record->campus->campus_name ?? 'All campuses',
            'deleted_at' => $record->deleted_at,
            'restore_url' => $restoreUrl,
            'delete_url' => $deleteUrl,
        ];
    }
}
