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
use Tests\Concerns\BackupZips;
use Tests\TestCase;
use ZipArchive;

/**
 * Restore → "Records only" (the default) versus "Everything in the backup":
 * what each replaces, account references to deleted accounts, campuses and
 * schools that no longer exist, and the files/ directory that only pre-clear
 * copies carry.
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

    public function test_a_backup_with_records_for_a_campus_that_no_longer_exists_restores_nothing(): void
    {
        $this->incident($this->zabali, 'Zabali record');
        $this->incident($this->bazal, 'Bazal record');
        DB::table('buildings')->insert(['campus_id' => $this->bazal->campus_id, 'building_name' => 'Bazal Library', 'risk_level' => 'Low']);
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

    public function test_everything_still_replaces_accounts_and_the_log_exactly_as_before(): void
    {
        $this->incident($this->zabali, 'In the backup');
        $backup = $this->backup();
        $later = $this->person('Added Later', 'Coordinator', 'later@ascot-test.local');
        ActivityLog::create(['personnel_id' => $this->admin->personnel_id, 'action' => 'login', 'description' => 'After the backup']);

        $this->restoreStored($backup, 'everything')->assertSessionHasNoErrors()
            ->assertSessionHas('status', "Database restored successfully from {$backup->filename}. Trash retention is paused until someone resumes it.");

        $this->assertFalse(Personnel::whereKey($later->personnel_id)->exists(), 'accounts are replaced');
        $this->assertFalse(ActivityLog::where('description', 'After the backup')->exists(), 'the log is wound back');
    }

    // ============================== files/ directory ==========================

    public function test_an_ordinary_backup_has_no_files_and_restores_exactly_as_before(): void
    {
        $this->incident($this->zabali, 'In the backup');
        Storage::disk('records')->put('incidents/photo.jpg', 'PHOTO');
        $backup = $this->backup();

        $names = array_keys($this->entriesOf($backup));
        $this->assertContains('manifest.json', $names);
        $this->assertSame([], array_filter($names, fn ($n) => str_starts_with($n, 'files/')), 'only the data — no files/');

        Storage::disk('records')->put('incidents/photo.jpg', 'CHANGED AFTER');
        foreach (['everything', 'records'] as $mode) {
            $this->restoreStored($backup, $mode)->assertSessionHasNoErrors()
                ->assertSessionHas('status', fn ($s) => ! str_contains($s, 'file'));
            $this->assertSame('CHANGED AFTER', Storage::disk('records')->get('incidents/photo.jpg'), "$mode: files untouched");
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
