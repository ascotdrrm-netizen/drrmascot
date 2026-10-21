<?php

namespace Tests\Feature;

use App\Models\Campus;
use App\Models\Personnel;
use App\Models\School;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Tests\TestCase;

class PasswordToggleTest extends TestCase
{
    use RefreshDatabase;

    protected Campus $campus;

    protected School $school;

    protected Personnel $coordinator;

    protected Personnel $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->campus = Campus::create(['campus_name' => 'ASCOT Zabali Campus']);
        // Registration requires a school on the chosen campus.
        $this->school = School::create(['campus_id' => $this->campus->campus_id, 'school_name' => 'School of Engineering']);
        $this->coordinator = Personnel::create(['campus_id' => $this->campus->campus_id, 'full_name' => 'Toggle Coordinator', 'role' => 'Coordinator', 'email' => 'toggle-coord@ascot-test.local', 'password_hash' => Hash::make('OldPassword1!')]);
        $this->admin = Personnel::create(['campus_id' => $this->campus->campus_id, 'full_name' => 'Toggle Admin', 'role' => 'DRRMO Admin', 'email' => 'toggle-admin@ascot-test.local', 'password_hash' => Hash::make('OldPassword1!')]);
    }

    /**
     * Every password input with its name/id/autocomplete, and every toggle
     * button with its type/aria attributes, parsed from the page.
     *
     * @return array{inputs: array<int, array<string, string>>, toggles: array<int, array<string, string>>}
     */
    protected function passwordFields(string $html): array
    {
        $attrs = function (string $tag): array {
            preg_match_all('/([\w:-]+)="([^"]*)"/', $tag, $m, PREG_SET_ORDER);
            return array_column($m, 2, 1);
        };

        preg_match_all('/<input type="password"[^>]*>/', $html, $inputs);
        preg_match_all('/<button[^>]*data-password-toggle[^>]*>/', $html, $toggles);

        return ['inputs' => array_map($attrs, $inputs[0]), 'toggles' => array_map($attrs, $toggles[0])];
    }

    protected function assertToggles(string $html, array $expectedNames, ?array $expectedIds = null): void
    {
        $fields = $this->passwordFields($html);

        $this->assertSame($expectedNames, array_column($fields['inputs'], 'name'), 'password input names');
        $this->assertCount(count($expectedNames), $fields['toggles'], 'one toggle per password field');

        $ids = array_column($fields['inputs'], 'id');
        if ($expectedIds !== null) {
            $this->assertSame($expectedIds, $ids);
        }

        foreach ($fields['toggles'] as $i => $toggle) {
            $this->assertSame('button', $toggle['type'], 'toggle must never submit the form');
            $this->assertSame('Show password', $toggle['aria-label']);
            $this->assertSame('false', $toggle['aria-pressed']);
            // Each button controls exactly its own field (independent toggles).
            $this->assertSame($ids[$i], $toggle['aria-controls']);
        }
        $this->assertSame(count($ids), count(array_unique($ids)), 'input ids are unique');
    }

    public function test_login_page_renders_the_toggle(): void
    {
        $html = $this->get(route('login'))->assertOk()->getContent();

        $this->assertToggles($html, ['password'], ['password']);
        $this->assertStringContainsString('autocomplete="current-password"', $html);
        $this->assertStringContainsString('Forgot password?', $html);
    }

    public function test_register_page_renders_independent_toggles_for_both_fields(): void
    {
        $html = $this->get(route('register'))->assertOk()->getContent();

        $this->assertToggles($html, ['password', 'password_confirmation'], ['password', 'password_confirmation']);
        $this->assertStringContainsString('data-strength-meter data-for="password"', $html);
    }

    public function test_reset_password_page_renders_toggles_for_both_fields(): void
    {
        $token = Password::createToken($this->coordinator);

        $html = $this->get(route('password.reset', ['token' => $token, 'email' => $this->coordinator->email]))->assertOk()->getContent();

        $this->assertToggles($html, ['password', 'password_confirmation'], ['password', 'password_confirmation']);
    }

    public function test_profile_page_renders_toggles_for_current_new_and_confirm(): void
    {
        $html = $this->actingAs($this->coordinator)->get(route('coordinator.profile.show'))->assertOk()->getContent();

        $this->assertToggles($html, ['current_password', 'password', 'password_confirmation'], ['current_password', 'password', 'password_confirmation']);
    }

    public function test_backup_page_renders_toggles_for_its_data_privacy_password_fields(): void
    {
        $html = $this->actingAs($this->admin)->get(route('admin.backup.index'))->assertOk()->getContent();

        // Not set yet: the "Set the Data Privacy Password" card's three fields come first.
        $this->assertToggles($html,
            ['account_password', 'new_data_privacy_password', 'new_data_privacy_password_confirmation', 'data_privacy_password', 'data_privacy_password'],
            ['account_password', 'new_data_privacy_password', 'new_data_privacy_password_confirmation', 'download_data_privacy_password', 'restore_data_privacy_password']);
    }

    public function test_validation_errors_still_display_under_the_field(): void
    {
        // Register: confirmation mismatch -> error under the password field.
        $this->from(route('register'))->post('/register', [
            'first_name' => 'New', 'last_name' => 'Person', 'campus_id' => $this->campus->campus_id, 'email' => 'new-person@ascot-test.local',
            'password' => 'NewPassword1!', 'password_confirmation' => 'Different1!',
        ])->assertRedirect(route('register'))->assertSessionHasErrors('password');

        $html = $this->get(route('register'))->getContent();
        $this->assertStringContainsString('id="password-error"', $html);
        $this->assertStringContainsString('aria-invalid="true"', $html);

        // Profile: wrong current password -> error under the current-password field.
        $this->actingAs($this->coordinator)->from(route('coordinator.profile.show'))->post(route('coordinator.profile.password'), [
            'current_password' => 'WrongPassword1!', 'password' => 'NewPassword1!', 'password_confirmation' => 'NewPassword1!',
        ])->assertSessionHasErrors('current_password');

        $html = $this->actingAs($this->coordinator)->get(route('coordinator.profile.show'))->getContent();
        $this->assertMatchesRegularExpression('/id="current_password-error"[^>]*>Your current password is incorrect\.</', $html);
    }

    public function test_login_register_and_profile_password_change_still_work_end_to_end(): void
    {
        // Login.
        $this->post('/login', ['email' => $this->coordinator->email, 'password' => 'OldPassword1!'])
            ->assertRedirect('/coordinator/dashboard');
        $this->assertAuthenticatedAs($this->coordinator);

        // Profile password change.
        $this->post(route('coordinator.profile.password'), [
            'current_password' => 'OldPassword1!', 'password' => 'Changed1!pass', 'password_confirmation' => 'Changed1!pass',
        ])->assertRedirect(route('coordinator.profile.show'))->assertSessionHasNoErrors();
        $this->assertTrue(Hash::check('Changed1!pass', $this->coordinator->fresh()->password_hash));

        // Register.
        $this->post('/logout');
        $this->post('/register', [
            'first_name' => 'Fresh', 'last_name' => 'Registrant', 'campus_id' => $this->campus->campus_id, 'school_id' => $this->school->school_id, 'email' => 'fresh@ascot-test.local',
            'password' => 'Register1!pw', 'password_confirmation' => 'Register1!pw', 'contact_number' => '09171234567',
        ])->assertRedirect(route('login'));
        $this->assertTrue(Hash::check('Register1!pw', Personnel::where('email', 'fresh@ascot-test.local')->value('password_hash')));
    }

    public function test_password_reset_still_works_end_to_end(): void
    {
        $token = Password::createToken($this->coordinator);

        $this->post('/reset-password', [
            'token' => $token, 'email' => $this->coordinator->email,
            'password' => 'ResetPass1!', 'password_confirmation' => 'ResetPass1!',
        ])->assertRedirect(route('login'));

        $this->assertTrue(Hash::check('ResetPass1!', $this->coordinator->fresh()->password_hash));
    }
}
