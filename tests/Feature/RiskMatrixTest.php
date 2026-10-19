<?php

namespace Tests\Feature;

use App\Models\Building\Building;
use App\Models\Building\HazardInspection;
use App\Models\Campus;
use App\Models\Personnel;
use App\Support\RiskMatrix;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Panel round 2: hazards are rated on a 5×5 risk matrix (likelihood × impact)
 * instead of a hand-picked Low / Medium / High.
 */
class RiskMatrixTest extends TestCase
{
    use RefreshDatabase;

    protected Campus $campus;

    protected Personnel $coord;

    protected Building $building;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-10-20 10:00:00', 'Asia/Manila'));
        $this->campus = Campus::create(['campus_name' => 'ASCOT Zabali Campus']);
        $this->coord = Personnel::create([
            'campus_id' => $this->campus->campus_id, 'full_name' => 'Risk Coordinator', 'role' => 'Coordinator',
            'email' => 'risk-coord@ascot-test.local', 'password_hash' => Hash::make('password'),
        ]);
        $this->building = Building::create(['campus_id' => $this->campus->campus_id, 'building_name' => 'Science Hall', 'hazard_type' => 'Earthquake']);
    }

    public function test_bands_meet_at_their_edges(): void
    {
        $this->assertSame('Low', RiskMatrix::level(1));
        $this->assertSame('Low', RiskMatrix::level(4));
        $this->assertSame('Moderate', RiskMatrix::level(5));
        $this->assertSame('Moderate', RiskMatrix::level(9));
        $this->assertSame('High', RiskMatrix::level(10));
        $this->assertSame('High', RiskMatrix::level(16));
        $this->assertSame('Very High', RiskMatrix::level(17));
        $this->assertSame('Very High', RiskMatrix::level(25));
        $this->assertSame('High', RiskMatrix::rate(4, 4));
        $this->assertSame('5–9', RiskMatrix::range('Moderate'));
    }

    public function test_factors_outside_one_to_five_are_refused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        RiskMatrix::score(6, 1);
    }

    public function test_an_inspection_computes_its_score_and_level_and_ignores_a_posted_level(): void
    {
        $this->actingAs($this->coord)->post(route('coordinator.buildings.inspections.store', $this->building), [
            'inspection_date' => '2026-10-19', 'likelihood' => 4, 'impact' => 5, 'findings' => 'Cracked column', 'risk_level' => 'Low',
        ])->assertRedirect();

        $inspection = HazardInspection::firstOrFail();
        $this->assertSame([4, 5, 20, 'Very High'], [$inspection->likelihood, $inspection->impact, $inspection->risk_score, $inspection->risk_level]);
        $this->assertSame('Likely (4) × Major (4) = 16', (new HazardInspection)->rate(4, 4)->risk_breakdown);
    }

    public function test_likelihood_and_impact_are_required(): void
    {
        $this->actingAs($this->coord)->post(route('coordinator.buildings.inspections.store', $this->building), [
            'inspection_date' => '2026-10-19', 'findings' => 'No rating',
        ])->assertSessionHasErrors(['likelihood', 'impact']);
        $this->assertSame(0, HazardInspection::count());
    }

    public function test_the_building_follows_its_latest_inspection(): void
    {
        $this->assertNull($this->building->fresh()->risk_level, 'a new building is not yet assessed');

        $post = fn (string $date, int $l, int $i) => $this->actingAs($this->coord)->post(route('coordinator.buildings.inspections.store', $this->building), [
            'inspection_date' => $date, 'likelihood' => $l, 'impact' => $i,
        ])->assertRedirect();

        $post('2026-10-10', 4, 4);
        $this->assertSame('High', $this->building->fresh()->risk_level);
        $post('2026-10-01', 5, 5); // older: does not change the latest
        $this->assertSame('High', $this->building->fresh()->risk_level);
        $post('2026-10-18', 1, 2); // repaired
        $this->assertSame('Low', $this->building->fresh()->risk_level);

        $latest = HazardInspection::where('inspection_date', '2026-10-18')->firstOrFail();
        $this->actingAs($this->coord)->delete(route('coordinator.buildings.inspections.destroy', [$this->building, $latest]))->assertRedirect();
        $this->assertSame('High', $this->building->fresh()->risk_level, 'deleting the latest falls back to the one before');
    }

    public function test_the_forms_show_the_matrix_and_the_building_form_has_no_level_picker(): void
    {
        $this->actingAs($this->coord)->get(route('coordinator.buildings.inspections.create', $this->building))
            ->assertOk()->assertSee('data-risk-matrix', false)->assertSee('Almost certain')->assertSee('Catastrophic');
        $this->actingAs($this->coord)->get(route('coordinator.buildings.create'))
            ->assertOk()->assertDontSee('name="risk_level"', false)->assertSee('Not yet assessed');
        $this->actingAs($this->coord)->get(route('coordinator.buildings.index'))->assertOk()->assertSee('Not yet assessed');
    }

    public function test_the_dashboard_charts_four_levels_and_counts_very_high_as_open_high_risk(): void
    {
        (new HazardInspection(['building_id' => $this->building->building_id, 'inspection_date' => '2026-10-15']))
            ->forceFill(['created_by' => $this->coord->personnel_id])->rate(5, 5)->save();

        $data = $this->actingAs($this->coord)->get(route('coordinator.dashboard'))->viewData('analytics');
        $this->assertSame(RiskMatrix::LEVELS, $data['charts']['findings_by_risk']['labels']);
        $this->assertSame(1, $data['lists']['open_high_risk']['total']);
    }

    public function test_a_building_with_no_inspection_left_is_not_yet_assessed(): void
    {
        $this->actingAs($this->coord)->post(route('coordinator.buildings.inspections.store', $this->building), [
            'inspection_date' => '2026-10-10', 'likelihood' => 5, 'impact' => 5,
        ])->assertRedirect();
        $this->assertSame('Very High', $this->building->fresh()->risk_level);

        $only = HazardInspection::firstOrFail();
        $this->actingAs($this->coord)->delete(route('coordinator.buildings.inspections.destroy', [$this->building, $only]))->assertRedirect();
        $this->assertNull($this->building->fresh()->risk_level, 'a mistaken rating deleted leaves the building unassessed, not stuck at Very High');
    }
}
