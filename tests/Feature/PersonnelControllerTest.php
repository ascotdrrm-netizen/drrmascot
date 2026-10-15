<?php

namespace Tests\Feature;

use App\Models\Campus;
use App\Models\Personnel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class PersonnelControllerTest extends TestCase
{
    use RefreshDatabase;

    protected Campus $campus;

    protected Campus $otherCampus;

    protected Personnel $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->campus = Campus::create(['campus_name' => 'ASCOT Test Campus']);
        $this->otherCampus = Campus::create(['campus_name' => 'ASCOT Other Campus']);

        $this->admin = Personnel::create([
            'campus_id' => $this->campus->campus_id,
            'full_name' => 'Test Admin',
            'role' => 'DRRMO Admin',
            'email' => 'admin@ascot-test.local',
            'password_hash' => Hash::make('password'),
        ]);
    }

    public function test_admin_can_edit_a_coordinators_name_campus_and_role(): void
    {
        $coordinator = Personnel::create([
            'campus_id' => $this->campus->campus_id,
            'full_name' => 'Original Name',
            'role' => 'Coordinator',
            'email' => 'coordinator@ascot-test.local',
            'password_hash' => Hash::make('password'),
        ]);

        $response = $this->actingAs($this->admin)->put(route('admin.personnel.update', $coordinator), [
            'full_name' => 'Updated Name',
            'role' => 'Coordinator',
            'campus_id' => $this->otherCampus->campus_id,
            'contact_number' => '09171234567',
        ]);

        $response->assertRedirect(route('admin.personnel.index'));
        $response->assertSessionHasNoErrors();

        $coordinator->refresh();
        $this->assertSame('Updated Name', $coordinator->full_name);
        $this->assertSame($this->otherCampus->campus_id, $coordinator->campus_id);
        $this->assertSame('09171234567', $coordinator->contact_number);
    }

    public function test_demoting_the_last_remaining_admin_is_rejected(): void
    {
        // $this->admin is the ONLY DRRMO Admin in this test database.
        $this->assertSame(1, Personnel::where('role', 'DRRMO Admin')->count());

        $response = $this->actingAs($this->admin)->put(route('admin.personnel.update', $this->admin), [
            'full_name' => $this->admin->full_name,
            'role' => 'Coordinator',
            'campus_id' => $this->campus->campus_id,
            'contact_number' => null,
        ]);

        $response->assertSessionHasErrors(['role']);

        $this->admin->refresh();
        $this->assertSame('DRRMO Admin', $this->admin->role);
    }

    public function test_demoting_an_admin_succeeds_when_another_admin_remains(): void
    {
        $secondAdmin = Personnel::create([
            'campus_id' => $this->campus->campus_id,
            'full_name' => 'Second Admin',
            'role' => 'DRRMO Admin',
            'email' => 'second-admin@ascot-test.local',
            'password_hash' => Hash::make('password'),
        ]);

        $response = $this->actingAs($this->admin)->put(route('admin.personnel.update', $secondAdmin), [
            'full_name' => $secondAdmin->full_name,
            'role' => 'Coordinator',
            'campus_id' => $this->campus->campus_id,
            'contact_number' => null,
        ]);

        $response->assertRedirect(route('admin.personnel.index'));
        $response->assertSessionHasNoErrors();

        $secondAdmin->refresh();
        $this->assertSame('Coordinator', $secondAdmin->role);
    }

    public function test_coordinator_is_forbidden_from_editing_personnel(): void
    {
        $coordinator = Personnel::create([
            'campus_id' => $this->campus->campus_id,
            'full_name' => 'A Coordinator',
            'role' => 'Coordinator',
            'email' => 'coordinator2@ascot-test.local',
            'password_hash' => Hash::make('password'),
        ]);

        $target = Personnel::create([
            'campus_id' => $this->campus->campus_id,
            'full_name' => 'Edit Target',
            'role' => 'Coordinator',
            'email' => 'target@ascot-test.local',
            'password_hash' => Hash::make('password'),
        ]);

        $this->actingAs($coordinator)->get(route('admin.personnel.edit', $target))->assertForbidden();

        $this->actingAs($coordinator)->put(route('admin.personnel.update', $target), [
            'full_name' => 'Hacked Name',
            'role' => 'Coordinator',
            'campus_id' => $this->campus->campus_id,
            'contact_number' => null,
        ])->assertForbidden();

        $target->refresh();
        $this->assertSame('Edit Target', $target->full_name);
    }
}
