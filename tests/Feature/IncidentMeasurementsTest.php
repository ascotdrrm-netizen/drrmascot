<?php

namespace Tests\Feature;

use App\Models\Advisory\DisasterAdvisory;
use App\Models\Campus;
use App\Models\Incident\Incident;
use App\Models\Personnel;
use App\Support\HazardMeasurements;
use DOMDocument;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Measurements on an incident: the advisory's fields (one shared config),
 * recorded as OBSERVED — the observed label where the advisory's is a
 * forecast — only for a Disaster-related type, all optional, inactive fields
 * disabled (not just hidden), and never copied from an advisory.
 */
class IncidentMeasurementsTest extends TestCase
{
    use RefreshDatabase;

    protected Campus $campus;

    protected Personnel $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::parse('2026-10-12 09:00:00', 'Asia/Manila'));
        $this->campus = Campus::create(['campus_name' => 'ASCOT Zabali Campus']);
        $this->admin = Personnel::create(['campus_id' => $this->campus->campus_id, 'full_name' => 'Measure Admin', 'role' => 'DRRMO Admin',
            'email' => 'measure-admin@ascot-test.local', 'password_hash' => Hash::make('password')]);
    }

    // ============================== the definition ============================

    public function test_one_definition_observed_where_the_advisory_forecasts(): void
    {
        $observedLabels = [];
        foreach (Incident::typeGroups()[Incident::DISASTER_GROUP] as $type) {
            $advisory = HazardMeasurements::forAdvisory($type);
            $incident = HazardMeasurements::forIncident($type);
            // Same keys, same units, same validation bounds: only the wording may differ.
            $this->assertSame(array_keys($advisory), array_keys($incident), $type);
            foreach ($incident as $key => $field) {
                foreach (['type', 'unit', 'min', 'max', 'step', 'options'] as $part) {
                    $this->assertSame($advisory[$key][$part] ?? null, $field[$part] ?? null, "{$type}.{$key}.{$part}");
                }
                $this->assertFalse($field['required'], "{$type}.{$key} is optional on an incident");
                $this->assertStringNotContainsStringIgnoringCase('expected', $field['label'], "{$type}.{$key}");
                if ($field['label'] !== $advisory[$key]['label']) {
                    $observedLabels["{$type}.{$key}"] = $field['label'];
                }
            }
        }
        // Exactly one field is worded differently today.
        $this->assertSame(['Storm Surge.surge_height' => 'Surge height'], $observedLabels);
        // And the advisory keeps its forecast wording.
        $this->assertSame('Expected surge height', HazardMeasurements::forAdvisory('Storm Surge')['surge_height']['label']);
        $this->assertNotNull(config('glossary.measurement_terms')['Surge height'] ?? null, 'the observed label has its own tip');
    }

    public function test_campus_emergencies_other_and_fire_record_none(): void
    {
        foreach ([...Incident::typeGroups()['Campus emergency'], 'Other', 'Tsunami', 'Fire', null] as $type) {
            $this->assertSame([], HazardMeasurements::forIncident($type), (string) $type);
        }
    }

    // ================================ the form =================================

    public function test_only_the_chosen_types_fieldset_is_enabled(): void
    {
        foreach (['Typhoon', 'Earthquake', 'Flood', 'Landslide', 'Storm Surge'] as $type) {
            $x = $this->page(route('admin.incidents.edit', $this->incident($type)));
            $this->assertFalse($x->query('//*[@data-incident-measurements]')->item(0)->hasAttribute('hidden'), $type);
            foreach ($x->query('//fieldset[@data-measurements-for]') as $set) {
                $on = $set->getAttribute('data-measurements-for') === $type;
                $this->assertSame(! $on, $set->hasAttribute('disabled'), "{$type}: {$set->getAttribute('data-measurements-for')} disabled");
                $this->assertSame(! $on, $set->hasAttribute('hidden'), "{$type}: {$set->getAttribute('data-measurements-for')} hidden");
            }
            // Flood and Landslide share rainfall_warning: only one can ever be submitted.
            $enabled = $x->query('//fieldset[@data-measurements-for][not(@disabled)]//*[starts-with(@name, "measurements[")]');
            $names = array_map(fn ($n) => $n->getAttribute('name'), iterator_to_array($enabled));
            $this->assertSame(array_unique($names), $names, $type);
            $this->assertSame(array_map(fn ($k) => "measurements[{$k}]", array_keys(HazardMeasurements::forIncident($type))), $names, $type);
        }
    }

    public function test_a_type_that_records_none_has_every_field_disabled_and_the_section_hidden(): void
    {
        foreach (['Vehicular accident', 'Fire', 'Tsunami'] as $type) {
            $x = $this->page(route('admin.incidents.edit', $this->incident($type)));
            $this->assertTrue($x->query('//*[@data-incident-measurements]')->item(0)->hasAttribute('hidden'), $type);
            $this->assertSame(0, $x->query('//fieldset[@data-measurements-for][not(@disabled)]')->length, $type);
        }
    }

    public function test_the_create_form_matches_the_select_s_first_option(): void
    {
        // Nothing chosen yet: the browser shows the first option, Typhoon — so does the server.
        $x = $this->page(route('admin.incidents.create'));
        $this->assertSame(['Typhoon'], array_map(fn ($s) => $s->getAttribute('data-measurements-for'), iterator_to_array($x->query('//fieldset[@data-measurements-for][not(@disabled)]'))));
    }

    // ============================== saving =====================================

    public function test_all_optional_blanks_dropped_and_only_the_types_own_fields(): void
    {
        $this->post_('Earthquake')->assertSessionHasNoErrors();
        $this->assertNull(Incident::latest('incident_id')->first()->measurements, 'nothing entered: nothing stored');

        $this->post_('Earthquake', ['magnitude' => '6.2', 'depth' => '', 'epicentre' => '12 km N of Baler', 'intensity' => ''])->assertSessionHasNoErrors();
        $this->assertSame(['magnitude' => '6.2', 'epicentre' => '12 km N of Baler'], Incident::latest('incident_id')->first()->measurements);

        $this->post_('Storm Surge', ['surge_height' => '1.5'])->assertSessionHasNoErrors();
        $this->assertSame(['surge_height' => '1.5'], Incident::latest('incident_id')->first()->measurements);

        // Another type's field, out of range, not an option.
        $this->post_('Typhoon', ['magnitude' => '6.2'])->assertSessionHasErrors('measurements');
        $this->post_('Earthquake', ['magnitude' => '11'])->assertSessionHasErrors('measurements.magnitude');
        $this->post_('Flood', ['rainfall_warning' => 'Purple'])->assertSessionHasErrors('measurements.rainfall_warning');
        // A campus emergency, or Other, records none.
        $this->post_('Vehicular accident', ['magnitude' => '6.2'])->assertSessionHasErrors('measurements');
        $this->post_('Other', ['water_level' => '2'], 'Tsunami')->assertSessionHasErrors('measurements');
    }

    public function test_changing_to_a_type_that_records_none_clears_them(): void
    {
        $incident = $this->incident('Flood', ['water_level' => '1.2', 'rainfall_warning' => 'Orange']);

        $this->actingAs($this->admin)->put(route('admin.incidents.update', $incident), [
            'hazard_type' => 'Structural failure', 'campus_id' => $this->campus->campus_id, 'date_occurred' => '2026-10-10', 'status' => 'Active',
        ])->assertSessionHasNoErrors();

        $this->assertNull($incident->fresh()->measurements);
    }

    // ============================ the advisory prefill ==========================

    public function test_the_prefill_carries_no_measurements_and_says_why(): void
    {
        $advisory = DisasterAdvisory::forceCreate([
            'campus_id' => $this->campus->campus_id, 'hazard_type' => 'Earthquake', 'headline' => 'M6.5 off Baler',
            'measurements' => ['magnitude' => '6.5', 'depth' => '10', 'epicentre' => '12 km N of Baler', 'intensity' => 'V'],
            'source' => 'PHIVOLCS', 'issued_at' => now(), 'valid_until' => now()->addDay(), 'status' => 'Active',
            'instructions' => 'Duck, cover and hold.', 'created_by' => $this->admin->personnel_id,
        ]);

        $html = $this->actingAs($this->admin)->get(route('admin.incidents.create', ['advisory' => $advisory->advisory_id]))->assertOk()->getContent();
        $x = $this->xpath($html);
        $this->assertSame('Earthquake', $x->query('//select[@name="hazard_type"]//option[@selected]')->item(0)->getAttribute('value'));
        foreach ($x->query('//fieldset[@data-measurements-for="Earthquake"]//input') as $input) {
            $this->assertSame('', $input->getAttribute('value'), $input->getAttribute('name'));
        }
        $this->assertSame(0, $x->query('//fieldset[@data-measurements-for="Earthquake"]//option[@selected][@value!=""]')->length);
        $this->assertStringContainsString('Its measurements are not: a measurement is an observation', $html);
    }

    // ================================ helpers ==================================

    protected function post_(string $type, array $measurements = [], ?string $other = null)
    {
        return $this->actingAs($this->admin)->post(route('admin.incidents.store'), array_filter([
            'hazard_type' => $type, 'hazard_type_other' => $other, 'campus_id' => $this->campus->campus_id,
            'date_occurred' => '2026-10-10', 'status' => 'Active', 'measurements' => $measurements ?: null,
        ], fn ($v) => $v !== null));
    }

    protected function incident(string $type, ?array $measurements = null): Incident
    {
        return Incident::forceCreate(['campus_id' => $this->campus->campus_id, 'hazard_type' => $type, 'measurements' => $measurements,
            'date_occurred' => '2026-10-01', 'status' => 'Active', 'created_by' => $this->admin->personnel_id]);
    }

    protected function page(string $url): DOMXPath
    {
        return $this->xpath($this->actingAs($this->admin)->get($url)->assertOk()->getContent());
    }

    protected function xpath(string $html): DOMXPath
    {
        $dom = new DOMDocument;
        libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="UTF-8">' . $html);
        libxml_clear_errors();

        return new DOMXPath($dom);
    }
}
