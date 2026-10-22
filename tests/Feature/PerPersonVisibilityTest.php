<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Alert;
use App\Models\Building\Building;
use App\Models\Building\HazardInspection;
use App\Models\Campus;
use App\Models\Drill\Drill;
use App\Models\Incident\DamageReport;
use App\Models\Incident\Incident;
use App\Models\Personnel;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\ReadsReportHtml;
use Tests\TestCase;

/**
 * Per-person visibility for Coordinators (App\Support\RecordAccess):
 * buildings and drills stay campus-wide (drills are the deliberate exception:
 * see RecordAccess); inspections and incidents (with their damage reports and
 * alerts) are per person; only a drill's creator or the Admin may change it;
 * campus scoping still sits on top; the DRRMO Admin sees everything.
 *
 * Every record here is created through the app, as the person named, so
 * created_by is set exactly as in production.
 */
class PerPersonVisibilityTest extends TestCase
{
    use ReadsReportHtml, RefreshDatabase;

    protected Campus $zabali;

    protected Campus $bazal;

    protected Personnel $admin;

    protected Personnel $coordA;

    protected Personnel $coordB;

    protected Personnel $newbie;

    protected Personnel $bazalCoord;

    protected Building $sharedBuilding;

    protected Incident $incidentA;

    protected Incident $incidentB;

    protected HazardInspection $inspectionA;

    protected HazardInspection $inspectionB;

    protected Drill $drillA;

    protected Drill $scheduledDrill;

    protected Alert $alertA;

    protected DamageReport $damageA;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::parse('2026-09-25 10:00:00', 'Asia/Manila'));
        Storage::fake('public');

        $this->zabali = Campus::create(['campus_name' => 'ASCOT Zabali Campus']);
        $this->bazal = Campus::create(['campus_name' => 'ASCOT Bazal Campus']);

        $make = fn (Campus $campus, string $name, string $role, string $email) => Personnel::create([
            'campus_id' => $campus->campus_id, 'full_name' => $name, 'role' => $role,
            'email' => $email, 'password_hash' => Hash::make('password'),
        ]);
        $this->admin = $make($this->zabali, 'Per Admin', 'DRRMO Admin', 'pp-admin@ascot-test.local');
        $this->coordA = $make($this->zabali, 'Coordinator Alpha', 'Coordinator', 'pp-a@ascot-test.local');
        $this->coordB = $make($this->zabali, 'Coordinator Bravo', 'Coordinator', 'pp-b@ascot-test.local');
        $this->newbie = $make($this->zabali, 'Brand New Coordinator', 'Coordinator', 'pp-new@ascot-test.local');
        $this->bazalCoord = $make($this->bazal, 'Bazal Coordinator', 'Coordinator', 'pp-bazal@ascot-test.local');

        // A building recorded by Alpha — it belongs to the campus.
        $this->actingAs($this->coordA)->post(route('coordinator.buildings.store'), [
            'building_name' => 'Shared Gym', 'hazard_type' => 'Fire', 'risk_level' => 'High Risk',
        ])->assertRedirect();
        $this->sharedBuilding = Building::where('building_name', 'Shared Gym')->firstOrFail();

        $this->incidentA = $this->reportIncident($this->coordA, 'Alpha incident');
        $this->incidentB = $this->reportIncident($this->coordB, 'Bravo incident');

        // Both inspect the same (shared) building.
        $this->inspectionA = $this->inspect($this->coordA, 'Alpha finding', 'High Risk', '2026-09-21');
        $this->inspectionB = $this->inspect($this->coordB, 'Bravo finding', 'Moderate Risk', '2026-09-20');

        $this->actingAs($this->coordA)->post(route('coordinator.incidents.damage-reports.store', $this->incidentA), [
            'building_id' => $this->sharedBuilding->building_id, 'damage_level' => 'Minor', 'casualty_count' => 0,
        ])->assertRedirect();
        $this->damageA = DamageReport::where('incident_id', $this->incidentA->incident_id)->firstOrFail();
        // Alerts are sent by the Admin; they follow their incident.
        $this->alertA = Alert::create(['incident_id' => $this->incidentA->incident_id, 'message' => 'Alpha alert', 'sent_at' => '2026-09-20 10:00:00', 'recipient_count' => 3, 'delivery_status' => 'Sent']);

        // Two Admin-scheduled drills this month: Alpha documents the one already held.
        $this->drillA = $this->schedule('Alpha Venue', '2026-09-24');
        $this->actingAs($this->coordA)->put(route('coordinator.drills.documentation.update', $this->drillA), [
            'date_conducted' => '2026-09-24', 'time_conducted' => '09:30', 'narrative' => 'Alpha documented it.', 'participant_count' => 40,
        ])->assertRedirect();
        $this->scheduledDrill = $this->schedule('Upcoming Venue', '2026-09-30');
    }

    // ================================ helpers =================================

    protected function reportIncident(Personnel $as, string $description, ?Campus $campus = null): Incident
    {
        $prefix = $as->role === 'DRRMO Admin' ? 'admin' : 'coordinator';
        $this->actingAs($as)->post(route("{$prefix}.incidents.store"), array_filter([
            'hazard_type' => 'Fire', 'date_occurred' => '2026-09-20', 'status' => 'Active', 'description' => $description,
            'campus_id' => $as->role === 'DRRMO Admin' ? ($campus ?? $this->zabali)->campus_id : null,
        ]))->assertRedirect();

        return Incident::where('description', $description)->firstOrFail();
    }

    protected function inspect(Personnel $as, string $findings, string $risk, string $date): HazardInspection
    {
        // A 5×5 matrix rating that lands on $risk.
        [$likelihood, $impact] = ['Minimal Risk' => [1, 2], 'Moderate Risk' => [3, 2], 'High Risk' => [4, 4], 'Critical Risk' => [5, 5]][$risk];
        $this->actingAs($as)->post(route('coordinator.buildings.inspections.store', $this->sharedBuilding), [
            'inspection_date' => $date, 'likelihood' => $likelihood, 'impact' => $impact, 'findings' => $findings,
        ])->assertRedirect();

        return HazardInspection::where('findings', $findings)->firstOrFail();
    }

    protected function schedule(string $venue, string $date): Drill
    {
        $this->actingAs($this->admin)->post(route('admin.drills.store'), [
            'campus_ids' => [$this->zabali->campus_id], 'drill_type' => 'Earthquake',
            'scheduled_date' => $date, 'scheduled_time' => '09:00', 'venue' => $venue,
        ])->assertRedirect();

        return Drill::where('venue', $venue)->firstOrFail();
    }

    protected function quarter(): array
    {
        return ['period_type' => 'quarterly', 'quarter' => 3, 'year' => 2026];
    }

    // ============================ creator stamping ============================

    public function test_created_by_is_stamped_from_the_signed_in_user_and_cannot_be_set_from_input(): void
    {
        $this->assertSame($this->coordA->personnel_id, $this->incidentA->created_by);
        $this->assertSame('Coordinator Alpha', $this->incidentA->created_by_name);
        $this->assertSame($this->coordB->personnel_id, $this->inspectionB->created_by);
        $this->assertSame($this->coordA->personnel_id, $this->sharedBuilding->created_by);
        $this->assertSame($this->coordA->personnel_id, $this->damageA->created_by);

        // Tampered input naming someone else is ignored.
        $this->actingAs($this->coordB)->post(route('coordinator.incidents.store'), [
            'hazard_type' => 'Flood', 'date_occurred' => '2026-09-21', 'status' => 'Active', 'description' => 'Forged owner',
            'created_by' => $this->coordA->personnel_id, 'created_by_name' => 'Coordinator Alpha',
        ])->assertRedirect();
        $forged = Incident::where('description', 'Forged owner')->firstOrFail();
        $this->assertSame($this->coordB->personnel_id, $forged->created_by);
        $this->assertSame('Coordinator Bravo', $forged->created_by_name);
    }

    // ============================ brand-new account ===========================

    public function test_a_brand_new_coordinator_sees_no_existing_records(): void
    {
        $this->actingAs($this->newbie);

        $this->assertCount(0, $this->get(route('coordinator.incidents.index'))->assertOk()->viewData('incidents'));
        $this->assertCount(0, $this->get(route('coordinator.incidents.trash'))->assertOk()->viewData('incidents'));
        $this->assertCount(0, $this->get(route('coordinator.buildings.show', $this->sharedBuilding))->assertOk()->viewData('building')->hazardInspections);
        // Drills are the exception: every drill of the campus, from day one.
        $this->assertEqualsCanonicalizing([$this->drillA->drill_id, $this->scheduledDrill->drill_id], $this->get(route('coordinator.drills.index'))->assertOk()->viewData('drills')->pluck('drill_id')->all());

        // Dashboard: every per-person KPI 0 (drills are campus-wide), lists empty, no activity.
        $analytics = $this->get(route('coordinator.dashboard'))->assertOk()->viewData('analytics');
        foreach ($analytics['kpis'] as $kpi) {
            $this->assertSame($kpi['key'] === 'drills' ? 1 : 0, $kpi['current'], $kpi['key']);
        }
        $this->assertSame(0, $analytics['lists']['unresolved_incidents']['total']);
        $this->assertSame(0, $analytics['lists']['open_high_risk']['total']);
        $this->assertCount(0, $analytics['lists']['recent_activity']);
        $this->assertFalse($analytics['charts']['incidents_over_time']['has_data']);
        $this->assertFalse($analytics['charts']['findings_by_risk']['has_data']);

        // Reports: consolidated (page) and every module report are empty.
        $report = $this->get(route('reports.consolidated.index', $this->quarter()))->assertOk()->viewData('report');
        $this->assertSame(0, $report['incidents']['total']);
        $this->assertSame(0, $report['hazard_auditor']['inspections_conducted']);
        $this->assertSame(1, $report['drills']['total'], 'the campus drill Alpha documented');
        $this->assertSame(0, $report['alerts']['total']);
        foreach (['hazard', 'incidents', 'alerts'] as $module) {
            $report = $this->reportText($this->periodPdfHtml($this->newbie, $module, $this->quarter()));
            foreach (['Alpha', 'Bravo'] as $other) {
                $this->assertStringNotContainsString($other, $report, "$module report");
            }
        }

        // Own activity log: nothing but their own entries.
        $logs = $this->get(route('coordinator.activity-log.index'))->assertOk()->viewData('logs');
        $this->assertTrue(collect($logs->items())->every(fn ($l) => $l->personnel_id === $this->newbie->personnel_id));
    }

    // ===================== same campus, different person ======================

    public function test_two_coordinators_on_the_same_campus_see_different_lists(): void
    {
        $alpha = $this->actingAs($this->coordA)->get(route('coordinator.incidents.index'))->assertOk()->viewData('incidents');
        $bravo = $this->actingAs($this->coordB)->get(route('coordinator.incidents.index'))->assertOk()->viewData('incidents');
        $this->assertSame(['Alpha incident'], $alpha->pluck('description')->all());
        $this->assertSame(['Bravo incident'], $bravo->pluck('description')->all());

        // Same building page, each sees only their own inspection of it.
        $alphaInspections = $this->actingAs($this->coordA)->get(route('coordinator.buildings.show', $this->sharedBuilding))->assertOk();
        $alphaInspections->assertSee('Alpha finding')->assertDontSee('Bravo finding');
        $this->actingAs($this->coordB)->get(route('coordinator.buildings.show', $this->sharedBuilding))->assertOk()
            ->assertSee('Bravo finding')->assertDontSee('Alpha finding');

        // Drills: the same campus list for both.
        $drills = fn (Personnel $who) => $this->actingAs($who)->get(route('coordinator.drills.index'))->viewData('drills')->pluck('drill_id')->sort()->values()->all();
        $this->assertSame($drills($this->coordA), $drills($this->coordB));
        $this->assertCount(2, $drills($this->coordB));

        // Dashboards differ too.
        $kpis = fn (Personnel $who) => collect($this->actingAs($who)->get(route('coordinator.dashboard'))->viewData('analytics')['kpis'])->keyBy('key');
        $this->assertSame(1, $kpis($this->coordA)['total_incidents']['current']);
        $this->assertSame(1, $kpis($this->coordA)['drills']['current']);
        $this->assertSame(1, $kpis($this->coordA)['open_high_risk']['current'], "Alpha's High finding is the building's latest inspection");
        $this->assertSame(1, $kpis($this->coordB)['total_incidents']['current']);
        $this->assertSame(1, $kpis($this->coordB)['drills']['current'], 'campus-wide');
        $this->assertSame(0, $kpis($this->coordB)['open_high_risk']['current']);

        // Reports: each only their own.
        $report = fn (Personnel $who) => $this->actingAs($who)->get(route('reports.consolidated.index', $this->quarter()))->viewData('report');
        $this->assertSame(1, $report($this->coordA)['incidents']['total']);
        $this->assertSame(1, $report($this->coordA)['alerts']['total'], 'the alert on their incident');
        $this->assertSame(1, $report($this->coordB)['incidents']['total']);
        $this->assertSame(0, $report($this->coordB)['alerts']['total']);
        $report = $this->reportText($this->periodPdfHtml($this->coordB, 'hazard', $this->quarter()));
        $this->assertStringContainsString('Bravo finding', $report);
        $this->assertStringNotContainsString('Alpha finding', $report);
    }

    public function test_a_coordinator_cannot_open_a_colleagues_records_by_editing_the_url(): void
    {
        $i = $this->incidentA;
        $attempts = [
            ['get', route('coordinator.incidents.show', $i)],
            ['get', route('coordinator.incidents.edit', $i)],
            ['put', route('coordinator.incidents.update', $i), ['hazard_type' => 'Fire', 'date_occurred' => '2026-09-20', 'status' => 'Resolved']],
            ['delete', route('coordinator.incidents.destroy', $i)],
            ['get', route('coordinator.incidents.report', $i)],
            ['get', route('coordinator.incidents.damage-reports.create', $i)],
            ['post', route('coordinator.incidents.damage-reports.store', $i), ['building_id' => $this->sharedBuilding->building_id, 'damage_level' => 'Total', 'casualty_count' => 9]],
            ['get', route('coordinator.incidents.damage-reports.edit', [$i, $this->damageA])],
            ['delete', route('coordinator.incidents.damage-reports.destroy', [$i, $this->damageA])],
            ['get', route('coordinator.incidents.alerts.report', [$i, $this->alertA])],
            ['get', route('coordinator.buildings.inspections.edit', [$this->sharedBuilding, $this->inspectionA])],
            ['put', route('coordinator.buildings.inspections.update', [$this->sharedBuilding, $this->inspectionA]), ['inspection_date' => '2026-09-21', 'likelihood' => 1, 'impact' => 1]],
            ['delete', route('coordinator.buildings.inspections.destroy', [$this->sharedBuilding, $this->inspectionA])],
            ['get', route('coordinator.buildings.inspections.report', [$this->sharedBuilding, $this->inspectionA])],
        ];

        foreach ([$this->coordB, $this->newbie] as $intruder) {
            foreach ($attempts as $attempt) {
                $this->actingAs($intruder)->{$attempt[0]}($attempt[1], $attempt[2] ?? [])->assertForbidden();
            }
        }

        // Nothing changed.
        $this->assertSame('Active', $this->incidentA->fresh()->status);
        $this->assertNotNull(Incident::find($i->incident_id));
        $this->assertSame(1, $i->damageReports()->count());
        $this->assertSame('High Risk', $this->inspectionA->fresh()->risk_level);
        // Their own records still open fine.
        $this->actingAs($this->coordA)->get(route('coordinator.incidents.show', $i))->assertOk()->assertSee('Alpha alert');
        $this->actingAs($this->coordB)->get(route('coordinator.incidents.show', $this->incidentB))->assertOk();
    }

    // ================================ buildings ===============================

    public function test_buildings_stay_visible_to_every_coordinator_on_the_campus(): void
    {
        foreach ([$this->coordA, $this->coordB, $this->newbie] as $who) {
            $this->assertSame(['Shared Gym'], $this->actingAs($who)->get(route('coordinator.buildings.index'))->assertOk()->viewData('buildings')->pluck('building_name')->all());
            $this->actingAs($who)->get(route('coordinator.buildings.show', $this->sharedBuilding))->assertOk()->assertSee('Shared Gym');
            $this->actingAs($who)->get(route('coordinator.buildings.inspections.create', $this->sharedBuilding))->assertOk();
            $this->actingAs($who)->get(route('coordinator.buildings.report', $this->sharedBuilding))->assertOk();
        }

        // ...and every one of them can inspect it, adding to their own list only.
        $newbieInspection = $this->inspect($this->newbie, 'Newbie finding', 'Minimal Risk', '2026-09-22');
        $this->assertSame($this->newbie->personnel_id, $newbieInspection->created_by);
        $this->actingAs($this->coordA)->get(route('coordinator.buildings.show', $this->sharedBuilding))->assertDontSee('Newbie finding');

        // Damage reports can point at any campus building.
        $this->actingAs($this->coordB)->post(route('coordinator.incidents.damage-reports.store', $this->incidentB), [
            'building_id' => $this->sharedBuilding->building_id, 'damage_level' => 'Major', 'casualty_count' => 1,
        ])->assertRedirect();

        // Never another campus's buildings.
        $this->actingAs($this->bazalCoord)->get(route('coordinator.buildings.show', $this->sharedBuilding))->assertForbidden();
        $this->assertCount(0, $this->actingAs($this->bazalCoord)->get(route('coordinator.buildings.index'))->viewData('buildings'));
    }

    // ================================== drills ================================

    public function test_drills_are_visible_to_every_coordinator_of_the_campus(): void
    {
        foreach ([$this->coordA, $this->coordB, $this->newbie] as $who) {
            foreach ([$this->drillA, $this->scheduledDrill] as $drill) {
                $this->actingAs($who)->get(route('coordinator.drills.show', $drill))->assertOk();
                $this->actingAs($who)->get(route('coordinator.drills.documentation.edit', $drill))->assertOk();
                $this->actingAs($who)->get(route('coordinator.drills.after-activity.edit', $drill))->assertOk();
                $this->actingAs($who)->get(route('coordinator.drills.memorandum', $drill))->assertOk();
            }
            $this->actingAs($who)->get(route('coordinator.drills.documentation.pdf', $this->drillA))->assertOk();
        }

        // Any of them may file the After Activity Report; the submitter is recorded.
        $this->actingAs($this->coordB)->put(route('coordinator.drills.after-activity.update', $this->drillA), [
            'activity_title' => 'Earthquake Drill', 'date_conducted' => '2026-09-24', 'activities_conducted' => 'Alarm, duck-cover-hold, evacuation.',
        ])->assertRedirect();
        $this->assertSame('Coordinator Bravo', $this->drillA->afterActivityReport()->value('submitted_by_name'));
        $this->assertSame('Coordinator Alpha', $this->drillA->documentation()->value('submitted_by_name'));

        // Never another campus's drills.
        $this->actingAs($this->bazalCoord)->get(route('coordinator.drills.show', $this->drillA))->assertForbidden();
        $this->assertCount(0, $this->actingAs($this->bazalCoord)->get(route('coordinator.drills.index', ['month' => '2026-09']))->viewData('drills'));
    }

    public function test_only_the_creator_or_the_admin_can_change_a_drill(): void
    {
        $this->actingAs($this->coordA)->post(route('coordinator.drills.store'), [
            'drill_type' => 'Fire', 'scheduled_date' => '2026-09-29', 'scheduled_time' => '13:40', 'venue' => 'Alpha Own Venue',
        ])->assertRedirect();
        $own = Drill::where('venue', 'Alpha Own Venue')->firstOrFail();
        $this->assertSame($this->coordA->personnel_id, $own->created_by);

        $update = ['drill_type' => 'Fire', 'scheduled_date' => '2026-09-29', 'scheduled_time' => '14:00', 'venue' => 'Moved'];
        // A colleague can see it but not change it.
        $this->actingAs($this->coordB)->get(route('coordinator.drills.show', $own))->assertOk();
        $this->actingAs($this->coordB)->get(route('coordinator.drills.edit', $own))->assertForbidden();
        $this->actingAs($this->coordB)->put(route('coordinator.drills.update', $own), $update)->assertForbidden();
        $this->actingAs($this->coordB)->delete(route('coordinator.drills.destroy', $own))->assertForbidden();
        // An Admin-scheduled drill is the Admin's.
        $this->actingAs($this->coordA)->get(route('coordinator.drills.edit', $this->drillA))->assertForbidden();
        $this->actingAs($this->coordA)->delete(route('coordinator.drills.destroy', $this->drillA))->assertForbidden();

        $this->actingAs($this->coordA)->put(route('coordinator.drills.update', $own), $update)->assertRedirect();
        $this->assertSame('Moved', $own->fresh()->venue);
        $this->actingAs($this->admin)->put(route('admin.drills.update', $own), ['venue' => 'Admin moved'] + $update)->assertRedirect();
        $this->assertSame('Admin moved', $own->fresh()->venue);
    }

    // ============================ campus on top ===============================

    public function test_campus_scoping_still_applies_on_top_of_per_person(): void
    {
        $bazalIncident = $this->reportIncident($this->bazalCoord, 'Bazal incident');

        foreach ([route('coordinator.incidents.show', $bazalIncident), route('coordinator.incidents.report', $bazalIncident)] as $url) {
            $this->actingAs($this->coordA)->get($url)->assertForbidden();
        }
        foreach ([route('coordinator.incidents.show', $this->incidentA), route('coordinator.drills.show', $this->scheduledDrill), route('coordinator.buildings.show', $this->sharedBuilding)] as $url) {
            $this->actingAs($this->bazalCoord)->get($url)->assertForbidden();
        }

        // A tampered campus_id never widens a coordinator's list or report.
        $list = $this->actingAs($this->bazalCoord)->get(route('coordinator.incidents.index', ['campus_id' => $this->zabali->campus_id]))->viewData('incidents');
        $this->assertSame(['Bazal incident'], $list->pluck('description')->all());
        $report = $this->actingAs($this->coordA)->get(route('reports.consolidated.index', $this->quarter() + ['campus_id' => $this->bazal->campus_id]))->viewData('report');
        $this->assertSame(1, $report['incidents']['total']);

        // An Admin-reported incident on Zabali is Admin-only (no coordinator created it).
        $adminIncident = $this->reportIncident($this->admin, 'Admin incident');
        $this->actingAs($this->coordA)->get(route('coordinator.incidents.show', $adminIncident))->assertForbidden();
    }

    // ================================= admin ==================================

    public function test_the_admin_still_sees_everything(): void
    {
        $this->reportIncident($this->bazalCoord, 'Bazal incident');
        $this->actingAs($this->admin);

        $this->assertEqualsCanonicalizing(['Alpha incident', 'Bravo incident', 'Bazal incident'],
            $this->get(route('admin.incidents.index'))->assertOk()->viewData('incidents')->pluck('description')->all());
        $this->get(route('admin.buildings.show', $this->sharedBuilding))->assertOk()->assertSee('Alpha finding')->assertSee('Bravo finding');
        $this->assertEqualsCanonicalizing([$this->drillA->drill_id, $this->scheduledDrill->drill_id],
            $this->get(route('admin.drills.index'))->viewData('drills')->pluck('drill_id')->all());
        foreach ([$this->incidentA, $this->incidentB] as $incident) {
            $this->get(route('admin.incidents.show', $incident))->assertOk();
            $this->get(route('admin.incidents.report', $incident))->assertOk();
        }
        $this->get(route('admin.drills.show', $this->drillA))->assertOk();

        $kpis = collect($this->get(route('admin.dashboard'))->viewData('analytics')['kpis'])->keyBy('key');
        $this->assertSame(3, $kpis['total_incidents']['current']);
        $this->assertSame(2, $kpis['inspections']['current']);

        $report = $this->get(route('reports.consolidated.index', $this->quarter()))->viewData('report');
        $this->assertSame(3, $report['incidents']['total']);
        $this->assertSame(2, $report['hazard_auditor']['inspections_conducted']);

        // The full activity log, every user.
        $actors = collect($this->get(route('admin.activity-log.index'))->assertOk()->viewData('logs')->items())->pluck('personnel_id')->unique();
        $this->assertGreaterThan(1, $actors->count());
    }

    // ============================== activity log ==============================

    public function test_a_coordinator_activity_log_holds_only_their_own_entries(): void
    {
        $page = $this->actingAs($this->coordB)->get(route('coordinator.activity-log.index', ['personnel_id' => $this->coordA->personnel_id]))->assertOk();
        $page->assertSee('My Activity Log')->assertDontSee('Coordinator Alpha');
        $logs = collect($page->viewData('logs')->items());
        $this->assertNotEmpty($logs);
        $this->assertTrue($logs->every(fn ($l) => $l->personnel_id === $this->coordB->personnel_id), 'the user filter is ignored for coordinators');

        $pdf = $this->actingAs($this->coordB)->get(route('coordinator.activity-log.report'))->assertOk();
        $this->assertStringContainsString('application/pdf', $pdf->headers->get('Content-Type'));
        $this->assertStringNotContainsString('Coordinator Alpha', $this->reportText($this->capturePdf($this->coordB, 'activity-log.report', route('coordinator.activity-log.report'))['html']));

        // The Admin's log stays Admin-only.
        $this->actingAs($this->coordB)->get(route('admin.activity-log.index'))->assertForbidden();
    }
}
