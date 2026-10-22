<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Backup;
use App\Models\Campus;
use App\Models\Personnel;
use App\Models\School;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Concerns\BackupZips;
use Tests\TestCase;
use ZipArchive;

/**
 * Restore → "Records only" (the default) versus "Everything in the backup":
 * what each replaces, account references to deleted accounts, campuses and
 * schools that no longer exist, and the record files every backup carries
 * under files/.
 */
class RecordsRestoreTest extends TestCase
{
    use RefreshDatabase;

    protected const PRIVACY_PASSWORD = 'privacy-secret';

    protected Campus $zabali;

    protected Campus $bazal;

    protected School $engineering;

    protected Personnel $admin;

    protected Personnel $coordinator;

    protected function setUp(): void
    {
        parent::setUp();

        config(['privacy.data_privacy_password_hash' => Hash::make(self::PRIVACY_PASSWORD)]);
        Storage::fake('local');
        Storage::fake('public');
        Storage::fake('records');

        $this->zabali = Campus::create(['campus_name' => 'ASCOT Zabali Campus']);
        $this->bazal = Campus::create(['campus_name' => 'ASCOT Bazal Campus']);
        $this->engineering = School::create(['campus_id' => $this->zabali->campus_id, 'school_name' => 'School of Engineering']);
        $this->admin = $this->person('Restore Admin', 'DRRMO Admin', 'restore-admin@ascot-test.local');
        $this->coordinator = $this->person('Coordinator Alpha', 'Coordinator', 'alpha@ascot-test.local');
    }

    // ============================== records only ==============================

    public function test_records_only_replaces_records_and_leaves_accounts_campuses_schools_and_the_log(): void
    {
        $this->incident($this->zabali, 'In the backup');
        $backup = $this->backup();

        // After the backup: a record, an account, a campus, a school and log entries change.
        $this->incident($this->zabali, 'Added after the backup');
        $later = $this->person('Added Later', 'Coordinator', 'later@ascot-test.local');
        $this->admin->update(['full_name' => 'Renamed Admin']);
        $newCampus = Campus::create(['campus_name' => 'ASCOT Casiguran Campus']);
        School::create(['campus_id' => $newCampus->campus_id, 'school_name' => 'School of Fisheries']);
        ActivityLog::create(['personnel_id' => $this->admin->personnel_id, 'action' => 'records_cleared', 'description' => 'Cleared after the backup']);
        $logBefore = ActivityLog::orderBy('activity_log_id')->pluck('description')->all();

        $this->restoreStored($backup)->assertSessionHasNoErrors()
            ->assertSessionHas('status', fn ($s) => str_starts_with($s, "Records restored from {$backup->filename}: 1 records."));

        $this->assertSame(['In the backup'], DB::table('incidents')->pluck('description')->all());
        $this->assertTrue(Personnel::whereKey($later->personnel_id)->exists(), 'an account added since is kept');
        $this->assertSame('Renamed Admin', $this->admin->fresh()->full_name);
        $this->assertSame(3, Campus::count());
        $this->assertSame(2, School::count());
        $this->assertSame($logBefore, ActivityLog::orderBy('activity_log_id')->pluck('description')->all(), 'the activity log is not wound back');
        $this->assertTrue(ActivityLog::where('action', 'records_cleared')->exists());
    }

    public function test_a_request_naming_no_mode_gets_records_only(): void
    {
        $this->incident($this->zabali, 'In the backup');
        $backup = $this->backup();
        $later = $this->person('Added Later', 'Coordinator', 'later@ascot-test.local');

        $this->actingAs($this->admin)->post(route('admin.backup.stored.restore', $backup), [
            'confirmation' => 'RESTORE', 'data_privacy_password' => self::PRIVACY_PASSWORD,
        ])->assertSessionHasNoErrors();

        $this->assertTrue(Personnel::whereKey($later->personnel_id)->exists());
    }

    public function test_a_record_by_a_deleted_account_restores_with_the_account_cleared_and_the_name_kept(): void
    {
        $this->actingAs($this->coordinator);
        $id = $this->incident($this->zabali, 'By Alpha', $this->coordinator);
        $this->assertSame('Coordinator Alpha', DB::table('incidents')->where('incident_id', $id)->value('created_by_name'));
        $backup = $this->backup();

        $this->coordinator->delete();
        DB::table('incidents')->delete();

        $this->restoreStored($backup)->assertSessionHasNoErrors()
            ->assertSessionHas('status', fn ($s) => str_contains($s, '(1 by accounts that no longer exist, kept under their names)'));

        $row = DB::table('incidents')->where('incident_id', $id)->first();
        $this->assertNull($row->created_by);
        $this->assertSame('Coordinator Alpha', $row->created_by_name);
    }

    public function test_notifications_of_a_deleted_account_are_left_out_and_the_rest_restores(): void
    {
        $notice = fn (Personnel $to) => ['personnel_id' => $to->personnel_id, 'campus_id' => $this->zabali->campus_id,
            'kind' => 'incident', 'subject_id' => 1, 'message' => 'New incident', 'created_at' => now(), 'updated_at' => now()];
        DB::table('staff_notifications')->insert([$notice($this->coordinator), $notice($this->coordinator), $notice($this->admin)]);
        $this->incident($this->zabali, 'Kept');
        $backup = $this->backup();

        $this->coordinator->delete(); // takes its two notifications with it
        DB::table('incidents')->delete();

        $this->restoreStored($backup)->assertSessionHasNoErrors()
            ->assertSessionHas('status', fn ($s) => str_contains($s, '2 staff notifications for accounts that no longer exist were left out.'));
        $this->assertSame(1, DB::table('incidents')->count());
        $this->assertSame([$this->admin->personnel_id], DB::table('staff_notifications')->pluck('personnel_id')->map(fn ($id) => (int) $id)->all());
    }

    public function test_an_sos_acknowledged_by_a_deleted_account_restores_unacknowledged_by_anyone(): void
    {
        $sosId = DB::table('sos_events')->insertGetId(['campus_id' => $this->zabali->campus_id, 'client_key' => (string) Str::uuid(),
            'status' => 'Resolved', 'type' => 'Medical', 'acknowledged_by' => $this->coordinator->personnel_id,
            'acknowledged_at' => now(), 'resolved_at' => now(), 'created_at' => now(), 'updated_at' => now()], 'sos_id');
        $backup = $this->backup();

        $this->coordinator->delete();
        DB::table('sos_events')->delete();

        $this->restoreStored($backup)->assertSessionHasNoErrors();
        $this->assertDatabaseHas('sos_events', ['sos_id' => $sosId, 'status' => 'Resolved', 'acknowledged_by' => null]);
    }

    public function test_every_account_link_of_the_record_tables_is_checked(): void
    {
        $columns = \App\Support\RecordsRestore::accountColumns();
        $this->assertContains('acknowledged_by', $columns['sos_events']);
        $this->assertContains('reporter_id', $columns['sos_events']);
        $this->assertEqualsCanonicalizing(['created_by', 'published_by'], $columns['broadcasts']);
        $this->assertArrayNotHasKey('staff_notifications', $columns, 'its rows go with the account instead');
    }

    /**
     * Reports and SOS events of a deleted Reporter stay as "Former reporter"
     * (reporter_id NULL). A backup holding them restores, and so does one whose
     * Reporter was deleted only after the backup was made.
     */
    public function test_reports_and_sos_of_deleted_reporters_restore_as_former_reporter(): void
    {
        $gone = $this->reporter('gone-before@ascot-test.local');
        $reportId = $this->actingAs($gone)->postJson('/api/reporter/reports', ['client_key' => (string) Str::uuid(),
            'category' => 'flood', 'place' => 'Gym', 'description' => 'Water rising near the gym entrance.'])->assertCreated()->json('id');
        $gone->delete();
        $this->assertDatabaseHas('citizen_reports', ['report_id' => $reportId, 'reporter_id' => null]);

        $later = $this->reporter('gone-after@ascot-test.local');
        $sosId = $this->actingAs($later)->postJson('/api/reporter/sos', ['client_key' => (string) Str::uuid(), 'type' => 'Medical',
            'latitude' => 15.73, 'longitude' => 121.54])->assertCreated()->json('id');

        $backup = $this->backup();
        $later->delete();
        DB::table('sos_events')->delete();
        DB::table('citizen_reports')->delete();

        $this->restoreStored($backup)->assertSessionHasNoErrors()
            ->assertSessionHas('status', fn ($s) => str_contains($s, '(1 by accounts that no longer exist, kept under their names)'));

        $this->assertDatabaseHas('citizen_reports', ['report_id' => $reportId, 'reporter_id' => null]);
        $this->assertDatabaseHas('sos_events', ['sos_id' => $sosId, 'reporter_id' => null]);
    }

    protected function reporter(string $email): Personnel
    {
        $person = $this->person('Reporter Test', 'Reporter', $email);
        $person->forceFill(['is_approved' => true, 'email_verified_at' => now(), 'validated_by_coordinator_at' => now()])->save();

        return $person;
    }

    public function test_a_backup_with_records_for_a_campus_that_no_longer_exists_restores_nothing(): void
    {
        $this->incident($this->zabali, 'Zabali record');
        $this->incident($this->bazal, 'Bazal record');
        DB::table('buildings')->insert(['campus_id' => $this->bazal->campus_id, 'building_name' => 'Bazal Library', 'risk_level' => 'Minimal Risk']);
        $backup = $this->backup();

        $this->bazal->delete(); // cascades its own records
        $this->incident($this->zabali, 'Current record');
        $before = $this->snapshotOfRecords();

        $this->restoreStored($backup)->assertSessionHasErrors('backup_file');

        $error = session('errors')->first('backup_file');
        $this->assertStringContainsString('Nothing was restored.', $error);
        $this->assertStringContainsString("This backup has records for a campus that no longer exists (id {$this->bazal->campus_id}): 1 incident, 1 building/area.", $error);
        $this->assertSame($before, $this->snapshotOfRecords(), 'nothing half-restored');
    }

    public function test_a_backup_with_records_for_a_school_that_no_longer_exists_restores_nothing(): void
    {
        $fisheries = School::create(['campus_id' => $this->zabali->campus_id, 'school_name' => 'School of Fisheries']);
        $this->incident($this->zabali, 'Fisheries record', null, $fisheries);
        $backup = $this->backup();

        DB::table('incidents')->delete();
        $fisheries->delete();
        $this->incident($this->zabali, 'Current record');
        $before = $this->snapshotOfRecords();

        $this->restoreStored($backup)->assertSessionHasErrors('backup_file');

        $this->assertStringContainsString("records for a school that no longer exists (id {$fisheries->school_id}): 1 incident.", session('errors')->first('backup_file'));
        $this->assertSame($before, $this->snapshotOfRecords());
    }

    public function test_a_file_without_the_record_tables_is_refused_rather_than_emptying_them(): void
    {
        $this->incident($this->zabali, 'Must survive');

        $this->restoreUpload(UploadedFile::fake()->createWithContent('other.sql', "CREATE TABLE `other` (`id` int);\nINSERT INTO `other` VALUES (1);\n"))
            ->assertSessionHasErrors('backup_file');

        $this->assertStringContainsString('is not an ASCOT-DRRMS backup of the records', session('errors')->first('backup_file'));
        $this->assertSame(1, DB::table('incidents')->count());
    }

    public function test_a_backup_from_an_older_version_is_refused_by_records_only(): void
    {
        $this->incident($this->zabali, 'Must survive');
        $entries = $this->entriesOf($this->backup());
        $entries['data/incidents.jsonl'] = implode("\n", array_map(
            fn ($line) => json_encode(['retired_column' => 'x'] + json_decode($line, true)),
            array_filter(explode("\n", $entries['data/incidents.jsonl']))
        )) . "\n";

        $this->restoreUpload($this->zipUpload($entries, 'old.zip'))->assertSessionHasErrors('backup_file');

        $this->assertStringContainsString('columns that no longer exist (incidents.retired_column)', session('errors')->first('backup_file'));
        $this->assertSame(1, DB::table('incidents')->count());
    }

    // ================================ everything ==============================

    public function test_everything_winds_back_the_log_but_never_touches_accounts_campuses_or_schools(): void
    {
        $this->incident($this->zabali, 'In the backup');
        $backup = $this->backup();
        $later = $this->person('Added Later', 'Coordinator', 'later@ascot-test.local');
        $renamed = $this->admin->full_name;
        $this->admin->forceFill(['full_name' => 'Renamed Admin'])->save();
        $newCampus = \App\Models\Campus::create(['campus_name' => 'Campus Added Later']);
        ActivityLog::create(['personnel_id' => $this->admin->personnel_id, 'action' => 'login', 'description' => 'After the backup']);

        $this->restoreStored($backup, 'everything')->assertSessionHasNoErrors()
            ->assertSessionHas('status', fn ($s) => str_starts_with($s, "Restored from {$backup->filename}: ") && str_contains($s, 'Accounts, campuses and schools were left as they were.'));

        $this->assertTrue(Personnel::whereKey($later->personnel_id)->exists(), 'an account added since is kept');
        $this->assertSame('Renamed Admin', $this->admin->fresh()->full_name, 'an account is not wound back');
        $this->assertNotSame($renamed, $this->admin->fresh()->full_name);
        $this->assertTrue(\App\Models\Campus::whereKey($newCampus->campus_id)->exists(), 'campuses are kept with their accounts');
        $this->assertFalse(ActivityLog::where('description', 'After the backup')->exists(), 'the log is wound back');
        $this->assertSame(['In the backup'], DB::table('incidents')->pluck('description')->all());
    }

    // ============================== files/ directory ==========================

    public function test_a_backup_holds_the_record_files_and_restoring_it_brings_them_back(): void
    {
        $this->incident($this->zabali, 'In the backup');
        Storage::disk('records')->put('incidents/photo.jpg', 'PHOTO');
        Storage::disk('records')->put('personnel/face.jpg', 'AN ACCOUNT PHOTO');
        $backup = $this->backup();

        $entries = $this->entriesOf($backup);
        $this->assertSame('PHOTO', $entries['files/records/incidents/photo.jpg'] ?? null);
        $this->assertArrayNotHasKey('files/records/personnel/face.jpg', $entries, 'account photos are not record files');

        foreach (['everything', 'records'] as $mode) {
            Storage::disk('records')->delete('incidents/photo.jpg');
            $this->restoreStored($backup, $mode)->assertSessionHasNoErrors()
                ->assertSessionHas('status', fn ($s) => str_contains($s, '1 uploaded file restored.'));
            $this->assertSame('PHOTO', Storage::disk('records')->get('incidents/photo.jpg'), "$mode: the photo is back");
        }
    }

    public function test_files_entries_are_written_back_only_into_the_record_folders(): void
    {
        $this->incident($this->zabali, 'In the backup');
        $entries = $this->entriesOf($this->backup()) + [
            'files/public/buildings/ok.jpg' => 'OK',
            'files/public/personnel/face.jpg' => 'NOT A RECORD FILE',
            'files/local/drill-attachments/../../exports/evil.csv' => 'ESCAPE',
            'files/other/buildings/x.jpg' => 'WRONG DISK',
        ];

        $this->restoreUpload($this->zipUpload($entries))->assertSessionHasNoErrors()
            ->assertSessionHas('status', fn ($s) => str_contains($s, '1 uploaded file restored') && str_contains($s, '3 file entries outside the record folders were ignored'));

        $this->assertSame('OK', Storage::disk('records')->get('buildings/ok.jpg'));
        Storage::disk('records')->assertMissing('personnel/face.jpg');
        Storage::disk('records')->assertMissing('exports/evil.csv');
        Storage::disk('local')->assertMissing('exports/evil.csv');
    }

    // ================================ helpers =================================

    protected function person(string $name, string $role, string $email): Personnel
    {
        return Personnel::create(['campus_id' => $this->zabali->campus_id, 'full_name' => $name, 'role' => $role, 'email' => $email, 'password_hash' => Hash::make('password')]);
    }

    protected function incident(Campus $campus, string $description, ?Personnel $by = null, ?School $school = null): int
    {
        return DB::table('incidents')->insertGetId([
            'campus_id' => $campus->campus_id, 'school_id' => $school?->school_id, 'hazard_type' => 'Fire', 'date_occurred' => '2026-09-05',
            'description' => $description, 'created_by' => $by?->personnel_id, 'created_by_name' => $by?->full_name,
        ], 'incident_id');
    }

    /** A stored backup made the ordinary way (Download Backup). */
    protected function backup(): Backup
    {
        $this->actingAs($this->admin)->post(route('admin.backup.download'), ['data_privacy_password' => self::PRIVACY_PASSWORD])->assertOk();

        return Backup::latest('backup_id')->firstOrFail();
    }

    /** Every entry of a stored backup, read with its password: name => contents. */
    protected function entriesOf(Backup $backup): array
    {
        return BackupZips::entries(Storage::disk('local')->path($backup->storage_path));
    }

    /** An uploaded .zip holding exactly $entries (name => contents). */
    protected function zipUpload(array $entries, string $name = 'crafted.zip'): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'zip');
        $zip = new ZipArchive();
        $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        foreach ($entries as $entry => $contents) {
            $zip->addFromString($entry, $contents);
        }
        $zip->close();

        return new UploadedFile($path, $name, 'application/zip', null, true);
    }

    protected function restoreStored(Backup $backup, ?string $mode = 'records'): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($this->admin)->from(route('admin.backup.index'))->post(route('admin.backup.stored.restore', $backup), array_filter([
            'confirmation' => 'RESTORE', 'data_privacy_password' => self::PRIVACY_PASSWORD, 'mode' => $mode,
        ]));
    }

    protected function restoreUpload(UploadedFile $file, string $mode = 'records'): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($this->admin)->from(route('admin.backup.index'))->post(route('admin.backup.restore'), [
            'backup_file' => $file, 'confirmation' => 'RESTORE', 'data_privacy_password' => self::PRIVACY_PASSWORD, 'mode' => $mode,
        ]);
    }

    protected function snapshotOfRecords(): array
    {
        return collect(array_keys(\App\Support\RecordsClear::TABLES))->mapWithKeys(fn ($t) => [$t => DB::table($t)->get()->map(fn ($r) => (array) $r)->all()])->all();
    }
}
