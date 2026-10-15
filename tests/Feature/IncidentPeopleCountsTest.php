<?php

namespace Tests\Feature;

use App\Models\Campus;
use App\Models\Incident\Incident;
use App\Models\Personnel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Thesis panel (Arts & Sciences): an incident records how many people it
 * affected, injured, left missing or killed. Every count is optional, and a
 * blank one stays "not known" rather than becoming 0.
 */
class IncidentPeopleCountsTest extends TestCase
{
    use RefreshDatabase;

    protected Campus $campus;

    protected Personnel $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::parse('2026-10-14 09:00:00', 'Asia/Manila'));
        $this->campus = Campus::create(['campus_name' => 'ASCOT Zabali Campus']);
        $this->admin = Personnel::create(['campus_id' => $this->campus->campus_id, 'full_name' => 'People Admin', 'role' => 'DRRMO Admin',
            'email' => 'people-admin@ascot-test.local', 'password_hash' => Hash::make('password')]);
    }

    protected function payload(array $extra = []): array
    {
        return $extra + [
            'hazard_type' => 'Vehicular accident', 'date_occurred' => '2026-10-13', 'status' => 'Active',
            'campus_id' => $this->campus->campus_id,
        ];
    }

    public function test_the_form_offers_the_four_counts(): void
    {
        $html = $this->actingAs($this->admin)->get(route('admin.incidents.create'))->assertOk()->getContent();

        foreach (array_keys(Incident::PEOPLE) as $column) {
            $this->assertMatchesRegularExpression('/<input type="number" id="' . $column . '" name="' . $column . '" min="0"/', $html);
        }
    }

    public function test_counts_are_saved_and_shown_and_a_blank_one_stays_unknown(): void
    {
        $this->actingAs($this->admin)->post(route('admin.incidents.store'), $this->payload([
            'people_affected' => '12', 'people_injured' => '3', 'people_missing' => '', 'people_dead' => '0',
        ]))->assertSessionHasNoErrors();

        $incident = Incident::sole();
        $this->assertSame(12, $incident->people_affected);
        $this->assertSame(3, $incident->people_injured);
        $this->assertNull($incident->people_missing, 'blank means not known');
        $this->assertSame(0, $incident->people_dead, '0 is a real count');

        $this->get(route('admin.incidents.show', $incident))->assertOk()
            ->assertSee('data-people-counts', false)
            ->assertSeeInOrder(['Affected', '12', 'Injured', '3', 'Missing', 'Not known', 'Dead', '0']);
    }

    public function test_no_counts_means_no_people_block_and_bad_counts_are_refused(): void
    {
        $this->actingAs($this->admin)->post(route('admin.incidents.store'), $this->payload())->assertSessionHasNoErrors();
        $this->get(route('admin.incidents.show', Incident::sole()))->assertOk()->assertDontSee('data-people-counts', false);

        $this->post(route('admin.incidents.store'), $this->payload(['people_injured' => '-1']))->assertSessionHasErrors('people_injured');
        $this->post(route('admin.incidents.store'), $this->payload(['people_dead' => 'two']))->assertSessionHasErrors('people_dead');
        $this->assertSame(1, Incident::count());
    }
}
