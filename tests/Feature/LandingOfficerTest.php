<?php

namespace Tests\Feature;

use App\Models\Campus;
use App\Models\Personnel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * The standing rule that no personnel email or contact number reaches a public
 * page, checked on the landing page. (The DRRM Officer card this file was named
 * for has been replaced by the emergency hotlines; see LandingHotlinesTest.)
 */
class LandingOfficerTest extends TestCase
{
    use RefreshDatabase;

    protected Campus $campus;

    protected Personnel $admin;

    protected Personnel $coordinator;

    protected function setUp(): void
    {
        parent::setUp();

        $this->campus = Campus::create(['campus_name' => 'ASCOT Zabali Campus']);
        // Distinctive private values that must never reach the public page.
        $this->admin = Personnel::create([
            'campus_id' => $this->campus->campus_id, 'full_name' => 'Maria Santos', 'role' => 'DRRMO Admin',
            'email' => 'officer-private@ascot-test.local', 'contact_number' => '09998887771', 'password_hash' => Hash::make('password'),
        ]);
        $this->coordinator = Personnel::create([
            'campus_id' => $this->campus->campus_id, 'full_name' => 'Coordinator Person', 'role' => 'Coordinator',
            'email' => 'coord-private@ascot-test.local', 'contact_number' => '09998887772', 'password_hash' => Hash::make('password'),
        ]);
    }

    protected function section(string $html): string
    {
        $this->assertSame(1, preg_match('/<section[^>]*data-hotlines.*?<\/section>/s', $html, $m), 'the hotlines section renders once');

        return $m[0];
    }

    public function test_no_personnel_email_or_contact_number_appears_for_any_visitor(): void
    {
        $this->admin->forceFill(['photo_path' => 'personnel/maria-santos-1-abc123.jpg'])->save();

        $emails = DB::table('personnel')->pluck('email')->filter()->all();
        $numbers = DB::table('personnel')->pluck('contact_number')->filter()->all();
        $this->assertNotEmpty($emails);
        $this->assertNotEmpty($numbers);

        foreach ([null, $this->admin, $this->coordinator] as $viewer) {
            $html = ($viewer ? $this->actingAs($viewer) : $this)->get(route('landing'))->assertOk()->getContent();
            $this->section($html);

            foreach ($emails as $email) {
                $this->assertStringNotContainsString($email, $html, "email leaked ({$email})");
            }
            foreach ($numbers as $number) {
                $this->assertStringNotContainsString($number, $html, "contact_number leaked ({$number})");
            }
            $this->assertStringContainsString('0917-152-9432', $html);
        }
    }
}
