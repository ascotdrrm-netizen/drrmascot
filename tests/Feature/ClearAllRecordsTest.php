<?php

namespace Tests\Feature;

use App\Http\Controllers\BackupController;
use App\Models\ActivityLog;
use App\Models\Backup;
use App\Models\Campus;
use App\Models\Personnel;
use App\Models\School;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\BackupZips;
use Tests\TestCase;

/**
 * Backup & Restore → Clear All Records: the gates (Admin only, Data Privacy
 * Password, the typed phrase, a verified pre-clear backup), the wipe (Trash
 * included, files included, children first, all or nothing), what survives,
 * the manifest and the activity-log entry.
 */
class ClearAllRecordsTest extends TestCase
{
    use RefreshDatabase;

    protected const PRIVACY_PASSWORD = 'privacy-secret';

    protected const CLEARED_TABLES = ['staff_notifications', 'broadcast_deliveries', 'broadcasts', 'assignments', 'responders', 'roll_call_responses', 'roll_calls', 'report_events', 'report_photos', 'citizen_reports', 'sos_events', 'campus_statuses', 'alerts', 'damage_reports', 'incidents', 'hazard_inspections', 'buildings', 'drill_attachments', 'drill_documentations', 'drill_after_activity_reports', 'drills', 'disaster_advisories'];

    protected Personnel $admin;

    protected Personnel $coordinator;

    protected string $snapshotDir;

    protected function setUp(): void
    {
        parent::setUp();

        config(['privacy.data_privacy_password_hash' => Hash::make(self::PRIVACY_PASSWORD)]);
        Storage::fake('local');
        Storage::fake('public');
        Storage::fake('records'); // record photos and attachments (AppSupportFiles)
        $this->snapshotDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'ascot-clear-test-' . uniqid();
        mkdir($this->snapshotDir, 0777, true);
        file_put_contents($this->snapshotDir . '/ascot_drrms-2026-10-09-1200.sql', 'earlier automatic copy');
        config(['backup.dir' => $this->snapshotDir]);

        $campus = Campus::create(['campus_name' => 'ASCOT Zabali Campus']);
        $school = School::create(['campus_id' => $campus->campus_id, 'school_name' => 'School of Engineering']);
        $make = fn (string $name, string $role, string $email) => Personnel::create([
            'campus_id' => $campus->campus_id, 'full_name' => $name, 'role' => $role, 'email' => $email, 'password_hash' => Hash::make('password'),
        ]);
        $this->admin = $make('Clear Admin', 'DRRMO Admin', 'clear-admin@ascot-test.local');
        $this->coordinator = $make('Clear Coord', 'Coordinator', 'clear-coord@ascot-test.local');

        // Records of every kind, some in Trash, with files on both disks.
        $c = $campus->campus_id;
        $photo = fn (string $disk, string $path, int $bytes) => Storage::disk($disk)->put($path, str_repeat('x', $bytes)) ? $path : null;
        $b1 = DB::table('buildings')->insertGetId(['campus_id' => $c, 'building_name' => 'Gym', 'risk_level' => 'High Risk', 'photo_path' => $photo('records', 'buildings/gym.jpg', 1000)], 'building_id');
        $b2 = DB::table('buildings')->insertGetId(['campus_id' => $c, 'building_name' => 'Old Hall', 'risk_level' => 'Minimal Risk', 'photo_path' => 'buildings/already-gone.jpg', 'deleted_at' => now()], 'building_id');
        DB::table('hazard_inspections')->insert(['building_id' => $b1, 'school_id' => $school->school_id, 'inspection_date' => '2026-09-01', 'risk_level' => 'High Risk', 'photo_path' => $photo('records', 'hazard-inspections/exit.jpg', 2000)]);
        $advisory = fn (array $more) => DB::table('disaster_advisories')->insertGetId($more + ['hazard_type' => 'Typhoon', 'headline' => 'Signal No. 1', 'source' => 'PAGASA',
            'issued_at' => '2026-09-04 08:00:00', 'valid_until' => '2026-09-06 08:00:00', 'instructions' => 'Secure loose objects.'], 'advisory_id');
        $a1 = $advisory(['campus_id' => null]); // every campus
        $advisory(['campus_id' => $c, 'deleted_at' => now()]);
        $i1 = DB::table('incidents')->insertGetId(['campus_id' => $c, 'advisory_id' => $a1, 'hazard_type' => 'Fire', 'date_occurred' => '2026-09-05', 'description' => 'Kitchen fire', 'photo_path' => $photo('records', 'incidents/fire.jpg', 3000)], 'incident_id');
        DB::table('incidents')->insert(['campus_id' => $c, 'hazard_type' => 'Flood', 'date_occurred' => '2026-09-06', 'description' => 'Trashed flood', 'deleted_at' => now()]);
        DB::table('alerts')->insert(['incident_id' => $i1, 'message' => 'Evacuate', 'sent_at' => '2026-09-05 10:00:00', 'recipient_count' => 3]);
        DB::table('damage_reports')->insert(['incident_id' => $i1, 'building_id' => $b1, 'damage_level' => 'Minor']);
        $d1 = DB::table('drills')->insertGetId(['campus_id' => $c, 'drill_type' => 'Fire', 'scheduled_date' => '2026-09-20', 'scheduled_time' => '09:00:00', 'venue' => 'Court'], 'drill_id');
        DB::table('drills')->insert(['campus_id' => $c, 'drill_type' => 'Flood', 'scheduled_date' => '2026-09-21', 'scheduled_time' => '09:00:00', 'venue' => 'Field', 'deleted_at' => now()]);
        DB::table('drill_documentations')->insert(['drill_id' => $d1, 'date_conducted' => '2026-09-20', 'narrative' => 'Held']);
        DB::table('drill_after_activity_reports')->insert(['drill_id' => $d1, 'activity_title' => 'Fire Drill', 'date_conducted' => '2026-09-20', 'activities_conducted' => 'Alarm']);
        DB::table('drill_attachments')->insert(['drill_id' => $d1, 'section' => 'documentation', 'path' => $photo('records', "drill-attachments/$d1/documentation/a.jpg", 4000), 'original_name' => 'a.jpg', 'mime_type' => 'image/jpeg', 'size_bytes' => 4000]);
        $photo('records', 'buildings/orphan-left-behind.jpg', 500);

        // Things that must survive.
        $photo('records', 'personnel/face.jpg', 100);
        Storage::disk('local')->put('exports/legacy.csv', 'a,b');
        Storage::disk('local')->put('requisition-letters/letter.pdf', 'PDF');
        Storage::disk('local')->put('backups/ascot-drrms-backup-2026-09-01_000000.zip', 'old zip');
        Backup::create(['filename' => 'ascot-drrms-backup-2026-09-01_000000.zip', 'storage_path' => 'backups/ascot-drrms-backup-2026-09-01_000000.zip', 'created_by' => $this->admin->personnel_id, 'file_size' => 7]);
        ActivityLog::create(['personnel_id' => $this->admin->personnel_id, 'action' => 'login', 'description' => 'Earlier login']);
    }

    protected function tearDown(): void
    {
        $this->deleteTree($this->snapshotDir);
        parent::tearDown();
    }

    // ================================== gates =================================

    public function test_a_coordinator_cannot_reach_it_by_route_or_by_form_post(): void
    {
        $this->actingAs($this->coordinator)->get(route('admin.backup.clear-records'))->assertForbidden();
        $this->actingAs($this->coordinator)->post(route('admin.backup.clear-records.run'), $this->validForm())->assertForbidden();

        $this->assertNothingDeleted();
    }

    public function test_a_wrong_data_privacy_password_deletes_nothing(): void
    {
        $this->clear(['data_privacy_password' => 'wrong'])->assertSessionHasErrors('data_privacy_password');

        $this->assertNothingDeleted();
        $this->assertSame(1, ActivityLog::where('action', 'data_privacy_denied')->count(), 'logged like Restore');
        $this->assertSame(0, $this->preClearBackups()->count(), 'no backup was even started');
    }

    public function test_a_wrong_confirmation_phrase_deletes_nothing(): void
    {
        foreach (['clear all records', 'CLEAR ALL RECORD', 'DELETE DATABASE', ''] as $phrase) {
            $this->clear(['confirmation' => $phrase])->assertSessionHasErrors('confirmation');
        }

        $this->assertNothingDeleted();
        $this->assertSame(0, $this->preClearBackups()->count());
    }

    public function test_when_the_backup_fails_verification_nothing_is_deleted_and_the_reason_is_shown(): void
    {
        // A backup that silently loses the incidents' rows.
        $this->app->bind(BackupController::class, fn () => new class extends BackupController {
            protected function writeArchive(string $absolutePath, array $files): array
            {
                $manifest = parent::writeArchive($absolutePath, $files);
                BackupZips::rewrite($absolutePath, fn (\ZipArchive $zip) => $zip->addFromString('data/incidents.jsonl', ''));

                return $manifest;
            }
        });

        $response = $this->clear()->assertRedirect(route('admin.backup.clear-records'));
        $response->assertSessionHasErrors('clear');
        $this->assertStringContainsString('Nothing was deleted', session('errors')->first('clear'));
        $this->assertStringContainsString('table incidents has 0 rows in the backup but 2 in the database', session('errors')->first('clear'));

        $this->assertNothingDeleted();
        $this->assertSame(0, $this->preClearBackups()->count(), 'the unverified copy is not left looking like a good one');
        $this->followRedirects($response)->assertSee('could not be verified');
    }

    public function test_records_added_while_the_backup_is_made_stop_the_wipe(): void
    {
        $this->app->bind(BackupController::class, fn () => new class extends BackupController {
            protected function writeArchive(string $absolutePath, array $files): array
            {
                $manifest = parent::writeArchive($absolutePath, $files);
                DB::table('incidents')->insert(['campus_id' => Campus::first()->campus_id, 'hazard_type' => 'Fire', 'date_occurred' => '2026-10-09', 'description' => 'Reported mid-backup']);

                return $manifest;
            }
        });

        $this->clear()->assertSessionHasErrors('clear');

        $this->assertStringContainsString('Records changed while the backup was being made', session('errors')->first('clear'));
        $this->assertSame(3, DB::table('incidents')->count(), 'nothing deleted, including the new one');
        $this->assertSame(1, $this->preClearBackups()->count(), 'the verified backup is kept');
        $this->assertSame('aborted', $this->manifest()['status']);
    }

    // ================================= success ================================

    public function test_success_clears_every_listed_table_and_file_and_keeps_everything_else(): void
    {
        $this->clear()->assertRedirect(route('admin.backup.clear-records'))->assertSessionHasNoErrors();

        foreach (self::CLEARED_TABLES as $table) {
            $this->assertSame(0, DB::table($table)->count(), "$table is empty, Trash included");
        }
        foreach (['buildings/gym.jpg', 'hazard-inspections/exit.jpg', 'incidents/fire.jpg', 'buildings/orphan-left-behind.jpg'] as $path) {
            Storage::disk('records')->assertMissing($path);
        }
        $this->assertSame([], Storage::disk('records')->allFiles('drill-attachments'));

        // Kept.
        $this->assertSame(2, Personnel::count());
        $this->assertSame(1, Campus::count());
        $this->assertSame(1, School::count());
        $this->assertTrue(ActivityLog::where('description', 'Earlier login')->exists());
        Storage::disk('records')->assertExists('personnel/face.jpg');
        Storage::disk('local')->assertExists('requisition-letters/letter.pdf');
        Storage::disk('local')->assertExists('backups/ascot-drrms-backup-2026-09-01_000000.zip');
        $this->assertTrue(Backup::where('filename', 'ascot-drrms-backup-2026-09-01_000000.zip')->exists());
    }

    public function test_clear_includes_reporter_submissions_sos_status_and_their_files(): void
    {
        $reporter = Personnel::create(['campus_id' => \App\Models\Campus::value('campus_id'),
            'full_name' => 'Reporter Test', 'role' => 'Reporter', 'email' => 'reporter@example.test',
            'password_hash' => Hash::make('password')]);
        $id = DB::table('citizen_reports')->insertGetId([
            'reporter_id' => $reporter->personnel_id, 'campus_id' => $reporter->campus_id,
            'client_key' => (string) \Illuminate\Support\Str::uuid(), 'category' => 'flood',
            'place' => 'Gym', 'description' => 'Water inside the gym.', 'status' => 'New',
            'proof_path' => 'attachments/proof.pdf', 'created_at' => now(), 'updated_at' => now(),
        ], 'report_id');
        DB::table('report_photos')->insert(['report_id' => $id, 'path' => 'reports/photo.jpg']);
        DB::table('report_events')->insert(['report_id' => $id, 'status' => 'New', 'note' => 'Submitted', 'created_at' => now()]);
        DB::table('sos_events')->insert(['reporter_id' => $reporter->personnel_id,
            'campus_id' => $reporter->campus_id, 'client_key' => (string) \Illuminate\Support\Str::uuid(),
            'status' => 'New', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('campus_statuses')->insert(['campus_id' => $reporter->campus_id,
            'status' => 'evacuate', 'detail' => 'Leave now.', 'updated_at' => now(), 'created_at' => now()]);
        Storage::disk('records')->put('reports/photo.jpg', 'PHOTO');
        Storage::disk('records')->put('attachments/proof.pdf', 'PROOF');

        $rollCall = \App\Models\RollCall::create(['campus_id' => $reporter->campus_id, 'title' => 'Safety check', 'started_at' => now(), 'created_by' => $this->admin->getKey()]);
        $rollCall->responses()->create(['reporter_id' => $reporter->getKey(), 'full_name' => $reporter->full_name, 'answer' => 'Safe', 'responded_at' => now()]);
        $responder = \App\Models\Responder::create(['campus_id' => $reporter->campus_id, 'full_name' => 'Campus Responder', 'team' => 'Clinic', 'contact_number' => '09171234567']);
        \App\Models\Assignment::create(['campus_id' => $reporter->campus_id, 'responder_id' => $responder->getKey(), 'report_id' => $id,
            'assigned_by' => $this->admin->getKey(), 'assigned_at' => now(), 'status' => 'Assigned']);

        $this->clear()->assertSessionHasNoErrors();

        foreach (['report_events', 'report_photos', 'citizen_reports', 'sos_events', 'campus_statuses', 'roll_calls', 'roll_call_responses', 'responders', 'assignments'] as $table) {
            $this->assertDatabaseCount($table, 0);
        }
        Storage::disk('records')->assertMissing('reports/photo.jpg');
        Storage::disk('records')->assertMissing('attachments/proof.pdf');
        $this->assertDatabaseHas('personnel', ['personnel_id' => $reporter->personnel_id]);
        $this->actingAs($this->admin)->post(route('admin.backup.stored.restore', $this->preClearBackups()->sole()), [
            'confirmation' => 'RESTORE', 'data_privacy_password' => self::PRIVACY_PASSWORD,
        ])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('roll_call_responses', ['reporter_id' => $reporter->getKey(), 'answer' => 'Safe']);
        $this->assertDatabaseHas('assignments', ['responder_id' => $responder->getKey(), 'report_id' => $id]);
    }

    public function test_the_activity_log_entry_and_the_manifest_record_what_was_removed(): void
    {
        $this->clear();

        $log = ActivityLog::where('action', 'records_cleared')->sole();
        $backup = $this->preClearBackups()->sole();
        $this->assertSame($this->admin->personnel_id, $log->personnel_id);
        $this->assertSame('backup', $log->subject_type);
        $this->assertSame($backup->backup_id, $log->subject_id);
        $this->assertStringContainsString('cleared all records: 14 records and 5 files deleted', $log->description);
        $this->assertStringContainsString(pathinfo($backup->filename, PATHINFO_FILENAME) . '.manifest.json', $log->description);
        $this->assertSame('Records Cleared', $log->action_label);

        $m = $this->manifest();
        $this->assertSame('completed', $m['status']);
        $this->assertSame('Clear Admin', $m['cleared_by']['name']);
        $this->assertSame(
            ['staff_notifications' => 0, 'broadcast_deliveries' => 0, 'broadcasts' => 0, 'assignments' => 0, 'responders' => 0, 'roll_call_responses' => 0, 'roll_calls' => 0, 'report_events' => 0, 'report_photos' => 0, 'citizen_reports' => 0, 'sos_events' => 0, 'campus_statuses' => 0, 'alert_recipients' => 0, 'alerts' => 1, 'damage_reports' => 1, 'incidents' => 2, 'disaster_advisories' => 2, 'hazard_inspections' => 1, 'buildings' => 2, 'drill_attachments' => 1, 'drill_documentations' => 1, 'drill_after_activity_reports' => 1, 'drills' => 2],
            array_map(fn ($t) => $t['deleted'], $m['tables'])
        );
        $this->assertSame(1, $m['tables']['incidents']['of_which_in_trash']);
        $this->assertSame(1, $m['tables']['buildings']['of_which_in_trash']);
        $this->assertSame(1, $m['tables']['drills']['of_which_in_trash']);
        $this->assertSame(1, $m['tables']['disaster_advisories']['of_which_in_trash']);
        $this->assertEqualsCanonicalizing(
            ['buildings/gym.jpg', 'hazard-inspections/exit.jpg', 'incidents/fire.jpg', 'buildings/orphan-left-behind.jpg', 'drill-attachments/1/documentation/a.jpg'],
            array_map(fn ($f) => preg_replace('#drill-attachments/\d+/#', 'drill-attachments/1/', $f['path']), $m['files']['deleted'])
        );
        $this->assertSame([['disk' => 'records', 'path' => 'buildings/already-gone.jpg']], $m['files']['missing_before']);
        $this->assertSame([], $m['files']['not_deleted']);
        $this->assertSame(['totals' => 14, 'files' => 5], ['totals' => $m['totals']['records_deleted'], 'files' => $m['totals']['files_deleted']]);
    }

    public function test_the_screen_names_the_pre_clear_copy_afterwards(): void
    {
        $response = $this->followRedirects($this->clear());

        $backup = $this->preClearBackups()->sole();
        $response->assertSee('Records cleared: 14 records and 5 files deleted.')
            ->assertSee($backup->filename)
            ->assertSee(pathinfo($backup->filename, PATHINFO_FILENAME) . '.manifest.json')
            ->assertSee('pre-clear-');
    }

    public function test_the_pre_clear_backup_restores_and_is_never_pruned(): void
    {
        $this->clear();
        $backup = $this->preClearBackups()->sole();

        // 20 more regular backups, then one more download: pruning runs, the pre-clear copy stays.
        foreach (range(1, 20) as $n) {
            Storage::disk('local')->put("backups/regular-$n.zip", 'zip');
            Backup::create(['filename' => "ascot-drrms-backup-regular-$n.zip", 'storage_path' => "backups/regular-$n.zip", 'created_by' => $this->admin->personnel_id, 'file_size' => 3]);
        }
        $this->actingAs($this->admin)->post(route('admin.backup.download'), ['data_privacy_password' => self::PRIVACY_PASSWORD])->assertOk();
        $this->assertTrue(Backup::whereKey($backup->backup_id)->exists());
        Storage::disk('local')->assertExists($backup->storage_path);
        $this->assertSame(20, Backup::where('filename', 'not like', BackupController::PRE_CLEAR_PREFIX . '%')->count());

        // And it is a real way back: restoring it brings the records back.
        $this->actingAs($this->admin)->post(route('admin.backup.stored.restore', $backup), [
            'confirmation' => 'RESTORE', 'data_privacy_password' => self::PRIVACY_PASSWORD,
        ])->assertSessionHasNoErrors();
        $this->assertSame(2, DB::table('incidents')->count());
        $this->assertSame(2, DB::table('buildings')->count());
    }

    public function test_clear_then_restore_returns_everything_including_trash_and_photos(): void
    {
        $before = collect(self::CLEARED_TABLES)->mapWithKeys(fn ($t) => [$t => DB::table($t)->orderByRaw('1')->get()->map(fn ($r) => (array) $r)->all()])->all();
        $files = ['records' => array_merge(['buildings/gym.jpg', 'hazard-inspections/exit.jpg', 'incidents/fire.jpg', 'buildings/orphan-left-behind.jpg'], Storage::disk('records')->allFiles('drill-attachments'))];
        $bytes = collect($files)->flatMap(fn ($paths, $disk) => collect($paths)->mapWithKeys(fn ($p) => ["$disk:$p" => Storage::disk($disk)->get($p)]))->all();
        $this->clear();
        $backup = $this->preClearBackups()->sole();

        // The pre-clear zip carries every record file under files/<disk>/<path>.
        $entries = BackupZips::entries(Storage::disk('local')->path($backup->storage_path));
        foreach (array_keys($bytes) as $key) {
            [$disk, $path] = explode(':', $key, 2);
            $this->assertArrayHasKey("files/$disk/$path", $entries, "$key is in the zip");
        }

        $this->actingAs($this->admin)->post(route('admin.backup.stored.restore', $backup), [
            'confirmation' => 'RESTORE', 'data_privacy_password' => self::PRIVACY_PASSWORD,
        ])->assertSessionHasNoErrors()->assertSessionHas('status', fn ($s) => str_starts_with($s, 'Restored from ') && str_contains($s, '5 uploaded files restored'));

        $after = collect(self::CLEARED_TABLES)->mapWithKeys(fn ($t) => [$t => DB::table($t)->orderByRaw('1')->get()->map(fn ($r) => (array) $r)->all()])->all();
        $this->assertSame($before, $after, 'every row back exactly, Trash included');
        foreach ($bytes as $key => $content) {
            [$disk, $path] = explode(':', $key, 2);
            $this->assertSame($content, Storage::disk($disk)->get($path), "$key back byte for byte");
        }
        $this->assertFalse(ActivityLog::where('action', 'records_cleared')->exists(), 'the activity log goes back to before the clear');
        $this->assertSame(2, Personnel::count());
    }

    public function test_a_photo_missing_from_the_pre_clear_zip_fails_verification(): void
    {
        $this->app->bind(BackupController::class, fn () => new class extends BackupController {
            protected function createStoredBackup(bool $preClear = false, array $files = []): Backup
            {
                return parent::createStoredBackup($preClear, array_slice($files, 1)); // drops one photo
            }
        });

        $this->clear()->assertSessionHasErrors('clear');

        $this->assertStringContainsString('is missing from the backup', session('errors')->first('clear'));
        $this->assertNothingDeleted();
    }

    // ============================ folders untouched ===========================

    public function test_the_snapshot_folder_and_exports_are_untouched_and_the_extra_copy_is_written(): void
    {
        $this->clear();

        $this->assertSame('earlier automatic copy', file_get_contents($this->snapshotDir . '/ascot_drrms-2026-10-09-1200.sql'));
        Storage::disk('local')->assertExists('exports/legacy.csv');
        $this->assertSame('a,b', Storage::disk('local')->get('exports/legacy.csv'));

        $folders = glob($this->snapshotDir . '/pre-clear-*', GLOB_ONLYDIR);
        $this->assertCount(1, $folders);
        $this->assertFileExists($folders[0] . '/' . $this->preClearBackups()->sole()->filename);
        $this->assertSame(1000, filesize($folders[0] . '/files/records/buildings/gym.jpg'), 'deleted photos stay recoverable here');
        $this->assertCount(1, glob($folders[0] . '/files/records/drill-attachments/*/documentation/a.jpg'));
        $this->assertCount(1, glob($folders[0] . '/*.manifest.json'));
    }

    public function test_without_automatic_copies_it_still_clears_on_the_stored_backup_alone(): void
    {
        config(['backup.dir' => null]);

        $this->actingAs($this->admin)->get(route('admin.backup.clear-records'))->assertOk()->assertSee('restoring it brings back the records and their photos (and the activity log as it was before the clear)', false)->assertDontSee('pre-clear-…</span> folder', false);
        $this->clear()->assertSessionHasNoErrors();

        $this->assertSame(0, DB::table('incidents')->count());
        $this->assertSame(1, $this->preClearBackups()->count());
        $this->assertNull($this->manifest()['extra_copy']);
    }

    // ================================= screen =================================

    public function test_the_screen_lists_live_counts_including_trash(): void
    {
        $this->actingAs($this->admin)->get(route('admin.backup.clear-records'))->assertOk()
            ->assertSee('2 incidents, 2 buildings/areas, 1 hazard inspection, 2 drills, 1 SMS &amp; broadcast alert, 2 disaster advisories, 1 damage report', false)
            ->assertSee('5 uploaded files', false)
            ->assertSee('1 file named on a record is already missing from disk')
            ->assertSee('CLEAR ALL RECORDS')
            ->assertSee('2 personnel accounts')
            ->assertSee('1 campus<', false);
    }

    public function test_the_screen_warns_when_the_copy_nears_the_hosts_file_size_limit(): void
    {
        $page = fn () => $this->actingAs($this->admin)->get(route('admin.backup.clear-records'))->assertOk();
        $estimate = \App\Support\RecordsClear::estimatedCopyBytes();

        // Well under the default 10 MB: no warning.
        $page()->assertDontSee('data-clear-size-warning', false);

        // At 90% of the configured limit: warned, in words that say nothing is lost.
        config(['backup.max_file_bytes' => (int) floor($estimate / 0.9)]);
        $page()->assertSee('data-clear-size-warning', false)
            ->assertSee('The copy taken first will be about ' . \App\Support\RecordsClear::size($estimate) . '.')
            ->assertSee("the copy can't be written and clearing will stop before anything is deleted.", false);

        // Just below 90%: quiet again.
        config(['backup.max_file_bytes' => (int) floor($estimate / 0.9) + 2048]);
        $page()->assertDontSee('data-clear-size-warning', false);
    }

    public function test_the_warning_names_the_configured_limit(): void
    {
        config(['backup.max_file_bytes' => 10 * 1024 * 1024]);
        $this->writeLargeRecordFile(10 * 1024 * 1024 * 0.95);

        $this->actingAs($this->admin)->get(route('admin.backup.clear-records'))->assertOk()
            ->assertSee('If this server limits files to 10 MB,', false);
    }

    // ================================ helpers =================================

    protected function writeLargeRecordFile(float $bytes): void
    {
        Storage::disk('records')->put('buildings/large.jpg', str_repeat('x', (int) $bytes));
    }

    protected function validForm(): array
    {
        return ['data_privacy_password' => self::PRIVACY_PASSWORD, 'confirmation' => 'CLEAR ALL RECORDS'];
    }

    protected function clear(array $overrides = []): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($this->admin)->from(route('admin.backup.clear-records'))
            ->post(route('admin.backup.clear-records.run'), array_merge($this->validForm(), $overrides));
    }

    protected function assertNothingDeleted(): void
    {
        $this->assertSame([2, 2, 2, 1, 1, 1, 1, 1, 1], array_map(fn ($t) => DB::table($t)->count(), ['incidents', 'buildings', 'drills', 'alerts', 'damage_reports', 'hazard_inspections', 'drill_documentations', 'drill_after_activity_reports', 'drill_attachments']));
        Storage::disk('records')->assertExists('buildings/gym.jpg');
        $this->assertCount(1, Storage::disk('records')->allFiles('drill-attachments'));
        $this->assertFalse(ActivityLog::where('action', 'records_cleared')->exists());
    }

    protected function preClearBackups()
    {
        return Backup::where('filename', 'like', BackupController::PRE_CLEAR_PREFIX . '%');
    }

    protected function manifest(): array
    {
        $backup = $this->preClearBackups()->sole();

        return json_decode(Storage::disk('local')->get('backups/' . pathinfo($backup->filename, PATHINFO_FILENAME) . '.manifest.json'), true);
    }

    protected function deleteTree(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }
        $items = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($items as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($dir);
    }
}
