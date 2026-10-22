<?php

namespace Tests\Feature;

use App\Models\Advisory\DisasterAdvisory;
use App\Models\Building\Building;
use App\Models\Campus;
use App\Models\Incident\Incident;
use App\Models\Personnel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Thesis panel (IT experts): one Archive for every deleted record, with
 * filters; it shows each person only what their Trash pages would, and
 * restores through each module's own route.
 */
class ArchiveTest extends TestCase
{
    use RefreshDatabase;

    protected Campus $zabali;

    protected Campus $bazal;

    protected Personnel $admin;

    protected Personnel $coordinator;

    protected function setUp(): void
    {
        parent::setUp();

        $this->zabali = Campus::create(['campus_name' => 'ASCOT Zabali Campus']);
        $this->bazal = Campus::create(['campus_name' => 'ASCOT Bazal Campus']);
        $this->admin = Personnel::create(['campus_id' => $this->zabali->campus_id, 'full_name' => 'Archive Admin', 'role' => 'DRRMO Admin',
            'email' => 'archive-admin@ascot-test.local', 'password_hash' => Hash::make('password')]);
        $this->coordinator = Personnel::forceCreate(['campus_id' => $this->zabali->campus_id, 'full_name' => 'Archive Coordinator', 'role' => 'Coordinator',
            'email' => 'archive-coord@ascot-test.local', 'password_hash' => Hash::make('password'), 'is_approved' => true]);
    }

    protected function deletedIncident(Campus $campus, Personnel $by, string $type): Incident
    {
        $incident = Incident::forceCreate(['campus_id' => $campus->campus_id, 'hazard_type' => $type, 'date_occurred' => '2026-10-10',
            'status' => 'Resolved', 'created_by' => $by->personnel_id]);
        $incident->delete();

        return $incident;
    }

    public function test_the_admin_sees_every_type_and_can_filter(): void
    {
        $this->deletedIncident($this->zabali, $this->admin, 'Flood');
        $this->deletedIncident($this->bazal, $this->admin, 'Fire');
        Building::forceCreate(['campus_id' => $this->zabali->campus_id, 'building_name' => 'Old Gym', 'risk_level' => 'Minimal Risk', 'created_by' => $this->admin->personnel_id])->delete();
        DisasterAdvisory::forceCreate(['hazard_type' => 'Typhoon', 'headline' => 'Old typhoon warning', 'source' => 'PAGASA', 'issued_at' => now()->subDays(3),
            'valid_until' => now()->subDay(), 'status' => 'Passed', 'instructions' => 'Stay safe.', 'created_by' => $this->admin->personnel_id])->delete();

        $this->actingAs($this->admin)->get(route('admin.archive.index'))->assertOk()
            ->assertSee('Flood')->assertSee('Fire')->assertSee('Old Gym')->assertSee('Old typhoon warning')
            ->assertSee('4 records in the archive.');

        $this->get(route('admin.archive.index', ['type' => 'incidents', 'campus' => $this->bazal->campus_id]))->assertOk()
            ->assertSee('Fire')->assertDontSee('Old Gym')->assertSee('1 record in the archive.');
    }

    public function test_a_coordinator_sees_only_what_their_trash_pages_would(): void
    {
        $this->deletedIncident($this->zabali, $this->coordinator, 'Landslide');
        $this->deletedIncident($this->bazal, $this->admin, 'Fire');

        $html = $this->actingAs($this->coordinator)->get(route('coordinator.archive.index'))->assertOk()->getContent();
        $this->assertStringContainsString('Landslide', $html);
        $this->assertStringNotContainsString('>Fire', $html);
    }

    public function test_restore_goes_through_the_modules_own_route(): void
    {
        $incident = $this->deletedIncident($this->zabali, $this->admin, 'Flood');

        $this->actingAs($this->admin)->get(route('admin.archive.index'))
            ->assertSee('action="' . route('admin.incidents.restore', $incident->incident_id) . '"', false);
        $this->post(route('admin.incidents.restore', $incident->incident_id))->assertRedirect();
        $this->assertFalse($incident->fresh()->trashed());
    }

    public function test_the_admin_can_delete_a_record_permanently(): void
    {
        $incident = $this->deletedIncident($this->zabali, $this->admin, 'Flood');
        $url = route('admin.archive.destroy', ['incidents', $incident->incident_id]);

        $this->actingAs($this->admin)->get(route('admin.archive.index'))->assertSee('action="' . $url . '"', false);
        $this->from(route('admin.archive.index'))->delete($url)
            ->assertRedirect(route('admin.archive.index'))->assertSessionHas('status');

        $this->assertNull(Incident::withTrashed()->find($incident->incident_id));
        $this->assertDatabaseHas('activity_logs', ['action' => 'permanently_deleted', 'subject_type' => 'Incident',
            'subject_id' => $incident->incident_id, 'personnel_id' => $this->admin->personnel_id]);
    }

    public function test_a_building_damage_reports_point_at_is_kept(): void
    {
        $building = Building::forceCreate(['campus_id' => $this->zabali->campus_id, 'building_name' => 'Old Gym', 'risk_level' => 'Minimal Risk', 'created_by' => $this->admin->personnel_id]);
        $incident = $this->deletedIncident($this->zabali, $this->admin, 'Earthquake');
        \App\Models\Incident\DamageReport::forceCreate(['incident_id' => $incident->incident_id, 'building_id' => $building->building_id,
            'damage_level' => 'Minor', 'casualty_count' => 0, 'est_cost' => 5000]);
        $building->delete();

        $this->actingAs($this->admin)->delete(route('admin.archive.destroy', ['buildings', $building->building_id]))
            ->assertSessionHasErrors('archive');
        $this->assertTrue(Building::onlyTrashed()->whereKey($building->building_id)->exists());
    }

    public function test_only_the_admin_can_delete_permanently(): void
    {
        $incident = $this->deletedIncident($this->zabali, $this->coordinator, 'Landslide');

        $this->actingAs($this->coordinator)->get(route('coordinator.archive.index'))->assertOk()->assertDontSee('Delete permanently');
        $this->delete(route('admin.archive.destroy', ['incidents', $incident->incident_id]))->assertForbidden();
        $this->assertTrue($incident->fresh()->trashed());
    }
}
