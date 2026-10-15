<?php

namespace Tests\Feature;

use App\Models\Advisory\DisasterAdvisory;
use App\Models\Campus;
use App\Models\Incident\Incident;
use App\Models\Personnel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Tests\Concerns\ReadsReportHtml;
use Tests\TestCase;

/**
 * Disaster Advisories (phase 1): issuing per campus, measurements checked
 * against the hazard type, the dashboard warning, the printable memo, and
 * "Record what happened" linking an incident to its advisory.
 */
class AdvisoryModuleTest extends TestCase
{
    use RefreshDatabase, ReadsReportHtml;

    protected Campus $zabali;

    protected Campus $maria;

    protected Personnel $admin;

    protected Personnel $alpha;   // coordinator, Zabali

    protected Personnel $bravo;   // coordinator, Zabali

    protected Personnel $charlie; // coordinator, Maria Aurora

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::parse('2026-10-10 08:00:00', 'Asia/Manila'));
        $this->zabali = Campus::create(['campus_name' => 'ASCOT Zabali Campus']);
        $this->maria = Campus::create(['campus_name' => 'ASCOT Maria Aurora Campus']);
        $make = fn (Campus $campus, string $name, string $role) => Personnel::create(['campus_id' => $campus->campus_id, 'full_name' => $name, 'role' => $role, 'email' => strtolower(str_replace(' ', '-', $name)) . '@ascot-test.local', 'password_hash' => Hash::make('password')]);
        $this->admin = $make($this->zabali, 'Advisory Admin', 'DRRMO Admin');
        $this->alpha = $make($this->zabali, 'Coordinator Alpha', 'Coordinator');
        $this->bravo = $make($this->zabali, 'Coordinator Bravo', 'Coordinator');
        $this->charlie = $make($this->maria, 'Coordinator Charlie', 'Coordinator');
    }

    // ============================== issuing ===================================

    public function test_a_coordinator_cannot_issue_for_another_campus_or_all_campuses_by_form_post(): void
    {
        foreach ([(string) $this->maria->campus_id, 'all'] as $campus) {
            $this->actingAs($this->alpha)
                ->post(route('coordinator.advisories.store'), $this->typhoon(['campus_id' => $campus]))
                ->assertForbidden();
        }
        $this->assertSame(0, DisasterAdvisory::count());

        // Without a campus (as the form sends it) it is their own campus.
        $this->actingAs($this->alpha)->post(route('coordinator.advisories.store'), $this->typhoon())->assertRedirect();
        $advisory = DisasterAdvisory::sole();
        $this->assertSame($this->zabali->campus_id, $advisory->campus_id);
        $this->assertSame('Active', $advisory->status);
        $this->assertSame($this->alpha->personnel_id, $advisory->created_by);
        $this->assertSame(['storm_name' => 'Egay', 'wind_signal' => '2'], $advisory->measurements);
    }

    public function test_the_admin_issues_for_one_campus_or_all_campuses_and_created_by_is_not_taken_from_input(): void
    {
        $this->actingAs($this->admin)->post(route('admin.advisories.store'), $this->typhoon(['campus_id' => 'all', 'created_by' => $this->alpha->personnel_id]))->assertRedirect();
        $this->actingAs($this->admin)->post(route('admin.advisories.store'), $this->typhoon(['campus_id' => $this->maria->campus_id]))->assertRedirect();

        [$all, $maria] = DisasterAdvisory::orderBy('advisory_id')->get()->all();
        $this->assertNull($all->campus_id);
        $this->assertSame('All campuses', $all->campus_label);
        $this->assertSame($this->admin->personnel_id, $all->created_by);
        $this->assertSame($this->maria->campus_id, $maria->campus_id);
    }

    public function test_measurements_are_checked_against_the_hazard_type(): void
    {
        $post = fn (array $data) => $this->actingAs($this->alpha)->post(route('coordinator.advisories.store'), $data);

        // Another type's field: a magnitude on a typhoon, a wind signal on an earthquake.
        $post($this->typhoon(['measurements' => ['wind_signal' => '2', 'magnitude' => '6.5']]))->assertSessionHasErrors('measurements');
        $post($this->typhoon(['hazard_type' => 'Earthquake', 'measurements' => ['magnitude' => '6.5', 'wind_signal' => '2']]))->assertSessionHasErrors('measurements');
        // Out of range; not one of the options.
        $post($this->typhoon(['measurements' => ['wind_signal' => '7']]))->assertSessionHasErrors('measurements.wind_signal');
        $post($this->typhoon(['hazard_type' => 'Earthquake', 'measurements' => ['magnitude' => '11']]))->assertSessionHasErrors('measurements.magnitude');
        $post($this->typhoon(['hazard_type' => 'Flood', 'measurements' => ['rainfall_warning' => 'Purple']]))->assertSessionHasErrors('measurements.rainfall_warning');
        // Valid until must come after issued.
        $post($this->typhoon(['valid_until' => '2026-10-10T07:00']))->assertSessionHasErrors('valid_until');
        $this->assertSame(0, DisasterAdvisory::count());

        // The right fields for the type pass.
        $post($this->typhoon(['hazard_type' => 'Earthquake', 'measurements' => ['magnitude' => '6.5', 'intensity' => 'V', 'epicentre' => '12 km N of Baler']]))->assertSessionHasNoErrors();
        $this->assertSame('Magnitude 6.5', DisasterAdvisory::sole()->headline_measurement);

        // Every figure is optional (thesis panel): a typhoon advisory with only the
        // storm name posts, and its headline simply has no figure in it.
        $post($this->typhoon(['measurements' => ['storm_name' => 'Egay']]))->assertSessionHasNoErrors();
        $this->assertNull(DisasterAdvisory::latest('advisory_id')->first()->headline_measurement);
    }

    // ============================ the warning =================================

    public function test_the_banner_shows_only_live_advisories_for_the_viewers_campus(): void
    {
        $zabali = $this->advisory(['headline' => 'Zabali signal two', 'campus_id' => $this->zabali->campus_id]);
        $this->advisory(['headline' => 'Maria flood warning', 'campus_id' => $this->maria->campus_id]);
        $this->advisory(['headline' => 'Province-wide typhoon', 'campus_id' => null]);
        $this->advisory(['headline' => 'Old expired one', 'campus_id' => $this->zabali->campus_id, 'valid_until' => now()->subHour()]);
        $this->advisory(['headline' => 'Already passed', 'campus_id' => $this->zabali->campus_id, 'status' => 'Passed']);

        $alpha = $this->banner($this->alpha, 'coordinator');
        $this->assertStringContainsString('Zabali signal two', $alpha);
        $this->assertStringContainsString('Province-wide typhoon', $alpha);
        $this->assertStringContainsString('Wind Signal No. 2', $alpha);
        $this->assertStringContainsString('Expected Oct 10, 6:00 PM', $alpha);
        foreach (['Maria flood warning', 'Old expired one', 'Already passed'] as $hidden) {
            $this->assertStringNotContainsString($hidden, $alpha);
        }

        // Another campus in the URL changes nothing for a coordinator.
        $tampered = $this->banner($this->alpha, 'coordinator', ['campus_id' => $this->maria->campus_id]);
        $this->assertStringContainsString('Zabali signal two', $tampered);
        $this->assertStringNotContainsString('Maria flood warning', $tampered);

        $charlie = $this->banner($this->charlie, 'coordinator');
        $this->assertStringContainsString('Maria flood warning', $charlie);
        $this->assertStringContainsString('Province-wide typhoon', $charlie);
        $this->assertStringNotContainsString('Zabali signal two', $charlie);

        // The Admin sees every live one; filtered to a campus, that campus's and the all-campus ones.
        $admin = $this->banner($this->admin, 'admin');
        foreach (['Zabali signal two', 'Maria flood warning', 'Province-wide typhoon'] as $shown) {
            $this->assertStringContainsString($shown, $admin);
        }
        $adminMaria = $this->banner($this->admin, 'admin', ['campus_id' => $this->maria->campus_id]);
        $this->assertStringNotContainsString('Zabali signal two', $adminMaria);
        $this->assertStringContainsString('Province-wide typhoon', $adminMaria);

        // Once Valid until passes it leaves the banner on its own, and the list says why.
        $this->travelTo($zabali->valid_until->copy()->addMinute());
        $this->assertSame('', $this->banner($this->alpha, 'coordinator'));
        $this->actingAs($this->alpha)->get(route('coordinator.advisories.index'))
            ->assertSee('Zabali signal two')->assertSee('Expired');
    }

    public function test_a_cancelled_advisory_leaves_the_banner_but_stays_in_the_list(): void
    {
        $this->actingAs($this->alpha)->post(route('coordinator.advisories.store'), $this->typhoon())->assertRedirect();
        $advisory = DisasterAdvisory::sole();
        $this->assertStringContainsString('Typhoon Egay over Aurora', $this->banner($this->bravo, 'coordinator'));

        $this->actingAs($this->alpha)
            ->put(route('coordinator.advisories.update', $advisory), $this->typhoon(['status' => 'Cancelled']))
            ->assertRedirect(route('coordinator.advisories.show', $advisory));

        $this->assertSame('', $this->banner($this->bravo, 'coordinator'));
        $this->actingAs($this->bravo)->get(route('coordinator.advisories.index'))
            ->assertSee('Typhoon Egay over Aurora')->assertSee('Cancelled');
        $this->actingAs($this->bravo)->get(route('coordinator.advisories.index', ['status' => 'Cancelled']))
            ->assertSee('Typhoon Egay over Aurora');
    }

    // ============================ permissions =================================

    public function test_only_the_issuer_or_the_admin_can_change_an_advisory_and_other_campuses_cannot_see_it(): void
    {
        $this->actingAs($this->alpha)->post(route('coordinator.advisories.store'), $this->typhoon())->assertRedirect();
        $mine = DisasterAdvisory::sole();
        $allCampuses = $this->advisory(['campus_id' => null, 'created_by' => $this->admin->personnel_id]);

        // A colleague on the same campus can see it, not change it.
        $this->actingAs($this->bravo)->get(route('coordinator.advisories.show', $mine))->assertOk()->assertDontSee('>Edit</a>', false);
        $this->actingAs($this->bravo)->get(route('coordinator.advisories.edit', $mine))->assertForbidden();
        $this->actingAs($this->bravo)->put(route('coordinator.advisories.update', $mine), $this->typhoon(['status' => 'Passed']))->assertForbidden();
        $this->actingAs($this->bravo)->delete(route('coordinator.advisories.destroy', $mine))->assertForbidden();
        // Another campus can't even open it; everyone sees the all-campus one, only the Admin changes it.
        $this->actingAs($this->charlie)->get(route('coordinator.advisories.show', $mine))->assertForbidden();
        $this->actingAs($this->charlie)->get(route('coordinator.advisories.show', $allCampuses))->assertOk();
        $this->actingAs($this->alpha)->get(route('coordinator.advisories.edit', $allCampuses))->assertForbidden();
        $this->actingAs($this->charlie)->get(route('coordinator.advisories.index'))->assertDontSee('Typhoon Egay over Aurora');

        // The issuer moves it to Trash and restores it; a colleague can't restore it.
        $this->actingAs($this->alpha)->delete(route('coordinator.advisories.destroy', $mine))->assertRedirect();
        $this->assertSoftDeleted('disaster_advisories', ['advisory_id' => $mine->advisory_id]);
        $this->actingAs($this->bravo)->post(route('coordinator.advisories.restore', $mine->advisory_id))->assertForbidden();
        $this->actingAs($this->alpha)->get(route('coordinator.advisories.trash'))->assertSee('Typhoon Egay over Aurora');
        $this->actingAs($this->alpha)->post(route('coordinator.advisories.restore', $mine->advisory_id))->assertRedirect();
        $this->assertNull($mine->fresh()->deleted_at);

        // The Admin can change anyone's.
        $this->actingAs($this->admin)->put(route('admin.advisories.update', $mine), $this->typhoon(['status' => 'Passed']))->assertRedirect();
        $this->assertSame('Passed', $mine->fresh()->status);
    }

    // =============================== the memo =================================

    public function test_the_memo_prints_on_the_letterhead_without_personal_data(): void
    {
        $this->actingAs($this->alpha)->post(route('coordinator.advisories.store'), $this->typhoon())->assertRedirect();
        $advisory = DisasterAdvisory::sole();

        ['html' => $html, 'response' => $response] = $this->capturePdf($this->bravo, 'reports.records.advisory-memorandum', route('coordinator.advisories.memorandum', $advisory));
        $text = $this->reportText($html);

        $this->assertStringContainsString('ASCOT-Disaster-Advisory_Typhoon_2026-10-10_', $response->headers->get('Content-Disposition'));
        // The letterhead shell (reports.pdf.document); PdfLetterheadTest checks the printed page itself.
        $this->assertStringContainsString('class="letterhead"', $html);
        foreach (['Disaster Advisory', 'Typhoon Advisory', 'Typhoon Egay over Aurora', 'Wind Signal No.', 'Stay indoors', 'ASCOT Zabali Campus', 'PAGASA · TCB #12'] as $expected) {
            $this->assertStringContainsString($expected, $text);
        }
        foreach ([$this->alpha->full_name, $this->alpha->email] as $personal) {
            $this->assertStringNotContainsString($personal, $text);
        }
        $this->assertDatabaseHas('activity_logs', ['personnel_id' => $this->bravo->personnel_id, 'action' => 'report_exported']);
    }

    // ======================== Record what happened ============================

    public function test_record_what_happened_prefills_the_incident_form_and_links_the_incident(): void
    {
        $this->actingAs($this->alpha)->post(route('coordinator.advisories.store'), $this->typhoon())->assertRedirect();
        $advisory = DisasterAdvisory::sole();

        $this->actingAs($this->bravo)->get(route('coordinator.advisories.show', $advisory))
            ->assertSee(route('coordinator.incidents.create', ['advisory' => $advisory->advisory_id]), false);

        $form = $this->actingAs($this->bravo)->get(route('coordinator.incidents.create', ['advisory' => $advisory->advisory_id]))->assertOk();
        $form->assertSee('name="advisory_id" value="' . $advisory->advisory_id . '"', false)
            ->assertSee('<option value="Typhoon" selected', false)
            ->assertSee('value="2026-10-10"', false)
            ->assertSee('Typhoon Egay over Aurora');

        $this->actingAs($this->bravo)->post(route('coordinator.incidents.store'), [
            'hazard_type' => 'Typhoon', 'date_occurred' => '2026-10-10', 'status' => 'Active',
            'description' => 'Roof sheets torn off the gym.', 'advisory_id' => $advisory->advisory_id,
        ])->assertRedirect();

        $incident = Incident::sole();
        $this->assertSame($advisory->advisory_id, $incident->advisory_id);
        $this->assertSame($this->zabali->campus_id, $incident->campus_id);
        $this->actingAs($this->bravo)->get(route('coordinator.incidents.show', $incident))
            ->assertSee('Preceded by advisory')->assertSee(route('coordinator.advisories.show', $advisory), false);
        // The advisory lists it under What happened (for whoever can see the incident).
        $this->actingAs($this->bravo)->get(route('coordinator.advisories.show', $advisory))
            ->assertSee(route('coordinator.incidents.show', $incident), false);
    }

    public function test_landslide_and_storm_surge_prefill_as_their_incident_types_and_an_unlisted_hazard_as_other(): void
    {
        // Landslide and Storm Surge are incident types now (Disaster-related).
        foreach (['Landslide' => ['rainfall_warning' => 'Orange'], 'Storm Surge' => ['surge_height' => '2.5']] as $type => $measurements) {
            $advisory = $this->advisory(['hazard_type' => $type, 'measurements' => $measurements, 'campus_id' => $this->zabali->campus_id]);

            $this->actingAs($this->alpha)->get(route('coordinator.incidents.create', ['advisory' => $advisory->advisory_id]))
                ->assertSee('<option value="' . $type . '" selected', false);
        }

        // A hazard neither list names still comes in as Other, with its name typed in.
        $advisory = $this->advisory(['hazard_type' => 'Other', 'hazard_type_other' => 'Tsunami', 'measurements' => [], 'campus_id' => $this->zabali->campus_id]);
        $this->actingAs($this->alpha)->get(route('coordinator.incidents.create', ['advisory' => $advisory->advisory_id]))
            ->assertSee('<option value="Other" selected', false)
            ->assertSee('name="hazard_type_other" maxlength="100"' . "\n" . '                   value="Tsunami"', false);
    }

    public function test_an_incident_cannot_be_linked_to_a_cancelled_hidden_or_mismatched_advisory(): void
    {
        $cancelled = $this->advisory(['campus_id' => $this->zabali->campus_id, 'status' => 'Cancelled']);
        $maria = $this->advisory(['campus_id' => $this->maria->campus_id]);
        $incident = ['hazard_type' => 'Typhoon', 'date_occurred' => '2026-10-10', 'status' => 'Active'];

        $this->actingAs($this->alpha)->get(route('coordinator.advisories.show', $cancelled))->assertDontSee('Record what happened');
        $this->actingAs($this->alpha)->get(route('coordinator.incidents.create', ['advisory' => $cancelled->advisory_id]))->assertStatus(422);
        $this->actingAs($this->alpha)->post(route('coordinator.incidents.store'), $incident + ['advisory_id' => $cancelled->advisory_id])->assertStatus(422);
        // Another campus's advisory: not even visible.
        $this->actingAs($this->alpha)->post(route('coordinator.incidents.store'), $incident + ['advisory_id' => $maria->advisory_id])->assertForbidden();
        // The Admin recording a Maria Aurora advisory's incident at Zabali.
        $this->actingAs($this->admin)->post(route('admin.incidents.store'), $incident + ['campus_id' => $this->zabali->campus_id, 'advisory_id' => $maria->advisory_id])
            ->assertSessionHasErrors('campus_id');
        $this->assertSame(0, Incident::count());

        // A Passed one still can.
        $maria->update(['status' => 'Passed']);
        $this->actingAs($this->admin)->post(route('admin.incidents.store'), $incident + ['campus_id' => $this->maria->campus_id, 'advisory_id' => $maria->advisory_id])
            ->assertSessionHasNoErrors();
        $this->assertSame($maria->advisory_id, Incident::sole()->advisory_id);
    }

    // ================================ helpers =================================

    /** The form's fields for a Signal No. 2 typhoon advisory, issued now. */
    protected function typhoon(array $overrides = []): array
    {
        return array_replace([
            'hazard_type' => 'Typhoon',
            'headline' => 'Typhoon Egay over Aurora',
            'measurements' => ['storm_name' => 'Egay', 'wind_signal' => '2', 'max_winds' => ''],
            'source' => 'PAGASA',
            'bulletin_reference' => 'TCB #12',
            'issued_at' => '2026-10-10T08:00',
            'expected_at' => '2026-10-10T18:00',
            'valid_until' => '2026-10-11T08:00',
            'instructions' => 'Classes are suspended. Stay indoors and follow your building warden.',
        ], $overrides);
    }

    protected function advisory(array $attributes): DisasterAdvisory
    {
        return DisasterAdvisory::forceCreate(array_replace([
            'hazard_type' => 'Typhoon', 'headline' => 'Typhoon advisory', 'measurements' => ['wind_signal' => '2'],
            'source' => 'PAGASA', 'issued_at' => now(), 'expected_at' => now()->addHours(10), 'valid_until' => now()->addDay(),
            'status' => 'Active', 'instructions' => 'Stay indoors.', 'created_by' => $this->admin->personnel_id,
        ], $attributes));
    }

    /** The dashboard's advisory banner, or '' when there is none. */
    protected function banner(Personnel $as, string $prefix, array $query = []): string
    {
        $html = $this->actingAs($as)->get(route("{$prefix}.dashboard", $query))->assertOk()->getContent();

        return preg_match('#<div[^>]*data-advisory-banner>.*?</ul>#s', $html, $m) ? $m[0] : '';
    }
}
