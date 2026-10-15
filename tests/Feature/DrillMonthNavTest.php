<?php

namespace Tests\Feature;

use App\Models\Campus;
use App\Models\Personnel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * The drills calendar's month control: "< October 2026 >". The label is the
 * month on screen and the arrows move it; a separate "Today" link appears
 * only once you've navigated away from the current month. Plain links, so it
 * all works with JavaScript off.
 */
class DrillMonthNavTest extends TestCase
{
    use RefreshDatabase;

    protected Personnel $coordinator;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::parse('2026-10-11 10:00:00', 'Asia/Manila'));
        $campus = Campus::create(['campus_name' => 'ASCOT Zabali Campus']);
        $this->coordinator = Personnel::create(['campus_id' => $campus->campus_id, 'full_name' => 'Nav Coordinator', 'role' => 'Coordinator', 'email' => 'nav@ascot-test.local', 'password_hash' => Hash::make('password')]);
    }

    /** The control on a page: [month label text, prev href, next href, Today href or null]. */
    protected function control(string $url): array
    {
        $document = new \DOMDocument();
        libxml_use_internal_errors(true);
        $document->loadHTML('<?xml encoding="utf-8"?>' . $this->actingAs($this->coordinator)->get($url)->assertOk()->getContent());
        libxml_clear_errors();
        $xpath = new \DOMXPath($document);

        $label = $xpath->query('//*[@data-month-nav]//*[@data-month-label]');
        $this->assertSame(1, $label->length, 'one month label, inside the arrow group');
        $this->assertSame('span', $label->item(0)->nodeName, 'the label is state, not a button or link');
        $prev = $xpath->query('//*[@data-month-nav]/a[@data-month-prev]')->item(0);
        $next = $xpath->query('//*[@data-month-nav]/a[@data-month-next]')->item(0);
        $this->assertSame('Previous month', $prev->getAttribute('aria-label'));
        $this->assertSame('Next month', $next->getAttribute('aria-label'));
        $today = $xpath->query('//a[@data-month-today]');
        $this->assertLessThanOrEqual(1, $today->length);

        return [trim($label->item(0)->textContent), $prev->getAttribute('href'), $next->getAttribute('href'), $today->length ? $today->item(0)->getAttribute('href') : null];
    }

    public function test_the_label_shows_the_viewed_month_and_follows_each_arrow(): void
    {
        [$label, $prev, $next] = $this->control(route('coordinator.drills.index'));
        $this->assertSame('October 2026', $label);

        // Forward twice (across nothing special), then back across the year boundary.
        [$label, , $next2] = $this->control($next);
        $this->assertSame('November 2026', $label);
        [$label] = $this->control($next2);
        $this->assertSame('December 2026', $label);

        [$label, $prev2] = $this->control($prev);
        $this->assertSame('September 2026', $label);
        [$label] = $this->control($prev2);
        $this->assertSame('August 2026', $label);

        [$label, , $next3] = $this->control(route('coordinator.drills.index', ['month' => '2026-12']));
        [$label] = $this->control($next3);
        $this->assertSame('January 2027', $label);

        // The list view carries the same control.
        [$label] = $this->control(route('coordinator.drills.index', ['view' => 'list', 'month' => '2027-03']));
        $this->assertSame('March 2027', $label);
    }

    public function test_today_is_absent_on_the_current_month_and_present_on_any_other(): void
    {
        [, , , $today] = $this->control(route('coordinator.drills.index'));
        $this->assertNull($today, 'no Today link on the current month');
        [, , , $today] = $this->control(route('coordinator.drills.index', ['month' => '2026-10']));
        $this->assertNull($today, 'no Today link on the current month, asked for by name');

        foreach (['2026-09', '2026-11', '2025-10', '2027-10'] as $month) {
            [, , , $today] = $this->control(route('coordinator.drills.index', ['month' => $month]));
            $this->assertNotNull($today, "Today link on $month");
        }
    }

    public function test_today_returns_to_the_current_month(): void
    {
        foreach ([['month' => '2027-02'], ['month' => '2026-05', 'view' => 'list'], ['view' => 'list', 'date' => '2026-05-14']] as $from) {
            [, , , $today] = $this->control(route('coordinator.drills.index', $from));
            $this->assertNotNull($today, json_encode($from));
            [$label, , , $todayAgain] = $this->control($today);
            $this->assertSame('October 2026', $label, 'Today from ' . json_encode($from));
            $this->assertNull($todayAgain, 'and Today disappears once back');
            // It keeps the chosen view (calendar or list).
            $this->assertSame(($from['view'] ?? null) === 'list', str_contains($today, 'view=list'));
        }
    }
}
