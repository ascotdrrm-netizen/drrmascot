<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Campus;
use App\Models\Personnel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\Concerns\ReadsReportHtml;
use Tests\TestCase;

class ActivityLogActorTest extends TestCase
{
    use ReadsReportHtml, RefreshDatabase;

    protected Campus $campus;

    protected Personnel $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->campus = Campus::create(['campus_name' => 'ASCOT Zabali Campus']);
        $this->admin = $this->person('Log Admin', 'DRRMO Admin', 'log-admin@ascot-test.local');
    }

    protected function person(string $name, string $role, string $email): Personnel
    {
        return Personnel::create([
            'campus_id' => $this->campus->campus_id,
            'full_name' => $name,
            'role' => $role,
            'email' => $email,
            'password_hash' => Hash::make('password'),
        ]);
    }

    protected function log(?int $personnelId, string $description): ActivityLog
    {
        return ActivityLog::create([
            'personnel_id' => $personnelId,
            'action' => 'login',
            'description' => $description,
        ]);
    }

    protected function actorCells(string $html): array
    {
        // The User column is the 2nd <td> of each row in the activity table.
        preg_match_all('/<tr[^>]*>\s*<td[^>]*>[^<]*<\/td>\s*<td[^>]*>([^<]*)<\/td>/', $html, $m);

        return array_map('trim', $m[1]);
    }

    public function test_row_with_no_personnel_shows_system_not_unknown(): void
    {
        $this->log(null, 'Failed login attempt for someone@example.com');

        $html = $this->actingAs($this->admin)->get(route('admin.activity-log.index'))->assertOk()->getContent();

        $this->assertSame(['System'], $this->actorCells($html));
        $this->assertStringNotContainsString('>Unknown<', $html);
    }

    public function test_deleted_accounts_rows_still_show_the_stored_actor_name(): void
    {
        $jane = $this->person('Jane Cruz', 'Coordinator', 'jane@ascot-test.local');
        $row = $this->log($jane->personnel_id, 'Jane Cruz logged in');

        // The creating hook stored the name at write time.
        $this->assertSame('Jane Cruz', $row->fresh()->actor_name);

        // Hard delete, as PersonnelController::destroy() does; the FK nulls personnel_id.
        $jane->delete();
        $row->refresh();
        $this->assertNull($row->personnel_id);
        $this->assertSame('Jane Cruz', $row->actor_name);

        $html = $this->actingAs($this->admin)->get(route('admin.activity-log.index'))->assertOk()->getContent();
        $this->assertSame(['Jane Cruz (deleted account)'], $this->actorCells($html));
    }

    public function test_rows_with_a_live_personnel_record_are_unaffected(): void
    {
        $juan = $this->person('Juan Dela Cruz', 'Coordinator', 'juan@ascot-test.local');
        $this->log($juan->personnel_id, 'Juan logged in');

        $html = $this->actingAs($this->admin)->get(route('admin.activity-log.index'))->assertOk()->getContent();
        $this->assertSame(['Juan Dela Cruz'], $this->actorCells($html));

        // A live account shows its current name (renames apply), never "(deleted account)".
        $juan->update(['full_name' => 'Juan P. Dela Cruz']);
        $html = $this->actingAs($this->admin)->get(route('admin.activity-log.index'))->getContent();
        $this->assertSame(['Juan P. Dela Cruz'], $this->actorCells($html));
    }

    public function test_actor_name_is_filled_from_the_signed_in_user_and_by_lookup(): void
    {
        $this->actingAs($this->admin);
        $this->assertSame('Log Admin', $this->log($this->admin->personnel_id, 'self')->actor_name);

        $other = $this->person('Maria Santos', 'Coordinator', 'maria@ascot-test.local');
        $this->assertSame('Maria Santos', $this->log($other->personnel_id, 'other')->actor_name);

        $this->assertNull($this->log(null, 'nobody')->actor_name);
    }

    public function test_user_filter_separates_system_rows_from_deleted_accounts(): void
    {
        $this->log(null, 'system row');
        $gone = $this->person('Gone User', 'Coordinator', 'gone@ascot-test.local');
        $this->log($gone->personnel_id, 'deleted-account row');
        $gone->delete();
        $this->log($this->admin->personnel_id, 'live row');

        $system = $this->actingAs($this->admin)->get(route('admin.activity-log.index', ['personnel_id' => 'system']))->getContent();
        $this->assertSame(['System'], $this->actorCells($system));

        $deleted = $this->actingAs($this->admin)->get(route('admin.activity-log.index', ['personnel_id' => 'deleted']))->getContent();
        $this->assertSame(['Gone User (deleted account)'], $this->actorCells($deleted));

        $live = $this->actingAs($this->admin)->get(route('admin.activity-log.index', ['personnel_id' => $this->admin->personnel_id]))->getContent();
        $this->assertSame(['Log Admin'], $this->actorCells($live));
    }
    public function test_activity_log_pdf_prints_readable_action_labels_never_raw_keys(): void
    {
        foreach (['report_exported', 'drill_scheduled', 'login', 'drill_released'] as $action) {
            ActivityLog::create(['personnel_id' => $this->admin->personnel_id, 'action' => $action, 'description' => 'Fixture entry', 'ip_address' => '203.0.113.7']);
        }

        $html = $this->capturePdf($this->admin, 'activity-log.report', route('admin.activity-log.report'))['html'];

        $this->assertMatchesRegularExpression('/<th[^>]*>Action<\/th>/', $html);
        foreach (['Report Exported', 'Drill Scheduled', 'Login', 'Drill Released'] as $label) {
            $this->assertMatchesRegularExpression('/<td>' . preg_quote($label, '/') . '<\/td>/', $html, $label);
        }
        foreach (['report_exported', 'drill_scheduled', 'drill_released'] as $key) {
            $this->assertStringNotContainsString($key, $html, "raw key $key");
        }
        $this->assertDoesNotMatchRegularExpression('/<th[^>]*>IP address<\/th>/i', $html, 'IP stays on screen only');
        $this->assertStringNotContainsString('203.0.113.7', $html);
    }

    public function test_an_unlisted_action_key_is_title_cased_not_shown_raw(): void
    {
        $this->assertSame('Report Exported', (new ActivityLog(['action' => 'report_exported']))->action_label);
        $this->assertSame('Some New Action', (new ActivityLog(['action' => 'some_new_action']))->action_label);
    }
}
