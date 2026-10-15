<?php

namespace Tests\Feature;

use App\Models\Campus;
use App\Models\Personnel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * The landing page's emergency hotlines, all from config/hotlines.php: every
 * number renders as a tap-to-call link, the radio frequency is never a link,
 * and an entry with no number says "to be provided" instead of a blank line.
 * The DRRM Officer card they replaced is gone, with its profile-page note.
 */
class LandingHotlinesTest extends TestCase
{
    use RefreshDatabase;

    protected function page(): \DOMXPath
    {
        $document = new \DOMDocument();
        libxml_use_internal_errors(true);
        $document->loadHTML('<?xml encoding="utf-8"?>' . $this->get(route('landing'))->assertOk()->getContent());
        libxml_clear_errors();

        return new \DOMXPath($document);
    }

    protected function text(\DOMNode $node): string
    {
        return trim(preg_replace('/\s+/', ' ', $node->textContent));
    }

    /** The rendered entry <li> for a label, inside the hotlines section. */
    protected function entry(\DOMXPath $xpath, string $label): \DOMElement
    {
        foreach ($xpath->query("//section[@data-hotlines]//li[@data-hotline-entry]") as $li) {
            if ($this->text($xpath->query('./span[1]', $li)->item(0)) === $label) {
                return $li;
            }
        }
        $this->fail("no hotline entry labelled \"$label\"");
    }

    public function test_every_configured_number_renders_as_a_tap_to_call_link(): void
    {
        $xpath = $this->page();
        $groups = config('hotlines.groups');
        $this->assertNotEmpty($groups);

        // Every group, in order.
        $titles = array_map(fn ($n) => $this->text($n), iterator_to_array($xpath->query("//section[@data-hotlines]//*[@data-hotline-group]/h3")));
        $this->assertSame(array_column($groups, 'title'), $titles);

        $expected = 0;
        foreach ($groups as $group) {
            foreach ($group['entries'] as $entry) {
                $li = $this->entry($xpath, $entry['label']);
                $links = $xpath->query('.//a[@data-hotline-number]', $li);
                $numbers = $entry['numbers'] ?? [];
                $this->assertSame(count($numbers), $links->length, "{$entry['label']}: one link per number");
                foreach ($numbers as $i => $number) {
                    $expected++;
                    $this->assertSame($number, $this->text($links->item($i)), "{$entry['label']}: number as printed");
                    $digits = preg_replace('/\D/', '', $number);
                    $tel = strlen($digits) === 11 && $digits[0] === '0' ? '+63' . substr($digits, 1) : $digits;
                    $this->assertSame("tel:$tel", $links->item($i)->getAttribute('href'), "{$entry['label']}: tel link");
                }
            }
        }
        // Nothing renders that isn't configured.
        $this->assertSame($expected, $xpath->query("//section[@data-hotlines]//a[@data-hotline-number]")->length);
        // Spot check against the posters.
        $this->assertSame('tel:+639171529432', $this->entry($xpath, 'ASCOT DRRMO Office')->getElementsByTagName('a')->item(0)->getAttribute('href'));
    }

    public function test_an_entry_with_no_number_shows_to_be_provided_not_a_blank_line(): void
    {
        $li = $this->entry($xpath = $this->page(), 'Campus Security');
        $this->assertSame('to be provided', $this->text($xpath->query('.//*[@data-hotline-pending]', $li)->item(0)));
        $this->assertSame(0, $li->getElementsByTagName('a')->length);
        $this->assertSame('Campus Security to be provided', $this->text($li));

        // Any entry configured without numbers gets the same treatment.
        // (The list is edited in the app now: App\Support\Hotlines reads the hotline tables.)
        \App\Models\Hotline\HotlineGroup::query()->delete();
        $group = \App\Models\Hotline\HotlineGroup::create(['title' => 'Test', 'sort_order' => 0]);
        $group->hotlines()->create(['label' => 'Gate Guard', 'numbers' => [], 'sort_order' => 0]);
        $group->hotlines()->create(['label' => 'Clinic', 'sort_order' => 1]);
        \App\Support\Hotlines::flush();
        $xpath = $this->page();
        foreach (['Gate Guard', 'Clinic'] as $label) {
            $li = $this->entry($xpath, $label);
            $this->assertSame("$label to be provided", $this->text($li));
            $this->assertSame(0, $li->getElementsByTagName('a')->length);
        }
    }

    public function test_the_radio_frequency_is_marked_as_radio_and_is_not_a_link(): void
    {
        $li = $this->entry($xpath = $this->page(), 'MDRRMO radio frequency');
        $radio = $xpath->query('.//*[@data-hotline-radio]', $li)->item(0);
        $this->assertNotNull($radio);
        $this->assertSame('Radio 147.110 MHz', $this->text($radio));
        $this->assertSame(0, $li->getElementsByTagName('a')->length, 'a radio frequency must not be a tel: link');
        $this->assertSame(0, $xpath->query("//a[contains(@href, '147110') or contains(@href, '147.110')]")->length);
    }

    public function test_each_campus_has_its_own_municipal_group_marked_with_the_campus_it_serves(): void
    {
        $xpath = $this->page();
        $subtitles = [];
        foreach ($xpath->query("//section[@data-hotlines]//*[@data-hotline-group]") as $group) {
            $subtitle = $xpath->query('./p[not(@data-hotline-note)]', $group)->item(0);
            $subtitles[$this->text($group->getElementsByTagName('h3')->item(0))] = $subtitle ? $this->text($subtitle) : null;
        }
        $this->assertSame('Serves Zabali Campus', $subtitles['Baler'] ?? null);
        $this->assertSame('Serves Bazal Campus', $subtitles['Maria Aurora'] ?? null);
        $this->assertSame('Serves Casiguran Campus', $subtitles['Casiguran'] ?? null);

        // Baler's note no longer says the other campuses' numbers are missing.
        foreach ($xpath->query("//section[@data-hotlines]//*[@data-hotline-group]") as $group) {
            if ($this->text($group->getElementsByTagName('h3')->item(0)) === 'Baler') {
                $this->assertSame('These are the Baler municipality numbers, nearest to Zabali Campus.', $this->text($xpath->query('.//*[@data-hotline-note]', $group)->item(0)));
            }
        }
    }

    /** Masonry by CSS columns, and no card may split across two columns. */
    public function test_the_groups_flow_in_columns_and_no_card_can_split(): void
    {
        $xpath = $this->page();

        $columns = $xpath->query("//section[@data-hotlines]//*[@data-hotline-columns]")->item(0);
        $this->assertNotNull($columns, 'the hotline column container');
        $classes = explode(' ', $columns->getAttribute('class'));
        // Two columns beside "The DRRM Office" from 640px (the list has two thirds of the width).
        foreach (['columns-1', 'sm:columns-2'] as $class) {
            $this->assertContains($class, $classes);
        }

        $cards = $xpath->query('./*[@data-hotline-group]', $columns);
        $this->assertSame(count(config('hotlines.groups')), $cards->length);
        foreach ($cards as $card) {
            $title = $this->text($card->getElementsByTagName('h3')->item(0));
            $this->assertStringContainsString('break-inside: avoid', $card->getAttribute('style'), "$title can split across columns");
            $this->assertContains('break-inside-avoid', explode(' ', $card->getAttribute('class')), "$title can split across columns");
        }
    }

    /** The page must not tell people to call a number it doesn't list. */
    public function test_the_page_lists_and_mentions_no_911(): void
    {
        $html = $this->get(route('landing'))->assertOk()->getContent();

        $this->assertStringNotContainsString('911', strip_tags($html));
        $this->assertStringNotContainsString('tel:911', $html);
        $this->assertStringContainsString('Use the numbers for the municipality where your campus is.', $html);
    }

    public function test_the_ascot_group_says_where_the_office_is(): void
    {
        $xpath = $this->page();
        foreach ($xpath->query("//section[@data-hotlines]//*[@data-hotline-group]") as $group) {
            if ($this->text($group->getElementsByTagName('h3')->item(0)) === 'ASCOT') {
                $this->assertStringContainsString('Ermita Hill, Zabali Campus', $this->text($xpath->query('.//*[@data-hotline-note]', $group)->item(0)));

                return;
            }
        }
        $this->fail('no ASCOT group');
    }

    public function test_the_officer_card_and_its_profile_note_are_gone_and_the_office_intro_stays(): void
    {
        $campus = Campus::create(['campus_name' => 'ASCOT Zabali Campus']);
        $admin = Personnel::forceCreate(['campus_id' => $campus->campus_id, 'full_name' => 'Maria Santos', 'role' => 'DRRMO Admin', 'email' => 'admin@ascot-test.local', 'password_hash' => Hash::make('password'), 'photo_path' => 'personnel/maria.jpg']);

        $html = $this->get(route('landing'))->assertOk()->getContent();
        $this->assertStringNotContainsString('data-drrm-officer', $html);
        $this->assertStringNotContainsString('DRRM Officer', $html);
        $this->assertStringNotContainsString('Maria Santos', $html, 'no personnel name on the public page');
        $this->assertStringNotContainsString('personnel/maria.jpg', $html);

        // The office intro has its own section after the hotlines (UI audit:
        // emergency numbers first, background last).
        $this->assertSame(1, preg_match('/<section[^>]*data-hotlines.*?<\/section>/s', $html, $m));
        $this->assertStringNotContainsString('The DRRM Office', $m[0]);
        $this->assertSame(1, preg_match('/<section[^>]*data-drrm-office.*?<\/section>/s', $html, $about));
        $this->assertStringContainsString('The DRRM Office', $about[0]);
        $this->assertGreaterThan(strpos($html, 'data-hotlines'), strpos($html, 'data-drrm-office'));

        $this->actingAs($admin)->get(route('admin.profile.show'))->assertOk()
            ->assertDontSee('data-public-photo-note', false)
            ->assertDontSee('home page as the DRRM Officer');
    }
}
