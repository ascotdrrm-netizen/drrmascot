<?php

namespace App\Support;

use App\Models\Advisory\DisasterAdvisory;
use App\Models\Alert;
use App\Models\Building\Building;
use App\Models\Building\HazardInspection;
use App\Models\Drill\Drill;
use App\Models\Incident\DamageReport;
use App\Models\Incident\Incident;
use App\Models\Personnel;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

/**
 * Who may see which record — the single home of every visibility rule.
 *
 * DRRMO Admin: everything, every campus. Coordinator: always their own campus
 * first (never widened), then narrowed per person:
 *
 *   Buildings/Areas    the whole campus (a building belongs to the campus, and
 *                      every coordinator needs the full list to inspect it)
 *   Hazard inspections only the ones they recorded (created_by)
 *   Incidents          only the ones they reported (created_by)
 *   Damage reports,    follow their incident
 *   alerts
 *   Drills             the whole campus — an EXCEPTION to per-person
 *                      visibility, like buildings. A drill is an institution
 *                      event, not one person's record: the DRRMO schedules most
 *                      of them for a campus, and any coordinator of that campus
 *                      may document it or file its After Activity Report (the
 *                      submitter is recorded). Hiding drills per person would
 *                      hide the Admin's drills from the coordinators who hold
 *                      them. Editing and deleting the drill itself is narrower:
 *                      see canManageDrill().
 *   Disaster advisories the whole campus, plus every all-campus advisory
 *                      (campus_id NULL) — a warning is for everyone there.
 *                      Editing and deleting: see canManageAdvisory().
 *
 * Records with no creator (created before attribution existed and not
 * traceable from the activity log) are therefore Admin-only.
 *
 * The apply*() methods add the same rules to a query — Eloquent or the plain
 * query builder, with an optional table alias — so lists, dashboards and
 * reports filter exactly like allows() decides for one record.
 */
final class RecordAccess
{
    public static function isAdmin(?Personnel $viewer): bool
    {
        return $viewer !== null && $viewer->role === 'DRRMO Admin';
    }

    /** Can $viewer open this record? (Campus first, then per person.) */
    public static function allows(Model $record, ?Personnel $viewer): bool
    {
        if ($viewer === null) {
            return false;
        }

        if (self::isAdmin($viewer)) {
            return true;
        }

        $mine = fn ($id) => $id !== null && (int) $id === (int) $viewer->personnel_id;

        return match (true) {
            $record instanceof Building => self::sameCampus($record->campus_id, $viewer),
            $record instanceof Incident => self::sameCampus($record->campus_id, $viewer) && $mine($record->created_by),
            $record instanceof HazardInspection => self::sameCampus(self::inspectionCampusId($record), $viewer) && $mine($record->created_by),
            $record instanceof Drill => self::sameCampus($record->campus_id, $viewer),
            $record instanceof DisasterAdvisory => $record->campus_id === null || self::sameCampus($record->campus_id, $viewer),
            $record instanceof DamageReport, $record instanceof Alert => $record->incident !== null && self::allows($record->incident, $viewer),
            default => throw new InvalidArgumentException('No access rule for ' . $record::class),
        };
    }

    /** Incidents (and anything filtered through them): campus + reported by the viewer. */
    public static function applyToIncidents($query, ?Personnel $viewer, string $table = 'incidents')
    {
        if (self::isAdmin($viewer)) {
            return $query;
        }

        return $query->where("{$table}.campus_id", self::campusOf($viewer))
            ->where("{$table}.created_by", self::idOf($viewer));
    }

    /**
     * Hazard inspections: recorded by the viewer. The campus half lives on the
     * building, so callers that join buildings pass its alias; otherwise the
     * campus is checked with a subquery.
     */
    public static function applyToInspections($query, ?Personnel $viewer, string $table = 'hazard_inspections', ?string $buildingsTable = null)
    {
        if (self::isAdmin($viewer)) {
            return $query;
        }

        $campusId = self::campusOf($viewer);
        $query->where("{$table}.created_by", self::idOf($viewer));

        return $buildingsTable !== null
            ? $query->where("{$buildingsTable}.campus_id", $campusId)
            : $query->whereIn("{$table}.building_id", Building::withTrashed()->where('campus_id', $campusId)->select('building_id'));
    }

    /** Drills: the whole campus (deliberately not per person — see the class comment). */
    public static function applyToDrills($query, ?Personnel $viewer, string $table = 'drills')
    {
        if (self::isAdmin($viewer)) {
            return $query;
        }

        return $query->where("{$table}.campus_id", self::campusOf($viewer));
    }

    /**
     * May $viewer edit, delete or restore this drill (its schedule)? The Admin:
     * any drill. A coordinator: only drills they created themselves, on their
     * own campus — a drill the Admin scheduled, or one with no recorded
     * creator, stays the Admin's. (Documentation and the After Activity Report
     * are open to every coordinator who can see the drill.)
     */
    /**
     * May $viewer move this building/area to Trash? The same rule as drills:
     * the Admin, or the coordinator who created it, on their own campus. A
     * building is shared by the campus, and trashing it hides every
     * colleague's inspections of it, so any other coordinator may edit it but
     * not trash it. One with no recorded creator stays the Admin's.
     */
    public static function canTrashBuilding(\App\Models\Building\Building $building, ?Personnel $viewer): bool
    {
        if ($viewer === null) {
            return false;
        }

        if (self::isAdmin($viewer)) {
            return true;
        }

        return self::sameCampus($building->campus_id, $viewer)
            && $building->created_by !== null
            && (int) $building->created_by === (int) $viewer->personnel_id;
    }

    public static function canManageDrill(Drill $drill, ?Personnel $viewer): bool
    {
        if ($viewer === null) {
            return false;
        }

        if (self::isAdmin($viewer)) {
            return true;
        }

        return self::sameCampus($drill->campus_id, $viewer)
            && $drill->created_by !== null
            && (int) $drill->created_by === (int) $viewer->personnel_id;
    }

    /** Disaster advisories: the viewer's campus plus the all-campus ones (campus_id NULL). */
    public static function applyToAdvisories($query, ?Personnel $viewer, string $table = 'disaster_advisories')
    {
        if (self::isAdmin($viewer)) {
            return $query;
        }

        return $query->where(fn ($q) => $q->whereNull("{$table}.campus_id")->orWhere("{$table}.campus_id", self::campusOf($viewer)));
    }

    /**
     * May $viewer edit, delete or restore this advisory? The Admin: any. A
     * coordinator: only advisories they issued, for their own campus. An
     * all-campus advisory is always the Admin's (a coordinator can't issue one).
     */
    public static function canManageAdvisory(DisasterAdvisory $advisory, ?Personnel $viewer): bool
    {
        if ($viewer === null) {
            return false;
        }

        if (self::isAdmin($viewer)) {
            return true;
        }

        return $advisory->campus_id !== null
            && self::sameCampus($advisory->campus_id, $viewer)
            && $advisory->created_by !== null
            && (int) $advisory->created_by === (int) $viewer->personnel_id;
    }

    /** Damage reports / alerts: only those on incidents the viewer can see. */
    public static function applyThroughIncident($query, ?Personnel $viewer, string $incidentIdColumn)
    {
        if (self::isAdmin($viewer)) {
            return $query;
        }

        return $query->whereIn($incidentIdColumn, self::applyToIncidents(Incident::withTrashed(), $viewer)->select('incidents.incident_id'));
    }

    /** Buildings: campus only (deliberately not per person). */
    public static function applyToBuildings($query, ?Personnel $viewer, string $table = 'buildings')
    {
        if (self::isAdmin($viewer)) {
            return $query;
        }

        return $query->where("{$table}.campus_id", self::campusOf($viewer));
    }

    protected static function sameCampus($campusId, Personnel $viewer): bool
    {
        return $campusId !== null && (int) $campusId === (int) $viewer->campus_id;
    }

    protected static function inspectionCampusId(HazardInspection $inspection)
    {
        return Building::withTrashed()->whereKey($inspection->building_id)->value('campus_id');
    }

    /** A coordinator without a campus matches nothing (never "all campuses"). */
    protected static function campusOf(?Personnel $viewer): int
    {
        return (int) ($viewer?->campus_id ?? 0);
    }

    /** No viewer (e.g. a console call) matches nothing. */
    protected static function idOf(?Personnel $viewer): int
    {
        return (int) ($viewer?->personnel_id ?? 0);
    }
}
