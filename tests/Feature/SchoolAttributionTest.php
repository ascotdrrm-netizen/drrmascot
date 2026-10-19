<?php

namespace Tests\Feature;

use App\Models\Building\Building;
use App\Models\Building\HazardInspection;
use App\Models\Campus;
use App\Models\Drill\Drill;
use App\Models\Incident\Incident;
use App\Models\Personnel;
use App\Models\School;
use Carbon\Carbon;
use Database\Seeders\SchoolSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\View;
use App\Models\Drill\DrillDocumentation;
use Tests\Concerns\ReadsReportHtml;
use Tests\TestCase;

/**
 * Schools are ATTRIBUTION ONLY: recorded, displayed, filtered and reported —
 * never used for access control (campus scoping is unchanged and covered by
 * the existing tests, which this task did not modify).
 */
class SchoolAttributionTest extends TestCase
{
    use ReadsReportHtml, RefreshDatabase;

    protected Campus $zabali;
    protected Campus $bazal;
    protected Campus $casiguran;
    protected Personnel $admin;
    protected Personnel $engineering;
    protected Personnel $education1;
    protected Personnel $education2;
    protected Personnel $zabaliNoSchool;
    protected Personnel $casiguranCoord;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-09-26 10:00:00', 'Asia/Manila'));

        $this->zabali = Campus::create(['campus_name' => 'ASCOT Zabali Campus']);
        $this->bazal = Campus::create(['campus_name' => 'ASCOT Bazal Campus']);
        $this->casiguran = Campus::create(['campus_name' => 'ASCOT Casiguran Campus']);
        $this->seed(SchoolSeeder::class);

        $mk = fn (Campus $c, string $name, string $role, ?string $school, string $email) => Personnel::create([
            'campus_id' => $c->campus_id, 'full_name' => $name, 'role' => $role, 'email' => $email,
            'school_id' => $school ? $this->school($school)->school_id : null, 'password_hash' => Hash::make('password'),
        ]);
        $this->admin = $mk($this->zabali, 'School Admin', 'DRRMO Admin', null, 'school-admin@ascot-test.local');
        $this->engineering = $mk($this->zabali, 'Eng Coordinator', 'Coordinator', 'School of Engineering', 'eng@ascot-test.local');
        $this->education1 = $mk($this->zabali, 'Edu Coordinator One', 'Coordinator', 'School of Education', 'edu1@ascot-test.local');
        $this->education2 = $mk($this->zabali, 'Edu Coordinator Two', 'Coordinator', 'School of Education', 'edu2@ascot-test.local');
        $this->zabaliNoSchool = $mk($this->zabali, 'Zabali Unassigned', 'Coordinator', null, 'zabali-none@ascot-test.local');
        $this->casiguranCoord = $mk($this->casiguran, 'Casiguran Coordinator', 'Coordinator', 'School of Fisheries and Ocean Sciences', 'cas@ascot-test.local');
    }

    protected function school(string $name): School
    {
        return School::where('school_name', $name)->firstOrFail();
    }

    protected function reportIncident(Personnel $as, string $description): Incident
    {
        $prefix = $as->role === 'DRRMO Admin' ? 'admin' : 'coordinator';
        $this->actingAs($as)->post(route("{$prefix}.incidents.store"), array_filter([
            'hazard_type' => 'Fire', 'date_occurred' => '2026-09-20', 'status' => 'Active', 'description' => $description,
            'campus_id' => $as->role === 'DRRMO Admin' ? $this->zabali->campus_id : null,
        ]))->assertRedirect();

        return Incident::where('description', $description)->firstOrFail();
    }

    // ============================== seeded schools ==============================

    public function test_the_nine_schools_are_seeded_on_their_campuses(): void
    {
        $map = School::with('campus')->get()->mapWithKeys(fn ($s) => [$s->school_name => $s->campus->campus_name])->all();

        // UAT 2026-10-18: Accountancy was missing from the sign-up list.
        $this->assertCount(9, $map);
        foreach (['School of Engineering', 'School of Arts and Sciences', 'School of Information Technology',
            'School of Forestry and Environmental Sciences', 'School of Education', 'School of Industrial Technology',
            'School of Accountancy and Business Management'] as $name) {
            $this->assertSame('ASCOT Zabali Campus', $map[$name], $name);
        }
        $this->assertSame('ASCOT Bazal Campus', $map['School of Agricultural and Science']);
        $this->assertSame('ASCOT Casiguran Campus', $map['School of Fisheries and Ocean Sciences']);
    }

    // ============================== registration ===============================

    public function test_registration_creates_a_coordinator_with_the_chosen_school(): void
    {
        $this->post('/register', [
            'first_name' => 'New', 'last_name' => 'Engineer', 'campus_id' => $this->zabali->campus_id, 'school_id' => $this->school('School of Engineering')->school_id,
            'email' => 'new-eng@ascot-test.local', 'password' => 'Register1!pw', 'password_confirmation' => 'Register1!pw', 'contact_number' => '09171234567',
            'role' => 'DRRMO Admin', // tampered: ignored
        ])->assertRedirect(route('login'));

        $person = Personnel::where('email', 'new-eng@ascot-test.local')->firstOrFail();
        $this->assertSame('Coordinator', $person->role);
        $this->assertSame($this->school('School of Engineering')->school_id, $person->school_id);
    }

    public function test_registration_rejects_a_school_from_another_campus_even_if_the_form_is_bypassed(): void
    {
        $this->from('/register')->post('/register', [
            'first_name' => 'Mismatched', 'last_name' => 'Person', 'campus_id' => $this->zabali->campus_id,
            'school_id' => $this->school('School of Fisheries and Ocean Sciences')->school_id,
            'email' => 'mismatch@ascot-test.local', 'password' => 'Register1!pw', 'password_confirmation' => 'Register1!pw', 'contact_number' => '09171234567',
        ])->assertRedirect('/register')->assertSessionHasErrors(['school_id' => 'The selected school is not part of the selected campus.']);

        $this->from('/register')->post('/register', [
            'first_name' => 'Bogus', 'last_name' => 'School', 'campus_id' => $this->zabali->campus_id, 'school_id' => '999999',
            'email' => 'bogus@ascot-test.local', 'password' => 'Register1!pw', 'password_confirmation' => 'Register1!pw', 'contact_number' => '09171234567',
        ])->assertSessionHasErrors('school_id');

        $this->assertNull(Personnel::whereIn('email', ['mismatch@ascot-test.local', 'bogus@ascot-test.local'])->first());
        $this->assertGuest();
    }

    /**
     * School is now REQUIRED at self-registration (it used to be optional and
     * this test asserted success with null). Enforced server-side: a missing
     * school_id, or the placeholder's empty value, is rejected and nothing is created.
     */
    public function test_registration_without_a_school_is_rejected_server_side(): void
    {
        $base = [
            'first_name' => 'General', 'last_name' => 'Services', 'campus_id' => $this->zabali->campus_id,
            'password' => 'Register1!pw', 'password_confirmation' => 'Register1!pw', 'contact_number' => '09171234567',
        ];

        // Field missing entirely (form bypassed).
        $this->from('/register')->post('/register', $base + ['email' => 'gsu@ascot-test.local'])
            ->assertRedirect('/register')
            ->assertSessionHasErrors(['school_id' => 'Please select your school.']);

        // The placeholder option left selected posts an empty value.
        $this->from('/register')->post('/register', $base + ['email' => 'gsu2@ascot-test.local', 'school_id' => ''])
            ->assertRedirect('/register')
            ->assertSessionHasErrors(['school_id' => 'Please select your school.']);

        $this->assertNull(Personnel::whereIn('email', ['gsu@ascot-test.local', 'gsu2@ascot-test.local'])->first());
        $this->assertGuest();
    }

    public function test_registration_school_field_is_marked_required_with_the_existing_hint(): void
    {
        $html = $this->get('/register')->assertOk()->getContent();

        // Label "School" (no "(optional)"); the select carries `required`, which
        // is what gives its label the same red asterisk as the other fields.
        $this->assertMatchesRegularExpression('/<label for="school_id"[^>]*>School<\/label>/', $html);
        $this->assertStringNotContainsString('School (optional)', $html);
        $this->assertMatchesRegularExpression('/<select name="school_id"[^>]*\srequired(="required")?[\s>]/', $html);
        // Placeholder is an empty value (rejected server-side); the helper text no/n        // longer suggests leaving it blank.
        $this->assertStringContainsString('<option value="">Select a campus first</option>', $html);
        $this->assertStringContainsString('<p class="form-help">Choose the school you coordinate for.</p>', $html);
        $this->assertStringNotContainsString('Leave it blank', $html);
    }

    public function test_single_school_campus_registration_posts_its_school_and_passes(): void
    {
        foreach ([[$this->bazal, 'School of Agricultural and Science', 'bazal-new'], [$this->casiguran, 'School of Fisheries and Ocean Sciences', 'cas-new']] as [$campus, $schoolName, $who]) {
            // The value the dropdown preselects for that campus (its only school).
            $schoolId = $this->school($schoolName)->school_id;
            $this->assertSame(1, \App\Models\School::where('campus_id', $campus->campus_id)->count());

            $this->post('/register', [
                'first_name' => 'Single', 'last_name' => 'School', 'campus_id' => $campus->campus_id, 'school_id' => (string) $schoolId,
                'email' => "$who@ascot-test.local", 'password' => 'Register1!pw', 'password_confirmation' => 'Register1!pw', 'contact_number' => '09171234567',
            ])->assertSessionHasNoErrors()->assertRedirect(route('login'));
            $this->assertSame($schoolId, Personnel::where('email', "$who@ascot-test.local")->value('school_id'));
            $this->post('/logout');
        }

        // After a validation error on a single-school campus, the school comes back selected.
        $agri = $this->school('School of Agricultural and Science')->school_id;
        $this->from('/register')->post('/register', [
            'first_name' => 'Again', 'last_name' => 'Bazal', 'campus_id' => $this->bazal->campus_id, 'school_id' => (string) $agri,
            'email' => 'not-an-email', 'password' => 'Register1!pw', 'password_confirmation' => 'Register1!pw', 'contact_number' => '09171234567',
        ])->assertSessionHasErrors('email');
        $this->assertMatchesRegularExpression('/<option value="' . $agri . '" selected>School of Agricultural and Science<\/option>/', $this->get('/register')->getContent());
    }

    public function test_admin_can_still_save_personnel_with_no_school(): void
    {
        // e.g. the DRRMO Director (General Services Unit): no school, still editable and savable.
        $this->actingAs($this->admin)->put(route('admin.personnel.update', $this->zabaliNoSchool), [
            'full_name' => 'Zabali Unassigned', 'role' => 'Coordinator', 'campus_id' => $this->zabali->campus_id, 'school_id' => '',
        ])->assertSessionHasNoErrors()->assertRedirect(route('admin.personnel.index'));
        $this->assertNull($this->zabaliNoSchool->fresh()->school_id);

        // The Admin's own account (no school) too.
        $this->actingAs($this->admin)->get(route('admin.personnel.edit', $this->admin))->assertOk();
        $this->actingAs($this->admin)->put(route('admin.personnel.update', $this->admin), [
            'full_name' => 'School Admin', 'role' => 'DRRMO Admin', 'campus_id' => $this->zabali->campus_id,
        ])->assertSessionHasNoErrors()->assertRedirect(route('admin.personnel.index'));
        $this->assertNull($this->admin->fresh()->school_id);

        // The column itself stays nullable.
        $this->assertTrue(collect(\Illuminate\Support\Facades\Schema::getColumns('personnel'))->firstWhere('name', 'school_id')['nullable']);
    }

    public function test_registration_repopulates_campus_and_school_after_a_validation_error(): void
    {
        $engineering = $this->school('School of Engineering')->school_id;

        $this->from('/register')->post('/register', [
            'first_name' => 'Repopulate', 'last_name' => 'Me', 'campus_id' => $this->zabali->campus_id, 'school_id' => $engineering,
            'email' => 'not-an-email', 'password' => 'Register1!pw', 'password_confirmation' => 'Register1!pw', 'contact_number' => '09171234567',
        ])->assertSessionHasErrors('email');

        $html = $this->get('/register')->assertOk()->getContent();
        $this->assertMatchesRegularExpression('/<option value="' . $this->zabali->campus_id . '"\s+selected/', $html);
        $this->assertMatchesRegularExpression('/<option value="' . $engineering . '" selected>School of Engineering<\/option>/', $html);
        // Only Zabali's schools are offered for the chosen campus.
        $this->assertStringNotContainsString('>School of Fisheries and Ocean Sciences</option>', $html);
    }

    // ============================ personnel (Admin) ============================

    public function test_admin_edit_rejects_a_mismatched_pair_and_campus_change_clears_the_school(): void
    {
        $data = fn (array $over) => array_merge(['full_name' => 'Eng Coordinator', 'role' => 'Coordinator', 'contact_number' => null], $over);

        // Stale Zabali school posted with a Casiguran campus: rejected, nothing saved.
        $this->actingAs($this->admin)->from(route('admin.personnel.edit', $this->engineering))
            ->put(route('admin.personnel.update', $this->engineering), $data(['campus_id' => $this->casiguran->campus_id, 'school_id' => $this->engineering->school_id]))
            ->assertSessionHasErrors('school_id');
        $this->assertSame($this->zabali->campus_id, $this->engineering->fresh()->campus_id);

        // Campus changed with the school cleared (what the dropdown does): saved with null.
        $this->actingAs($this->admin)
            ->put(route('admin.personnel.update', $this->engineering), $data(['campus_id' => $this->casiguran->campus_id, 'school_id' => '']))
            ->assertRedirect(route('admin.personnel.index'));
        $fresh = $this->engineering->fresh();
        $this->assertSame($this->casiguran->campus_id, $fresh->campus_id);
        $this->assertNull($fresh->school_id);

        // Re-selected on the new campus.
        $fisheries = $this->school('School of Fisheries and Ocean Sciences')->school_id;
        $this->actingAs($this->admin)
            ->put(route('admin.personnel.update', $this->engineering), $data(['campus_id' => $this->casiguran->campus_id, 'school_id' => $fisheries]))
            ->assertSessionHasNoErrors();
        $this->assertSame($fisheries, $this->engineering->fresh()->school_id);
    }

    public function test_single_school_campus_is_preselected_as_a_real_value(): void
    {
        $bazalPerson = Personnel::create(['campus_id' => $this->bazal->campus_id, 'full_name' => 'Bazal Person', 'role' => 'Coordinator',
            'email' => 'bazal-person@ascot-test.local', 'password_hash' => Hash::make('password')]);
        $agri = $this->school('School of Agricultural and Science')->school_id;

        $html = $this->actingAs($this->admin)->get(route('admin.personnel.edit', $bazalPerson))->assertOk()->getContent();
        // A real selected <option>, so the browser posts it.
        $this->assertMatchesRegularExpression('/<option value="' . $agri . '" selected>School of Agricultural and Science<\/option>/', $html);

        // Posting the form as rendered saves that school.
        $this->actingAs($this->admin)->put(route('admin.personnel.update', $bazalPerson), [
            'full_name' => 'Bazal Person', 'role' => 'Coordinator', 'campus_id' => $this->bazal->campus_id, 'school_id' => (string) $agri,
        ])->assertSessionHasNoErrors();
        $this->assertSame($agri, $bazalPerson->fresh()->school_id);
    }

    public function test_personnel_list_shows_and_filters_by_school(): void
    {
        $edu = $this->school('School of Education')->school_id;

        $page = $this->actingAs($this->admin)->get(route('admin.personnel.index', ['school_id' => $edu]))->assertOk();
        $this->assertEqualsCanonicalizing(['Edu Coordinator One', 'Edu Coordinator Two'], $page->viewData('personnel')->pluck('full_name')->all());
        $page->assertSee('School of Education');

        $none = $this->actingAs($this->admin)->get(route('admin.personnel.index', ['school_id' => 'none']))->assertOk();
        $this->assertEqualsCanonicalizing(['School Admin', 'Zabali Unassigned'], $none->viewData('personnel')->pluck('full_name')->all());

        $this->actingAs($this->engineering)->get(route('coordinator.profile.show'))->assertOk()->assertSee('School of Engineering');
    }

    // ============================== attribution ================================

    public function test_record_created_by_a_coordinator_carries_their_school(): void
    {
        $incident = $this->reportIncident($this->engineering, 'Eng incident');
        $this->assertSame($this->engineering->school_id, $incident->school_id);

        $building = Building::create(['campus_id' => $this->zabali->campus_id, 'building_name' => 'Eng Hall', 'hazard_type' => 'Fire', 'risk_level' => 'Low']);
        $this->actingAs($this->engineering)->post(route('coordinator.buildings.inspections.store', $building), [
            'inspection_date' => '2026-09-21', 'likelihood' => 3, 'impact' => 2, 'findings' => 'Eng inspection',
        ])->assertRedirect();
        $this->assertSame($this->engineering->school_id, HazardInspection::where('findings', 'Eng inspection')->value('school_id'));

        $this->actingAs($this->engineering)->get(route('coordinator.incidents.show', $incident))->assertOk()->assertSee('School of Engineering');
        $this->actingAs($this->engineering)->get(route('coordinator.buildings.show', $building))->assertOk()->assertSee('School of Engineering');
    }

    public function test_record_created_by_the_admin_has_no_school_and_renders_a_dash(): void
    {
        $incident = $this->reportIncident($this->admin, 'Admin incident');

        $this->assertNull($incident->school_id);
        $html = $this->actingAs($this->admin)->get(route('admin.incidents.show', $incident))->assertOk()->getContent();
        $this->assertMatchesRegularExpression('/Recorded by school<\/dt>\s*<dd[^>]*>—<\/dd>/', $html);
    }

    public function test_a_school_with_two_coordinators_attributes_both_and_filters_together(): void
    {
        $a = $this->reportIncident($this->education1, 'Edu one incident');
        $b = $this->reportIncident($this->education2, 'Edu two incident');
        $this->reportIncident($this->engineering, 'Eng other incident');

        $edu = $this->school('School of Education')->school_id;
        $this->assertSame([$edu, $edu], [$a->school_id, $b->school_id]);

        // Per-person visibility: a school doesn't share records between its
        // coordinators — each sees only their own, whatever the school filter...
        $own = $this->actingAs($this->education2)->get(route('coordinator.incidents.index'))->assertOk();
        $this->assertSame(['Edu two incident'], $own->viewData('incidents')->pluck('description')->all());
        $filtered = $this->actingAs($this->education2)->get(route('coordinator.incidents.index', ['school_id' => $edu]))->assertOk();
        $this->assertSame(['Edu two incident'], $filtered->viewData('incidents')->pluck('description')->all());
        // ...while the Admin's school filter still narrows to both Education records.
        $admin = $this->actingAs($this->admin)->get(route('admin.incidents.index', ['school_id' => $edu]))->assertOk();
        $this->assertEqualsCanonicalizing(['Edu one incident', 'Edu two incident'], $admin->viewData('incidents')->pluck('description')->all());
    }

    public function test_campus_scoping_is_unaffected_by_school(): void
    {
        // School never grants access: only the coordinator who reported it opens it
        // (per-person visibility) — a same-campus colleague, with or without a school, can't.
        $eng = $this->reportIncident($this->engineering, 'Engineering only');
        $this->actingAs($this->engineering)->get(route('coordinator.incidents.show', $eng))->assertOk();
        $this->actingAs($this->zabaliNoSchool)->get(route('coordinator.incidents.show', $eng))->assertForbidden();
        $this->actingAs($this->education1)->get(route('coordinator.incidents.show', $eng))->assertForbidden();
        // A coordinator on another campus still can't, whatever their school.
        $this->actingAs($this->casiguranCoord)->get(route('coordinator.incidents.show', $eng))->assertForbidden();
    }

    public function test_admin_moving_an_incident_to_another_campus_clears_its_school(): void
    {
        $incident = $this->reportIncident($this->engineering, 'Moving incident');

        $this->actingAs($this->admin)->put(route('admin.incidents.update', $incident), [
            'hazard_type' => 'Fire', 'date_occurred' => '2026-09-20', 'status' => 'Active', 'description' => 'Moving incident',
            'campus_id' => $this->casiguran->campus_id,
        ])->assertRedirect();

        $this->assertNull($incident->fresh()->school_id);
        $this->assertSame($this->casiguran->campus_id, $incident->fresh()->campus_id);
    }

    public function test_drills_are_campus_wide_and_carry_no_school(): void
    {
        // Drill Tracker: a drill is an institution event — no school, whoever schedules it.
        $this->assertFalse(\Illuminate\Support\Facades\Schema::hasColumn('drills', 'school_id'));

        $this->actingAs($this->engineering)->post(route('coordinator.drills.store'), [
            'drill_type' => 'Fire', 'scheduled_date' => '2026-09-25', 'scheduled_time' => '09:00', 'venue' => 'Eng scheduled',
        ])->assertRedirect();
        $drill = Drill::where('venue', 'Eng scheduled')->firstOrFail();

        // The drills page has no School filter, and the drill page names no school.
        $index = $this->actingAs($this->engineering)->get(route('coordinator.drills.index', ['month' => '2026-09']))->assertOk()->getContent();
        $this->assertStringNotContainsString('name="school_id"', $index);
        $this->actingAs($this->admin)->get(route('admin.drills.show', $drill))->assertOk()
            ->assertDontSee('Responsible school')->assertDontSee('School of Engineering');
    }

    // ======================= existing (null-school) records =====================

    public function test_existing_records_without_a_school_still_display_and_export(): void
    {
        $incident = Incident::create(['campus_id' => $this->zabali->campus_id, 'hazard_type' => 'Flood', 'date_occurred' => '2026-09-10', 'status' => 'Resolved', 'description' => 'Legacy incident']);
        $building = Building::create(['campus_id' => $this->zabali->campus_id, 'building_name' => 'Old Hall', 'hazard_type' => 'Flood', 'risk_level' => 'Low']);
        $inspection = HazardInspection::create(['building_id' => $building->building_id, 'inspection_date' => '2026-09-10', 'risk_level' => 'Low', 'findings' => 'Legacy finding']);
        $drill = Drill::forceCreate(['campus_id' => $this->zabali->campus_id, 'drill_type' => 'Fire', 'scheduled_date' => '2026-09-10',
            'scheduled_time' => '09:00:00', 'venue' => 'Legacy Venue']);
        (new DrillDocumentation(['date_conducted' => '2026-09-10', 'narrative' => 'Legacy drill.']))->forceFill(['drill_id' => $drill->drill_id])->save();

        foreach ([
            route('admin.incidents.index'), route('admin.incidents.show', $incident), route('admin.drills.index'), route('admin.drills.show', $drill),
            route('admin.buildings.show', $building), route('admin.personnel.index'),
        ] as $url) {
            $this->actingAs($this->admin)->get($url)->assertOk();
        }

        foreach ([
            route('admin.incidents.report', $incident), route('admin.drills.documentation.pdf', $drill),
            route('admin.buildings.inspections.report', [$building, $inspection]),
        ] as $url) {
            $this->actingAs($this->admin)->get($url)->assertOk();
        }

        $query = ['period_type' => 'quarterly', 'quarter' => 3, 'year' => 2026];
        foreach (['hazard', 'incidents', 'drills', 'drill-documentation', 'after-activity', 'alerts'] as $module) {
            $this->actingAs($this->admin)->get(route('reports.module.pdf', ['module' => $module] + $query))->assertOk();
        }
        $report = $this->reportText($this->consolidatedPdfHtml($this->admin, $query));
        // Per-school row: school, campus, inspections, incidents, alerts — drills have no school, so no drills column.
        $this->assertMatchesRegularExpression('/Not assigned\s+—\s+1\s+1\s+0/', $report, 'legacy records land in the Not assigned row');
        $this->actingAs($this->admin)->get(route('reports.consolidated.pdf', $query))->assertOk();
    }

    // ================================= filters =================================

    public function test_list_page_school_filters_narrow_and_show_not_assigned(): void
    {
        $eng = $this->reportIncident($this->engineering, 'Eng filtered');
        $none = $this->reportIncident($this->zabaliNoSchool, 'Unassigned filtered');
        $engId = $this->engineering->school_id;

        $only = fn ($r) => $r->assertOk()->viewData('incidents')->pluck('description')->all();
        $this->assertSame(['Eng filtered'], $only($this->actingAs($this->admin)->get(route('admin.incidents.index', ['school_id' => $engId]))));
        $this->assertSame(['Unassigned filtered'], $only($this->actingAs($this->admin)->get(route('admin.incidents.index', ['school_id' => 'none']))));
        $this->assertCount(2, $this->actingAs($this->admin)->get(route('admin.incidents.index'))->viewData('incidents'));

        // Trash.
        $eng->delete();
        $none->delete();
        $trash = $this->actingAs($this->admin)->get(route('admin.incidents.trash', ['school_id' => 'none']))->assertOk();
        $this->assertSame(['Unassigned filtered'], $trash->viewData('incidents')->pluck('description')->all());

    }

    public function test_filters_carry_into_the_pdf_and_the_header_states_them(): void
    {
        $this->reportIncident($this->engineering, 'Eng in report');
        $this->reportIncident($this->zabaliNoSchool, 'Unassigned in report');
        $engId = $this->engineering->school_id;
        $query = ['period_type' => 'quarterly', 'quarter' => 3, 'year' => 2026];

        // Module report: only the Engineering record; header names campus + school; filename too.
        ['html' => $html, 'response' => $res] = $this->capturePdf($this->admin, 'reports.detailed-pdf', route('reports.module.pdf', ['module' => 'incidents', 'school_id' => $engId] + $query));
        $this->assertStringContainsString('Eng in report', $html);
        $this->assertStringNotContainsString('Unassigned in report', $html);
        $this->assertMatchesRegularExpression('/meta-label">Campus covered<\/div><div class="meta-value">ASCOT Zabali Campus</', $html, 'a school picked with All Campuses names its campus');
        $this->assertMatchesRegularExpression('/meta-label">School<\/div><div class="meta-value">School of Engineering</', $html);
        $this->assertStringContainsString('_ASCOT-Zabali-Campus_School-of-Engineering.pdf', $res->headers->get('Content-Disposition'));

        // Module PDF header.
        $captured = [];
        View::composer('reports.detailed-pdf', function ($v) use (&$captured) { $captured = $v->getData(); });
        $this->actingAs($this->admin)->get(route('reports.module.pdf', ['module' => 'incidents', 'school_id' => 'none'] + $query))->assertOk();
        $html = view('reports.detailed-pdf', $captured + ['totalPages' => 1])->render();
        $this->assertMatchesRegularExpression('/meta-label">School<\/div><div class="meta-value">Not assigned</', $html);
        $this->assertStringContainsString('Unassigned in report', $html);
        $this->assertStringNotContainsString('Eng in report', $html);

        // Consolidated: header, totals, per-school breakdown, filename.
        ['html' => $html, 'response' => $consolidated] = $this->capturePdf($this->admin, 'reports.consolidated-pdf', route('reports.consolidated.pdf', ['school_id' => $engId] + $query));
        $this->assertStringContainsString('ASCOT Zabali Campus', $html);
        $this->assertMatchesRegularExpression('/meta-label">School<\/div><div class="meta-value">School of Engineering</', $html);
        $this->assertMatchesRegularExpression('/field-label">Total incidents<\/div><div class="field-value">1</', $html);
        $this->assertStringContainsString('Per-School Breakdown', $html);
        $this->assertStringContainsString('_School-of-Engineering.pdf', $consolidated->headers->get('Content-Disposition'));

        // The web page keeps the selection for the Download PDF link.
        $page = $this->actingAs($this->admin)->get(route('reports.consolidated.index', ['school_id' => $engId] + $query))->assertOk();
        $this->assertSame($engId, $page->viewData('filters')['school_id']);
    }

    // ================================= security ================================

    public function test_a_coordinator_passing_another_campus_school_gets_no_data(): void
    {
        $this->reportIncident($this->casiguranCoord, 'Casiguran secret');
        $this->reportIncident($this->engineering, 'Zabali visible');
        $fisheries = $this->casiguranCoord->school_id;
        $query = ['period_type' => 'quarterly', 'quarter' => 3, 'year' => 2026];

        // List pages: rejected with a validation error, never Casiguran rows.
        // (Drills have no school filter.)
        foreach (['coordinator.incidents.index', 'coordinator.incidents.trash'] as $name) {
            $res = $this->actingAs($this->engineering)->from(route('coordinator.dashboard'))->get(route($name, ['school_id' => $fisheries, 'campus_id' => $this->casiguran->campus_id]));
            $res->assertRedirect(route('coordinator.dashboard'))->assertSessionHasErrors(['school_id' => 'The selected school is not part of the selected campus.']);
            $this->assertStringNotContainsString('Casiguran secret', (string) $res->getContent(), $name);
        }

        // Reports: rejected too.
        foreach (['incidents', 'hazard', 'drills', 'alerts'] as $module) {
            $this->actingAs($this->engineering)->get(route('reports.module.pdf', ['module' => $module, 'school_id' => $fisheries] + $query))->assertSessionHasErrors('school_id');
        }
        $this->actingAs($this->engineering)->get(route('reports.consolidated.pdf', ['school_id' => $fisheries, 'campus_id' => $this->casiguran->campus_id] + $query))->assertSessionHasErrors('school_id');
        $this->actingAs($this->engineering)->get(route('reports.consolidated.index', ['school_id' => $fisheries] + $query))->assertSessionHasErrors('school_id');

        // Without the bad filter, the coordinator still sees only Zabali.
        $own = $this->actingAs($this->engineering)->get(route('coordinator.incidents.index', ['campus_id' => $this->casiguran->campus_id]))->assertOk();
        $this->assertSame(['Zabali visible'], $own->viewData('incidents')->pluck('description')->all());
        $report = $this->reportText($this->periodPdfHtml($this->engineering, 'incidents', ['school_id' => 'none', 'campus_id' => $this->casiguran->campus_id] + $query));
        $this->assertStringNotContainsString('Casiguran secret', $report);
    }

    public function test_admin_school_filter_must_match_the_selected_campus_and_bad_values_are_rejected(): void
    {
        $fisheries = $this->school('School of Fisheries and Ocean Sciences')->school_id;

        $this->actingAs($this->admin)->get(route('admin.incidents.index', ['campus_id' => $this->zabali->campus_id, 'school_id' => $fisheries]))
            ->assertSessionHasErrors('school_id');
        foreach (['999999', 'abc', '1;DROP'] as $bad) {
            $this->actingAs($this->admin)->get(route('admin.incidents.index', ['school_id' => $bad]))->assertSessionHasErrors('school_id');
        }
        $this->actingAs($this->admin)->get(route('reports.module.pdf', ['module' => 'drills', 'campus_id' => $this->zabali->campus_id, 'school_id' => $fisheries, 'period_type' => 'yearly', 'year' => 2026]))
            ->assertSessionHasErrors('school_id');
    }
}
