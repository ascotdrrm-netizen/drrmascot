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
 *   Hazard inspections the ones they recorded (created_by), plus every one the
 *                      DRRMO Admin recorded on their campus
 *   Incidents          the ones they reported (created_by), plus every one the
 *                      DRRMO Admin reported for their campus — the DRRMO's
 *                      input is for the campus to see. Editing, deleting and
 *                      restoring stay with whoever recorded it, or the Admin:
 *                      see canManage(). Trash and the Archive list only what
 *                      the viewer may restore (applyToOwnIncidents()).
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

        $mineOrAdmins = fn ($id) => self::isMine($id, $viewer) || self::isAdminId($id);

        return match (true) {
            $record instanceof Building => self::sameCampus($record->campus_id, $viewer),
            $record instanceof Incident => self::sameCampus($record->campus_id, $viewer) && $mineOrAdmins($record->created_by),
            $record instanceof HazardInspection => self::sameCampus(self::inspectionCampusId($record), $viewer) && $mineOrAdmins($record->created_by),
            $record instanceof Drill => self::sameCampus($record->campus_id, $viewer),
            $record instanceof DisasterAdvisory => $record->campus_id === null || self::sameCampus($record->campus_id, $viewer),
            $record instanceof DamageReport, $record instanceof Alert => $record->incident !== null && self::allows($record->incident, $viewer),
            default => throw new InvalidArgumentException('No access rule for ' . $record::class),
        };
    }

    /**
     * May $viewer edit, delete or restore this incident or inspection (or a
     * damage report / alert on an incident)? The Admin: any. A coordinator:
     * only what they recorded themselves — the Admin's records are theirs to
     * see, not to change.
     */
    public static function canManage(Model $record, ?Personnel $viewer): bool
    {
        if ($record instanceof DamageReport || $record instanceof Alert) {
            return $record->incident !== null && self::canManage($record->incident, $viewer);
        }

        if (! $record instanceof Incident && ! $record instanceof HazardInspection) {
            throw new InvalidArgumentException('No manage rule for ' . $record::class);
        }

        return self::isAdmin($viewer)
            || ($viewer !== null && self::isMine($record->created_by, $viewer) && self::allows($record, $viewer));
    }

    /** Incidents (and anything filtered through them): campus + reported by the viewer or the Admin. */
    public static function applyToIncidents($query, ?Personnel $viewer, string $table = 'incidents')
    {
        if (self::isAdmin($viewer)) {
            return $query;
        }

        return $query->where("{$table}.campus_id", self::campusOf($viewer))
            ->where(fn ($q) => self::whereMineOrAdmins($q, "{$table}.created_by", $viewer));
    }

    /** Incidents the viewer may restore (Trash, Archive): campus + reported by the viewer. */
    public static function applyToOwnIncidents($query, ?Personnel $viewer, string $table = 'incidents')
    {
        if (self::isAdmin($viewer)) {
            return $query;
        }

        return $query->where("{$table}.campus_id", self::campusOf($viewer))
            ->where("{$table}.created_by", self::idOf($viewer));
    }

    /**
     * Hazard inspections: recorded by the viewer or the Admin. The campus half
     * lives on the building, so callers that join buildings pass its alias;
     * otherwise the campus is checked with a subquery.
     */
    public static function applyToInspections($query, ?Personnel $viewer, string $table = 'hazard_inspections', ?string $buildingsTable = null)
    {
        if (self::isAdmin($viewer)) {
            return $query;
        }

        $campusId = self::campusOf($viewer);
        $query->where(fn ($q) => self::whereMineOrAdmins($q, "{$table}.created_by", $viewer));

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

    protected static function isMine($createdBy, Personnel $viewer): bool
    {
        return $createdBy !== null && (int) $createdBy === (int) $viewer->personnel_id;
    }

    /** Was this recorded by a DRRMO Admin? (No recorded creator: no.) */
    protected static function isAdminId($createdBy): bool
    {
        return $createdBy !== null && Personnel::whereKey($createdBy)->where('role', 'DRRMO Admin')->exists();
    }

    /** $column is the viewer's id, or any DRRMO Admin's. */
    protected static function whereMineOrAdmins($query, string $column, ?Personnel $viewer)
    {
        return $query->where($column, self::idOf($viewer))
            ->orWhereIn($column, Personnel::where('role', 'DRRMO Admin')->select('personnel_id'));
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
