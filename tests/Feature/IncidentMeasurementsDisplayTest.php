<?php

namespace Tests\Feature;

use App\Models\Campus;
use App\Models\Incident\Incident;
use App\Models\Personnel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Tests\Concerns\ReadsReportHtml;
use Tests\TestCase;

/**
 * An incident's measurements on its page and in its record PDF, in the
 * advisory's shape: observed labels, units, "None entered" when blank, and
 * nothing at all for a type that records none.
 */
class IncidentMeasurementsDisplayTest extends TestCase
{
    use RefreshDatabase, ReadsReportHtml;

    protected Campus $campus;

    protected Personnel $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::parse('2026-10-12 09:00:00', 'Asia/Manila'));
        $this->campus = Campus::create(['campus_name' => 'ASCOT Zabali Campus']);
        $this->admin = Personnel::create(['campus_id' => $this->campus->campus_id, 'full_name' => 'Display Admin', 'role' => 'DRRMO Admin',
            'email' => 'display-admin@ascot-test.local', 'password_hash' => Hash::make('password')]);
    }

    public function test_the_page_shows_them_observed_with_units(): void
    {
        $surge = $this->incident('Storm Surge', ['surge_height' => '1.5']);
        $this->actingAs($this->admin)->get(route('admin.incidents.show', $surge))->assertOk()
            ->assertSee('data-incident-measurements', false)->assertSee('As observed')
            ->assertSee('Surge height')->assertSee('1.5 m')->assertDontSee('Expected surge height')
            ->assertSee(config('glossary.measurement_terms')['Surge height']);

        $quake = $this->incident('Earthquake', ['magnitude' => '6.2', 'depth' => '10', 'intensity' => 'V']);
        $this->actingAs($this->admin)->get(route('admin.incidents.show', $quake))
            ->assertSeeInOrder(['Magnitude', '6.2', 'Depth', '10 km', 'PHIVOLCS Intensity (at the campus)', 'V']);
    }

    public function test_the_page_says_none_entered_or_shows_no_card(): void
    {
        $this->actingAs($this->admin)->get(route('admin.incidents.show', $this->incident('Flood')))
            ->assertSee('data-incident-measurements', false)->assertSee('None entered.');

        foreach (['Vehicular accident', 'Fire', 'Tsunami'] as $type) {
            $this->actingAs($this->admin)->get(route('admin.incidents.show', $this->incident($type)))->assertOk()
                ->assertDontSee('data-incident-measurements', false)->assertDontSee('As observed');
        }
    }

    public function test_the_record_pdf_shows_them_the_same_way(): void
    {
        $pdf = fn (Incident $incident) => $this->reportText($this->capturePdf($this->admin, 'reports.records.incident', route('admin.incidents.report', $incident))['html']);

        $text = $pdf($this->incident('Storm Surge', ['surge_height' => '1.5']));
        $this->assertStringContainsString('Measurements (as observed)', $text);
        // Label and value are adjacent cells' divs: no whitespace between them once tags are stripped.
        $this->assertMatchesRegularExpression('/Surge height\s*1\.5 m/', $text);
        $this->assertStringNotContainsString('Expected surge height', $text);

        $text = $pdf($this->incident('Typhoon', ['storm_name' => 'Egay', 'wind_signal' => '3', 'max_winds' => '120']));
        $this->assertMatchesRegularExpression('/Storm name\s*Egay\s*Wind Signal No\.\s*3\s*Maximum sustained winds\s*120 km\/h/', $text);

        $this->assertMatchesRegularExpression('/Measurements \(as observed\)\s*None entered/', $pdf($this->incident('Earthquake')));
        $this->assertStringNotContainsString('Measurements', $pdf($this->incident('Injury or medical emergency')));
    }

    protected function incident(string $type, ?array $measurements = null): Incident
    {
        return Incident::forceCreate(['campus_id' => $this->campus->campus_id, 'hazard_type' => $type, 'measurements' => $measurements,
            'date_occurred' => '2026-10-05', 'status' => 'Active', 'created_by' => $this->admin->personnel_id]);
    }
}
