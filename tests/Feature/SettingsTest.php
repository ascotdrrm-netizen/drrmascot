<?php

namespace Tests\Feature;

use App\Models\Campus;
use App\Models\Personnel;
use App\Models\School;
use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Thesis panel: the Admin adds a missing school/course (Accountancy) and
 * sets who signs "Noted by", which is never the same person as "Prepared by".
 */
class SettingsTest extends TestCase
{
    use RefreshDatabase;

    protected Campus $zabali;

    protected Personnel $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->zabali = Campus::create(['campus_name' => 'ASCOT Zabali Campus']);
        $this->admin = Personnel::create(['campus_id' => $this->zabali->campus_id, 'full_name' => 'Settings Admin', 'role' => 'DRRMO Admin',
            'email' => 'settings-admin@ascot-test.local', 'password_hash' => Hash::make('password')]);
    }

    public function test_only_the_admin_has_settings(): void
    {
        $coordinator = Personnel::forceCreate(['campus_id' => $this->zabali->campus_id, 'full_name' => 'A Coordinator', 'role' => 'Coordinator',
            'email' => 'coord@ascot-test.local', 'password_hash' => Hash::make('password'), 'is_approved' => true]);

        $this->actingAs($coordinator)->get(route('admin.settings.index'))->assertForbidden();
        $this->actingAs($this->admin)->get(route('admin.settings.index'))->assertOk()->assertSee('Schools and courses');
    }

    public function test_the_admin_adds_accountancy_and_registration_offers_it(): void
    {
        $this->actingAs($this->admin)->post(route('admin.settings.schools.store'), [
            'campus_id' => $this->zabali->campus_id, 'school_name' => 'School of Accountancy',
        ])->assertRedirect(route('admin.settings.index'));

        $this->assertTrue(School::where('campus_id', $this->zabali->campus_id)->where('school_name', 'School of Accountancy')->exists());
        $this->post(route('admin.settings.schools.store'), ['campus_id' => $this->zabali->campus_id, 'school_name' => 'School of Accountancy'])
            ->assertSessionHasErrors('school_name');

        auth()->logout();
        $this->get(route('register'))->assertOk()->assertSee('School of Accountancy');
    }

    public function test_a_school_is_renamed_in_place(): void
    {
        $school = School::create(['campus_id' => $this->zabali->campus_id, 'school_name' => 'School of Acountancy']);

        $this->actingAs($this->admin)->put(route('admin.settings.schools.update', $school), ['school_name' => 'School of Accountancy'])
            ->assertRedirect();
        $this->assertSame('School of Accountancy', $school->fresh()->school_name);
    }

    public function test_noted_by_shows_the_configured_official_but_never_the_preparer(): void
    {
        $this->actingAs($this->admin)->put(route('admin.settings.signatory'), [
            'noted_by_name' => 'Dr. Maria Reyes', 'noted_by_position' => 'Vice President for Administration',
        ])->assertRedirect();

        $html = view('reports.pdf._signature', ['preparedByName' => 'Settings Admin', 'preparedByRole' => 'DRRMO Admin'])->render();
        $this->assertStringContainsString('Dr. Maria Reyes', $html);
        $this->assertStringContainsString('Vice President for Administration', $html);

        // The official prepares the report: their name is not printed twice.
        $html = view('reports.pdf._signature', ['preparedByName' => 'Dr. Maria Reyes', 'preparedByRole' => 'VP'])->render();
        $this->assertSame(1, substr_count($html, 'Dr. Maria Reyes'));
        $this->assertStringContainsString('Signature over printed name', $html);
    }

    public function test_by_default_the_pdf_signature_is_unchanged(): void
    {
        $this->assertNull(Settings::get('report.noted_by_name'));
        $html = view('reports.pdf._signature', ['preparedByName' => 'Someone'])->render();
        $this->assertStringContainsString('DRRMO Administrator', $html);
        $this->assertStringContainsString('Signature over printed name', $html);
    }
}
