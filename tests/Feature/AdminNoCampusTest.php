<?php

namespace Tests\Feature;

use App\Models\Campus;
use App\Models\Incident\Incident;
use App\Models\Personnel;
use App\Models\School;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * A DRRMO Admin covers every campus: the account has no campus and no
 * school. A Coordinator or Reporter made Admin loses both, so what they
 * record afterwards is no longer "Recorded by school" their old school.
 */
class AdminNoCampusTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_coordinator_made_admin_loses_their_campus_and_school(): void
    {
        $zabali = Campus::create(['campus_name' => 'ASCOT Zabali Campus']);
        $sit = School::create(['campus_id' => $zabali->campus_id, 'school_name' => 'School of Information Technology']);
        $head = Personnel::create(['campus_id' => $zabali->campus_id, 'full_name' => 'Head Admin', 'role' => 'DRRMO Admin',
            'email' => 'head@ascot-test.local', 'password_hash' => Hash::make('password')]);
        $head->forceFill(['is_approved' => true])->save();
        $coordinator = Personnel::create(['campus_id' => $zabali->campus_id, 'school_id' => $sit->school_id, 'full_name' => 'Justine Coordinator',
            'role' => 'Coordinator', 'email' => 'justine@ascot-test.local', 'password_hash' => Hash::make('password')]);
        $coordinator->forceFill(['is_approved' => true])->save();
        $this->assertNull($head->fresh()->campus_id, 'an Admin is saved without a campus');

        // Made Admin in User Management, with the old campus and school still in the form.
        $this->actingAs($head)->put(route('admin.personnel.update', $coordinator), [
            'full_name' => 'Justine Coordinator', 'role' => 'DRRMO Admin', 'campus_id' => $zabali->campus_id, 'school_id' => $sit->school_id,
        ])->assertRedirect(route('admin.personnel.index'))->assertSessionHasNoErrors();
        $this->assertNull($coordinator->fresh()->campus_id);
        $this->assertNull($coordinator->fresh()->school_id);

        // ...or with no campus at all, as the form sends once Admin is chosen.
        $this->put(route('admin.personnel.update', $coordinator), ['full_name' => 'Justine Virrey', 'role' => 'DRRMO Admin'])
            ->assertSessionHasNoErrors();
        $this->get(route('admin.personnel.index'))->assertOk()->assertSee('All campuses');

        // What they record now is not their old school's.
        $this->actingAs($coordinator->fresh())->post(route('admin.incidents.store'), [
            'campus_id' => $zabali->campus_id, 'hazard_type' => 'Fire', 'date_occurred' => '2026-10-22', 'status' => 'Active', 'description' => 'After the promotion',
        ])->assertRedirect();
        $this->assertNull(Incident::where('description', 'After the promotion')->value('school_id'));

        // A Coordinator still needs a campus.
        $this->actingAs($head)->put(route('admin.personnel.update', $coordinator), ['full_name' => 'Justine Virrey', 'role' => 'Coordinator'])
            ->assertSessionHasErrors('campus_id');
    }

    public function test_the_migration_clears_existing_admins_campus_and_school_only(): void
    {
        $zabali = Campus::create(['campus_name' => 'ASCOT Zabali Campus']);
        $sit = School::create(['campus_id' => $zabali->campus_id, 'school_name' => 'School of Information Technology']);
        // Rows as they were before: an Admin with a campus and school.
        $admin = \Illuminate\Support\Facades\DB::table('personnel')->insertGetId(['campus_id' => $zabali->campus_id, 'school_id' => $sit->school_id,
            'full_name' => 'Old Admin', 'role' => 'DRRMO Admin', 'email' => 'old-admin@ascot-test.local', 'password_hash' => 'x'], 'personnel_id');
        $coordinator = \Illuminate\Support\Facades\DB::table('personnel')->insertGetId(['campus_id' => $zabali->campus_id, 'school_id' => $sit->school_id,
            'full_name' => 'A Coordinator', 'role' => 'Coordinator', 'email' => 'coord@ascot-test.local', 'password_hash' => 'x'], 'personnel_id');

        (require database_path('migrations/2026_10_22_000003_admins_have_no_campus_or_school.php'))->up();

        $this->assertDatabaseHas('personnel', ['personnel_id' => $admin, 'campus_id' => null, 'school_id' => null]);
        $this->assertDatabaseHas('personnel', ['personnel_id' => $coordinator, 'campus_id' => $zabali->campus_id, 'school_id' => $sit->school_id]);
    }
}
