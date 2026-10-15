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
 * The Consolidated Report's filter form has a field group per period type,
 * and three of them carry an input named "year" (Monthly, Quarterly,
 * Yearly). A form submits every ENABLED field, and PHP keeps the last
 * "year", so unless the inactive groups are disabled the Yearly box wins:
 * a year typed under Monthly or Quarterly was silently discarded. Only the
 * selected type's fields may be enabled.
 */
class ConsolidatedReportPeriodFieldsTest extends TestCase
{
    use RefreshDatabase;

    public function test_only_the_selected_period_types_fields_are_enabled(): void
    {
        $this->travelTo(Carbon::parse('2026-10-11 09:00:00', 'Asia/Manila'));
        $campus = Campus::create(['campus_name' => 'ASCOT Zabali Campus']);
        $admin = Personnel::create(['campus_id' => $campus->campus_id, 'full_name' => 'Report Admin', 'role' => 'DRRMO Admin',
            'email' => 'report-admin@ascot-test.local', 'password_hash' => Hash::make('password')]);

        $queries = [
            'quarterly (the default)' => [],
            'monthly' => ['period_type' => 'monthly', 'month' => 3, 'year' => 2025],
            'quarterly' => ['period_type' => 'quarterly', 'quarter' => 2, 'year' => 2025],
            'yearly' => ['period_type' => 'yearly', 'year' => 2024],
            'weekly' => ['period_type' => 'weekly', 'week_date' => '2026-10-07'],
            'custom' => ['period_type' => 'custom', 'start_date' => '2026-09-01', 'end_date' => '2026-09-30'],
        ];

        foreach ($queries as $case => $query) {
            $html = $this->actingAs($admin)->get(route('reports.consolidated.index', $query))->assertOk()->getContent();
            $dom = new DOMDocument;
            libxml_use_internal_errors(true);
            $dom->loadHTML('<?xml encoding="UTF-8">' . $html);
            libxml_clear_errors();
            $x = new DOMXPath($dom);
            $form = '//form[contains(@action, "reports/consolidated")]';
            $selected = $query['period_type'] ?? 'quarterly';

            $this->assertSame($selected, $x->query("$form//select[@name='period_type']/option[@selected]")->item(0)->getAttribute('value'), $case);
            foreach ($x->query("$form//*[@data-filter-period-fields]") as $group) {
                $active = $group->getAttribute('data-filter-period-fields') === $selected;
                foreach ($x->query('.//input | .//select', $group) as $field) {
                    $this->assertSame(! $active, $field->hasAttribute('disabled'), "{$case}: #{$field->getAttribute('id')}");
                }
            }
            // So exactly one "year" can be submitted — and it is the selected type's.
            $years = $x->query("$form//input[@name='year'][not(@disabled)]");
            $this->assertSame(in_array($selected, ['monthly', 'quarterly', 'yearly'], true) ? 1 : 0, $years->length, $case);
        }
    }
}
