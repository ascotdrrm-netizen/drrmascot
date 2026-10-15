<?php

namespace Tests\Feature;

use App\Models\Campus;
use App\Models\Personnel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Thesis panel (IT experts): a password can't be copied out of its field, and
 * "Confirm password" must be typed rather than pasted, so it really confirms
 * what was typed above. The guards are in components/password-input.
 */
class PasswordCopyPasteTest extends TestCase
{
    use RefreshDatabase;

    public function test_register_marks_both_fields_and_only_the_confirmation_refuses_paste(): void
    {
        $html = $this->get(route('register'))->assertOk()->getContent();

        $this->assertMatchesRegularExpression('/<input type="password" id="password" name="password"[^>]*data-password-field/s', $html);
        $this->assertMatchesRegularExpression('/<input type="password" id="password_confirmation" name="password_confirmation"[^>]*data-password-field[^>]*data-no-paste/s', $html);
        // The main field still accepts paste, so password managers keep working.
        $this->assertDoesNotMatchRegularExpression('/<input type="password" id="password" name="password"[^>]*data-no-paste/s', $html);

        $this->assertStringContainsString('aria-describedby="password_confirmation-no-paste"', $html);
        $this->assertStringContainsString('Type it again: pasting is turned off here.', $html);
        // The script that enforces it is on the page once.
        $this->assertSame(1, substr_count($html, "document.addEventListener('paste'"));
        $this->assertSame(1, substr_count($html, "['copy', 'cut'].forEach"));
    }

    public function test_the_profile_password_change_has_the_same_guard(): void
    {
        $campus = Campus::create(['campus_name' => 'ASCOT Zabali Campus']);
        $user = Personnel::forceCreate([
            'campus_id' => $campus->campus_id, 'full_name' => 'Ana Cruz', 'role' => 'Coordinator',
            'email' => 'ana@example.com', 'password_hash' => Hash::make('Password123!'), 'is_approved' => true,
        ]);

        $html = $this->actingAs($user)->get(route('coordinator.profile.show'))->assertOk()->getContent();

        $this->assertMatchesRegularExpression('/name="password_confirmation"[^>]*data-no-paste/s', $html);
        $this->assertDoesNotMatchRegularExpression('/name="current_password"[^>]*data-no-paste/s', $html);
    }
}
