<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Backup;
use App\Models\Building\Building;
use App\Models\Campus;
use App\Models\Incident\Incident;
use App\Models\Personnel;
use Database\Seeders\PresentationDemoSeeder;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class ExpertFeedbackTest extends TestCase
{
    use RefreshDatabase;

    private Personnel $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('records');
        Storage::fake('local');
        $campus = Campus::create(['campus_name' => 'ASCOT Bazal Campus']);
        $this->admin = Personnel::create(['campus_id' => $campus->getKey(), 'full_name' => 'Admin Test',
            'role' => 'DRRMO Admin', 'email' => 'admin@example.test', 'password_hash' => Hash::make('password')]);
    }

    public function test_logout_invalidates_session_and_protected_pages_are_not_cached(): void
    {
        $response = $this->actingAs($this->admin)->withSession(['private_marker' => 'secret'])->get('/admin/dashboard')->assertOk();
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $this->post('/logout')->assertRedirect('/')->assertSessionMissing('private_marker');
        $this->assertGuest();
        $this->get('/')->assertOk()->assertSee('drrms-logout', false);
        $this->get('/admin/dashboard')->assertRedirect('/login');
    }

    public function test_incident_photo_ajax_retry_and_notification_are_saved_once(): void
    {
        $data = ['client_key' => (string) Str::uuid(), 'campus_id' => \App\Models\Campus::value('campus_id'),
            'hazard_type' => 'Flood', 'date_occurred' => today()->toDateString(), 'status' => 'Active',
            'description' => 'Water covered the gymnasium entrance.', 'photo' => UploadedFile::fake()->image('flood.jpg')];
        $headers = ['X-Requested-With' => 'XMLHttpRequest', 'Accept' => 'application/json', 'Referer' => route('admin.incidents.index')];
        $this->actingAs($this->admin)->withHeaders($headers)->post('/admin/incidents', $data)->assertOk()->assertJsonStructure(['redirect']);
        $this->post('/admin/incidents', $data)->assertOk();
        $this->assertDatabaseCount('incidents', 1);
        $this->assertCount(1, Storage::disk('records')->allFiles('incidents'));
        $this->assertDatabaseCount('staff_notifications', 1);
        $this->get('/api/command/notifications')->assertOk()->assertJsonPath('unread', 1);
        $this->withHeaders(['Accept' => 'text/html'])->get('/admin/incidents')->assertOk()->assertSee('data-incident-grid', false)->assertSee('data-notification-bell', false)->assertSee('Water covered the gymnasium entrance.');
    }

    public function test_failed_incident_photo_upload_rolls_back_the_incident(): void
    {
        $disk = \Mockery::mock(FilesystemAdapter::class);
        $disk->shouldReceive('putFileAs')->once()->andReturn(false);
        Storage::shouldReceive('disk')->with('records')->andReturn($disk);
        $this->withoutExceptionHandling();
        try {
            $this->actingAs($this->admin)->post('/admin/incidents', ['campus_id' => \App\Models\Campus::value('campus_id'),
                'hazard_type' => 'Flood', 'date_occurred' => today()->toDateString(), 'status' => 'Active', 'photo' => UploadedFile::fake()->image('flood.jpg')]);
            $this->fail('A failed file write must not show a saved incident.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('could not be stored', $exception->getMessage());
        }
        $this->assertDatabaseCount('incidents', 0);
        $this->assertDatabaseCount('staff_notifications', 0);
    }

    public function test_description_and_student_officer_promotion_are_supported(): void
    {
        $this->actingAs($this->admin)->post('/admin/buildings', ['campus_id' => \App\Models\Campus::value('campus_id'),
            'building_name' => 'Gym', 'description' => 'Assembly hall with a side exit.', 'hazard_type' => 'Flood', 'risk_level' => 'Medium'])->assertRedirect();
        $building = Building::sole();
        $this->get(route('admin.buildings.show', $building))->assertOk()->assertSee('Assembly hall with a side exit.');
        $student = Personnel::forceCreate(['campus_id' => \App\Models\Campus::value('campus_id'), 'full_name' => 'Student Officer', 'role' => 'Reporter',
            'email' => 'officer@example.test', 'password_hash' => Hash::make('password'), 'reporter_kind' => 'Student', 'is_approved' => false]);
        $data = ['campus_id' => $student->campus_id, 'full_name' => $student->full_name, 'role' => 'Coordinator'];
        $this->put(route('admin.personnel.update', $student), $data)->assertUnprocessable();
        $student->forceFill(['is_approved' => true, 'email_verified_at' => now(), 'validated_by_coordinator_at' => now()])->save();
        $this->put(route('admin.personnel.update', $student), $data)->assertRedirect();
        $this->assertSame('Coordinator', $student->fresh()->role);
    }

    public function test_successful_new_and_stored_backup_downloads_are_logged(): void
    {
        config(['privacy.data_privacy_password_hash' => Hash::make('privacy-secret')]);
        $this->actingAs($this->admin)->post(route('admin.backup.download'), ['data_privacy_password' => 'privacy-secret'])->assertOk();
        $backup = Backup::sole();
        $this->get(route('admin.backup.stored.download', $backup))->assertOk();
        $this->assertSame(2, ActivityLog::where('action', 'backup_downloaded')->count());
        $this->assertDatabaseHas('activity_logs', ['subject_type' => 'backup', 'subject_id' => $backup->getKey(), 'personnel_id' => $this->admin->getKey()]);
    }

    public function test_people_metrics_distinguish_unknown_and_zero_and_demo_is_repeatable(): void
    {
        $this->seed(PresentationDemoSeeder::class);
        $this->seed(PresentationDemoSeeder::class);
        $this->assertDatabaseCount('incidents', 1);
        $this->assertDatabaseCount('damage_reports', 1);
        $incident = Incident::sole();
        $this->assertStringContainsString('[DEMO]', $incident->description);
        Storage::disk('records')->assertExists($incident->photo_path);
        $analytics = $this->actingAs($this->admin)->get('/admin/dashboard')->assertOk()->viewData('analytics');
        $this->assertSame(24, $analytics['people'][0]['total']);
        $this->assertSame(0, $analytics['people'][1]['total']);
        $this->assertTrue($analytics['kpis'][0]['higher_is_worse']);
        $incident->update(['people_injured' => null]);
        $analytics = $this->get('/admin/dashboard')->assertOk()->viewData('analytics');
        $this->assertNull($analytics['people'][1]['total']);
    }
}
