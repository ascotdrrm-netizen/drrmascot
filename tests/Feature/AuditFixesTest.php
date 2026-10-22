<?php

namespace Tests\Feature;

use App\Models\Building\Building;
use App\Models\Campus;
use App\Models\Incident\Incident;
use App\Models\Personnel;
use App\Models\School;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Fixes from the pre-defense audit (approval has its own AccountApprovalTest):
 *  2. Trash keeps a building's or incident's photo; only permanent delete removes it.
 *  3. Only a building's creator or the Admin can move it to Trash.
 *  4. Registration rejects an over-long email or phone instead of crashing.
 *  5. Damage reports: one per building per incident, distinct-building counts,
 *     no building in Trash, upper limits on casualties and cost.
 */
class AuditFixesTest extends TestCase
{
    use RefreshDatabase;

    protected Campus $campus;

    protected Personnel $admin;

    protected Personnel $alpha;

    protected Personnel $bravo;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('records');
        $this->campus = Campus::create(['campus_name' => 'ASCOT Zabali Campus']);
        $make = fn (string $name, string $role, string $email) => Personnel::create(['campus_id' => $this->campus->campus_id, 'full_name' => $name, 'role' => $role, 'email' => $email, 'password_hash' => Hash::make('password')]);
        $this->admin = $make('Fix Admin', 'DRRMO Admin', 'fix-admin@ascot-test.local');
        $this->alpha = $make('Coordinator Alpha', 'Coordinator', 'fix-alpha@ascot-test.local');
        $this->bravo = $make('Coordinator Bravo', 'Coordinator', 'fix-bravo@ascot-test.local');
    }

    // ===================== 2. Trash keeps the photo ===========================

    public function test_trashing_and_restoring_a_building_keeps_its_photo(): void
    {
        $building = $this->building($this->alpha, 'buildings/gym.jpg');

        $this->actingAs($this->alpha)->delete(route('coordinator.buildings.destroy', $building))->assertRedirect();
        $this->assertSoftDeleted('buildings', ['building_id' => $building->building_id]);
        Storage::disk('records')->assertExists('buildings/gym.jpg');

        $this->actingAs($this->alpha)->post(route('coordinator.buildings.restore', $building->building_id))->assertRedirect();
        $this->assertSame('buildings/gym.jpg', $building->fresh()->photo_path);
        Storage::disk('records')->assertExists('buildings/gym.jpg');
    }

    public function test_trashing_and_restoring_an_incident_keeps_its_photo(): void
    {
        Storage::disk('records')->put('incidents/fire.jpg', 'PHOTO');
        $incident = Incident::create(['campus_id' => $this->campus->campus_id, 'hazard_type' => 'Fire', 'date_occurred' => '2026-09-05', 'status' => 'Active', 'description' => 'x', 'photo_path' => 'incidents/fire.jpg']);

        $this->actingAs($this->admin)->delete(route('admin.incidents.destroy', $incident))->assertRedirect();
        Storage::disk('records')->assertExists('incidents/fire.jpg');

        $this->actingAs($this->admin)->post(route('admin.incidents.restore', $incident->incident_id))->assertRedirect();
        $this->assertNull($incident->fresh()->deleted_at);
        Storage::disk('records')->assertExists('incidents/fire.jpg');
    }

    public function test_permanent_delete_removes_the_photos_including_the_inspections(): void
    {
        $building = $this->building($this->alpha, 'buildings/gym.jpg');
        Storage::disk('records')->put('hazard-inspections/exit.jpg', 'X');
        DB::table('hazard_inspections')->insert(['building_id' => $building->building_id, 'inspection_date' => '2026-09-01', 'risk_level' => 'Minimal Risk', 'photo_path' => 'hazard-inspections/exit.jpg']);
        Storage::disk('records')->put('incidents/fire.jpg', 'PHOTO');
        $incident = Incident::create(['campus_id' => $this->campus->campus_id, 'hazard_type' => 'Fire', 'date_occurred' => '2026-09-05', 'status' => 'Active', 'photo_path' => 'incidents/fire.jpg']);

        $building->delete();
        Storage::disk('records')->assertExists('buildings/gym.jpg');
        Building::withTrashed()->find($building->building_id)->forceDelete();
        Incident::find($incident->incident_id)->forceDelete();

        Storage::disk('records')->assertMissing('buildings/gym.jpg');
        Storage::disk('records')->assertMissing('hazard-inspections/exit.jpg');
        Storage::disk('records')->assertMissing('incidents/fire.jpg');
    }

    // ========== 3. Only the creator or the Admin trashes a building ===========

    public function test_only_the_creator_or_the_admin_can_trash_a_shared_building(): void
    {
        $building = $this->building($this->alpha);

        // A colleague: no Delete control, and a direct form post is refused.
        $this->actingAs($this->bravo)->get(route('coordinator.buildings.index'))->assertOk()
            ->assertDontSee('data-confirm-form="This moves ' . $building->building_name . ' ', false)
            ->assertSee(route('coordinator.buildings.edit', $building), false);
        $this->actingAs($this->bravo)->delete(route('coordinator.buildings.destroy', $building))->assertForbidden();
        $this->assertNull($building->fresh()->deleted_at);

        // The creator can.
        $this->actingAs($this->alpha)->get(route('coordinator.buildings.index'))->assertSee('data-confirm-form="This moves ' . $building->building_name . ' ', false);
        $this->actingAs($this->alpha)->delete(route('coordinator.buildings.destroy', $building))->assertRedirect();
        $this->assertSoftDeleted('buildings', ['building_id' => $building->building_id]);
    }

    public function test_the_admin_can_trash_any_building_including_one_with_no_recorded_creator(): void
    {
        $legacy = $this->building(null);

        $this->actingAs($this->alpha)->delete(route('coordinator.buildings.destroy', $legacy))->assertForbidden();
        $this->actingAs($this->admin)->delete(route('admin.buildings.destroy', $legacy))->assertRedirect();
        $this->assertSoftDeleted('buildings', ['building_id' => $legacy->building_id]);
    }

    // ================ 4. Registration limits, not a crash =====================

    public function test_an_over_long_email_or_phone_is_a_validation_error_not_a_crash(): void
    {
        $school = School::create(['campus_id' => $this->campus->campus_id, 'school_name' => 'School of Engineering']);
        $form = fn (array $o) => array_merge(['first_name' => 'Ana', 'last_name' => 'Cruz', 'email' => 'ana@example.com', 'contact_number' => '09171234567',
            'password' => 'Str0ng!Passw0rd#2026', 'password_confirmation' => 'Str0ng!Passw0rd#2026', 'campus_id' => $this->campus->campus_id, 'school_id' => $school->school_id], $o);

        $this->post('/register', $form(['email' => str_repeat('a', 108) . '@example.com']))->assertSessionHasErrors('email');
        $this->post('/register', $form(['contact_number' => str_repeat('0', 21)]))->assertSessionHasErrors('contact_number');
        $this->assertSame(0, Personnel::where('full_name', 'Ana Cruz')->count());

        $html = $this->get('/register')->assertOk()->getContent();
        $this->assertMatchesRegularExpression('/<input[^>]*id="email"[^>]*maxlength="100"/s', $html);
        $this->assertMatchesRegularExpression('/<input[^>]*id="contact_number"[^>]*maxlength="13"/s', $html);
    }

    // =========================== 5. Damage reports ============================

    public function test_one_damage_report_per_building_per_incident_and_counts_are_of_buildings(): void
    {
        $incident = Incident::create(['campus_id' => $this->campus->campus_id, 'hazard_type' => 'Fire', 'date_occurred' => '2026-09-05', 'status' => 'Active']);
        $gym = $this->building($this->alpha);
        $hall = $this->building($this->alpha);
        $post = fn (Building $b, array $o = []) => $this->actingAs($this->admin)->post(route('admin.incidents.damage-reports.store', $incident), array_merge(['building_id' => $b->building_id, 'damage_level' => 'Minor', 'casualty_count' => 1], $o));

        $post($gym)->assertSessionHasNoErrors();
        $post($gym, ['casualty_count' => 5])->assertSessionHasErrors(['building_id' => 'This building/area already has a damage report for this incident. Edit that one instead.']);
        $this->assertSame(1, $incident->damageReports()->count());

        // Editing the existing report keeps its own building.
        $report = $incident->damageReports()->first();
        $this->actingAs($this->admin)->put(route('admin.incidents.damage-reports.update', [$incident, $report]), ['building_id' => $gym->building_id, 'damage_level' => 'Major', 'casualty_count' => 2])->assertSessionHasNoErrors();

        $post($hall)->assertSessionHasNoErrors();
        $this->actingAs($this->admin)->get(route('admin.incidents.show', $incident))->assertOk()
            ->assertSee('2 buildings affected, 3 casualties in total');
    }

    public function test_singular_wording_for_one_building_and_one_casualty(): void
    {
        $incident = Incident::create(['campus_id' => $this->campus->campus_id, 'hazard_type' => 'Fire', 'date_occurred' => '2026-09-05', 'status' => 'Active']);
        $this->actingAs($this->admin)->post(route('admin.incidents.damage-reports.store', $incident), ['building_id' => $this->building($this->alpha)->building_id, 'damage_level' => 'Minor', 'casualty_count' => 1]);

        $this->actingAs($this->admin)->get(route('admin.incidents.show', $incident))->assertOk()
            ->assertSee('1 building affected, 1 casualty in total');
    }

    public function test_a_building_in_trash_is_rejected_except_the_one_a_report_already_has(): void
    {
        $incident = Incident::create(['campus_id' => $this->campus->campus_id, 'hazard_type' => 'Fire', 'date_occurred' => '2026-09-05', 'status' => 'Active']);
        $gym = $this->building($this->alpha);
        $this->actingAs($this->admin)->post(route('admin.incidents.damage-reports.store', $incident), ['building_id' => $gym->building_id, 'damage_level' => 'Minor', 'casualty_count' => 0])->assertSessionHasNoErrors();
        $report = $incident->damageReports()->first();
        $trashed = $this->building($this->alpha);
        $trashed->delete();
        $gym->delete();

        $this->actingAs($this->admin)->post(route('admin.incidents.damage-reports.store', $incident), ['building_id' => $trashed->building_id, 'damage_level' => 'Minor', 'casualty_count' => 0])
            ->assertSessionHasErrors(['building_id' => "Choose a building/area on this incident's campus that is not in Trash."]);

        // The existing report can still be edited without changing its (now trashed) building.
        $this->actingAs($this->admin)->get(route('admin.incidents.damage-reports.edit', [$incident, $report]))->assertOk()->assertSee($gym->building_name);
        $this->actingAs($this->admin)->put(route('admin.incidents.damage-reports.update', [$incident, $report]), ['building_id' => $gym->building_id, 'damage_level' => 'Total', 'casualty_count' => 3])->assertSessionHasNoErrors();
        $this->assertSame('Total', $report->fresh()->damage_level);
    }

    public function test_casualties_and_cost_have_upper_limits_instead_of_crashing(): void
    {
        $incident = Incident::create(['campus_id' => $this->campus->campus_id, 'hazard_type' => 'Fire', 'date_occurred' => '2026-09-05', 'status' => 'Active']);
        $b = $this->building($this->alpha);
        $post = fn (array $o) => $this->actingAs($this->admin)->post(route('admin.incidents.damage-reports.store', $incident), array_merge(['building_id' => $b->building_id, 'damage_level' => 'Minor', 'casualty_count' => 1], $o));

        $post(['casualty_count' => 3000000000])->assertSessionHasErrors('casualty_count');
        $post(['est_cost' => 99999999999])->assertSessionHasErrors('est_cost');
        $this->assertSame(0, $incident->damageReports()->count());
        $post(['casualty_count' => 100000, 'est_cost' => 9999999999.99])->assertSessionHasNoErrors();
    }

    // ================================ helpers =================================

    protected function building(?Personnel $by, ?string $photo = null): Building
    {
        if ($photo) {
            Storage::disk('records')->put($photo, 'PHOTO');
        }
        static $n = 0;
        $n++;
        $id = DB::table('buildings')->insertGetId(['campus_id' => $this->campus->campus_id, 'building_name' => "Building $n", 'risk_level' => 'Minimal Risk', 'photo_path' => $photo, 'created_by' => $by?->personnel_id, 'created_by_name' => $by?->full_name], 'building_id');

        return Building::withTrashed()->find($id);
    }
}
