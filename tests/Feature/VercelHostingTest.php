<?php

namespace Tests\Feature;

use App\Models\Backup;
use App\Models\Broadcast;
use App\Models\Campus;
use App\Models\Personnel;
use App\Services\BroadcastSender;
use App\Services\DirectUploads;
use App\Support\Backup\BackupArchive;
use App\Support\Backup\StoredBackups;
use App\Support\Files;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class VercelHostingTest extends TestCase
{
    use RefreshDatabase;

    private Personnel $person;

    protected function setUp(): void
    {
        parent::setUp();
        config(['hosting.direct_uploads' => true, 'hosting.serverless' => true, 'filesystems.records_disk' => 's3']);
        Storage::fake('s3');
        Storage::disk('s3')->buildTemporaryUrlsUsing(fn ($path, $expires, $options) => 'https://storage.example.test/'.rawurlencode($path).'?'.http_build_query($options));
        $campus = Campus::create(['campus_name' => 'Zabali']);
        $this->person = Personnel::create(['campus_id' => $campus->getKey(), 'full_name' => 'Admin', 'email' => 'admin@example.test', 'role' => 'DRRMO Admin', 'password_hash' => Hash::make('Password1!')]);
        $this->person->forceFill(['is_approved' => true, 'email_verified_at' => now()])->save();
        Route::post('/test-direct-upload', function (Request $request) {
            $request->validate(['photos' => ['required', 'array', 'max:3'], 'photos.*' => ['required', 'image', 'max:10240']]);

            return response()->json(['path' => Files::store($request->file('photos.0'), 'incidents')]);
        })->middleware(['web', 'auth']);
    }

    private function ticket(array $overrides = [], string $bytes = ''): string
    {
        $bytes = $bytes ?: base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jZ1sAAAAASUVORK5CYII=');
        $data = array_replace(['key' => '_staging/'.Str::uuid(), 'owner' => $this->person->getKey(), 'campus' => $this->person->campus_id,
            'expires' => now()->addHour()->timestamp, 'target' => '/test-direct-upload', 'method' => 'POST',
            'field' => 'photos.0', 'size' => strlen($bytes), 'name' => 'incident.png'], $overrides);
        Storage::disk('s3')->put($data['key'], $bytes);

        return Crypt::encryptString(json_encode($data));
    }

    public function test_staged_photo_passes_real_image_validation_and_is_saved_privately(): void
    {
        $this->actingAs($this->person)->postJson('/test-direct-upload', ['_staged_uploads' => [$this->ticket()]])
            ->assertOk()->assertJsonPath('path', fn ($path) => str_starts_with($path, 'incidents/'));
        $this->assertCount(1, Storage::disk('s3')->allFiles('incidents'));
    }

    public function test_ticket_cannot_be_tampered_with_reassigned_or_used_on_another_form(): void
    {
        foreach ([['owner' => $this->person->getKey() + 1], ['campus' => $this->person->campus_id + 1],
            ['target' => '/admin/incidents'], ['method' => 'PATCH'], ['expires' => now()->subMinute()->timestamp]] as $overrides) {
            $this->actingAs($this->person)->postJson('/test-direct-upload', ['_staged_uploads' => [$this->ticket($overrides)]])->assertUnprocessable();
        }
        $this->actingAs($this->person)->postJson('/test-direct-upload', ['_staged_uploads' => ['forged']])->assertUnprocessable();
        $this->assertEmpty(Storage::disk('s3')->allFiles('incidents'));
    }

    public function test_uploaded_bytes_must_match_the_declared_size_and_actual_image_type(): void
    {
        $this->actingAs($this->person)->postJson('/test-direct-upload', ['_staged_uploads' => [$this->ticket(['size' => 5])]])->assertUnprocessable();
        $this->actingAs($this->person)->postJson('/test-direct-upload', ['_staged_uploads' => [$this->ticket([], '<script>fake photo</script>')]])->assertUnprocessable()->assertJsonValidationErrors('photos.0');
    }

    public function test_unapproved_or_signed_out_accounts_cannot_stage_or_attach_files(): void
    {
        $this->postJson('/uploads/sign', [])->assertUnauthorized();
        $this->person->forceFill(['is_approved' => false])->save();
        $this->actingAs($this->person)->postJson('/uploads/sign', [])->assertForbidden();
        $this->actingAs($this->person)->postJson('/test-direct-upload', ['_staged_uploads' => [$this->ticket()]])->assertForbidden();
    }

    public function test_signer_rejects_large_files_and_nonlocal_targets_before_storage_access(): void
    {
        $data = ['name' => 'photo.png', 'size' => 11 * 1024 * 1024, 'target' => '//evil.test', 'method' => 'POST', 'field' => 'photos.0'];
        $this->actingAs($this->person)->postJson('/uploads/sign', $data)->assertUnprocessable()->assertJsonValidationErrors(['size', 'target']);
    }

    public function test_s3_signing_keeps_expected_size_in_the_ticket_without_unsupported_checksums(): void
    {
        $disk = Storage::build(['driver' => 's3', 'key' => 'demo-access', 'secret' => 'demo-secret',
            'region' => 'ap-southeast-1', 'bucket' => 'drrms', 'endpoint' => 'https://storage.example.test/storage/v1/s3',
            'use_path_style_endpoint' => true, 'request_checksum_calculation' => 'when_required', 'response_checksum_validation' => 'when_required']);
        Storage::set('s3', $disk);
        $data = ['name' => 'photo.jpg', 'size' => 12345, 'field' => 'photos.0', 'target' => '/test-direct-upload', 'method' => 'POST'];
        $signed = app(DirectUploads::class)->sign($this->person, $data);
        $this->assertStringContainsString('X-Amz-Signature=', $signed['url']);
        $this->assertStringNotContainsString('x-amz-checksum-', strtolower($signed['url']));
        $ticket = json_decode(Crypt::decryptString($signed['ticket']), true);
        $this->assertSame($this->person->getKey(), $ticket['owner']);
        $this->assertSame('/test-direct-upload', $ticket['target']);
        $this->assertSame(12345, $ticket['size']);
    }

    public function test_cloud_file_redirect_still_requires_the_original_viewer_and_signature(): void
    {
        Files::disk()->put('incidents/demo.png', 'photo');
        $this->actingAs($this->person);
        $url = Files::url('incidents/demo.png');
        $response = $this->get($url)->assertRedirect();
        $this->assertStringStartsWith('https://storage.example.test/', $response->headers->get('Location'));
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $this->get(str_replace('demo.png', 'other.png', $url))->assertForbidden();
        auth()->logout();
        $this->get($url)->assertRedirect(route('login'));
    }

    public function test_expired_staging_is_removed_but_recent_and_record_files_are_kept(): void
    {
        $disk = Storage::disk('s3');
        $disk->put('_staging/old', 'old');
        touch($disk->path('_staging/old'), now()->subHours(3)->timestamp);
        $disk->put('_staging/new', 'new');
        $disk->put('incidents/keep.jpg', 'keep');
        $this->assertSame(1, app(DirectUploads::class)->cleanExpired());
        $disk->assertMissing('_staging/old');
        $disk->assertExists(['_staging/new', 'incidents/keep.jpg']);
    }

    public function test_maintenance_requires_a_long_matching_secret(): void
    {
        config(['hosting.cron_secret' => str_repeat('a', 40)]);
        $this->getJson('/internal/maintenance')->assertForbidden();
        $this->withToken(str_repeat('b', 40))->getJson('/internal/maintenance')->assertForbidden();
        $this->withToken(str_repeat('a', 40))->getJson('/internal/maintenance')->assertOk()->assertJsonPath('worked', false);
    }

    public function test_coordinator_work_is_scoped_and_one_delivery_is_processed_per_request(): void
    {
        $this->person->update(['role' => 'Coordinator']);
        $away = Campus::create(['campus_name' => 'Bazal']);
        foreach ([$away->getKey(), $this->person->campus_id] as $campus) {
            $broadcast = Broadcast::create(['campus_id' => $campus, 'created_by' => $this->person->getKey(), 'client_key' => (string) Str::uuid(),
                'title' => 'Notice', 'body' => 'Stay safe', 'status' => 'Queued', 'published_at' => now()]);
            $broadcast->deliveries()->create(['channel' => 'email', 'destination' => 'a@example.test', 'personnel_id' => $this->person->getKey(), 'status' => 'Pending']);
        }
        $sender = $this->mock(BroadcastSender::class);
        $sender->shouldReceive('refreshStatus')->once()->with(Broadcast::orderByDesc('broadcast_id')->first()->getKey());
        $sender->shouldReceive('hasPending')->once()->andReturn(true);
        $sender->shouldReceive('sendNext')->once();
        $this->actingAs($this->person)->postJson('/broadcasts/work')->assertOk()->assertJsonPath('worked', true);
    }

    public function test_production_default_seeder_cannot_create_a_known_password(): void
    {
        $this->app->instance('env', 'production');
        try {
            (new DatabaseSeeder)->run();
            $this->fail('Production must reject the development seeder.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('app:create-admin', $exception->getMessage());
        }
        $this->assertDatabaseMissing('personnel', ['email' => 'admin@ascot.edu.ph']);
    }

    public function test_cloud_backup_can_be_downloaded_and_restored_without_a_local_permanent_copy(): void
    {
        config(['backup.disk' => 's3', 'privacy.data_privacy_password_hash' => Hash::make('privacy-secret')]);
        $this->actingAs($this->person)->post(route('admin.backup.download'), ['data_privacy_password' => 'privacy-secret'])->assertRedirect();
        $backup = Backup::firstOrFail();
        Storage::disk('s3')->assertExists($backup->storage_path);
        $temporary = StoredBackups::withLocalFile($backup->storage_path, function ($path) {
            $this->assertNotNull(BackupArchive::read($path));

            return $path;
        });
        $this->assertFileDoesNotExist($temporary);
        $this->actingAs($this->person)->post(route('admin.backup.stored.restore', $backup), [
            'confirmation' => 'RESTORE', 'data_privacy_password' => 'privacy-secret',
        ])->assertRedirect()->assertSessionHasNoErrors();
        Storage::disk('s3')->assertExists($backup->storage_path);
    }

    public function test_a_backup_over_the_buckets_file_limit_is_saved_in_parts_and_joined_back(): void
    {
        config(['backup.disk' => 's3', 'backup.max_file_bytes' => 1024, 'privacy.data_privacy_password_hash' => Hash::make('privacy-secret')]);
        $response = $this->actingAs($this->person)->post(route('admin.backup.download'), ['data_privacy_password' => 'privacy-secret'])->assertOk();
        $backup = Backup::firstOrFail();
        $parts = StoredBackups::parts($backup->storage_path);
        $this->assertGreaterThan(1, count($parts));
        Storage::disk('s3')->assertMissing($backup->storage_path);
        foreach ($parts as $part) {
            $this->assertLessThanOrEqual(StoredBackups::partBytes(), Storage::disk('s3')->size($part));
        }

        $joined = implode('', array_map(fn ($part) => Storage::disk('s3')->get($part), $parts));
        $this->assertSame($joined, $response->streamedContent());
        $this->assertSame(strlen($joined), (int) $backup->file_size);
        StoredBackups::withLocalFile($backup->storage_path, fn ($path) => $this->assertNotNull(BackupArchive::read($path)));
        $this->actingAs($this->person)->post(route('admin.backup.stored.restore', $backup), [
            'confirmation' => 'RESTORE', 'data_privacy_password' => 'privacy-secret',
        ])->assertRedirect()->assertSessionHasNoErrors();

        StoredBackups::delete($backup->storage_path);
        $this->assertEmpty(Storage::disk('s3')->allFiles('backups'));
    }

    public function test_clear_all_records_goes_ahead_when_its_copy_is_over_the_buckets_file_limit(): void
    {
        config(['backup.disk' => 's3', 'backup.max_file_bytes' => 1024, 'privacy.data_privacy_password_hash' => Hash::make('privacy-secret')]);
        Storage::disk('s3')->put('buildings/photo.jpg', random_bytes(4096));
        DB::table('buildings')->insert(['campus_id' => $this->person->campus_id, 'building_name' => 'Gym', 'risk_level' => 'High', 'photo_path' => 'buildings/photo.jpg']);

        $this->actingAs($this->person)->from(route('admin.backup.clear-records'))->post(route('admin.backup.clear-records.run'), [
            'data_privacy_password' => 'privacy-secret', 'confirmation' => 'CLEAR ALL RECORDS',
        ])->assertRedirect()->assertSessionHasNoErrors()->assertSessionHas('cleared');

        $backup = Backup::firstOrFail();
        $this->assertGreaterThan(1, count(StoredBackups::parts($backup->storage_path)));
        $this->assertSame(0, DB::table('buildings')->count());
        Storage::disk('s3')->assertMissing('buildings/photo.jpg');
    }

    public function test_large_generated_pdf_is_downloaded_from_private_storage(): void
    {
        Route::get('/test-large-pdf', fn () => response(str_repeat('pdf', 1024 * 1024), 200, [
            'Content-Type' => 'application/pdf', 'Content-Disposition' => 'attachment; filename="report.pdf"',
        ]))->middleware(['web', 'auth']);
        $response = $this->actingAs($this->person)->get('/test-large-pdf')->assertRedirect();
        $this->assertStringStartsWith('https://storage.example.test/', $response->headers->get('Location'));
        $this->assertCount(1, Storage::disk('s3')->allFiles('_exports'));
    }

    public function test_first_admin_command_hashes_password_and_initializes_catalog_once(): void
    {
        $this->person->update(['role' => 'Coordinator']);
        $this->artisan('app:create-admin')
            ->expectsQuestion('Administrator full name', 'Demo Administrator')
            ->expectsQuestion('Administrator email', 'DEMO@EXAMPLE.TEST')
            ->expectsQuestion('Password (8+ characters, uppercase, number and symbol)', 'StrongPassword1!')
            ->expectsQuestion('Confirm password', 'StrongPassword1!')->assertSuccessful();
        $admin = Personnel::where('email', 'demo@example.test')->firstOrFail();
        $this->assertTrue(Hash::check('StrongPassword1!', $admin->password_hash));
        $this->assertTrue($admin->is_approved);
        $this->assertNotNull($admin->email_verified_at);
        $this->assertDatabaseHas('schools', ['school_name' => 'School of Information Technology']);
        $this->artisan('app:create-admin')->expectsOutputToContain('An administrator already exists')->assertFailed();
        $this->assertSame(1, Personnel::where('role', 'DRRMO Admin')->count());
    }

    public function test_deploy_fills_campuses_and_schools_so_registration_has_options(): void
    {
        $this->artisan('app:ensure-campuses')->assertSuccessful();
        $this->artisan('app:ensure-campuses')->assertSuccessful();
        $this->assertSame(1, Campus::where('campus_name', 'ASCOT Casiguran Campus')->count());
        $this->assertDatabaseHas('schools', ['school_name' => 'School of Information Technology']);
        auth()->logout();
        $this->get('/register')->assertOk()->assertSee('ASCOT Bazal Campus');
    }

    public function test_demo_accounts_get_random_passwords_and_can_sign_in_once_created(): void
    {
        // Use the real campus name, as on a fresh live database.
        $this->person->campus->update(['campus_name' => 'ASCOT Zabali Campus']);
        // A throwaway storage folder: the real storage/app/private/demo-accounts.txt
        // holds the owner's live demo password and must never be touched by tests.
        $this->app->useStoragePath($storage = sys_get_temp_dir().'/drrms-demo-test-'.Str::random(8));
        mkdir($storage.'/app/private', 0777, true);
        $file = storage_path('app/private/demo-accounts.txt');
        $this->artisan('app:create-demo-accounts')->expectsConfirmation('Create demo accounts on the testing database?', 'yes')
            ->expectsOutputToContain('demo.reporter@drrms.test')->assertSuccessful();

        $reporter = Personnel::where('email', 'demo.reporter@drrms.test')->firstOrFail();
        $this->assertTrue($reporter->is_approved);
        $this->assertNotNull($reporter->email_verified_at);
        $this->assertNotNull($reporter->validated_by_coordinator_at);
        $this->assertNotNull($reporter->school_id);
        $this->assertSame(3, Personnel::where('role', 'Coordinator')->where('email', 'like', 'demo.%')->count());
        preg_match('/demo\.reporter@drrms\.test\t(\S+)/', file_get_contents($file), $match);
        $this->assertFalse(Hash::check('changeme123', $reporter->password_hash));
        $this->post('/login', ['email' => 'demo.reporter@drrms.test', 'password' => $match[1]])->assertRedirect('/app');

        $this->artisan('app:create-demo-accounts')->expectsConfirmation('Create demo accounts on the testing database?', 'yes')
            ->expectsOutputToContain('Nothing changed')->assertSuccessful();
        @unlink($file);
        @rmdir($storage.'/app/private'); @rmdir($storage.'/app'); @rmdir($storage);
    }

    public function test_host_start_up_creates_demo_accounts_with_the_dashboard_password(): void
    {
        $this->person->campus->update(['campus_name' => 'ASCOT Zabali Campus']);
        config(['hosting.demo_accounts_password' => 'weak']);
        $this->artisan('app:create-demo-accounts', ['--from-env' => true])->assertFailed();
        $this->assertDatabaseMissing('personnel', ['email' => 'demo.admin@drrms.test']);

        config(['hosting.demo_accounts_password' => 'Chosen#Pass2026']);
        $this->artisan('app:create-demo-accounts', ['--from-env' => true])
            ->expectsOutputToContain('demo.admin@drrms.test')->doesntExpectOutputToContain('Chosen#Pass2026')->assertSuccessful();
        auth()->logout();
        $this->post('/login', ['email' => 'demo.admin@drrms.test', 'password' => 'Chosen#Pass2026'])->assertRedirect('/admin/dashboard');
        $this->post('/logout');
        $this->post('/app/login', ['email' => 'demo.reporter@drrms.test', 'password' => 'Chosen#Pass2026'])->assertRedirect('/app');
        $this->post('/logout');

        // A lost password is replaced by changing it in the dashboard.
        config(['hosting.demo_accounts_password' => 'Replaced#Pass2027']);
        $this->artisan('app:create-demo-accounts', ['--from-env' => true])->expectsOutputToContain('demo.admin@drrms.test')->assertSuccessful();
        $this->post('/login', ['email' => 'demo.admin@drrms.test', 'password' => 'Replaced#Pass2027'])->assertRedirect('/admin/dashboard');
        $this->artisan('app:create-demo-accounts', ['--from-env' => true])->expectsOutputToContain('Nothing changed')->assertSuccessful();
    }
}
