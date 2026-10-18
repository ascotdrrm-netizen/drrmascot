<?php

namespace Tests\Feature;

use App\Models\Campus;
use App\Models\Personnel;
use App\Models\School;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * UAT 2026-10-18: Edit profile accepted letters in the contact number.
 * Every form that saves a contact number now takes digits only.
 */
class UatPhoneValidationTest extends TestCase
{
    use RefreshDatabase;

    protected const PASSWORD = 'Str0ng!Passw0rd#2026';

    protected Campus $campus;

    protected School $school;

    protected Personnel $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();

        $this->campus = Campus::create(['campus_name' => 'ASCOT Zabali Campus']);
        $this->school = School::create(['campus_id' => $this->campus->campus_id, 'school_name' => 'School of Engineering']);
        $this->admin = Personnel::create(['campus_id' => $this->campus->campus_id, 'full_name' => 'Phone Admin', 'role' => 'DRRMO Admin',
            'email' => 'phone-admin@ascot-test.local', 'password_hash' => Hash::make(self::PASSWORD), 'contact_number' => '09171234567']);
    }

    public function test_edit_profile_rejects_letters_in_the_contact_number(): void
    {
        $this->actingAs($this->admin)->post(route('admin.profile.update'), ['full_name' => 'Phone Admin', 'contact_number' => '0917abc4567'])
            ->assertSessionHasErrors('contact_number');

        $this->assertSame('09171234567', $this->admin->fresh()->contact_number);
    }

    public function test_edit_profile_accepts_a_digits_only_mobile_number(): void
    {
        $this->actingAs($this->admin)->post(route('admin.profile.update'), ['full_name' => 'Phone Admin', 'contact_number' => '09998887777'])
            ->assertSessionHasNoErrors();

        $this->assertSame('09998887777', $this->admin->fresh()->contact_number);
    }

    public function test_staff_registration_rejects_letters_in_the_contact_number(): void
    {
        $this->post('/register', [
            'first_name' => 'New', 'last_name' => 'Coordinator', 'email' => 'new.coord@example.com',
            'contact_number' => 'call me maybe', 'password' => self::PASSWORD, 'password_confirmation' => self::PASSWORD,
            'campus_id' => $this->campus->campus_id, 'school_id' => $this->school->school_id,
        ])->assertSessionHasErrors('contact_number');
    }

    public function test_reporter_registration_rejects_letters_in_the_contact_number(): void
    {
        $this->post('/app/register', ['contact_number' => '09-ABC-DEFGH'])->assertSessionHasErrors('contact_number');
    }

    public function test_admin_edit_of_a_user_rejects_letters_in_the_contact_number(): void
    {
        $coordinator = Personnel::create(['campus_id' => $this->campus->campus_id, 'full_name' => 'Some Coordinator', 'role' => 'Coordinator',
            'email' => 'some-coord@ascot-test.local', 'password_hash' => Hash::make(self::PASSWORD)]);

        $this->actingAs($this->admin)->put(route('admin.personnel.update', $coordinator), [
            'full_name' => 'Some Coordinator', 'role' => 'Coordinator',
            'campus_id' => $this->campus->campus_id, 'contact_number' => 'none',
        ])->assertSessionHasErrors('contact_number');
    }
}
