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
 * Incident types (config/incident_types.php): two groups, Disaster-related
 * and Campus emergency, offered as two <optgroup>s, and "Other" on its own
 * after both — "neither list covers this", with the typed text stored. The
 * column is unchanged free text, so every existing value stays valid.
 */
class IncidentTypesTest extends TestCase
{
    use RefreshDatabase;

    protected Campus $campus;

    protected Personnel $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::parse('2026-10-12 09:00:00', 'Asia/Manila'));
        $this->campus = Campus::create(['campus_name' => 'ASCOT Zabali Campus']);
        $this->admin = Personnel::create(['campus_id' => $this->campus->campus_id, 'full_name' => 'Types Admin', 'role' => 'DRRMO Admin',
            'email' => 'types-admin@ascot-test.local', 'password_hash' => Hash::make('password')]);
    }

    public function test_the_two_groups_and_other_on_its_own(): void
    {
        $this->assertSame(['Disaster-related', 'Campus emergency'], array_keys(Incident::typeGroups()));
        $this->assertSame(['Typhoon', 'Earthquake', 'Flood', 'Fire', 'Landslide', 'Storm Surge'], Incident::typeGroups()['Disaster-related']);
        $this->assertSame(['Vehicular accident', 'Injury or medical emergency', 'Electrical or power failure', 'Chemical or laboratory incident', 'Structural failure'],
            Incident::typeGroups()['Campus emergency']);

        // Other belongs to neither group, so neither group can claim what it holds.
        $this->assertNotContains('Other', Incident::types());
        $this->assertSame('Disaster-related', Incident::groupOf('Fire'));
        $this->assertSame('Campus emergency', Incident::groupOf('Structural failure'));
        $this->assertNull(Incident::groupOf('Tsunami'));
        $this->assertNull(Incident::groupOf('Other'));
    }

    public function test_every_advisory_hazard_is_a_disaster_related_incident_type_spelled_identically(): void
    {
        // An advisory can always be recorded as an incident of the matching type.
        // Editing either list so they drift must fail here, not quietly fall
        // through to Other in "Record what happened".
        $hazards = array_values(array_diff(array_keys(config('hazard_measurements')), ['Other']));
        $this->assertSame(['Typhoon', 'Earthquake', 'Flood', 'Landslide', 'Storm Surge'], $hazards, 'today\'s advisory hazards');

        foreach ($hazards as $hazard) {
            $this->assertContains($hazard, Incident::typeGroups()['Disaster-related'], "advisory hazard \"{$hazard}\" has no incident type spelled the same");
        }
    }

    public function test_the_form_offers_two_optgroups_then_other(): void
    {
        $x = $this->page(route('admin.incidents.create'));

        $this->assertSame('Incident type', trim($x->query('//label[@for="hazard_type"]')->item(0)->textContent));
        $children = [];
        foreach ($x->query('//select[@name="hazard_type"]/*') as $node) {
            $children[] = $node->nodeName === 'optgroup' ? 'group:' . $node->getAttribute('label') : 'option:' . $node->getAttribute('value');
        }
        $this->assertSame(['group:Disaster-related', 'group:Campus emergency', 'option:Other'], $children);
        foreach (Incident::typeGroups() as $group => $types) {
            $options = array_map(fn ($o) => $o->getAttribute('value'), iterator_to_array($x->query("//select[@name='hazard_type']/optgroup[@label='{$group}']/option")));
            $this->assertSame($types, $options, $group);
        }
    }

    public function test_every_type_is_accepted_and_stored_as_written(): void
    {
        foreach (Incident::types() as $type) {
            $this->post_($type)->assertSessionHasNoErrors();
            $this->assertSame($type, Incident::latest('incident_id')->first()->hazard_type);
        }

        // Other stores the typed text; it needs one.
        $this->post_('Other', 'Tsunami')->assertSessionHasNoErrors();
        $this->assertSame('Tsunami', Incident::latest('incident_id')->first()->hazard_type);
        $this->post_('Other', '')->assertSessionHasErrors('hazard_type_other');
        $this->post_('Meteor')->assertSessionHasErrors('hazard_type');
    }

    public function test_the_original_four_and_custom_text_reopen_as_they_were(): void
    {
        foreach (['Typhoon', 'Earthquake', 'Flood', 'Fire'] as $type) {
            $incident = $this->incident($type);
            $x = $this->page(route('admin.incidents.edit', $incident));
            $this->assertSame($type, $x->query('//select[@name="hazard_type"]//option[@selected]')->item(0)->getAttribute('value'), $type);
        }

        $x = $this->page(route('admin.incidents.edit', $this->incident('Tsunami')));
        $this->assertSame('Other', $x->query('//select[@name="hazard_type"]//option[@selected]')->item(0)->getAttribute('value'));
        $this->assertSame('Tsunami', $x->query('//input[@name="hazard_type_other"]')->item(0)->getAttribute('value'));
    }

    public function test_an_advisory_prefills_a_matching_type_and_anything_else_as_other(): void
    {
        $cases = ['Typhoon' => 'Typhoon', 'Earthquake' => 'Earthquake', 'Flood' => 'Flood', 'Landslide' => 'Landslide', 'Storm Surge' => 'Storm Surge'];
        foreach ($cases as $hazard => $type) {
            $this->assertSame(['hazard_type' => $type, 'hazard_type_other' => ''], $this->advisory($hazard)->incidentHazardPrefill(), $hazard);
        }
        $this->assertSame(['hazard_type' => 'Other', 'hazard_type_other' => 'Tsunami'], $this->advisory('Other', 'Tsunami')->incidentHazardPrefill());
    }

    // ================================ helpers =================================

    protected function post_(string $type, ?string $other = null)
    {
        return $this->actingAs($this->admin)->post(route('admin.incidents.store'), array_filter([
            'hazard_type' => $type, 'hazard_type_other' => $other, 'campus_id' => $this->campus->campus_id,
            'date_occurred' => '2026-10-10', 'status' => 'Active',
        ], fn ($v) => $v !== null));
    }

    protected function incident(string $type): Incident
    {
        return Incident::forceCreate(['campus_id' => $this->campus->campus_id, 'hazard_type' => $type, 'date_occurred' => '2026-10-01',
            'status' => 'Active', 'created_by' => $this->admin->personnel_id]);
    }

    protected function advisory(string $hazard, ?string $other = null): DisasterAdvisory
    {
        return DisasterAdvisory::forceCreate([
            'campus_id' => $this->campus->campus_id, 'hazard_type' => $hazard, 'hazard_type_other' => $other, 'headline' => "{$hazard} advisory",
            'measurements' => [], 'source' => 'PAGASA', 'issued_at' => now(), 'valid_until' => now()->addDay(), 'status' => 'Active',
            'instructions' => 'Stay safe.', 'created_by' => $this->admin->personnel_id,
        ]);
    }

    protected function page(string $url): DOMXPath
    {
        $dom = new DOMDocument;
        libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="UTF-8">' . $this->actingAs($this->admin)->get($url)->assertOk()->getContent());
        libxml_clear_errors();

        return new DOMXPath($dom);
    }
}
