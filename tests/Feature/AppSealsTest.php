<?php

namespace Tests\Feature;

use App\Models\Advisory\DisasterAdvisory;
use App\Models\Building\Building;
use App\Models\Campus;
use App\Models\Drill\Drill;
use App\Models\Incident\Incident;
use App\Models\Personnel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AppSealsTest extends TestCase
{
    use RefreshDatabase;

    protected Personnel $admin;

    protected Personnel $coordinator;

    protected function setUp(): void
    {
        parent::setUp();

        $campus = Campus::create(['campus_name' => 'ASCOT Zabali Campus']);
        $this->admin = Personnel::create(['campus_id' => $campus->campus_id, 'full_name' => 'Seal Admin', 'role' => 'DRRMO Admin', 'email' => 'seal-admin@ascot-test.local', 'password_hash' => Hash::make('password')]);
        $this->coordinator = Personnel::create(['campus_id' => $campus->campus_id, 'full_name' => 'Seal Coordinator', 'role' => 'Coordinator', 'email' => 'seal-coord@ascot-test.local', 'password_hash' => Hash::make('password')]);
    }

    /*
     * The seals' place, reversed deliberately from acc5263 (which kept them in
     * the sidebar and out of the page header): they now sit on the right of
     * the page title from 640px, and in the phone top bar below that. The
     * sidebar starts with the wordmark. Each page's actions get their own row
     * under the title, so buttons never compete with the title for width.
     */

    public function test_the_seals_are_in_the_page_header_and_the_phone_top_bar_and_not_the_sidebar(): void
    {
        foreach ([[$this->admin, 'admin.dashboard'], [$this->coordinator, 'coordinator.dashboard']] as [$user, $route]) {
            $xpath = $this->dom($this->actingAs($user)->get(route($route))->assertOk()->getContent());

            // Two places, each seal once in each, and nowhere else.
            foreach (['ascot-logo-256.png', 'drrm-logo-256.png'] as $logo) {
                $this->assertSame(2, $xpath->query("//img[contains(@src, 'images/{$logo}')]")->length, "$route: $logo appears twice (header + top bar)");
                $this->assertSame(0, $xpath->query("//aside[@id='sidebar']//img[contains(@src, 'images/{$logo}')]")->length, "$route: $logo in the sidebar");
            }
            $this->assertSame(0, $xpath->query("//aside[@id='sidebar']//img")->length, "$route: an image in the sidebar");

            // Page header: on the title row, beside the title block; shown from 640px.
            $header = $this->single($xpath, "//main//*[@data-title-row]/*[@data-app-seals='header']", "$route: header seals");
            $this->assertSame(1, $xpath->query("//main//*[@data-title-row]//h1[@class='page-title']")->length, "$route: title on the title row");
            $this->assertClasses(['app-seals', 'hidden', 'sm:flex'], $header, "$route: header seals");
            $this->assertSeals($xpath, $header, "$route: header seals");

            // Phone top bar (outside <main> and the sidebar); shown below 640px.
            $topbar = $this->single($xpath, "//*[@data-app-seals='topbar']", "$route: top bar seals");
            $this->assertSame(0, $xpath->query("//main//*[@data-app-seals='topbar'] | //aside//*[@data-app-seals='topbar']")->length);
            $this->assertClasses(['app-seals', 'app-seals-bar', 'sm:hidden'], $topbar, "$route: top bar seals");
            $this->assertClasses(['lg:hidden'], $topbar->parentNode, "$route: the top bar is the phone/tablet bar");
            $this->assertSame('sidebar-open', $xpath->query(".//button[@id='sidebar-open']", $topbar->parentNode)->item(0)?->getAttribute('id'), "$route: seals share the bar with the menu button");
            $this->assertSeals($xpath, $topbar, "$route: top bar seals");
        }
    }

    public function test_the_sidebar_starts_with_the_wordmark(): void
    {
        foreach ([[$this->admin, 'admin.dashboard'], [$this->coordinator, 'coordinator.dashboard']] as [$user, $route]) {
            $xpath = $this->dom($this->actingAs($user)->get(route($route))->assertOk()->getContent());

            // The first thing in the sidebar that shows content is the wordmark
            // (the drawer's close button is only an icon, top-right, phones only).
            $first = $xpath->query("//aside[@id='sidebar']//*[self::img or self::span[@data-sidebar-wordmark] or self::nav or self::a][1]")->item(0);
            $this->assertNotNull($first, $route);
            $this->assertTrue($first->hasAttribute('data-sidebar-wordmark'), "$route: the sidebar starts with <{$first->nodeName}> instead of the wordmark");
            $this->assertSame('ASCOT-DRRMS', trim($first->textContent));
            // 24px in the desktop sidebar; 22px in the phone drawer, beside the close button.
            $this->assertClasses(['lg:text-2xl', 'text-[22px]', 'font-bold'], $first, "$route: wordmark size");

            // Then the signed-in user, then the navigation.
            $aside = $xpath->query("//aside[@id='sidebar']")->item(0)->textContent;
            $this->assertTrue(strpos($aside, 'ASCOT-DRRMS') < strpos($aside, $user->full_name) && strpos($aside, $user->full_name) < strpos($aside, 'Dashboard'), "$route: wordmark, user, nav order");

            // The drawer's close button can't overlap the wordmark whatever the font:
            // both are items of one flex row, the button can't shrink, and the
            // wordmark may only shrink by wrapping.
            $close = $xpath->query("//button[@id='sidebar-close']")->item(0);
            $this->assertSame($first->parentNode, $close->parentNode, "$route: wordmark and close button share one row");
            $this->assertClasses(['flex'], $close->parentNode, "$route: that row is a flex row");
            $this->assertClasses(['shrink-0', 'lg:hidden'], $close, "$route: close button keeps its size, phones/tablets only");
            $this->assertClasses(['min-w-0', 'break-words'], $first, "$route: wordmark wraps rather than overflows");
            $this->assertStringNotContainsString('absolute', $close->getAttribute('class'), "$route: close button must not float over the wordmark");
        }
    }

    /**
     * The pages with the most header buttons — the advisory page (four) and
     * the incidents, buildings and drills lists (three each) — keep the title
     * and the buttons on separate rows: the header stacks the title row and
     * an actions row, the title row holds only the title and the seals, and
     * every button is in the actions row.
     */
    public function test_on_the_busiest_pages_the_buttons_have_their_own_row_below_the_title(): void
    {
        $incident = Incident::forceCreate(['campus_id' => $this->admin->campus_id, 'hazard_type' => 'Fire', 'date_occurred' => '2026-09-20', 'status' => 'Active', 'created_by' => $this->admin->personnel_id]);
        $incident->delete();
        $building = Building::create(['campus_id' => $this->admin->campus_id, 'building_name' => 'Old Annex', 'hazard_type' => 'Fire', 'risk_level' => 'Low']);
        $building->delete();
        $drill = Drill::forceCreate(['campus_id' => $this->admin->campus_id, 'drill_type' => 'Fire', 'scheduled_date' => '2026-09-20', 'scheduled_time' => '09:00:00', 'venue' => 'Gym', 'created_by' => $this->admin->personnel_id]);
        $drill->delete();
        $advisory = DisasterAdvisory::forceCreate(['hazard_type' => 'Typhoon', 'campus_id' => null, 'headline' => 'Typhoon Egay — Signal No. 2 over Aurora', 'measurements' => ['wind_signal' => '2'], 'source' => 'PAGASA', 'issued_at' => now(), 'valid_until' => now()->addDay(), 'status' => 'Active', 'instructions' => 'Stay indoors.', 'created_by' => $this->admin->personnel_id]);

        $pages = [
            route('admin.advisories.show', $advisory) => ['Record what happened', 'Print advisory memo', 'Edit', 'Delete'],
            route('admin.incidents.index') => ['View Trash (1)', '+ Report incident', 'Generate Report'],
            route('admin.buildings.index') => ['View Trash (1)', '+ Add Building/Area', 'Generate Report'],
            route('admin.drills.index') => ['View Trash (1)', '+ Schedule Drill', 'Generate Report'],
        ];

        foreach ($pages as $url => $buttons) {
            $xpath = $this->dom($this->actingAs($this->admin)->get($url)->assertOk()->getContent());

            // The header stacks two blocks: the title row, then the actions row.
            $header = $this->single($xpath, "//main//*[@data-page-header]", $url);
            $this->assertNotContains('flex', explode(' ', $header->getAttribute('class')), "$url: the header itself must not be a row");
            $children = array_values(array_filter(iterator_to_array($header->childNodes), fn ($n) => $n instanceof \DOMElement));
            $this->assertCount(2, $children, "$url: title row + actions row");
            $this->assertTrue($children[0]->hasAttribute('data-title-row'), "$url: title row first");
            $this->assertTrue($children[1]->hasAttribute('data-page-actions'), "$url: actions row second");

            // The title row competes with nothing: no buttons or links in it.
            $this->assertSame(0, $xpath->query("//*[@data-title-row]//a | //*[@data-title-row]//button")->length, "$url: a button on the title row");

            // Every one of the page's buttons is in the actions row.
            $labels = [];
            foreach ($xpath->query("//*[@data-page-actions]//a | //*[@data-page-actions]//button") as $node) {
                $labels[] = trim(preg_replace('/\s+/', ' ', $node->textContent));
            }
            foreach ($buttons as $label) {
                $this->assertContains($label, $labels, "$url: \"$label\" not in the actions row");
            }
        }
    }

    // ================================ helpers =================================

    protected function dom(string $html): \DOMXPath
    {
        $document = new \DOMDocument();
        libxml_use_internal_errors(true); // HTML5 tags (dialog, nav…) aren't libxml's HTML 4
        $document->loadHTML('<?xml encoding="utf-8"?>' . $html);
        libxml_clear_errors();

        return new \DOMXPath($document);
    }

    protected function single(\DOMXPath $xpath, string $query, string $message): \DOMElement
    {
        $nodes = $xpath->query($query);
        $this->assertSame(1, $nodes->length, "$message: expected exactly one match for $query");

        return $nodes->item(0);
    }

    protected function assertClasses(array $expected, \DOMElement $element, string $message): void
    {
        $classes = explode(' ', preg_replace('/\s+/', ' ', $element->getAttribute('class')));
        foreach ($expected as $class) {
            $this->assertContains($class, $classes, "$message: missing class \"$class\"");
        }
    }

    /** Both seals, ASCOT first, with their alt text. */
    protected function assertSeals(\DOMXPath $xpath, \DOMElement $container, string $message): void
    {
        $images = $xpath->query('.//img', $container);
        $this->assertSame(2, $images->length, $message);
        $this->assertStringEndsWith('images/ascot-logo-256.png', $images->item(0)->getAttribute('src'), $message);
        $this->assertSame('ASCOT Official Seal', $images->item(0)->getAttribute('alt'), $message);
        $this->assertStringEndsWith('images/drrm-logo-256.png', $images->item(1)->getAttribute('src'), $message);
        $this->assertSame('DRRM Office Logo', $images->item(1)->getAttribute('alt'), $message);
    }
}
