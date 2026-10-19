<?php

namespace Tests\Feature;

use App\Models\Campus;
use App\Models\Drill\Drill;
use App\Models\Drill\DrillDocumentation;
use App\Models\Personnel;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Panel round 2: a drill "duplicated" after its documentation was added.
 * A submission sent twice (double tap, or a retry after a lost answer on a
 * slow connection) must be saved once.
 */
class DrillDuplicateTest extends TestCase
{
    use RefreshDatabase;

    protected Campus $zabali;

    protected Personnel $coord;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-10-20 10:00:00', 'Asia/Manila'));
        $this->zabali = Campus::create(['campus_name' => 'ASCOT Zabali Campus']);
        $this->coord = Personnel::create([
            'campus_id' => $this->zabali->campus_id, 'full_name' => 'Dup Coordinator', 'role' => 'Coordinator',
            'email' => 'dup-coord@ascot-test.local', 'password_hash' => Hash::make('password'),
        ]);
    }

    protected function schedule(array $headers = [], string $time = '12:45')
    {
        return $this->actingAs($this->coord)->withHeaders($headers)->post(route('coordinator.drills.store'), [
            'drill_type' => 'Earthquake', 'scheduled_date' => '2026-10-21', 'scheduled_time' => $time, 'venue' => 'Gym',
        ]);
    }

    public function test_the_same_drill_cannot_be_scheduled_twice(): void
    {
        $this->schedule()->assertRedirect();
        $this->schedule()->assertSessionHasErrors('scheduled_date');

        $this->assertSame(1, Drill::count());
    }

    public function test_a_different_time_is_a_different_drill(): void
    {
        $this->schedule()->assertRedirect();
        $this->schedule([], '17:00')->assertRedirect();

        $this->assertSame(2, Drill::count());
    }

    public function test_a_retried_ajax_submission_is_saved_once_and_gets_the_first_answer(): void
    {
        $headers = ['X-Requested-With' => 'XMLHttpRequest', 'Accept' => 'application/json', 'Idempotency-Key' => 'retry-key-0001'];

        $first = $this->schedule($headers)->assertOk()->json();
        // Same key, but the venue spelled differently would pass the duplicate check:
        // only the key stops it.
        $second = $this->actingAs($this->coord)->withHeaders($headers)->post(route('coordinator.drills.store'), [
            'drill_type' => 'Earthquake', 'scheduled_date' => '2026-10-21', 'scheduled_time' => '12:45', 'venue' => 'Gymnasium',
        ])->assertOk()->json();

        $this->assertSame(1, Drill::count());
        $this->assertSame($first['redirect'], $second['redirect']);
    }

    public function test_a_new_key_is_a_new_submission(): void
    {
        $headers = ['X-Requested-With' => 'XMLHttpRequest', 'Accept' => 'application/json'];
        $this->schedule($headers + ['Idempotency-Key' => 'first-key-0001'])->assertOk();
        $this->schedule($headers + ['Idempotency-Key' => 'other-key-0002'], '17:00')->assertOk();

        $this->assertSame(2, Drill::count());
    }

    public function test_documentation_sent_twice_stays_one_record_and_one_drill(): void
    {
        $drill = Drill::forceCreate([
            'campus_id' => $this->zabali->campus_id, 'drill_type' => 'Earthquake', 'scheduled_date' => '2026-10-15',
            'scheduled_time' => '12:45:00', 'venue' => 'Gym', 'created_by' => $this->coord->personnel_id,
        ]);
        $payload = ['date_conducted' => '2026-10-15', 'time_conducted' => '13:00', 'narrative' => 'Held as planned.'];

        $this->actingAs($this->coord)->put(route('coordinator.drills.documentation.update', $drill), $payload)->assertRedirect();
        $this->actingAs($this->coord)->put(route('coordinator.drills.documentation.update', $drill), $payload + ['notes' => 'Again'])->assertRedirect();

        $this->assertSame(1, DrillDocumentation::count());
        $this->assertSame(1, Drill::count());
        $this->assertSame('Again', DrillDocumentation::first()->notes);
    }

    public function test_the_drill_page_shows_its_progress_and_the_next_step(): void
    {
        $drill = Drill::forceCreate([
            'campus_id' => $this->zabali->campus_id, 'drill_type' => 'Fire', 'scheduled_date' => '2026-10-15',
            'scheduled_time' => '09:00:00', 'venue' => 'Gym', 'created_by' => $this->coord->personnel_id,
        ]);
        $this->actingAs($this->coord)->get(route('coordinator.drills.show', $drill))
            ->assertSee('data-drill-progress', false)->assertSee('Next: documentation');

        $this->actingAs($this->coord)->put(route('coordinator.drills.documentation.update', $drill), [
            'date_conducted' => '2026-10-15', 'narrative' => 'Held.',
        ])->assertSessionHas('status', 'Documentation submitted. Next: write the After Activity Report.');
        $this->actingAs($this->coord)->get(route('coordinator.drills.show', $drill))->assertSee('Next: After Activity Report');

        $upcoming = Drill::forceCreate([
            'campus_id' => $this->zabali->campus_id, 'drill_type' => 'Fire', 'scheduled_date' => '2026-10-30',
            'scheduled_time' => '09:00:00', 'venue' => 'Gym', 'created_by' => $this->coord->personnel_id,
        ]);
        $this->actingAs($this->coord)->get(route('coordinator.drills.show', $upcoming))->assertSee('After the drill is held on Oct 30');
    }
}
