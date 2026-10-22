<?php

namespace Tests\Feature;

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
 * The Incidents list's Incident type filter: one type, a whole group ("All
 * disaster-related", "All campus emergencies") or Other — what neither group
 * lists. A group filter takes exactly its group's types and never Other, so
 * neither group claims an incident it can't vouch for.
 */
class FilterBarIncidentTypeTest extends TestCase
{
    use RefreshDatabase;

    protected Personnel $admin;

    protected Personnel $alpha;

    protected Personnel $bravo;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::parse('2026-10-12 09:00:00', 'Asia/Manila'));
        $campus = Campus::create(['campus_name' => 'ASCOT Zabali Campus']);
        $make = fn (string $name, string $role) => Personnel::create(['campus_id' => $campus->campus_id, 'full_name' => $name, 'role' => $role,
            'email' => strtolower(str_replace(' ', '-', $name)) . '@ascot-test.local', 'password_hash' => Hash::make('password')]);
        $this->admin = $make('Type Admin', 'DRRMO Admin');
        $this->alpha = $make('Coordinator Alpha', 'Coordinator');
        $this->bravo = $make('Coordinator Bravo', 'Coordinator');

        foreach ([
            [$this->alpha, 'Typhoon'], [$this->alpha, 'Fire'], [$this->alpha, 'Storm Surge'],
            [$this->alpha, 'Vehicular accident'], [$this->alpha, 'Chemical or laboratory incident'],
            [$this->alpha, 'Tsunami'],                        // Other: neither list covers it
            [$this->bravo, 'Typhoon'], [$this->bravo, 'Injury or medical emergency'], [$this->bravo, 'Volcanic ashfall'],
        ] as [$by, $type]) {
            Incident::forceCreate(['campus_id' => $campus->campus_id, 'hazard_type' => $type, 'date_occurred' => '2026-10-01', 'status' => 'Active', 'created_by' => $by->personnel_id]);
        }
    }

    public function test_the_control_offers_all_both_groups_their_types_then_other(): void
    {
        $x = $this->page($this->admin, route('admin.incidents.index'));
        $children = [];
        foreach ($x->query('//*[@data-filter-bar]//select[@name="incident_type"]/*') as $node) {
            $children[] = $node->nodeName === 'optgroup' ? 'group:' . $node->getAttribute('label') : $node->getAttribute('value') . '=' . trim($node->textContent);
        }
        $this->assertSame(['=All', 'group:disaster-related=All disaster-related', 'group:campus-emergency=All campus emergencies',
            'group:Disaster-related', 'group:Campus emergency', 'Other=Other'], $children);
        foreach (Incident::typeGroups() as $group => $types) {
            $options = array_map(fn ($o) => $o->getAttribute('value'), iterator_to_array($x->query("//*[@data-filter-bar]//select[@name='incident_type']/optgroup[@label='{$group}']/option")));
            $this->assertSame($types, $options);
        }
    }

    public function test_each_kind_of_value_filters_exactly(): void
    {
        $cases = [
            'Typhoon' => ['Typhoon', 'Typhoon'],
            'group:disaster-related' => ['Fire', 'Storm Surge', 'Typhoon', 'Typhoon'],
            'group:campus-emergency' => ['Chemical or laboratory incident', 'Injury or medical emergency', 'Vehicular accident'],
            'Other' => ['Tsunami', 'Volcanic ashfall'],
        ];
        foreach ($cases as $value => $expected) {
            [, $r] = $this->pageWith($this->admin, route('admin.incidents.index', ['incident_type' => $value]));
            $this->assertEqualsCanonicalizing($expected, $r->viewData('incidents')->pluck('hazard_type')->all(), $value);
        }
    }

    public function test_neither_group_claims_other(): void
    {
        foreach (['group:disaster-related', 'group:campus-emergency'] as $group) {
            [, $r] = $this->pageWith($this->admin, route('admin.incidents.index', ['incident_type' => $group]));
            foreach ($r->viewData('incidents') as $incident) {
                $this->assertNotNull(Incident::groupOf($incident->hazard_type), "{$group} claimed \"{$incident->hazard_type}\"");
            }
        }
    }

    public function test_the_summary_names_the_type_or_group(): void
    {
        $lines = [
            [$this->admin, 'admin', ['incident_type' => 'Vehicular accident'], 'Showing all incidents of type Vehicular accident for all campuses, all dates.'],
            [$this->admin, 'admin', ['incident_type' => 'group:campus-emergency'], 'Showing all campus emergencies for all campuses, all dates.'],
            [$this->admin, 'admin', ['incident_type' => 'group:disaster-related', 'status' => 'Active'], 'Showing all Active disaster-related incidents for all campuses, all dates.'],
            [$this->admin, 'admin', ['incident_type' => 'Other'], 'Showing all incidents of type Other for all campuses, all dates.'],
            [$this->alpha, 'coordinator', ['incident_type' => 'Fire'], 'Showing your and the DRRMO\'s incidents of type Fire for ASCOT Zabali Campus, all dates.'],
            [$this->alpha, 'coordinator', ['incident_type' => 'group:campus-emergency'], 'Showing your and the DRRMO\'s campus emergencies for ASCOT Zabali Campus, all dates.'],
        ];
        foreach ($lines as [$user, $prefix, $query, $expected]) {
            $x = $this->page($user, route("{$prefix}.incidents.index", $query));
            $this->assertSame($expected, $this->summary($x));
            $this->assertSame(1, $x->query('//a[@data-filter-clear]')->length);
        }
    }

    public function test_a_coordinator_still_sees_only_their_own(): void
    {
        [, $r] = $this->pageWith($this->alpha, route('coordinator.incidents.index', ['incident_type' => 'Typhoon']));
        $this->assertCount(1, $r->viewData('incidents'));
        [, $r] = $this->pageWith($this->alpha, route('coordinator.incidents.index', ['incident_type' => 'Other']));
        $this->assertSame(['Tsunami'], $r->viewData('incidents')->pluck('hazard_type')->all());
    }

    public function test_an_unknown_value_is_ignored(): void
    {
        foreach (['Meteor', 'group:nonsense', 'group:'] as $value) {
            [$x, $r] = $this->pageWith($this->admin, route('admin.incidents.index', ['incident_type' => $value]));
            $this->assertCount(9, $r->viewData('incidents'), $value);
            $this->assertSame('Showing all incidents for all campuses, all dates.', $this->summary($x));
        }
    }

    public function test_the_bars_select_renders_optgroups_for_any_page(): void
    {
        view()->share('errors', new \Illuminate\Support\ViewErrorBag);
        $html = (string) $this->blade('<x-filter-bar action="/x" :summary="[\'what\' => \'a\', \'who\' => \'b\']" :controls="$controls" />', ['controls' => [
            ['kind' => 'select', 'name' => 'thing', 'label' => 'Thing', 'selected' => 'b2', 'options' => ['' => 'All', 'Group A' => ['a1' => 'A one'], 'Group B' => ['b1' => 'B one', 'b2' => 'B two']]],
        ]]);
        $x = $this->xpath($html);
        $this->assertSame(['Group A', 'Group B'], array_map(fn ($g) => $g->getAttribute('label'), iterator_to_array($x->query('//select[@name="thing"]/optgroup'))));
        $this->assertSame('b2', $x->query('//select[@name="thing"]//option[@selected]')->item(0)->getAttribute('value'));
    }

    // ================================ helpers =================================

    protected function page(Personnel $as, string $url): DOMXPath
    {
        return $this->pageWith($as, $url)[0];
    }

    protected function pageWith(Personnel $as, string $url): array
    {
        $response = $this->actingAs($as)->get($url)->assertOk();

        return [$this->xpath($response->getContent()), $response];
    }

    protected function xpath(string $html): DOMXPath
    {
        $dom = new DOMDocument;
        libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="UTF-8">' . $html);
        libxml_clear_errors();

        return new DOMXPath($dom);
    }

    protected function summary(DOMXPath $x): string
    {
        return trim(preg_replace('/\s+/u', ' ', $x->query('//*[@data-filter-summary]')->item(0)->textContent));
    }
}
