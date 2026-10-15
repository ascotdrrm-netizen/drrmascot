<?php

namespace Tests\Feature;

use App\Models\Campus;
use App\Models\Personnel;
use DOMDocument;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * The Dashboard on the shared filter bar: Time range (the period) and, for
 * the Admin, Campus. It keeps its "compared with …" clause — the KPI cards'
 * changes are measured against it. A coordinator's numbers mix scopes
 * (their own incidents and inspections, every drill of the campus), and the
 * line says exactly that.
 */
class FilterBarDashboardTest extends TestCase
{
    use RefreshDatabase;

    protected Campus $zabali;

    protected Campus $bazal;

    protected Personnel $admin;

    protected Personnel $alpha;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::parse('2026-10-11 09:00:00', 'Asia/Manila'));
        $this->zabali = Campus::create(['campus_name' => 'ASCOT Zabali Campus']);
        $this->bazal = Campus::create(['campus_name' => 'ASCOT Bazal Campus']);
        $make = fn (Campus $campus, string $name, string $role) => Personnel::create(['campus_id' => $campus->campus_id, 'full_name' => $name, 'role' => $role,
            'email' => strtolower(str_replace(' ', '-', $name)) . '@ascot-test.local', 'password_hash' => Hash::make('password')]);
        $this->admin = $make($this->zabali, 'Dashboard Admin', 'DRRMO Admin');
        $this->alpha = $make($this->zabali, 'Coordinator Alpha', 'Coordinator');
    }

    public function test_the_admins_bar_keeps_the_comparison(): void
    {
        $x = $this->page($this->admin, route('admin.dashboard'));
        $this->assertSame(['range', 'campus'], $this->controls($x));
        $this->assertSame('apply', $x->query('//*[@data-filter-bar]//form')->item(0)->getAttribute('data-filter-form'));
        $this->assertSame('Showing September 12 – October 11, 2026 for all campuses — compared with August 13 – September 11, 2026.', $this->summary($x));
        $this->assertSame(0, $x->query('//a[@data-filter-clear]')->length, 'the default 30 days is not a filter');

        $x = $this->page($this->admin, route('admin.dashboard', ['range' => 'quarter', 'campus_id' => $this->bazal->campus_id]));
        $this->assertSame('Showing October 1 – October 11, 2026 for ASCOT Bazal Campus — compared with September 20 – September 30, 2026.', $this->summary($x));
        $this->assertSame(1, $x->query('//a[@data-filter-clear]')->length);
    }

    public function test_a_coordinators_line_names_what_their_numbers_cover(): void
    {
        foreach ([[], ['campus_id' => $this->bazal->campus_id]] as $query) {
            $x = $this->page($this->alpha, route('coordinator.dashboard', $query));
            $this->assertSame(['range'], $this->controls($x));
            $this->assertSame(0, $x->query('//select[@name="campus_id"]')->length);
            $this->assertSame('auto', $x->query('//*[@data-filter-bar]//form')->item(0)->getAttribute('data-filter-form'));
            $this->assertSame('Showing September 12 – October 11, 2026 for your incidents and inspections, and every drill, on ASCOT Zabali Campus'
                . ' — compared with August 13 – September 11, 2026.', $this->summary($x));
        }
    }

    public function test_a_bad_range_is_still_rejected(): void
    {
        $this->actingAs($this->admin)->get(route('admin.dashboard', ['range' => 'forever']))->assertSessionHasErrors('range');
    }

    // ================================ helpers =================================

    protected function page(Personnel $as, string $url): DOMXPath
    {
        $dom = new DOMDocument;
        libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="UTF-8">' . $this->actingAs($as)->get($url)->assertOk()->getContent());
        libxml_clear_errors();

        return new DOMXPath($dom);
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
