<?php

namespace Tests\Feature;

use App\Models\Campus;
use App\Models\Drill\Drill;
use App\Models\Personnel;
use DOMDocument;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Drills on the shared filter bar: the month navigator stays and stands in
 * for the period (no period picker, so no second date control to disagree
 * with it), Campus for the Admin only, the calendar/list switch at the right.
 */
class FilterBarDrillsTest extends TestCase
{
    use RefreshDatabase;

    protected Campus $zabali;

    protected Campus $bazal;

    protected Personnel $admin;

    protected Personnel $alpha; // coordinator, Zabali

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::parse('2026-10-11 09:00:00', 'Asia/Manila'));
        $this->zabali = Campus::create(['campus_name' => 'ASCOT Zabali Campus']);
        $this->bazal = Campus::create(['campus_name' => 'ASCOT Bazal Campus']);
        $make = fn (Campus $campus, string $name, string $role) => Personnel::create(['campus_id' => $campus->campus_id, 'full_name' => $name, 'role' => $role,
            'email' => strtolower(str_replace(' ', '-', $name)) . '@ascot-test.local', 'password_hash' => Hash::make('password')]);
        $this->admin = $make($this->zabali, 'Drills Admin', 'DRRMO Admin');
        $this->alpha = $make($this->zabali, 'Coordinator Alpha', 'Coordinator');

        foreach ([[$this->zabali, '2026-10-05'], [$this->bazal, '2026-10-06'], [$this->zabali, '2026-09-15']] as [$campus, $date]) {
            Drill::forceCreate(['campus_id' => $campus->campus_id, 'drill_type' => 'Fire', 'scheduled_date' => $date, 'scheduled_time' => '09:00',
                'venue' => 'Gym', 'created_by' => $this->admin->personnel_id]);
        }
    }

    public function test_the_admins_bar_month_navigator_campus_and_switch(): void
    {
        [$x, $r] = $this->page($this->admin, route('admin.drills.index'));
        $this->assertCount(2, $r->viewData('drills')->filter(fn ($d) => $d->scheduled_date->format('Y-m') === '2026-10'));
        // Month navigator first (in the period's place), then Campus; no period picker.
        $this->assertSame(1, $x->query('//*[@data-filter-bar]//*[@data-filter-lead]//*[@data-month-nav]')->length);
        $this->assertSame(['campus'], $this->controls($x));
        $this->assertSame(0, $x->query('//*[@data-filter-bar]//select[@name="period_type"]')->length);
        $this->assertSame('auto', $x->query('//*[@data-filter-bar]//form')->item(0)->getAttribute('data-filter-form'));
        $this->assertSame(1, $x->query('//*[@data-filter-aside]//a[@data-view-link="list"]')->length);
        $this->assertSame('Showing all drills for all campuses, October 2026.', $this->summary($x));
    }

    public function test_changing_campus_keeps_the_month_and_view_and_clear_keeps_them_too(): void
    {
        [$x, $r] = $this->page($this->admin, route('admin.drills.index', ['view' => 'list', 'month' => '2026-09', 'campus_id' => $this->zabali->campus_id]));
        $hidden = [];
        foreach ($x->query('//*[@data-filter-bar]//form//input[@type="hidden"]') as $input) {
            $hidden[$input->getAttribute('name')] = $input->getAttribute('value');
        }
        $this->assertSame(['view' => 'list', 'month' => '2026-09'], $hidden);
        $this->assertSame('Showing all drills for ASCOT Zabali Campus, September 2026.', $this->summary($x));
        $this->assertSame(route('admin.drills.index', ['view' => 'list', 'month' => '2026-09']), $x->query('//a[@data-filter-clear]')->item(0)->getAttribute('href'));
        $this->assertSame(['2026-09-15'], $r->viewData('drills')->pluck('scheduled_date')->map->toDateString()->all());
    }

    public function test_a_coordinator_has_the_navigator_and_switch_but_no_controls(): void
    {
        foreach ([[], ['campus_id' => $this->bazal->campus_id]] as $query) {
            [$x, $r] = $this->page($this->alpha, route('coordinator.drills.index', $query));
            $this->assertSame([], $this->controls($x));
            $this->assertSame(0, $x->query('//*[@data-filter-bar]//form')->length);
            $this->assertSame(0, $x->query('//select[@name="campus_id"]')->length);
            $this->assertSame(1, $x->query('//*[@data-filter-bar]//*[@data-month-nav]')->length);
            $this->assertSame('Showing all drills for ASCOT Zabali Campus, October 2026.', $this->summary($x));
            $this->assertNotContains($this->bazal->campus_id, $r->viewData('drills')->pluck('campus_id')->all());
        }

        // A single day in the list.
        [$x] = $this->page($this->alpha, route('coordinator.drills.index', ['view' => 'list', 'date' => '2026-09-15']));
        $this->assertSame('Showing all drills for ASCOT Zabali Campus, Sep 15, 2026.', $this->summary($x));
    }

    public function test_an_unknown_campus_is_ignored(): void
    {
        [$x, $r] = $this->page($this->admin, route('admin.drills.index', ['campus_id' => '999999']));
        // Both campuses' October drills (the grid starts Sun Sep 27, so not Sep 15).
        $this->assertEqualsCanonicalizing([$this->zabali->campus_id, $this->bazal->campus_id], $r->viewData('drills')->pluck('campus_id')->all());
        $this->assertSame('Showing all drills for all campuses, October 2026.', $this->summary($x));
        $this->assertSame(0, $x->query('//a[@data-filter-clear]')->length);
    }

    // ================================ helpers =================================

    protected function page(Personnel $as, string $url): array
    {
        $response = $this->actingAs($as)->get($url)->assertOk();
        $dom = new DOMDocument;
        libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="UTF-8">' . $response->getContent());
        libxml_clear_errors();

        return [new DOMXPath($dom), $response];
    }

    protected function summary(DOMXPath $x): string
    {
        return trim(preg_replace('/\s+/u', ' ', $x->query('//*[@data-filter-summary]')->item(0)->textContent));
    }

    protected function controls(DOMXPath $x): array
    {
        return array_map(fn ($n) => $n->getAttribute('data-filter-control'), iterator_to_array($x->query('//*[@data-filter-bar]//*[@data-filter-control]')));
    }
}
