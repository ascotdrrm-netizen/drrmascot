<?php

namespace Tests\Feature;

use App\Models\Advisory\DisasterAdvisory;
use App\Models\Campus;
use App\Models\Incident\Incident;
use App\Models\Personnel;
use DOMDocument;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * How advisories read on screen. The stored status stays Active / Passed /
 * Cancelled; the screen says "In effect", or "Expired" once valid_until has
 * passed with nobody marking it Passed. The sidebar calls the module
 * Advisories. On an advisory's page a coordinator's empty "What happened"
 * says "none of yours" and counts (only counts) the incidents others recorded.
 */
class AdvisoryLabelsTest extends TestCase
{
    use RefreshDatabase;

    protected Campus $zabali;

    protected Personnel $admin;

    protected Personnel $alpha; // coordinator, Zabali

    protected Personnel $bravo; // coordinator, Zabali

    protected DisasterAdvisory $advisory;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::parse('2026-10-10 08:00:00', 'Asia/Manila'));
        $this->zabali = Campus::create(['campus_name' => 'ASCOT Zabali Campus']);
        $make = fn (string $name, string $role) => Personnel::create(['campus_id' => $this->zabali->campus_id, 'full_name' => $name, 'role' => $role, 'email' => strtolower(str_replace(' ', '-', $name)) . '@ascot-test.local', 'password_hash' => Hash::make('password')]);
        $this->admin = $make('Labels Admin', 'DRRMO Admin');
        $this->alpha = $make('Coordinator Alpha', 'Coordinator');
        $this->bravo = $make('Coordinator Bravo', 'Coordinator');

        $this->advisory = DisasterAdvisory::forceCreate([
            'campus_id' => $this->zabali->campus_id, 'hazard_type' => 'Typhoon', 'headline' => 'Typhoon Egay over Aurora',
            'measurements' => ['wind_signal' => '2'], 'source' => 'PAGASA', 'issued_at' => now(), 'expected_at' => now()->addHours(10),
            'valid_until' => now()->addDay(), 'status' => 'Active', 'instructions' => 'Stay indoors.', 'created_by' => $this->alpha->personnel_id,
        ]);
    }

    // ============================ status labels ===============================

    public function test_a_live_advisory_reads_in_effect_while_the_stored_status_stays_active(): void
    {
        foreach ([route('coordinator.advisories.index'), route('coordinator.advisories.show', $this->advisory)] as $url) {
            $badge = $this->badge($this->page($this->alpha, $url));
            $this->assertSame('In effect', $badge['text'], $url);
            $this->assertStringContainsString('bg-red-50', $badge['class']);
            $this->assertSame(config('glossary.advisory_status')['In effect'], $badge['title']);
        }
        $this->assertSame('Active', $this->advisory->fresh()->status);
    }

    public function test_past_its_valid_until_time_it_reads_expired_in_amber_and_the_glossary_says_why(): void
    {
        $this->travelTo($this->advisory->valid_until->copy()->addMinute());

        foreach ([route('coordinator.advisories.index'), route('coordinator.advisories.show', $this->advisory)] as $url) {
            $badge = $this->badge($this->page($this->alpha, $url));
            $this->assertSame('Expired', $badge['text'], $url);
            $this->assertStringContainsString('bg-amber-50', $badge['class']);
            $this->assertSame(config('glossary.advisory_status')['Expired'], $badge['title']);
        }
        $this->assertStringContainsString('without anyone marking it Passed', config('glossary.advisory_status')['Expired']);
        $this->assertSame('Active', $this->advisory->fresh()->status);

        // Passed and Cancelled read as stored, expired or not.
        foreach (['Passed', 'Cancelled'] as $status) {
            $this->advisory->update(['status' => $status]);
            $this->assertSame($status, $this->badge($this->page($this->alpha, route('coordinator.advisories.show', $this->advisory)))['text']);
        }
    }

    public function test_the_filter_and_the_edit_form_label_active_as_in_effect_but_still_send_active(): void
    {
        foreach ([route('coordinator.advisories.index'), route('coordinator.advisories.edit', $this->advisory)] as $url) {
            $xpath = $this->page($this->alpha, $url);
            $options = [];
            foreach ($xpath->query('//select[@id="status"]/option[@value!=""]') as $option) {
                $options[$option->getAttribute('value')] = trim($option->textContent);
            }
            $this->assertSame(['Active' => 'In effect', 'Passed' => 'Passed', 'Cancelled' => 'Cancelled'], $options, $url);
        }

        // Filtering by it still works on the stored value.
        $this->actingAs($this->alpha)->get(route('coordinator.advisories.index', ['status' => 'Active']))->assertSee('Typhoon Egay over Aurora');
    }

    public function test_the_create_note_and_help_say_in_effect_and_help_explains_expired(): void
    {
        $this->actingAs($this->alpha)->get(route('coordinator.advisories.create'))
            ->assertSee('Issued as <span class="font-medium">In effect</span>', false)
            ->assertDontSee('Issued as <span class="font-medium">Active</span>', false);

        $help = $this->page($this->alpha, route('help'));
        $terms = array_map(fn ($dt) => trim($dt->textContent), iterator_to_array($help->query('//dt')));
        foreach (['Advisories', 'In effect', 'Expired', 'Passed', 'Cancelled'] as $term) {
            $this->assertContains($term, $terms);
        }
        $this->assertNotContains('Disasters', $terms);
        $this->assertSame(1, $help->query('//dd[contains(., "without anyone marking it Passed")]')->length);
    }

    // ================================ sidebar =================================

    public function test_the_sidebar_calls_the_module_advisories(): void
    {
        foreach ([[$this->alpha, 'coordinator'], [$this->admin, 'admin']] as [$user, $prefix]) {
            $nav = $this->page($user, route("{$prefix}.dashboard"));
            $link = $nav->query('//nav[@id="sidebar-nav"]//a[@href="' . route("{$prefix}.advisories.index") . '"]')->item(0);
            $this->assertNotNull($link, $prefix);
            $this->assertSame('Advisories', trim($link->textContent));
            $this->assertStringNotContainsString('Disasters', $nav->query('//nav[@id="sidebar-nav"]')->item(0)->textContent);
        }
    }

    // ===================== What happened, for a coordinator ====================

    public function test_a_coordinator_with_nothing_recorded_anywhere_sees_none_of_theirs_and_how_to_record_one(): void
    {
        $card = $this->linkedIncidents($this->alpha, 'coordinator');
        $this->assertStringContainsString('No incident you recorded from this advisory.', $card);
        $this->assertStringContainsString('use Record what happened', $card);
        $this->assertStringNotContainsString('recorded by others', $card);
    }

    public function test_a_coordinator_is_told_how_many_incidents_others_recorded_and_nothing_else_about_them(): void
    {
        $theirs = $this->incident($this->bravo, 'Roof sheets torn off the gym');

        $card = $this->linkedIncidents($this->alpha, 'coordinator');
        $this->assertStringContainsString('No incident you recorded from this advisory.', $card);
        $this->assertStringContainsString('1 incident recorded by others isn’t shown to you: an incident is visible only to whoever recorded it and the DRRMO.', $card);
        $this->assertStringNotContainsString(route('coordinator.incidents.show', $theirs), $card);
        $this->assertStringNotContainsString('Coordinator Bravo', $card);

        $this->incident($this->bravo, 'Flooded canteen');
        $this->assertStringContainsString('2 incidents recorded by others aren’t shown to you', $this->linkedIncidents($this->alpha, 'coordinator'));
    }

    public function test_with_some_of_their_own_a_coordinator_sees_a_count_of_the_rest_under_the_table(): void
    {
        $mine = $this->incident($this->alpha, 'Fallen tree by the gate');
        $this->incident($this->bravo, 'Roof sheets torn off the gym');

        $xpath = $this->page($this->alpha, route('coordinator.advisories.show', $this->advisory));
        $this->assertSame(1, $xpath->query('//*[@data-section="linked-incidents"]//a[@href="' . route('coordinator.incidents.show', $mine) . '"]')->length);
        $this->assertSame('1 more, recorded by others, isn’t shown to you.', trim($xpath->query('//*[@data-hidden-incidents]')->item(0)?->textContent ?? ''));

        $this->incident($this->bravo, 'Flooded canteen');
        $xpath = $this->page($this->alpha, route('coordinator.advisories.show', $this->advisory));
        $this->assertSame('2 more, recorded by others, aren’t shown to you.', trim($xpath->query('//*[@data-hidden-incidents]')->item(0)?->textContent ?? ''));

        // Bravo, who recorded both of the others, is told about Alpha's one.
        $this->assertSame('1 more, recorded by others, isn’t shown to you.', trim($this->page($this->bravo, route('coordinator.advisories.show', $this->advisory))->query('//*[@data-hidden-incidents]')->item(0)?->textContent ?? ''));
    }

    public function test_the_admin_wording_is_unchanged_and_the_admin_sees_every_incident(): void
    {
        $card = $this->linkedIncidents($this->admin, 'admin');
        $this->assertStringContainsString('No incident recorded from this advisory.', $card);
        $this->assertStringNotContainsString('you recorded', $card);

        $this->incident($this->alpha, 'Fallen tree by the gate');
        $this->incident($this->bravo, 'Roof sheets torn off the gym');
        $xpath = $this->page($this->admin, route('admin.advisories.show', $this->advisory));
        $this->assertSame(2, $xpath->query('//*[@data-section="linked-incidents"]//tbody/tr')->length);
        $this->assertSame(0, $xpath->query('//*[@data-hidden-incidents]')->length);
        $this->assertStringNotContainsString('recorded by others', $this->linkedIncidents($this->admin, 'admin'));
    }

    // ================================ helpers =================================

    protected function incident(Personnel $by, string $description): Incident
    {
        return Incident::forceCreate([
            'campus_id' => $this->zabali->campus_id, 'advisory_id' => $this->advisory->advisory_id, 'hazard_type' => 'Typhoon',
            'date_occurred' => '2026-10-10', 'status' => 'Active', 'description' => $description, 'created_by' => $by->personnel_id,
        ]);
    }

    protected function page(Personnel $as, string $url): DOMXPath
    {
        $html = $this->actingAs($as)->get($url)->assertOk()->getContent();
        $dom = new DOMDocument;
        libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="UTF-8">' . $html);
        libxml_clear_errors();

        return new DOMXPath($dom);
    }

    /** The text, class and title of the advisory's status badge (the first one on the page). */
    protected function badge(DOMXPath $xpath): array
    {
        $labels = ['In effect', 'Expired', 'Passed', 'Cancelled', 'Active'];
        foreach ($xpath->query('//span[contains(@class, "rounded-full")]') as $span) {
            if (in_array(trim($span->textContent), $labels, true)) {
                return ['text' => trim($span->textContent), 'class' => $span->getAttribute('class'), 'title' => $span->getAttribute('title')];
            }
        }
        $this->fail('No advisory status badge on the page.');
    }

    /** The "What happened" card's text. */
    protected function linkedIncidents(Personnel $as, string $prefix): string
    {
        $card = $this->page($as, route("{$prefix}.advisories.show", $this->advisory))->query('//*[@data-section="linked-incidents"]')->item(0);
        $this->assertNotNull($card);

        return preg_replace('/\s+/u', ' ', $card->textContent);
    }
}
