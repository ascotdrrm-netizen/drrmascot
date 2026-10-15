<?php

namespace Tests\Feature;

use App\Models\Campus;
use App\Models\Personnel;
use App\Models\School;
use DOMDocument;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * The shared filter bar (x-filter-bar), first on User Management: controls in
 * a fixed order (campus, school, then module-specific), Apply for two or more
 * controls and submit-on-change for one (never both), Clear filters once
 * something is applied, and a "Showing <what> for <who>." line built from the
 * filters the query actually used — never from the form.
 */
class FilterBarTest extends TestCase
{
    use RefreshDatabase;

    protected Campus $zabali;

    protected Campus $bazal;

    protected School $education;

    protected Personnel $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->zabali = Campus::create(['campus_name' => 'ASCOT Zabali Campus']);
        $this->bazal = Campus::create(['campus_name' => 'ASCOT Bazal Campus']);
        $this->education = School::create(['campus_id' => $this->zabali->campus_id, 'school_name' => 'School of Education']);
        $make = fn (Campus $campus, string $name, string $role, ?School $school = null) => Personnel::create([
            'campus_id' => $campus->campus_id, 'school_id' => $school?->school_id, 'full_name' => $name, 'role' => $role,
            'email' => strtolower(str_replace(' ', '-', $name)) . '@ascot-test.local', 'password_hash' => Hash::make('password'),
        ]);
        $this->admin = $make($this->zabali, 'Filter Admin', 'DRRMO Admin');
        $make($this->zabali, 'Edu Coordinator', 'Coordinator', $this->education);
        $make($this->zabali, 'Plain Coordinator', 'Coordinator');
        $make($this->bazal, 'Bazal Coordinator', 'Coordinator');
    }

    // ========================= User Management's bar ==========================

    public function test_unfiltered_it_shows_everything_says_so_and_has_apply_but_no_clear(): void
    {
        [$xpath, $response] = $this->page(route('admin.personnel.index'));

        $this->assertCount(4, $response->viewData('personnel'));
        $this->assertSame('Showing all accounts for all campuses.', $this->summary($xpath));
        $this->assertSame(['campus', 'school', 'role'], $this->controlOrder($xpath));
        $this->assertSame(1, $xpath->query('//*[@data-filter-bar]//button[@data-filter-apply]')->length);
        $this->assertSame(0, $xpath->query('//*[@data-filter-clear]')->length);
        // Apply, so nothing in the bar submits on change.
        $this->assertSame(0, $xpath->query('//*[@data-filter-bar]//*[@onchange]')->length);
        $this->assertSame('Apply filters', trim($xpath->query('//button[@data-filter-apply]')->item(0)->textContent));
        // Every control shares one minimum width (the school select may grow past it).
        foreach ($xpath->query('//*[@data-filter-bar]//select') as $select) {
            $this->assertStringContainsString('sm:min-w-[13rem]', $select->getAttribute('class'), $select->getAttribute('name'));
        }
    }

    public function test_applied_filters_are_named_in_the_summary_and_can_be_cleared(): void
    {
        $query = ['role' => 'Coordinator', 'campus_id' => $this->zabali->campus_id, 'school_id' => $this->education->school_id];
        [$xpath, $response] = $this->page(route('admin.personnel.index', $query));

        $this->assertSame(['Edu Coordinator'], $response->viewData('personnel')->pluck('full_name')->all());
        $this->assertSame('Showing Coordinators in School of Education for ASCOT Zabali Campus.', $this->summary($xpath));
        $clear = $xpath->query('//a[@data-filter-clear]')->item(0);
        $this->assertNotNull($clear);
        $this->assertSame(route('admin.personnel.index'), $clear->getAttribute('href'));
        // The controls show what is applied.
        $this->assertSame('Coordinator', $xpath->query('//select[@name="role"]/option[@selected]')->item(0)->getAttribute('value'));
        $this->assertSame((string) $this->zabali->campus_id, $xpath->query('//select[@name="campus_id"]/option[@selected]')->item(0)->getAttribute('value'));
    }

    public function test_a_school_under_all_campuses_names_its_campus_and_not_assigned_reads_plainly(): void
    {
        [$xpath] = $this->page(route('admin.personnel.index', ['school_id' => $this->education->school_id]));
        $this->assertSame('Showing all accounts in School of Education for ASCOT Zabali Campus.', $this->summary($xpath));

        [$xpath, $response] = $this->page(route('admin.personnel.index', ['role' => 'DRRMO Admin', 'school_id' => 'none']));
        $this->assertSame(['Filter Admin'], $response->viewData('personnel')->pluck('full_name')->all());
        $this->assertSame('Showing DRRMO Admins with no school set for all campuses.', $this->summary($xpath));
    }

    public function test_unknown_values_are_ignored_so_the_summary_never_names_a_filter_that_isnt_applied(): void
    {
        foreach ([['role' => 'Superuser'], ['campus_id' => '999999'], ['campus_id' => 'abc'], ['role' => ['Coordinator']]] as $query) {
            [$xpath, $response] = $this->page(route('admin.personnel.index', $query));
            $this->assertCount(4, $response->viewData('personnel'), json_encode($query));
            $this->assertSame('Showing all accounts for all campuses.', $this->summary($xpath));
            $this->assertSame(0, $xpath->query('//*[@data-filter-clear]')->length);
        }
    }

    public function test_nothing_on_the_page_rewrites_the_summary_from_the_form(): void
    {
        $html = $this->actingAs($this->admin)->get(route('admin.personnel.index', ['role' => 'Coordinator']))->getContent();

        preg_match_all('#<script\b[^>]*>(.*?)</script>#si', $html, $scripts);
        foreach ($scripts[1] as $script) {
            $this->assertStringNotContainsString('filter-summary', $script);
            $this->assertStringNotContainsString('data-filter-bar', $script);
        }
    }

    // ======================== the component on its own ========================

    public function test_one_control_submits_on_change_and_has_no_apply_button(): void
    {
        $xpath = $this->render(['controls' => [['kind' => 'campus', 'campuses' => Campus::orderBy('campus_name')->get(), 'selected' => null]]]);

        $this->assertSame('auto', $xpath->query('//form[@data-filter-form]')->item(0)->getAttribute('data-filter-form'));
        $this->assertSame('this.form.submit()', $xpath->query('//select[@name="campus_id"]')->item(0)->getAttribute('onchange'));
        $this->assertSame(0, $xpath->query('//button[@data-filter-apply]')->length);
        $this->assertSame(0, $xpath->query('//*[@data-filter-clear]')->length);

        // Applied: Clear filters, still no Apply.
        $xpath = $this->render(['applied' => true, 'controls' => [['kind' => 'campus', 'campuses' => Campus::all(), 'selected' => $this->bazal->campus_id]]]);
        $this->assertSame(1, $xpath->query('//a[@data-filter-clear]')->length);
        $this->assertSame(0, $xpath->query('//button[@data-filter-apply]')->length);
    }

    public function test_a_lone_school_control_submits_on_change_too(): void
    {
        $xpath = $this->render(['controls' => [['kind' => 'school', 'schools' => School::forDropdowns(), 'campusId' => $this->zabali->campus_id, 'selected' => null]]]);

        $this->assertSame('this.form.submit()', $xpath->query('//select[@name="school_id"]')->item(0)->getAttribute('onchange'));
        $this->assertSame(0, $xpath->query('//button[@data-filter-apply]')->length);
    }

    public function test_no_controls_means_just_the_summary_line(): void
    {
        $xpath = $this->render([]);

        $this->assertSame('Showing everything for here.', $this->summary($xpath));
        $this->assertSame(0, $xpath->query('//form')->length);
        $this->assertSame(0, $xpath->query('//select')->length);
    }

    public function test_it_renders_only_the_controls_a_page_declares(): void
    {
        // A page that declares no campus gets no Campus control: the component never adds one.
        $xpath = $this->render(['controls' => [
            ['kind' => 'select', 'name' => 'status', 'label' => 'Status', 'options' => ['' => 'All', 'Active' => 'Active'], 'selected' => null],
            ['kind' => 'school', 'schools' => School::forDropdowns(), 'campusId' => $this->zabali->campus_id, 'selected' => null],
        ]]);

        $this->assertSame(0, $xpath->query('//select[@name="campus_id"]')->length);
        $this->assertSame(['school', 'status'], $this->controlOrder($xpath), 'school before module-specific, whatever the declared order');
    }

    // ================================ helpers =================================

    protected function page(string $url): array
    {
        $response = $this->actingAs($this->admin)->get($url)->assertOk();

        return [$this->xpath($response->getContent()), $response];
    }

    protected function render(array $props): DOMXPath
    {
        // Outside a request, so share the (empty) error bag the web middleware would.
        view()->share('errors', new \Illuminate\Support\ViewErrorBag);
        $html = (string) $this->blade(
            '<x-filter-bar action="/somewhere" :summary="$summary" :controls="$controls" :applied="$applied" />',
            ['summary' => ['what' => 'everything', 'who' => 'here'], 'controls' => $props['controls'] ?? [], 'applied' => $props['applied'] ?? false],
        );

        return $this->xpath($html);
    }

    protected function xpath(string $html): DOMXPath
    {
        $dom = new DOMDocument;
        libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="UTF-8">' . $html);
        libxml_clear_errors();

        return new DOMXPath($dom);
    }

    protected function summary(DOMXPath $xpath): string
    {
        return trim(preg_replace('/\s+/', ' ', $xpath->query('//*[@data-filter-summary]')->item(0)->textContent));
    }

    protected function controlOrder(DOMXPath $xpath): array
    {
        return array_map(fn ($node) => $node->getAttribute('data-filter-control'), iterator_to_array($xpath->query('//*[@data-filter-control]')));
    }
}
