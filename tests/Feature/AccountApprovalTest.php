<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Campus;
use App\Models\Incident\Incident;
use App\Models\Personnel;
use App\Models\School;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\View;
use Tests\TestCase;

/**
 * Self-registration no longer gives immediate access: the account waits for
 * the DRRMO Admin to approve it in User Management. Existing accounts (and
 * any the Admin creates) are approved by default.
 */
class AccountApprovalTest extends TestCase
{
    use RefreshDatabase;

    protected const PASSWORD = 'Str0ng!Passw0rd#2026';

    protected Campus $campus;

    protected School $school;

    protected Personnel $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->campus = Campus::create(['campus_name' => 'ASCOT Zabali Campus']);
        $this->school = School::create(['campus_id' => $this->campus->campus_id, 'school_name' => 'School of Engineering']);
        $this->admin = Personnel::create(['campus_id' => $this->campus->campus_id, 'full_name' => 'Approval Admin', 'role' => 'DRRMO Admin', 'email' => 'approval-admin@ascot-test.local', 'password_hash' => Hash::make(self::PASSWORD)]);
    }

    public function test_registering_creates_a_pending_account_and_does_not_sign_in(): void
    {
        $this->register()->assertRedirect(route('login'))
            ->assertSessionHas('status', fn ($s) => str_contains($s, 'waiting for DRRMO approval'));

        $this->assertGuest();
        $person = Personnel::where('email', 'new.coord@example.com')->sole();
        $this->assertFalse($person->is_approved);
        $this->assertSame('Coordinator', $person->role);
        $this->assertTrue(ActivityLog::where('action', 'account_registered')->where('subject_id', $person->personnel_id)->exists());
    }

    public function test_a_pending_account_cannot_sign_in_and_is_told_why(): void
    {
        $this->register();

        $this->post('/login', ['email' => 'new.coord@example.com', 'password' => self::PASSWORD])
            ->assertSessionHasErrors(['email' => 'Your account is waiting for DRRMO approval.']);

        $this->assertGuest();
        $this->get(route('coordinator.dashboard'))->assertRedirect(route('login'));
    }

    public function test_existing_and_admin_created_accounts_are_approved_and_sign_in(): void
    {
        $this->assertTrue($this->admin->fresh()->is_approved, 'the column defaults to approved');

        $this->post('/login', ['email' => 'approval-admin@ascot-test.local', 'password' => self::PASSWORD])->assertRedirect('/admin/dashboard');
        $this->assertAuthenticatedAs($this->admin);
    }

    public function test_pending_accounts_are_listed_first_and_counted_on_the_admin_dashboard(): void
    {
        $this->register();
        $this->register('second.coord@example.com');

        $this->actingAs($this->admin)->get(route('admin.personnel.index'))->assertOk()
            ->assertSeeInOrder(['Waiting for approval (2)', 'New Coordinator', 'Approve', 'Full name'])
            ->assertViewHas('personnel', fn ($list) => $list->pluck('email')->all() === ['approval-admin@ascot-test.local']);

        $this->actingAs($this->admin)->get(route('admin.dashboard'))->assertOk()
            ->assertSee('2 accounts waiting for approval.')
            ->assertSee(route('admin.personnel.index'), false);
    }

    public function test_approving_lets_the_person_sign_in_and_is_logged(): void
    {
        $this->register();
        $person = Personnel::where('email', 'new.coord@example.com')->sole();

        $this->actingAs($this->admin)->post(route('admin.personnel.approve', $person))->assertRedirect(route('admin.personnel.index'));

        $this->assertTrue($person->fresh()->is_approved);
        $log = ActivityLog::where('action', 'account_approved')->sole();
        $this->assertSame($this->admin->personnel_id, $log->personnel_id);
        $this->assertStringContainsString('approved the account of New Coordinator (new.coord@example.com, ASCOT Zabali Campus)', $log->description);

        $this->post(route('logout'));
        $this->post('/login', ['email' => 'new.coord@example.com', 'password' => self::PASSWORD])->assertRedirect('/coordinator/dashboard');
        $this->assertAuthenticatedAs($person->fresh());
    }

    public function test_rejecting_removes_the_registration_and_is_logged(): void
    {
        $this->register();
        $person = Personnel::where('email', 'new.coord@example.com')->sole();

        $this->actingAs($this->admin)->post(route('admin.personnel.reject', $person))->assertRedirect(route('admin.personnel.index'));

        $this->assertNull(Personnel::find($person->personnel_id));
        $this->assertStringContainsString('rejected the registration of New Coordinator', ActivityLog::where('action', 'account_rejected')->sole()->description);
    }

    public function test_approve_and_reject_only_act_on_pending_accounts_and_only_for_the_admin(): void
    {
        $this->register();
        $person = Personnel::where('email', 'new.coord@example.com')->sole();
        $coordinator = Personnel::create(['campus_id' => $this->campus->campus_id, 'full_name' => 'Existing Coord', 'role' => 'Coordinator', 'email' => 'existing@ascot-test.local', 'password_hash' => Hash::make(self::PASSWORD)]);

        $this->actingAs($coordinator)->post(route('admin.personnel.approve', $person))->assertForbidden();
        $this->actingAs($coordinator)->post(route('admin.personnel.reject', $person))->assertForbidden();
        $this->assertFalse($person->fresh()->is_approved);

        // An approved account can't be "rejected" (deleted) through this action.
        $this->actingAs($this->admin)->post(route('admin.personnel.reject', $coordinator))->assertNotFound();
        $this->actingAs($this->admin)->post(route('admin.personnel.approve', $coordinator))->assertNotFound();
        $this->assertNotNull($coordinator->fresh());
    }

    public function test_a_pending_account_gets_no_sms_alert_and_is_not_in_the_directory_pdf(): void
    {
        $this->register(contact: '09170000001');
        $incident = Incident::create(['campus_id' => $this->campus->campus_id, 'hazard_type' => 'Fire', 'date_occurred' => '2026-09-05', 'status' => 'Active', 'description' => 'x']);

        $this->actingAs($this->admin)->get(route('admin.incidents.alerts.create', $incident))->assertOk()
            ->assertViewHas('recipientCount', 0);

        $captured = [];
        View::composer('personnel.report', function ($v) use (&$captured) { $captured = $v->getData(); });
        $this->actingAs($this->admin)->get(route('admin.personnel.report'))->assertOk();
        $this->assertSame(1, $captured['totalCount']);
    }

    protected function register(string $email = 'new.coord@example.com', string $contact = '09171234567')
    {
        return $this->post('/register', [
            'first_name' => 'New', 'last_name' => $email === 'new.coord@example.com' ? 'Coordinator' : 'Second', 'email' => $email,
            'contact_number' => $contact, 'password' => self::PASSWORD, 'password_confirmation' => self::PASSWORD,
            'campus_id' => $this->campus->campus_id, 'school_id' => $this->school->school_id,
        ]);
    }
}
