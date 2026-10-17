<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Campus;
use App\Models\Personnel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/** Follow-ups from docs/CODE_AUDIT.md: H7, H6, M15, M1, M2, M3. */
class AuditFollowUpTest extends TestCase
{
    use RefreshDatabase;

    private Campus $campus;
    private Personnel $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->campus = Campus::create(['campus_name' => 'ASCOT Zabali Campus']);
        $this->admin = $this->person('DRRMO Admin', 'admin@example.test');
    }

    private function person(string $role, string $email): Personnel
    {
        $person = Personnel::create(['campus_id' => $this->campus->getKey(), 'full_name' => $role.' Test',
            'role' => $role, 'email' => $email, 'password_hash' => Hash::make('Password1!')]);
        $person->forceFill(['is_approved' => true, 'email_verified_at' => now(), 'validated_by_coordinator_at' => now()])->save();

        return $person;
    }

    public function test_deleting_a_reporter_keeps_their_reports_sos_and_photos(): void
    {
        Storage::fake('records');
        $reporter = $this->person('Reporter', 'reporter@example.test');
        $reportId = $this->actingAs($reporter)->postJson('/api/reporter/reports', ['client_key' => (string) Str::uuid(),
            'category' => 'flood', 'place' => 'Gym', 'description' => 'Water rising near the gym entrance.',
            'photos' => [UploadedFile::fake()->image('flood.jpg')]])->assertCreated()->json('id');
        $sosId = $this->postJson('/api/reporter/sos', ['client_key' => (string) Str::uuid(), 'type' => 'Medical',
            'latitude' => 15.73, 'longitude' => 121.54])->assertCreated()->json('id');
        $this->assertCount(1, Storage::disk('records')->allFiles('reports'));

        $reporter->delete();

        $this->assertDatabaseHas('citizen_reports', ['report_id' => $reportId, 'reporter_id' => null]);
        $this->assertDatabaseHas('sos_events', ['sos_id' => $sosId, 'reporter_id' => null]);
        $this->assertDatabaseHas('report_photos', ['report_id' => $reportId]);
        $this->assertCount(1, Storage::disk('records')->allFiles('reports'));
        $this->actingAs($this->admin)->get('/admin/reports-inbox')->assertOk()->assertSee('Former reporter');
    }

    public function test_campus_status_schools_and_signatory_changes_are_in_the_activity_log(): void
    {
        $this->actingAs($this->admin)->putJson("/api/command/campuses/{$this->campus->campus_id}/status",
            ['status' => 'evacuate', 'detail' => 'Leave by the main gate.'])->assertOk();
        $this->assertDatabaseHas('activity_logs', ['subject_type' => 'CampusStatus', 'personnel_id' => $this->admin->getKey(),
            'description' => "Campus Status 'ASCOT Zabali Campus → Evacuate' created"]);

        $this->post(route('admin.settings.schools.store'), ['campus_id' => $this->campus->getKey(), 'school_name' => 'School of Nursing']);
        $this->assertDatabaseHas('activity_logs', ['subject_type' => 'School', 'description' => "School 'School of Nursing' created"]);

        $this->put(route('admin.settings.signatory'), ['noted_by_name' => 'Juan Abordo', 'noted_by_position' => 'DRRM Officer']);
        $this->assertDatabaseHas('activity_logs', ['subject_type' => 'Settings', 'description' => 'Report signatory set to Juan Abordo, DRRM Officer']);
    }

    public function test_old_logs_are_pruned_failed_logins_first(): void
    {
        $row = fn (string $action, int $daysAgo) => tap(ActivityLog::create(['action' => $action, 'description' => $action]))
            ->forceFill(['created_at' => now()->subDays($daysAgo)])->saveQuietly();
        $row('login_failed', 40); $row('login_failed', 5); $row('login', 40); $row('login', 800);

        $this->artisan('model:prune', ['--model' => [ActivityLog::class]])->assertSuccessful();

        $this->assertSame(1, ActivityLog::where('action', 'login_failed')->count());
        $this->assertSame(1, ActivityLog::where('action', 'login')->count());
    }

    public function test_failed_login_hides_most_of_the_typed_email(): void
    {
        $this->post('/login', ['email' => 'juan.cruz@gmail.com', 'password' => 'wrong']);
        $this->assertDatabaseHas('activity_logs', ['action' => 'login_failed', 'description' => 'Failed login attempt for ju***@gmail.com']);
        $this->assertDatabaseMissing('activity_logs', ['description' => 'Failed login attempt for juan.cruz@gmail.com']);
    }

    public function test_changing_or_resetting_a_password_ends_other_sign_ins(): void
    {
        config(['session.driver' => 'database']);
        $session = fn (string $id) => DB::table('sessions')->insert(['id' => $id, 'user_id' => $this->admin->getKey(),
            'ip_address' => '127.0.0.1', 'user_agent' => 'x', 'payload' => '', 'last_activity' => time()]);
        $session('other-phone');
        $this->admin->forceFill(['remember_token' => 'old-token'])->saveQuietly();

        $this->actingAs($this->admin)->post(route('admin.profile.password'), ['current_password' => 'Password1!',
            'password' => 'NewPass#2026', 'password_confirmation' => 'NewPass#2026'])->assertSessionHasNoErrors();
        $this->assertDatabaseMissing('sessions', ['id' => 'other-phone']);
        $this->assertNotSame('old-token', $this->admin->fresh()->remember_token);

        $session('laptop');
        $token = Password::broker()->createToken($this->admin->fresh());
        auth()->logout();
        $this->post(route('password.update'), ['token' => $token, 'email' => 'admin@example.test',
            'password' => 'Another#2026', 'password_confirmation' => 'Another#2026'])->assertSessionHasNoErrors();
        $this->assertDatabaseMissing('sessions', ['id' => 'laptop']);
    }

    public function test_activity_log_records_the_visitor_ip_from_the_configured_header(): void
    {
        $this->post('/login', ['email' => 'admin@example.test', 'password' => 'Password1!'], ['CF-Connecting-IP' => '203.0.113.7']);
        $this->assertDatabaseMissing('activity_logs', ['action' => 'login', 'ip_address' => '203.0.113.7']);
        $this->post('/logout');

        config(['hosting.client_ip_header' => 'CF-Connecting-IP']);
        $this->post('/login', ['email' => 'admin@example.test', 'password' => 'Password1!'], ['CF-Connecting-IP' => '203.0.113.7']);
        $this->assertDatabaseHas('activity_logs', ['action' => 'login', 'ip_address' => '203.0.113.7']);
    }
}
