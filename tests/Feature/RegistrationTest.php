<?php

namespace Tests\Feature;

use App\Models\Campus;
use App\Models\Personnel;
use App\Models\School;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RegistrationTest extends TestCase
{
    use RefreshDatabase;

    protected Campus $campus;

    protected School $school;

    protected function setUp(): void
    {
        parent::setUp();

        $this->campus = Campus::create(['campus_name' => 'ASCOT Zabali Campus']);
        // Registration requires a school on the chosen campus.
        $this->school = School::create(['campus_id' => $this->campus->campus_id, 'school_name' => 'School of Engineering']);
    }

    public function test_register_form_has_no_role_field(): void
    {
        $response = $this->get(route('register'));

        $response->assertOk();
        // No selectable role input at all — the page may still mention
        // "DRRMO Admin" in explanatory prose about how those accounts are
        // created, but never as an <option> a visitor can pick.
        $response->assertDontSee('name="role"', false);
        $response->assertDontSee('<option value="DRRMO Admin"', false);
    }

    public function test_self_registration_always_creates_a_coordinator(): void
    {
        $response = $this->post('/register', [
            'first_name' => 'New', 'last_name' => 'Coordinator',
            'campus_id' => $this->campus->campus_id,
            'school_id' => $this->school->school_id,
            'email' => 'new-coord@ascot-test.local',
            'password' => 'Password123!',
            'password_confirmation' => 'Password123!',
            'contact_number' => '09171234567',
        ]);

        $response->assertRedirect(route('login'));

        $this->assertDatabaseHas('personnel', [
            'email' => 'new-coord@ascot-test.local',
            'role' => 'Coordinator',
        ]);
    }

    /**
     * The critical regression test: even if a request is crafted to smuggle
     * a role field in (bypassing the UI entirely), the controller must
     * never read it — role is hardcoded server-side, not request-driven.
     */
    public function test_self_registration_ignores_a_tampered_role_field_and_still_creates_a_coordinator(): void
    {
        $response = $this->post('/register', [
            'first_name' => 'Attempted', 'last_name' => 'Admin',
            'campus_id' => $this->campus->campus_id,
            'school_id' => $this->school->school_id,
            'role' => 'DRRMO Admin',
            'email' => 'attempted-admin@ascot-test.local',
            'password' => 'Password123!',
            'password_confirmation' => 'Password123!',
            'contact_number' => '09171234567',
        ]);

        $response->assertRedirect(route('login'));

        $created = Personnel::where('email', 'attempted-admin@ascot-test.local')->firstOrFail();
        $this->assertSame('Coordinator', $created->role);
        $this->assertNotSame('DRRMO Admin', $created->role);
        // Neither the Admin role nor a session: the account waits for approval.
        $this->assertGuest();
        $this->assertFalse($created->is_approved);
    }

    /**
     * Thesis panel (Engineering): the contact number is required — emergency
     * SMS alerts reach people by it — and the form says so with the asterisk
     * (the label gets .is-required from the input's native required).
     */
    public function test_the_contact_number_is_required_and_marked_on_the_form(): void
    {
        $this->get(route('register'))->assertOk()
            ->assertSee('<input type="tel" id="contact_number" name="contact_number"', false)
            ->assertSee('maxlength="13" required', false);

        $this->from('/register')->post('/register', [
            'first_name' => 'No', 'last_name' => 'Number', 'email' => 'no.number@example.com',
            'password' => 'Password123!', 'password_confirmation' => 'Password123!',
            'campus_id' => $this->campus->campus_id, 'school_id' => $this->school->school_id,
        ])->assertRedirect('/register')
            ->assertSessionHasErrors(['contact_number' => 'Please enter your contact number: emergency SMS alerts are sent to it.']);

        $this->assertDatabaseMissing('personnel', ['email' => 'no.number@example.com']);
    }
}
