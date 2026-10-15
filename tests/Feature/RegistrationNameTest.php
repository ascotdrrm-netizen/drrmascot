<?php

namespace Tests\Feature;

use App\Models\Campus;
use App\Models\Personnel;
use App\Models\School;
use App\Support\PersonName;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Registration's first name / middle initial / last name -> personnel.full_name. */
class RegistrationNameTest extends TestCase
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

    protected function register(array $name, string $email = 'name-test@ascot-test.local')
    {
        return $this->from('/register')->post('/register', $name + [
            'campus_id' => $this->campus->campus_id, 'school_id' => $this->school->school_id, 'email' => $email,
            'password' => 'Register1!pw', 'password_confirmation' => 'Register1!pw', 'contact_number' => '09171234567',
        ]);
    }

    protected function storedName(string $email = 'name-test@ascot-test.local'): ?string
    {
        return Personnel::where('email', $email)->value('full_name');
    }

    public function test_with_a_middle_initial_stores_first_m_last(): void
    {
        $this->register(['first_name' => 'Justine', 'middle_initial' => 'T', 'last_name' => 'Virrey'])->assertRedirect(route('login'));

        $this->assertSame('Justine T. Virrey', $this->storedName());
        $this->assertSame('Coordinator', Personnel::where('email', 'name-test@ascot-test.local')->value('role'));
    }

    public function test_without_a_middle_initial_stores_first_last(): void
    {
        $this->register(['first_name' => 'Cindy', 'middle_initial' => '', 'last_name' => 'Pagunaling'])->assertRedirect(route('login'));

        $this->assertSame('Cindy Pagunaling', $this->storedName());
    }

    public function test_middle_initial_forms_normalise_to_uppercase_letter_and_period(): void
    {
        foreach (['t', 'T', 'T.', 't.', ' t '] as $i => $initial) {
            $email = "initial-{$i}@ascot-test.local";
            $this->register(['first_name' => 'Justine', 'middle_initial' => $initial, 'last_name' => 'Virrey'], $email)->assertSessionHasNoErrors();
            $this->assertSame('Justine T. Virrey', $this->storedName($email), "input '{$initial}'");
            $this->post('/logout');
        }
    }

    public function test_extra_whitespace_is_trimmed_and_collapsed(): void
    {
        $this->register(['first_name' => '  Maria   Clara ', 'middle_initial' => ' s ', 'last_name' => "  Dela   Cruz\t"])->assertSessionHasNoErrors();

        $this->assertSame('Maria Clara S. Dela Cruz', $this->storedName());
    }

    public function test_missing_first_or_last_name_is_rejected(): void
    {
        $this->register(['first_name' => '', 'last_name' => 'Virrey'])->assertRedirect('/register')->assertSessionHasErrors('first_name');
        $this->register(['first_name' => 'Justine', 'last_name' => '   '])->assertSessionHasErrors('last_name');

        $this->assertNull($this->storedName());
        $this->assertGuest();
    }

    public function test_multi_character_or_non_letter_middle_initial_is_rejected(): void
    {
        foreach (['TV', 'Tom', '1', 'T..', '-'] as $bad) {
            $this->register(['first_name' => 'Justine', 'middle_initial' => $bad, 'last_name' => 'Virrey'])
                ->assertSessionHasErrors(['middle_initial' => 'The middle initial must be a single letter (for example "T" or "T.").']);
        }
        $this->assertNull($this->storedName());
    }

    public function test_name_parts_use_the_existing_character_rules(): void
    {
        $this->register(['first_name' => 'Justine2', 'last_name' => 'Virr3y'])
            ->assertSessionHasErrors(['first_name', 'last_name']);
        // Letters, spaces, periods, hyphens and apostrophes are still fine.
        $this->register(['first_name' => "Ma. Jo-Anne", 'last_name' => "O'Neil"])->assertSessionHasNoErrors();
        $this->assertSame("Ma. Jo-Anne O'Neil", $this->storedName());
    }

    public function test_combined_name_must_fit_the_column(): void
    {
        $this->assertSame(150, PersonName::MAX_LENGTH);

        // Each part within its own 100-char limit, but together 100 + 1 + 60 = 161 > 150.
        $this->register(['first_name' => str_repeat('a', 100), 'last_name' => str_repeat('b', 60)])
            ->assertSessionHasErrors(['last_name' => 'Your full name is too long: first name, middle initial and last name together must be at most 150 characters.']);
        $this->assertNull($this->storedName());

        // Exactly 150 fits.
        $this->register(['first_name' => str_repeat('a', 100), 'last_name' => str_repeat('b', 49)])->assertSessionHasNoErrors();
        $this->assertSame(150, mb_strlen($this->storedName()));

        // Over the per-part limit (log out: /register is guest-only).
        $this->post('/logout');
        $this->register(['first_name' => str_repeat('a', 101), 'last_name' => 'B'], 'other@ascot-test.local')->assertSessionHasErrors('first_name');
    }

    public function test_all_three_fields_repopulate_after_a_validation_error(): void
    {
        $this->register(['first_name' => 'Justine', 'middle_initial' => 't', 'last_name' => 'Virrey', 'password_confirmation' => 'mismatch'])
            ->assertSessionHasErrors('password');
        $this->from('/register')->post('/register', [
            'first_name' => 'Justine', 'middle_initial' => 't', 'last_name' => 'Virrey',
            'campus_id' => $this->campus->campus_id, 'email' => 'not-an-email', 'password' => 'Register1!pw', 'password_confirmation' => 'Register1!pw',
        ])->assertSessionHasErrors('email');

        $html = $this->get('/register')->assertOk()->getContent();
        $this->assertMatchesRegularExpression('/name="first_name" value="Justine"/', $html);
        $this->assertMatchesRegularExpression('/name="middle_initial" value="t"/', $html);
        $this->assertMatchesRegularExpression('/name="last_name" value="Virrey"/', $html);
    }

    public function test_form_renders_three_name_fields_and_keeps_the_rest(): void
    {
        $html = $this->get('/register')->assertOk()->getContent();

        foreach (['first_name', 'middle_initial', 'last_name'] as $field) {
            $this->assertStringContainsString('name="' . $field . '"', $html);
        }
        $this->assertStringNotContainsString('name="full_name"', $html);
        $this->assertStringContainsString('(optional)', $html);
        $this->assertSame(2, preg_match_all('/<button[^>]*data-password-toggle/', $html), 'password + confirmation toggles');
        $this->assertStringContainsString('data-school-select', $html);
        $this->assertStringNotContainsString('name="role"', $html);
    }

    public function test_combine_helper(): void
    {
        $this->assertSame('Justine T. Virrey', PersonName::combine('Justine', 't', 'Virrey'));
        $this->assertSame('Cindy Pagunaling', PersonName::combine(' Cindy ', null, 'Pagunaling'));
        $this->assertSame('Cindy Pagunaling', PersonName::combine('Cindy', '  ', 'Pagunaling'));
        $this->assertSame('Ñino A. Dela Cruz', PersonName::combine('Ñino', 'a.', 'Dela  Cruz'));
    }
}
