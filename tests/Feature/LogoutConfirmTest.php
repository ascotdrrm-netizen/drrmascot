<?php

namespace Tests\Feature;

use App\Models\Campus;
use App\Models\Personnel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Every Logout control goes through the one shared confirmation dialog
 * (components/confirm-dialog). The dialog is client-side only: a direct
 * POST to /logout still logs out and redirects to "/".
 */
class LogoutConfirmTest extends TestCase
{
    use RefreshDatabase;

    protected Personnel $admin;
    protected Personnel $coordinator;

    protected function setUp(): void
    {
        parent::setUp();
        $campus = Campus::create(['campus_name' => 'ASCOT Zabali Campus']);
        $this->admin = Personnel::create(['campus_id' => $campus->campus_id, 'full_name' => 'Logout Admin', 'role' => 'DRRMO Admin', 'email' => 'logout-admin@ascot-test.local', 'password_hash' => Hash::make('password')]);
        $this->coordinator = Personnel::create(['campus_id' => $campus->campus_id, 'full_name' => 'Logout Coordinator', 'role' => 'Coordinator', 'email' => 'logout-coord@ascot-test.local', 'password_hash' => Hash::make('password')]);
    }

    /** Asserts one dialog and that every logout form on the page opens it. */
    protected function assertLogoutGoesThroughTheDialog(string $html, int $expectedLogoutForms): void
    {
        $this->assertSame(1, substr_count($html, 'id="confirm-modal"'), 'exactly one shared dialog');
        $this->assertMatchesRegularExpression('/role="dialog"\s+aria-modal="true"\s+aria-labelledby="confirm-modal-message"/', $html);
        $this->assertStringContainsString('id="confirm-modal-title"', $html, 'title element the JS points aria-labelledby at');
        $this->assertStringContainsString('id="confirm-modal-cancel"', $html);
        $this->assertStringContainsString('id="confirm-modal-confirm"', $html);

        preg_match_all('/<form[^>]*action="[^"]*\/logout"[^>]*>/', $html, $forms);
        $this->assertCount($expectedLogoutForms, $forms[0], 'logout forms on the page');
        foreach ($forms[0] as $form) {
            $this->assertStringContainsString('method="POST"', $form);
            $this->assertStringContainsString('data-confirm-form="You\'ll need to sign in again to continue."', $form);
            $this->assertStringContainsString('data-confirm-title="Log out?"', $form);
            $this->assertStringContainsString('data-confirm-button="Log out"', $form);
            $this->assertStringContainsString('data-confirm-focus="cancel"', $form);
        }
    }

    public function test_dialog_renders_on_authenticated_pages_for_both_roles(): void
    {
        foreach ([[$this->admin, ['admin.dashboard', 'admin.incidents.index', 'admin.personnel.index']],
                  [$this->coordinator, ['coordinator.dashboard', 'coordinator.drills.index', 'coordinator.profile.show']]] as [$user, $routes]) {
            foreach ($routes as $route) {
                $html = $this->actingAs($user)->get(route($route))->assertOk()->getContent();
                // Sidebar = mobile drawer: one form serves both.
                $this->assertLogoutGoesThroughTheDialog($html, 1);
            }
        }
    }

    public function test_dialog_renders_on_the_landing_page_when_signed_in(): void
    {
        foreach ([$this->admin, $this->coordinator] as $user) {
            $html = $this->actingAs($user)->get(route('landing'))->assertOk()->getContent();
            // Desktop navbar + mobile menu.
            $this->assertLogoutGoesThroughTheDialog($html, 2);
        }
    }

    public function test_guests_get_no_dialog_on_the_landing_page(): void
    {
        $html = $this->get(route('landing'))->assertOk()->getContent();

        $this->assertStringNotContainsString('id="confirm-modal"', $html);
        $this->assertStringNotContainsString('/logout', $html);
    }

    public function test_the_dialog_script_exists_once_with_escape_backdrop_and_scroll_lock(): void
    {
        $html = $this->actingAs($this->admin)->get(route('admin.dashboard'))->getContent();

        $this->assertSame(1, substr_count($html, "closest('[data-confirm-form]')"), 'one handler, not one per layout/control');
        $this->assertStringContainsString("e.key === 'Escape'", $html);
        $this->assertStringContainsString('if (e.target === modal) closeModal();', $html);
        $this->assertStringContainsString("document.body.style.overflow = 'hidden';", $html);
        $this->assertStringContainsString("opts.focus === 'cancel' ? cancelBtn : confirmBtn", $html);
        $this->assertStringContainsString('prefers-reduced-motion', $html);
    }

    public function test_download_confirmations_still_use_the_same_dialog(): void
    {
        // Existing data-confirm links (report downloads) keep working through it — untitled.
        $html = $this->actingAs($this->admin)->get(route('admin.incidents.index'))->getContent();
        $this->assertStringContainsString('data-confirm-form', $html);
        $this->assertSame(1, substr_count($html, 'id="confirm-modal"'));
    }

    public function test_a_direct_post_to_logout_still_logs_out_and_redirects_home(): void
    {
        foreach ([$this->admin, $this->coordinator] as $user) {
            $this->actingAs($user)->post('/logout')->assertRedirect('/');
            $this->assertGuest();
        }
    }
}
