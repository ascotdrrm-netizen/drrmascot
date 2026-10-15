<?php

namespace Tests\Feature;

use App\Models\Campus;
use App\Models\Personnel;
use App\Support\Files;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Uploaded record files live on one private disk (App\Support\Files —
 * Supabase Storage in use) and are reached only through a signed, expiring
 * link, by a signed-in user. Nothing is served from a public folder.
 */
class RecordFilesTest extends TestCase
{
    use RefreshDatabase;

    protected Personnel $user;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('records');
        Storage::fake('public');
        Storage::fake('local');
        $campus = Campus::create(['campus_name' => 'ASCOT Zabali Campus']);
        $this->user = Personnel::create(['campus_id' => $campus->campus_id, 'full_name' => 'File Viewer', 'role' => 'Coordinator', 'email' => 'viewer@ascot-test.local', 'password_hash' => Hash::make('password')]);
    }

    public function test_a_signed_link_shows_the_photo_to_a_signed_in_user(): void
    {
        Files::disk()->put('incidents/fire.jpg', 'JPEGBYTES');
        $this->actingAs($this->user);
        $url = Files::url('incidents/fire.jpg');

        $this->assertStringContainsString('/files/incidents/fire.jpg?', $url);
        $response = $this->actingAs($this->user)->get($url)->assertOk();
        $this->assertSame('JPEGBYTES', $response->streamedContent());
        $response->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->assertStringContainsString('private', $response->headers->get('Cache-Control'));
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
    }

    public function test_without_a_signature_without_sign_in_or_after_it_expires_it_is_refused(): void
    {
        Files::disk()->put('incidents/fire.jpg', 'JPEGBYTES');
        $this->actingAs($this->user);
        $url = Files::url('incidents/fire.jpg');

        $this->actingAs($this->user)->get('/files/incidents/fire.jpg')->assertForbidden();
        $this->actingAs($this->user)->get(str_replace('fire.jpg', 'other.jpg', $url))->assertForbidden();

        auth()->logout();
        $this->get($url)->assertRedirect(route('login'));

        $this->travel(Files::SIGNED_MINUTES + 1)->minutes();
        $this->actingAs($this->user)->get($url)->assertForbidden();
    }

    public function test_paths_outside_the_record_folders_are_never_served(): void
    {
        Files::disk()->put('backups/secret.zip', 'ZIP');
        Files::disk()->put('incidents/fire.jpg', 'JPEGBYTES');
        $this->actingAs($this->user);

        foreach (['backups/secret.zip', 'incidents/../backups/secret.zip', 'incidents/missing.jpg'] as $path) {
            $this->actingAs($this->user)->get(Files::url($path))->assertNotFound();
        }
    }

    public function test_anything_but_a_photo_or_pdf_is_always_downloaded_never_shown(): void
    {
        Files::disk()->put('attachments/page.html', '<script>alert(1)</script>');
        $this->actingAs($this->user);

        $response = $this->actingAs($this->user)->get(Files::url('attachments/page.html'))->assertOk();
        $this->assertStringStartsWith('attachment;', $response->headers->get('Content-Disposition'));
    }

    public function test_a_copied_link_cannot_be_used_by_another_signed_in_user(): void
    {
        Files::disk()->put('incidents/fire.jpg', 'JPEGBYTES');
        $this->actingAs($this->user);
        $url = Files::url('incidents/fire.jpg');

        $other = Personnel::create([
            'campus_id' => $this->user->campus_id,
            'full_name' => 'Other Viewer',
            'role' => 'Coordinator',
            'email' => 'other@ascot-test.local',
            'password_hash' => Hash::make('password'),
        ]);

        $this->actingAs($other)->get($url)->assertForbidden();
    }

    public function test_files_from_before_supabase_are_copied_into_the_records_disk_once(): void
    {
        Storage::disk('public')->put('buildings/gym.jpg', 'GYM');
        Storage::disk('local')->put('drill-attachments/4/documentation/a.pdf', 'PDF');
        Storage::disk('local')->put('backups/old.zip', 'NOT A RECORD FILE');

        $this->artisan('files:move-to-records')->assertExitCode(0)->expectsOutputToContain('2 copied, 0 already there');

        $this->assertSame('GYM', Files::get('buildings/gym.jpg'));
        $this->assertSame('PDF', Files::get('drill-attachments/4/documentation/a.pdf'));
        Files::disk()->assertMissing('backups/old.zip');
        Storage::disk('public')->assertExists('buildings/gym.jpg'); // the original stays

        $this->artisan('files:move-to-records')->assertExitCode(0)->expectsOutputToContain('0 copied, 2 already there');
    }

    public function test_migration_refuses_to_replace_a_different_file_with_the_same_size(): void
    {
        Storage::disk('public')->put('buildings/gym.jpg', 'OLD');
        Files::disk()->put('buildings/gym.jpg', 'NEW');

        $this->artisan('files:move-to-records')->assertExitCode(1);
        $this->assertSame('NEW', Files::get('buildings/gym.jpg'));
    }
}
