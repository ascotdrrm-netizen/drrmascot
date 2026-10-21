<?php

namespace Tests\Feature;

use App\Models\Campus;
use App\Models\Personnel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * UAT 2026-10-21: a Reporter (student or faculty/staff) changes their own
 * photo and mobile number on the Me screen. Name, ID, campus and email stay.
 */
class ReporterProfileTest extends TestCase
{
    use RefreshDatabase;

    private Personnel $reporter;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('records');
        $campus = Campus::create(['campus_name' => 'ASCOT Zabali Campus']);
        $this->reporter = Personnel::create(['campus_id' => $campus->campus_id, 'full_name' => 'Ana Santos', 'role' => 'Reporter',
            'email' => 'ana@example.test', 'password_hash' => Hash::make('Password123!'), 'contact_number' => '09171234567']);
        $this->reporter->forceFill(['is_approved' => true, 'email_verified_at' => now(), 'phone_verified_at' => now()])->save();
    }

    public function test_the_me_screen_offers_the_edit_form(): void
    {
        $this->actingAs($this->reporter)->get(route('app.me'))->assertOk()
            ->assertSee('Edit profile')->assertSee(route('app.me.update'), false)
            ->assertSee('value="09171234567"', false);
    }

    public function test_a_reporter_changes_their_photo_and_keeps_a_verified_number(): void
    {
        $this->actingAs($this->reporter)->post(route('app.me.update'), [
            'contact_number' => '09171234567',
            'photo' => UploadedFile::fake()->image('me.jpg', 300, 300),
        ])->assertRedirect(route('app.me'))->assertSessionHas('status', 'Profile saved.');

        $person = $this->reporter->fresh();
        $this->assertNotNull($person->photo_path);
        $this->assertStringStartsWith('personnel/', $person->photo_path);
        Storage::disk('records')->assertExists($person->photo_path);
        $this->assertNotNull($person->phone_verified_at, 'the same number stays verified');

        // A second photo replaces the first file.
        $first = $person->photo_path;
        $this->actingAs($person)->post(route('app.me.update'), [
            'contact_number' => '09171234567', 'photo' => UploadedFile::fake()->image('new.png'),
        ]);
        Storage::disk('records')->assertMissing($first);
        $this->actingAs($person)->get(route('app.me'))->assertSee('Your profile photo');
    }

    public function test_a_new_number_must_be_verified_again(): void
    {
        $this->actingAs($this->reporter)->post(route('app.me.update'), ['contact_number' => '09998887777'])
            ->assertRedirect(route('app.me'))
            ->assertSessionHas('status', fn ($s) => str_contains($s, 'Verify your new number'));

        $person = $this->reporter->fresh();
        $this->assertSame('09998887777', $person->contact_number);
        $this->assertNull($person->phone_verified_at);
        $this->assertSame('Ana Santos', $person->full_name);
        $this->assertSame('ana@example.test', $person->email);
    }

    public function test_bad_input_is_refused_and_nothing_changes(): void
    {
        $this->actingAs($this->reporter)->from(route('app.me'))->post(route('app.me.update'), [
            'contact_number' => '12345',
            'photo' => UploadedFile::fake()->create('virus.exe', 10),
        ])->assertRedirect(route('app.me'))->assertSessionHasErrors(['contact_number', 'photo']);

        $person = $this->reporter->fresh();
        $this->assertSame('09171234567', $person->contact_number);
        $this->assertNull($person->photo_path);
    }

    public function test_only_reporters_use_this_form(): void
    {
        $staff = Personnel::create(['campus_id' => $this->reporter->campus_id, 'full_name' => 'Staff Test', 'role' => 'Coordinator',
            'email' => 'staff@example.test', 'password_hash' => Hash::make('Password123!')]);

        $this->actingAs($staff)->post(route('app.me.update'), ['contact_number' => '09171234567'])->assertForbidden();
        auth()->logout();
        $this->post(route('app.me.update'), ['contact_number' => '09171234567'])->assertRedirect();
        $this->assertSame('09171234567', $this->reporter->fresh()->contact_number);
    }
}
