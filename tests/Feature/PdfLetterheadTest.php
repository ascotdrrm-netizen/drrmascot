<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Advisory\DisasterAdvisory;
use App\Models\Alert;
use App\Models\Building\Building;
use App\Models\Building\HazardInspection;
use App\Models\Campus;
use App\Models\Drill\Drill;
use App\Models\Drill\DrillAfterActivityReport;
use App\Models\Drill\DrillDocumentation;
use App\Models\Incident\Incident;
use App\Models\Personnel;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Every dompdf output prints on the ASCOT letterhead (config/pdf.php): the
 * artwork full-bleed behind every page, content inside the measured content
 * box, and the page-number line between the content and the yellow band.
 *
 * Checked on the real PDF bytes: each page's content stream is inflated and
 * the drawing operators dompdf writes (image "cm /I Do", text "BT x y Td",
 * fills "re f", rules "m … l S") are read with their page coordinates.
 */
class PdfLetterheadTest extends TestCase
{
    use RefreshDatabase;

    protected Personnel $admin;

    protected Drill $drill;

    /** @var array<string, string> name => URL of every PDF the system prints */
    protected array $outputs;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::parse('2026-09-25 10:00:00', 'Asia/Manila'));
        Storage::fake('local');
        Storage::fake('public');

        $campus = Campus::create(['campus_name' => 'ASCOT Zabali Campus']);
        $this->admin = Personnel::create([
            'campus_id' => $campus->campus_id, 'full_name' => 'Letterhead Admin', 'role' => 'DRRMO Admin',
            'email' => 'letterhead-admin@ascot-test.local', 'password_hash' => Hash::make('password'),
        ]);

        $building = Building::create(['campus_id' => $campus->campus_id, 'building_name' => 'Zabali Gym', 'hazard_type' => 'Fire', 'risk_level' => 'High Risk']);
        $inspection = HazardInspection::create(['building_id' => $building->building_id, 'inspection_date' => '2026-08-01', 'findings' => 'Blocked exit beside the stage.', 'risk_level' => 'High Risk']);
        $incident = Incident::create(['campus_id' => $campus->campus_id, 'hazard_type' => 'Fire', 'date_occurred' => '2026-08-05', 'status' => 'Resolved', 'description' => 'Kitchen fire, contained.']);
        $advisory = DisasterAdvisory::forceCreate(['hazard_type' => 'Typhoon', 'headline' => 'Typhoon Egay over Aurora', 'measurements' => ['storm_name' => 'Egay', 'wind_signal' => '2', 'max_winds' => '120'], 'source' => 'PAGASA', 'issued_at' => '2026-09-25 08:00:00', 'expected_at' => '2026-09-25 18:00:00', 'valid_until' => '2026-09-26 08:00:00', 'status' => 'Active', 'instructions' => 'Classes are suspended. Stay indoors and follow your building warden.', 'campus_id' => $campus->campus_id]);
        $alert = Alert::create(['incident_id' => $incident->incident_id, 'message' => 'Evacuate the canteen now.', 'sent_at' => '2026-08-05 10:00:00', 'recipient_count' => 30, 'delivery_status' => 'Sent']);

        $this->drill = Drill::forceCreate(['campus_id' => $campus->campus_id, 'drill_type' => 'Fire', 'scheduled_date' => '2026-08-03', 'scheduled_time' => '09:00:00', 'venue' => 'Zabali Quadrangle', 'created_by' => $this->admin->personnel_id]);
        (new DrillDocumentation(['date_conducted' => '2026-08-03', 'narrative' => 'Alarm at 9:00, building cleared in 4 minutes.', 'participant_count' => 412]))
            ->forceFill(['drill_id' => $this->drill->drill_id, 'submitted_by_name' => $this->admin->full_name])->save();
        (new DrillAfterActivityReport(['activity_title' => 'Fire Drill', 'date_conducted' => '2026-08-03', 'activities_conducted' => 'Alarm, evacuation, head count.']))
            ->forceFill(['drill_id' => $this->drill->drill_id])->save();

        // Enough long entries that the activity log runs to several pages, so
        // the last page (not just the first) is checked too.
        foreach (range(1, 90) as $i) {
            ActivityLog::forceCreate([
                'personnel_id' => $this->admin->personnel_id, 'action' => 'report_exported', 'ip_address' => '127.0.0.1',
                'description' => "Entry {$i}: downloaded the Incident Reporting report for the third quarter of 2026, filtered to every campus and every school.",
                'created_at' => now()->subMinutes($i),
            ]);
        }

        $period = ['period_type' => 'quarterly', 'quarter' => 3, 'year' => 2026];
        $this->outputs = [
            'consolidated report' => route('reports.consolidated.pdf', $period),
            'period report: hazard' => route('reports.module.pdf', ['module' => 'hazard'] + $period),
            'period report: incidents' => route('reports.module.pdf', ['module' => 'incidents'] + $period),
            'period report: drill schedule' => route('reports.module.pdf', ['module' => 'drills'] + $period),
            'period report: alerts' => route('reports.module.pdf', ['module' => 'alerts'] + $period),
            'consolidated drill documentation' => route('reports.module.pdf', ['module' => 'drill-documentation'] + $period),
            'consolidated after activity reports' => route('reports.module.pdf', ['module' => 'after-activity'] + $period),
            'building record' => route('admin.buildings.report', $building),
            'inspection record' => route('admin.buildings.inspections.report', [$building, $inspection]),
            'incident record' => route('admin.incidents.report', $incident),
            'alert record' => route('admin.incidents.alerts.report', [$incident, $alert]),
            'drill documentation' => route('admin.drills.documentation.pdf', $this->drill),
            'drill after activity report' => route('admin.drills.after-activity.pdf', $this->drill),
            'drill memorandum' => route('admin.drills.memorandum', $this->drill),
            'disaster advisory memo' => route('admin.advisories.memorandum', $advisory),
            'activity log' => route('admin.activity-log.report'),
            'personnel list' => route('admin.personnel.report'),
        ];
    }

    // ================================= tests ==================================

    public function test_every_pdf_has_the_letterhead_behind_every_page_including_the_last(): void
    {
        [$width, $height] = $this->paper();

        foreach ($this->outputs as $name => $url) {
            $pdf = $this->download($url, $name);
            $pages = $this->pages($pdf);
            $this->assertNotEmpty($pages, "$name: no pages");

            foreach ($pages as $n => $content) {
                $where = "$name, page " . ($n + 1) . ' of ' . count($pages);
                $this->assertMatchesRegularExpression('#/MediaBox \[0\.000 0\.000 ' . sprintf('%.3F %.3F', $width, $height) . '\]#', $content['dict'], "$where: paper size");

                // The letterhead is the first thing drawn, so everything else sits on top of it.
                $first = $this->firstDrawing($content['stream']);
                $this->assertSame('image', $first['kind'], "$where: something is drawn before the letterhead");
                $this->assertEqualsWithDelta([0, 0, $width, $height], [$first['x'], $first['y'], $first['w'], $first['h']], 0.01, "$where: letterhead is not full-bleed");
                $this->assertSame([1308, 2000], $this->imageSize($pdf, $first['label']), "$where: the full-page image is not the letterhead artwork");
            }
        }

        $this->assertGreaterThan(1, count($this->pages($this->download($this->outputs['activity log'], 'activity log'))), 'the activity log fixture should run to several pages');
    }

    public function test_nothing_overlaps_the_letterhead_header_or_footer_band(): void
    {
        $box = $this->contentBox();

        foreach ($this->outputs as $name => $url) {
            $pages = $this->pages($this->download($url, $name));

            foreach ($pages as $n => $content) {
                $where = "$name, page " . ($n + 1);
                $stream = $content['stream'];

                foreach ($this->textRuns($stream) as $run) {
                    if ($run['y'] < $box['bottom']) {
                        continue; // the footer line, checked in the next test
                    }
                    $this->assertLessThanOrEqual($box['top'] + 0.5, $run['y'] + 0.8 * $run['size'], "$where: \"{$run['text']}\" reaches into the letterhead header");
                    $this->assertGreaterThanOrEqual($box['left'] - 0.5, $run['x'], "$where: \"{$run['text']}\" starts left of the margin");
                }

                foreach ($this->rectangles($stream) as [$x, $y, $w, $h]) {
                    $this->assertInBox($box, min($x, $x + $w), min($y, $y + $h), max($x, $x + $w), max($y, $y + $h), "$where: fill");
                }

                foreach ($this->rules($stream) as [$x1, $y1, $x2, $y2]) {
                    $this->assertInBox($box, min($x1, $x2), min($y1, $y2), max($x1, $x2), max($y1, $y2), "$where: rule");
                }

                foreach (array_slice($this->images($stream), 1) as $img) {
                    $this->assertInBox($box, $img['x'], $img['y'], $img['x'] + $img['w'], $img['y'] + $img['h'], "$where: image {$img['label']}");
                }
            }
        }
    }

    /**
     * No text runs past the content box's right edge, the footer line
     * included (fills, rules and images are already held inside the box on
     * all four sides by the test above; the letterhead artwork is the one
     * full-bleed exception). Each run's width is measured from the embedded
     * font's glyph widths, the way dompdf placed it.
     *
     * The measurement is checked against a known edge first: the
     * right-aligned "Page X of Y" must end ON the right edge. A width
     * calculation that came out short would fail that, instead of quietly
     * letting overflow through.
     */
    public function test_nothing_runs_past_the_right_edge_of_the_content_box(): void
    {
        $box = $this->contentBox();
        $tolerance = 0.5;

        foreach ($this->outputs as $name => $url) {
            $pdf = $this->download($url, $name);
            $fonts = $this->fontWidths($pdf);
            $pages = $this->pages($pdf);

            foreach ($pages as $n => $content) {
                $where = "$name, page " . ($n + 1);
                $runs = $this->measuredTextRuns($content['stream'], $fonts, $where);

                foreach ($runs as $run) {
                    $this->assertLessThanOrEqual($box['right'] + $tolerance, $run['right'], sprintf(
                        '%s: "%s" runs %.1f pt past the right edge of the content box', $where, $run['text'], $run['right'] - $box['right']
                    ));
                }

                $pageNumber = array_values(array_filter($runs, fn ($r) => $r['y'] < $box['bottom'] && str_starts_with(ltrim($r['text']), 'of ')));
                $this->assertCount(1, $pageNumber, "$where: the \"of Y\" part of the page number");
                $this->assertEqualsWithDelta($box['right'], $pageNumber[0]['right'], $tolerance, "$where: the right-aligned page number should end on the right edge — the width measurement is off");
            }
        }
    }

    public function test_page_numbers_sit_between_the_content_and_the_yellow_band_on_every_page(): void
    {
        [, $height] = $this->paper();
        $box = $this->contentBox();
        $bandTop = $height * (1 - config('pdf.footer_band_top'));

        foreach (['activity log', 'drill documentation', 'consolidated report'] as $name) {
            $pages = $this->pages($this->download($this->outputs[$name], $name));

            foreach ($pages as $n => $content) {
                $where = "$name, page " . ($n + 1);
                $footer = array_values(array_filter($this->textRuns($content['stream']), fn ($r) => $r['y'] < $box['bottom']));
                $this->assertNotEmpty($footer, "$where: no footer line");

                foreach ($footer as $run) {
                    $this->assertGreaterThanOrEqual($bandTop, $run['y'] - 0.25 * $run['size'], "$where: \"{$run['text']}\" dips into the yellow band");
                    $this->assertLessThanOrEqual($box['bottom'], $run['y'] + 0.8 * $run['size'], "$where: \"{$run['text']}\" runs into the content");
                }

                $line = preg_replace('/\s+/', ' ', implode('', array_column($footer, 'text')));
                $this->assertStringContainsString('Page ' . ($n + 1) . ' of ' . count($pages), $line, $where);
            }
        }
    }

    public function test_drill_documentation_with_photos_renders_on_the_letterhead_in_reasonable_time(): void
    {
        $drill = Drill::forceCreate(['campus_id' => $this->drill->campus_id, 'drill_type' => 'Earthquake', 'scheduled_date' => '2026-09-20', 'scheduled_time' => '09:00:00', 'venue' => 'Covered Court', 'created_by' => $this->admin->personnel_id]);
        $this->actingAs($this->admin)->put(route('admin.drills.documentation.update', $drill), [
            'date_conducted' => '2026-09-20', 'narrative' => 'Duck, cover and hold; evacuation to the open field.',
            'attachments' => array_map(fn ($i) => $this->photo("photo-$i.jpg"), range(1, 3)),
        ])->assertSessionHasNoErrors();

        $started = microtime(true);
        $pdf = $this->download(route('admin.drills.documentation.pdf', $drill), 'drill documentation with photos');
        $seconds = microtime(true) - $started;

        $pages = $this->pages($pdf);
        $photos = array_merge(...array_map(fn ($p) => array_slice($this->images($p['stream']), 1), $pages));
        $this->assertCount(3, $photos, 'all three photos are printed');
        $this->assertLessThan(10, $seconds, sprintf('rendered in %.2f s', $seconds));
    }

    // ================================ helpers =================================

    protected function download(string $url, string $name): string
    {
        $response = $this->actingAs($this->admin)->get($url);
        $this->assertSame(200, $response->getStatusCode(), "$name: HTTP {$response->getStatusCode()}");
        $this->assertSame('application/pdf', $response->headers->get('Content-Type'), $name);

        return $response->getContent();
    }

    /** [width, height] of the paper in points. */
    protected function paper(): array
    {
        [$width, $height] = config('pdf.paper_inches');

        return [$width * 72, $height * 72];
    }

    /** The content box in PDF coordinates (points, origin bottom-left). */
    protected function contentBox(): array
    {
        [$width, $height] = $this->paper();
        $m = array_map(fn ($in) => $in * 72, config('pdf.margins_inches'));

        return ['left' => $m['left'], 'right' => $width - $m['right'], 'bottom' => $m['bottom'], 'top' => $height - $m['top']];
    }

    protected function assertInBox(array $box, float $x1, float $y1, float $x2, float $y2, string $what): void
    {
        $tolerance = 1.0; // half a border width either side
        $this->assertTrue(
            $x1 >= $box['left'] - $tolerance && $x2 <= $box['right'] + $tolerance && $y1 >= $box['bottom'] - $tolerance && $y2 <= $box['top'] + $tolerance,
            sprintf('%s at (%.1f, %.1f)–(%.1f, %.1f) is outside the content box', $what, $x1, $y1, $x2, $y2),
        );
    }

    /**
     * Pages in order, each with its dictionary and its inflated content stream.
     *
     * @return list<array{dict: string, stream: string}>
     */
    protected function pages(string $pdf): array
    {
        preg_match_all('#\n(\d+) 0 obj\s*<<\s*/Type /Page\b(?!s)(.*?)>>\s*endobj#s', $pdf, $found, PREG_SET_ORDER);

        return array_map(function ($page) use ($pdf) {
            preg_match('#/Contents\s*(\[[^\]]*\]|\d+ 0 R)#', $page[2], $contents);
            preg_match_all('#(\d+) 0 R#', $contents[1], $refs);

            return ['dict' => $page[2], 'stream' => implode("\n", array_map(fn ($id) => $this->stream($pdf, (int) $id), $refs[1]))];
        }, $found);
    }

    protected function stream(string $pdf, int $id): string
    {
        $this->assertSame(1, preg_match('#\n' . $id . ' 0 obj\s*<<(.*?)>>\s*stream\r?\n#s', $pdf, $m, PREG_OFFSET_CAPTURE), "object $id not found");
        preg_match('#/Length (\d+)#', $m[1][0], $length);
        $raw = substr($pdf, $m[0][1] + strlen($m[0][0]), (int) $length[1]);

        return str_contains($m[1][0], '/FlateDecode') ? gzuncompress($raw) : $raw;
    }

    /** Pixel size of the image XObject drawn as /$label. */
    protected function imageSize(string $pdf, string $label): array
    {
        preg_match('#/' . $label . ' (\d+) 0 R#', $pdf, $ref);
        preg_match('#\n' . $ref[1] . ' 0 obj\s*<<(.*?)>>#s', $pdf, $dict);
        preg_match('#/Width (\d+)#', $dict[1], $w);
        preg_match('#/Height (\d+)#', $dict[1], $h);

        return [(int) $w[1], (int) $h[1]];
    }

    /** @return list<array{label: string, x: float, y: float, w: float, h: float}> in drawing order */
    protected function images(string $stream): array
    {
        preg_match_all('#([\d.-]+) 0 0 ([\d.-]+) ([\d.-]+) ([\d.-]+) cm /(\w+) Do#', $stream, $m, PREG_SET_ORDER);

        return array_map(fn ($i) => ['label' => $i[5], 'x' => (float) $i[3], 'y' => (float) $i[4], 'w' => (float) $i[1], 'h' => (float) $i[2]], $m);
    }

    /** The first image, text or fill drawn on the page. */
    protected function firstDrawing(string $stream): array
    {
        $candidates = [];
        if (preg_match('#[\d.-]+ 0 0 [\d.-]+ [\d.-]+ [\d.-]+ cm /\w+ Do#', $stream, $m, PREG_OFFSET_CAPTURE)) {
            $candidates[$m[0][1]] = ['kind' => 'image'] + $this->images($m[0][0])[0];
        }
        foreach (['text' => '#\bBT\b#', 'fill' => '#\bre f\b#', 'rule' => '#\bl S\b#'] as $kind => $pattern) {
            if (preg_match($pattern, $stream, $m, PREG_OFFSET_CAPTURE)) {
                $candidates[$m[0][1]] = ['kind' => $kind];
            }
        }
        ksort($candidates);

        return reset($candidates) ?: ['kind' => 'nothing'];
    }

    /** @return list<array{x: float, y: float, size: float, text: string}> baseline positions */
    protected function textRuns(string $stream): array
    {
        preg_match_all('#\bBT ([\d.-]+) ([\d.-]+) Td(.*?)\bET\b#s', $stream, $m, PREG_SET_ORDER);

        return array_map(function ($run) {
            preg_match('#/\w+ ([\d.]+) Tf#', $run[3], $size);
            preg_match_all('#\((.*?)(?<!\\\\)\)#s', $run[3], $strings);

            return ['x' => (float) $run[1], 'y' => (float) $run[2], 'size' => (float) ($size[1] ?? 0), 'text' => str_replace("\0", '', implode('', $strings[1]))];
        }, $m);
    }

    /**
     * Text runs with where they end. dompdf writes each run as
     * "BT x y Td [c Tc] /F size Tf [(…)] TJ": a 2-byte Identity-H string whose
     * codes index the font's /W widths (1/1000 of the size), plus Tc after
     * every glyph. Anything else it can't measure fails rather than being
     * skipped.
     *
     * @param  array<string, array{dw: float, w: array<int, float>}>  $fonts
     * @return list<array{x: float, y: float, right: float, text: string}>
     */
    protected function measuredTextRuns(string $stream, array $fonts, string $where): array
    {
        preg_match_all('#\bBT ([\d.-]+) ([\d.-]+) Td(.*?)\bET\b#s', $stream, $m, PREG_SET_ORDER);

        return array_map(function ($run) use ($fonts, $where) {
            $ops = $run[3];
            $this->assertSame(1, preg_match('#/(\w+) ([\d.]+) Tf#', $ops, $tf), "$where: a text run with no font: $ops");
            $this->assertSame(0, preg_match('#\b(Tz|Tw|Tm|TL|T\*|Ts)\b#', $ops), "$where: a text operator this check doesn't measure: $ops");
            $this->assertArrayHasKey($tf[1], $fonts, "$where: font /{$tf[1]} has no Identity-H widths to measure");
            $this->assertSame(1, preg_match('#\[(.*)\]\s*TJ#s', $ops, $array, PREG_OFFSET_CAPTURE), "$where: a text run without TJ: $ops");
            // The character spacing in force when TJ draws.
            preg_match_all('#([\d.-]+) Tc#', substr($ops, 0, $array[0][1]), $tc);
            $charSpacing = $tc[1] ? (float) end($tc[1]) : 0.0;
            $size = (float) $tf[2];
            $font = $fonts[$tf[1]];

            $advance = 0.0;
            $text = '';
            $glyphs = 0;
            foreach ($this->tjElements($array[1][0]) as $element) {
                if (is_float($element)) {
                    $advance -= $element / 1000 * $size; // a TJ kerning adjustment
                    continue;
                }
                foreach (str_split($element, 2) as $code) {
                    $cid = unpack('n', str_pad($code, 2, "\0", STR_PAD_LEFT))[1];
                    $advance += ($font['w'][$cid] ?? $font['dw']) / 1000 * $size + $charSpacing;
                    $text .= mb_chr($cid, 'UTF-8');
                    $glyphs++;
                }
            }
            // Tc after the last glyph is spacing, not ink.
            $advance -= $glyphs > 0 ? $charSpacing : 0.0;

            return ['x' => (float) $run[1], 'y' => (float) $run[2], 'right' => (float) $run[1] + $advance, 'text' => $text];
        }, $m);
    }

    /** @return list<string|float> the strings (raw bytes) and numbers in a TJ array */
    protected function tjElements(string $array): array
    {
        $elements = [];
        $i = 0;
        $length = strlen($array);
        while ($i < $length) {
            $char = $array[$i];
            if ($char === '(') {
                [$string, $i] = $this->pdfLiteralString($array, $i);
                $elements[] = $string;
            } elseif (preg_match('#\G-?[\d.]+#', $array, $number, 0, $i)) {
                $elements[] = (float) $number[0];
                $i += strlen($number[0]);
            } else {
                $i++;
            }
        }

        return $elements;
    }

    /** A PDF literal string starting at $start ("("): [bytes, offset after its ")"]. */
    protected function pdfLiteralString(string $s, int $start): array
    {
        $escapes = ['n' => "\n", 'r' => "\r", 't' => "\t", 'b' => "\x08", 'f' => "\f", '(' => '(', ')' => ')', '\\' => '\\'];
        $out = '';
        $depth = 0;
        for ($i = $start + 1; $i < strlen($s); $i++) {
            $char = $s[$i];
            if ($char === '\\') {
                $next = $s[++$i];
                if (preg_match('#\G[0-7]{1,3}#', $s, $octal, 0, $i)) {
                    $out .= chr(octdec($octal[0]) & 0xFF);
                    $i += strlen($octal[0]) - 1;
                } elseif ($next === "\r" || $next === "\n") {
                    // a line continuation adds nothing
                } else {
                    $out .= $escapes[$next] ?? $next;
                }
            } elseif ($char === '(') {
                $depth++;
                $out .= $char;
            } elseif ($char === ')') {
                if ($depth-- === 0) {
                    return [$out, $i + 1];
                }
                $out .= $char;
            } else {
                $out .= $char;
            }
        }
        $this->fail('Unterminated string in a TJ array');
    }

    /**
     * Glyph widths of every font the pages use, by resource name (/F1 …),
     * from each Type0 font's CIDFont: /DW and the /W array, whose entries are
     * "first [w w …]" or "first last w".
     *
     * @return array<string, array{dw: float, w: array<int, float>}>
     */
    protected function fontWidths(string $pdf): array
    {
        $object = function (int $id) use ($pdf): string {
            $this->assertSame(1, preg_match('#\n' . $id . ' 0 obj\s*<<(.*?)>>\s*endobj#s', $pdf, $m), "font object $id not found");

            return $m[1];
        };

        $fonts = [];
        preg_match_all('#/Font\s*<<(.*?)>>#s', $pdf, $dicts);
        foreach ($dicts[1] as $dict) {
            preg_match_all('#/(\w+) (\d+) 0 R#', $dict, $refs, PREG_SET_ORDER);
            foreach ($refs as [, $name, $id]) {
                $font = $object((int) $id);
                if (! str_contains($font, '/Identity-H') || ! preg_match('#/DescendantFonts \[(\d+) 0 R\]#', $font, $descendant)) {
                    continue; // not measurable: a run using it fails in measuredTextRuns()
                }
                $cidFont = $object((int) $descendant[1]);
                preg_match('#/DW ([\d.]+)#', $cidFont, $dw);
                $widths = [];
                if (preg_match('#/W\s*\[#', $cidFont, $w, PREG_OFFSET_CAPTURE)) {
                    // The array runs to the bracket that closes it (its entries hold brackets too).
                    $open = $w[0][1] + strlen($w[0][0]);
                    for ($end = $open, $depth = 1; $depth > 0; $end++) {
                        $depth += ['[' => 1, ']' => -1][$cidFont[$end]] ?? 0;
                    }
                    preg_match_all('#\[([^\]]*)\]|-?[\d.]+#', substr($cidFont, $open, $end - $open - 1), $tokens, PREG_SET_ORDER);
                    for ($t = 0; $t < count($tokens); $t++) {
                        $first = (int) $tokens[$t][0];
                        if (isset($tokens[$t + 1][1])) { // first [w w …]
                            foreach (preg_split('#\s+#', trim($tokens[++$t][1])) as $k => $width) {
                                $widths[$first + $k] = (float) $width;
                            }
                        } else { // first last w
                            $last = (int) $tokens[++$t][0];
                            $width = (float) $tokens[++$t][0];
                            for ($cid = $first; $cid <= $last; $cid++) {
                                $widths[$cid] = $width;
                            }
                        }
                    }
                }
                $fonts[$name] = ['dw' => (float) ($dw[1] ?? 1000), 'w' => $widths];
            }
        }

        return $fonts;
    }

    /** @return list<array{float, float, float, float}> x, y, w, h of every filled rectangle */
    protected function rectangles(string $stream): array
    {
        preg_match_all('#([\d.-]+) ([\d.-]+) ([\d.-]+) ([\d.-]+) re f\b#', $stream, $m, PREG_SET_ORDER);

        return array_map(fn ($r) => array_map('floatval', array_slice($r, 1)), $m);
    }

    /** @return list<array{float, float, float, float}> x1, y1, x2, y2 of every straight rule */
    protected function rules(string $stream): array
    {
        preg_match_all('#([\d.-]+) ([\d.-]+) m ([\d.-]+) ([\d.-]+) l S#', $stream, $m, PREG_SET_ORDER);

        return array_map(fn ($r) => array_map('floatval', array_slice($r, 1)), $m);
    }

    /** A phone-sized JPEG with photo-like detail (a flat fake image compresses to nothing). */
    protected function photo(string $name): UploadedFile
    {
        $small = imagecreatetruecolor(300, 200);
        for ($y = 0; $y < 200; $y++) {
            for ($x = 0; $x < 300; $x++) {
                imagesetpixel($small, $x, $y, (($x * 7 + $y * 3) % 256) << 16 | mt_rand(0, 255) << 8 | (($x + $y * 5) % 256));
            }
        }
        $photo = imagecreatetruecolor(3000, 2000);
        imagecopyresampled($photo, $small, 0, 0, 0, 0, 3000, 2000, 300, 200);
        $path = tempnam(sys_get_temp_dir(), 'pdfphoto');
        imagejpeg($photo, $path, 85);

        return new UploadedFile($path, $name, 'image/jpeg', null, true);
    }
}
