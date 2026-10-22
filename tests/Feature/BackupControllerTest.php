<?php

namespace Tests\Feature;

use App\Models\Backup;
use App\Models\Campus;
use App\Models\Personnel;
use App\Support\Backup\BackupArchive;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\BackupZips;
use Tests\TestCase;
use ZipArchive;

class BackupControllerTest extends TestCase
{
    use RefreshDatabase;

    protected const PRIVACY_PASSWORD = 'privacy-secret';

    protected const ZIP_PASSWORD = 'ASCOTDRRMS2026';

    protected Personnel $admin;

    protected Personnel $coordinator;

    protected Campus $campus;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'privacy.data_privacy_password_hash' => Hash::make(self::PRIVACY_PASSWORD),
        ]);

        // Stored backups go through Storage::disk('local') — fake it so tests never
        // write into the real storage/app/private/backups/ directory.
        Storage::fake('local');
        Storage::fake('records'); // and every backup reads the record files


        $this->campus = Campus::create(['campus_name' => 'ASCOT Test Campus']);

        $this->admin = Personnel::create([
            'campus_id' => $this->campus->campus_id,
            'full_name' => 'Test Admin',
            'role' => 'DRRMO Admin',
            'email' => 'admin@ascot-test.local',
            'password_hash' => Hash::make('password'),
        ]);

        $this->coordinator = Personnel::create([
            'campus_id' => $this->campus->campus_id,
            'full_name' => 'Test Coordinator',
            'role' => 'Coordinator',
            'email' => 'coordinator@ascot-test.local',
            'password_hash' => Hash::make('password'),
        ]);
    }

    public function test_downloaded_backup_is_a_7z_that_shows_nothing_without_the_password(): void
    {
        $response = $this->actingAs($this->admin)->post(route('admin.backup.download'), [
            'data_privacy_password' => self::PRIVACY_PASSWORD,
        ]);

        $response->assertOk();
        $this->assertStringEndsWith('.7z', $response->getFile()->getFilename());

        $path = $response->getFile()->getPathname();
        $bytes = file_get_contents($path);
        $this->assertStringStartsWith("7z\xBC\xAF\x27\x1C", $bytes, 'The backup must be a 7-Zip archive.');

        // The file names are encrypted too: nothing of what it holds can be read
        // from the file, not even the table names.
        $this->assertStringNotContainsString('manifest', $bytes);
        $this->assertStringNotContainsString('personnel', $bytes);

        // Opening it (even just listing it) needs the password.
        $copy = tempnam(sys_get_temp_dir(), 'test_backup_copy_');
        copy($path, $copy);
        $list = Process::run([BackupArchive::sevenZip(), 'l', '-p' . 'totally-wrong-password', $copy]);
        $wrongDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'test_backup_wrong_' . uniqid();
        $extract = Process::run([BackupArchive::sevenZip(), 'x', '-y', '-ptotally-wrong-password', '-o' . $wrongDir, $copy]);
        @unlink($copy);
        File::deleteDirectory($wrongDir);
        $this->assertFalse($list->successful(), 'Listing with a wrong password must fail.');
        $this->assertStringNotContainsString('personnel', $list->output());
        $this->assertFalse($extract->successful(), 'Extracting with a wrong password must fail.');

        // Inside, with the password: one JSON row per line, readable as is.
        $entries = BackupZips::entries($path);
        $names = array_column(array_map(fn ($l) => json_decode($l, true), array_filter(explode("\n", $entries['data/campuses.jsonl']))), 'campus_name');
        $this->assertContains('ASCOT Test Campus', $names);
        $manifest = json_decode($entries['manifest.json'], true);
        $this->assertSame('ascot-drrms-backup', $manifest['format']);
        $this->assertSame(1, $manifest['tables']['campuses']);

        // User accounts are never in a backup: no table, no emails, no password hashes.
        $this->assertArrayNotHasKey('personnel', $manifest['tables']);
        $this->assertArrayNotHasKey('data/personnel.jsonl', $entries);
        $this->assertStringNotContainsString('admin@ascot-test.local', implode("\n", $entries));
        $this->assertStringNotContainsString($this->admin->password_hash, implode("\n", $entries));

        unlink($path);
    }

    /** A record that exists only in the backup proves a restore wrote it back. */
    protected function markerBuilding(string $name): \App\Models\Building\Building
    {
        return \App\Models\Building\Building::forceCreate(['campus_id' => $this->campus->campus_id, 'building_name' => $name, 'risk_level' => 'Minimal Risk']);
    }

    protected function markerExists(string $name): bool
    {
        return \App\Models\Building\Building::where('building_name', $name)->exists();
    }

    public function test_sql_round_trips_through_restore(): void
    {
        $marker = $this->markerBuilding('Marker Gym');

        $downloadResponse = $this->actingAs($this->admin)->post(route('admin.backup.download'), [
            'data_privacy_password' => self::PRIVACY_PASSWORD,
        ]);
        $downloadResponse->assertOk();
        $path = $downloadResponse->getFile()->getPathname();
        $zipBytes = file_get_contents($path);
        unlink($path);

        // Mutate the DB: remove the marker row directly (not through the app),
        // simulating data loss that a restore should repair. An account added
        // since the backup must survive it.
        $marker->forceDelete();
        $this->assertFalse($this->markerExists('Marker Gym'));
        $later = Personnel::create(['campus_id' => $this->campus->campus_id, 'full_name' => 'Added Later', 'role' => 'Coordinator',
            'email' => 'later@ascot-test.local', 'password_hash' => Hash::make('password')]);

        $zipFile = UploadedFile::fake()->createWithContent('ascot-drrms-backup.7z', $zipBytes);

        $restoreResponse = $this->actingAs($this->admin)->post(route('admin.backup.restore'), [
            'mode' => 'everything',
            'backup_file' => $zipFile,
            'confirmation' => 'RESTORE',
            'data_privacy_password' => self::PRIVACY_PASSWORD,
        ]);

        $restoreResponse->assertRedirect(route('admin.backup.index'));
        $restoreResponse->assertSessionHasNoErrors();

        $this->assertTrue($this->markerExists('Marker Gym'));
        $this->assertTrue(Personnel::whereKey($later->personnel_id)->exists(), 'a restore never removes an account');
    }

    /**
     * 7-Zip adds ".7z" to an output name without an extension, as temp names
     * are on Linux (the server): the archive must still land at the exact path.
     */
    public function test_a_backup_written_to_a_name_without_an_extension_lands_at_that_path(): void
    {
        $dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'test_backup_noext_' . uniqid();
        mkdir($dir);
        $path = $dir . DIRECTORY_SEPARATOR . 'drrms-backup-abc123';
        try {
            BackupArchive::write($path);

            $this->assertFileExists($path);
            $this->assertFileDoesNotExist($path . '.7z');
            $this->assertNotNull(BackupArchive::read($path));
        } finally {
            File::deleteDirectory($dir);
        }
    }

    /** A backup zipped with the password used before ASCOTDRRMS2026 still restores. */
    public function test_backup_made_with_the_old_zip_password_still_restores(): void
    {
        $marker = $this->markerBuilding('Old Password Marker');

        $downloadResponse = $this->actingAs($this->admin)->post(route('admin.backup.download'), [
            'data_privacy_password' => self::PRIVACY_PASSWORD,
        ]);
        $downloadResponse->assertOk();
        $path = $downloadResponse->getFile()->getPathname();

        // Lay the contents out as an older backup was written: every entry at the
        // top level, each locked with the old password.
        $entries = BackupZips::entries($path);
        $oldPath = tempnam(sys_get_temp_dir(), 'test_backup_old_');
        $old = new ZipArchive();
        $old->open($oldPath, ZipArchive::OVERWRITE);
        foreach ($entries as $name => $contents) {
            $old->addFromString($name, $contents);
            $old->setEncryptionName($name, ZipArchive::EM_TRAD_PKWARE);
        }
        $old->setPassword('ASCOTDRRMS');
        $old->close();
        $zipBytes = file_get_contents($oldPath);
        unlink($oldPath);

        $marker->forceDelete();

        $restoreResponse = $this->actingAs($this->admin)->post(route('admin.backup.restore'), [
            'mode' => 'everything',
            'backup_file' => UploadedFile::fake()->createWithContent('ascot-drrms-backup.zip', $zipBytes),
            'confirmation' => 'RESTORE',
            'data_privacy_password' => self::PRIVACY_PASSWORD,
        ]);

        $restoreResponse->assertRedirect(route('admin.backup.index'));
        $restoreResponse->assertSessionHasNoErrors();
        $this->assertTrue($this->markerExists('Old Password Marker'));
    }

    /**
     * A plain .sql backup from before the move to Supabase (MySQL syntax:
     * backticks, backslash escapes) still restores: its INSERT values are read
     * as rows, never run.
     */
    /** The record tables every real dump declares (with CREATE TABLE), even when empty. */
    protected function recordTablesSql(): string
    {
        $tables = array_diff(array_keys(\App\Support\RecordsClear::TABLES), \App\Support\RecordsRestore::OPTIONAL_NEW_TABLES);

        return implode('', array_map(fn ($t) => "CREATE TABLE `{$t}` (`id` int);\n", $tables));
    }

    public function test_an_old_plain_mysql_sql_upload_still_restores(): void
    {
        $campusId = $this->campus->campus_id;
        $admin = $this->admin;
        $sql = "-- ASCOT-DRRMS database backup\nSET FOREIGN_KEY_CHECKS=0;\n"
            . $this->recordTablesSql()
            . "INSERT INTO `campuses` (`campus_id`, `campus_name`, `address`) VALUES ({$campusId}, 'ASCOT Test Campus', NULL);\n"
            . "INSERT INTO `personnel` (`personnel_id`, `campus_id`, `full_name`, `role`, `email`, `password_hash`, `is_approved`) VALUES "
            . "({$admin->personnel_id}, {$campusId}, 'Test Admin', 'DRRMO Admin', 'admin@ascot-test.local', '{$admin->password_hash}', 1),"
            . "(950, {$campusId}, 'Plain SQL O\\'Brien', 'Coordinator', 'plainsql@ascot-test.local', 'dummy-hash', 1);\n"
            . "INSERT INTO `buildings` (`building_id`, `campus_id`, `building_name`, `risk_level`) VALUES (950, {$campusId}, 'Plain SQL O\\'Brien Hall', 'Minimal Risk');\n"
            . "SET FOREIGN_KEY_CHECKS=1;\n";

        $sqlFile = UploadedFile::fake()->createWithContent('ascot-drrms-backup.sql', $sql);

        $restoreResponse = $this->actingAs($this->admin)->post(route('admin.backup.restore'), [
            'mode' => 'everything',
            'backup_file' => $sqlFile,
            'confirmation' => 'RESTORE',
            'data_privacy_password' => self::PRIVACY_PASSWORD,
        ]);

        $restoreResponse->assertRedirect(route('admin.backup.index'));
        $restoreResponse->assertSessionHasNoErrors();

        $this->assertTrue($this->markerExists("Plain SQL O'Brien Hall"));
        // The old dump's accounts are ignored: none added, none removed.
        $this->assertFalse(Personnel::where('email', 'plainsql@ascot-test.local')->exists(), 'a restore never adds an account');
        $this->assertTrue(Personnel::whereKey($this->coordinator->personnel_id)->exists(), 'a restore never removes an account');
        // The id counter moved past the restored ids: a new record doesn't collide.
        $this->assertGreaterThan(950, $this->markerBuilding('After')->building_id);
    }

    public function test_old_format_sql_enc_zip_is_rejected_and_leaves_database_untouched(): void
    {
        $marker = Personnel::create([
            'campus_id' => $this->campus->campus_id,
            'full_name' => 'Old Format Untouched Coordinator',
            'role' => 'Coordinator',
            'email' => 'oldformat@ascot-test.local',
            'password_hash' => Hash::make('password'),
        ]);

        $countBefore = Personnel::count();

        // Simulates a genuine pre-this-change backup: an UNprotected zip whose entry is
        // named *.sql.enc (the old Crypt-ciphertext format). No .sql-suffixed entry
        // exists, so the new extractSqlFromZip() must not find anything to read.
        $oldFormatZip = UploadedFile::fake()->createWithContent(
            'old-ascot-drrms-backup.zip',
            $this->buildZipBytes('{"iv":"...","value":"...","mac":"..."}', 'backup.sql.enc', null)
        );

        $restoreResponse = $this->actingAs($this->admin)->post(route('admin.backup.restore'), [
            'mode' => 'everything', // the full restore, exactly as before records-only existed
            'backup_file' => $oldFormatZip,
            'confirmation' => 'RESTORE',
            'data_privacy_password' => self::PRIVACY_PASSWORD,
        ]);

        $restoreResponse->assertSessionHasErrors(['backup_file']);
        $this->assertStringContainsString(
            'invalid or unrecognized',
            session('errors')->first('backup_file')
        );

        $this->assertSame($countBefore, Personnel::count());
        $this->assertTrue(Personnel::where('personnel_id', $marker->personnel_id)->exists());
    }

    public function test_old_aes256_encrypted_zip_still_restores_for_backward_compatibility(): void
    {
        // A row that exists BEFORE the restore, under a DIFFERENT name than the one
        // the restored dump will insert — proves the dump's content genuinely replaced
        // the table rather than the "after" assertion just seeing pre-existing data.
        $this->markerBuilding('Pre-Restore Hall');

        // Simulates a backup produced by the PREVIOUS version of this app, which used
        // EM_AES_256 instead of EM_TRAD_PKWARE. New backups are never written this way
        // anymore, but PHP's ZipArchive reads AES-256 entries transparently regardless
        // of which method the app is currently WRITING with, so this must still restore.
        // A complete, valid row (not a partial INSERT) — the restore replaces the
        // ENTIRE buildings table with exactly what the dump contains, and the table's
        // NOT NULL columns would reject an incomplete row.
        // No leading "-- comment\n" line here (unlike other synthetic dumps in this file):
        // applyRestore() splits on ";" with no statement-boundary awareness of comments, so
        // a comment before the first ";" gets glued onto this INSERT as one chunk and the
        // "starts with INSERT" per-statement regex would then never match it. Real dumps
        // from generateDump() never hit this because their header comment is followed by a
        // real ";"-terminated statement (SET FOREIGN_KEY_CHECKS=0;) before the first INSERT.
        // Foreign keys stay on during a restore now, so the campus the row
        // points at is in the dump too (as it is in every real backup).
        $campusId = $this->campus->campus_id;
        $sql = $this->recordTablesSql()
            . "INSERT INTO `campuses` (`campus_id`, `campus_name`) VALUES ({$campusId}, 'ASCOT Test Campus');\n"
            . "INSERT INTO `buildings` (`building_id`, `campus_id`, `building_name`, `risk_level`) VALUES "
            . "(999, {$campusId}, 'AES256 Restored Hall', 'Minimal Risk');\n";
        $oldAesZip = UploadedFile::fake()->createWithContent(
            'ascot-drrms-backup.zip',
            $this->buildZipBytes($sql, 'backup.sql', self::ZIP_PASSWORD, ZipArchive::EM_AES_256)
        );

        $this->assertTrue($this->markerExists('Pre-Restore Hall'));
        $this->assertFalse($this->markerExists('AES256 Restored Hall'));

        $restoreResponse = $this->actingAs($this->admin)->post(route('admin.backup.restore'), [
            'mode' => 'everything', // the full restore, exactly as before records-only existed
            'backup_file' => $oldAesZip,
            'confirmation' => 'RESTORE',
            'data_privacy_password' => self::PRIVACY_PASSWORD,
        ]);

        $restoreResponse->assertRedirect(route('admin.backup.index'));
        $restoreResponse->assertSessionHasNoErrors();

        // The dump's content replaced the table: the AES-256 zip's row is now present,
        // and the pre-existing marker (not part of the dump) is gone.
        $this->assertTrue($this->markerExists('AES256 Restored Hall'));
        $this->assertFalse($this->markerExists('Pre-Restore Hall'));
    }

    public function test_zip_with_wrong_password_is_rejected_and_leaves_database_untouched(): void
    {
        $marker = Personnel::create([
            'campus_id' => $this->campus->campus_id,
            'full_name' => 'Wrong Password Untouched Coordinator',
            'role' => 'Coordinator',
            'email' => 'wrongpassword@ascot-test.local',
            'password_hash' => Hash::make('password'),
        ]);

        $countBefore = Personnel::count();

        // A genuine .sql entry, correctly named, but the ZIP itself is encrypted with a
        // DIFFERENT password than the app's fixed ZIP_PASSWORD.
        $sql = "-- ASCOT-DRRMS database backup\nINSERT INTO `personnel` (`personnel_id`) VALUES (999);\n";
        $wrongPasswordZip = UploadedFile::fake()->createWithContent(
            'ascot-drrms-backup.zip',
            $this->buildZipBytes($sql, 'backup.sql', 'a-completely-different-password')
        );

        $restoreResponse = $this->actingAs($this->admin)->post(route('admin.backup.restore'), [
            'mode' => 'everything', // the full restore, exactly as before records-only existed
            'backup_file' => $wrongPasswordZip,
            'confirmation' => 'RESTORE',
            'data_privacy_password' => self::PRIVACY_PASSWORD,
        ]);

        $restoreResponse->assertSessionHasErrors(['backup_file']);
        $this->assertStringContainsString(
            'invalid or unrecognized',
            session('errors')->first('backup_file')
        );

        $this->assertSame($countBefore, Personnel::count());
        $this->assertTrue(Personnel::where('personnel_id', $marker->personnel_id)->exists());
        $this->assertFalse(Personnel::where('personnel_id', 999)->exists());
    }

    /**
     * A backup zip with the right password whose entry no longer matches its
     * CRC (damaged on disk) is refused as invalid, not read as empty SQL. This
     * is the same check that catches a wrong password ZipCrypto lets through.
     */
    public function test_zip_whose_entry_fails_its_checksum_is_rejected_and_leaves_database_untouched(): void
    {
        $marker = Personnel::create([
            'campus_id' => $this->campus->campus_id,
            'full_name' => 'Corrupt Zip Untouched Coordinator',
            'role' => 'Coordinator',
            'email' => 'corruptzip@ascot-test.local',
            'password_hash' => Hash::make('password'),
        ]);
        $countBefore = Personnel::count();

        $sql = "-- ASCOT-DRRMS database backup\n" . str_repeat("INSERT INTO `personnel` (`personnel_id`) VALUES (999);\n", 50);
        $bytes = $this->corruptFirstEntry($this->buildZipBytes($sql, 'ascot-drrms-backup.sql', self::ZIP_PASSWORD));

        $restoreResponse = $this->actingAs($this->admin)->post(route('admin.backup.restore'), [
            'mode' => 'everything',
            'backup_file' => UploadedFile::fake()->createWithContent('ascot-drrms-backup.zip', $bytes),
            'confirmation' => 'RESTORE',
            'data_privacy_password' => self::PRIVACY_PASSWORD,
        ]);

        $restoreResponse->assertSessionHasErrors(['backup_file']);
        $this->assertStringContainsString('invalid or unrecognized', session('errors')->first('backup_file'));
        $this->assertSame($countBefore, Personnel::count());
        $this->assertTrue(Personnel::where('personnel_id', $marker->personnel_id)->exists());
        $this->assertFalse(Personnel::where('personnel_id', 999)->exists());
    }

    /**
     * Two backups in the same second get two files. Before, they shared one
     * file under two rows, and pruning the older row deleted the file the
     * newer one still pointed to.
     */
    public function test_two_backups_in_the_same_second_get_separate_files_that_survive_pruning(): void
    {
        Carbon::setTestNow('2026-10-10 09:31:51');
        $download = fn () => $this->actingAs($this->admin)->post(route('admin.backup.download'), [
            'data_privacy_password' => self::PRIVACY_PASSWORD,
        ])->assertOk();

        $download();
        $download();

        [$first, $second] = Backup::orderBy('backup_id')->get()->all();
        $this->assertSame('ascot-drrms-backup-2026-10-10_093151.7z', $first->filename);
        $this->assertSame('ascot-drrms-backup-2026-10-10_093151-2.7z', $second->filename);
        $this->assertNotSame($first->storage_path, $second->storage_path);
        Storage::disk('local')->assertExists([$first->storage_path, $second->storage_path]);

        // 18 newer rows, then a third download makes 21: pruning removes only the oldest.
        for ($i = 1; $i <= 18; $i++) {
            Storage::disk('local')->put("backups/seed-{$i}.zip", "dummy-{$i}");
            Backup::create(['filename' => "seed-{$i}.zip", 'storage_path' => "backups/seed-{$i}.zip", 'created_by' => $this->admin->personnel_id, 'file_size' => 10]);
        }
        $download();

        $this->assertFalse(Backup::whereKey($first->backup_id)->exists());
        Storage::disk('local')->assertMissing($first->storage_path);
        $this->assertTrue(Backup::whereKey($second->backup_id)->exists());
        Storage::disk('local')->assertExists($second->storage_path);
    }

    public function test_zip_with_no_sql_entry_produces_error_and_leaves_database_untouched(): void
    {
        $marker = Personnel::create([
            'campus_id' => $this->campus->campus_id,
            'full_name' => 'Untouched Coordinator',
            'role' => 'Coordinator',
            'email' => 'untouched@ascot-test.local',
            'password_hash' => Hash::make('password'),
        ]);

        $countBefore = Personnel::count();

        // Archive has no .sql entry at all (a .txt entry instead), so restore()
        // should never find anything to read.
        $zipFile = UploadedFile::fake()->createWithContent(
            'ascot-drrms-backup.zip',
            $this->buildZipBytes('not a sql dump', 'backup.txt', null)
        );

        $restoreResponse = $this->actingAs($this->admin)->post(route('admin.backup.restore'), [
            'mode' => 'everything', // the full restore, exactly as before records-only existed
            'backup_file' => $zipFile,
            'confirmation' => 'RESTORE',
            'data_privacy_password' => self::PRIVACY_PASSWORD,
        ]);

        $restoreResponse->assertSessionHasErrors(['backup_file']);
        $this->assertStringContainsString(
            'invalid or unrecognized',
            session('errors')->first('backup_file')
        );

        // Nothing changed: same row count, marker row (and everything else) intact.
        $this->assertSame($countBefore, Personnel::count());
        $this->assertTrue(Personnel::where('personnel_id', $marker->personnel_id)->exists());
    }

    public function test_coordinator_is_forbidden_from_backup_download_and_restore(): void
    {
        $downloadResponse = $this->actingAs($this->coordinator)->post(route('admin.backup.download'), [
            'data_privacy_password' => self::PRIVACY_PASSWORD,
        ]);
        $downloadResponse->assertForbidden();

        $sqlFile = UploadedFile::fake()->createWithContent('ascot-drrms-backup.sql', "-- dummy\n");

        $restoreResponse = $this->actingAs($this->coordinator)->post(route('admin.backup.restore'), [
            'backup_file' => $sqlFile,
            'confirmation' => 'RESTORE',
            'data_privacy_password' => self::PRIVACY_PASSWORD,
        ]);
        $restoreResponse->assertForbidden();
    }

    public function test_each_download_also_saves_and_lists_a_stored_backup_row(): void
    {
        $this->assertSame(0, Backup::count());

        $response = $this->actingAs($this->admin)->post(route('admin.backup.download'), [
            'data_privacy_password' => self::PRIVACY_PASSWORD,
        ]);
        $response->assertOk();

        $this->assertSame(1, Backup::count());

        $backup = Backup::first();
        $this->assertSame($this->admin->personnel_id, $backup->created_by);
        $this->assertGreaterThan(0, $backup->file_size);
        $this->assertTrue(Storage::disk('local')->exists($backup->storage_path));
        $this->assertNotNull($backup->created_at);

        // The stored copy must be the same password-locked .7z as the download.
        $storedPath = Storage::disk('local')->path($backup->storage_path);
        $this->assertStringEndsWith('.7z', $backup->filename);
        $this->assertStringStartsWith("7z\xBC\xAF\x27\x1C", file_get_contents($storedPath));
        $this->assertArrayHasKey('manifest.json', BackupZips::entries($storedPath));

        $indexResponse = $this->actingAs($this->admin)->get(route('admin.backup.index'));
        $indexResponse->assertOk();
        $indexResponse->assertSee($backup->filename);
        $indexResponse->assertSee('Test Admin');
    }

    public function test_restore_from_a_stored_backup_requires_the_same_gates_and_succeeds(): void
    {
        $marker = $this->markerBuilding('Stored Restore Marker');

        $downloadResponse = $this->actingAs($this->admin)->post(route('admin.backup.download'), [
            'data_privacy_password' => self::PRIVACY_PASSWORD,
        ]);
        $downloadResponse->assertOk();
        $backup = Backup::first();

        // Wrong confirmation phrase must be rejected.
        $wrongPhrase = $this->actingAs($this->admin)->post(route('admin.backup.stored.restore', $backup), [
            'mode' => 'everything', // the full restore, exactly as before records-only existed
            'confirmation' => 'NOPE',
            'data_privacy_password' => self::PRIVACY_PASSWORD,
        ]);
        $wrongPhrase->assertSessionHasErrors(['confirmation']);

        // Wrong data privacy password must be rejected.
        $wrongPassword = $this->actingAs($this->admin)->post(route('admin.backup.stored.restore', $backup), [
            'mode' => 'everything', // the full restore, exactly as before records-only existed
            'confirmation' => 'RESTORE',
            'data_privacy_password' => 'not-the-right-password',
        ]);
        $wrongPassword->assertSessionHasErrors(['data_privacy_password']);

        // Mutate the DB, then restore from the stored backup with both gates correct.
        $marker->forceDelete();
        $this->assertFalse($this->markerExists('Stored Restore Marker'));

        $restoreResponse = $this->actingAs($this->admin)->post(route('admin.backup.stored.restore', $backup), [
            'mode' => 'everything', // the full restore, exactly as before records-only existed
            'confirmation' => 'RESTORE',
            'data_privacy_password' => self::PRIVACY_PASSWORD,
        ]);
        $restoreResponse->assertRedirect(route('admin.backup.index'));
        $restoreResponse->assertSessionHasNoErrors();

        $this->assertTrue($this->markerExists('Stored Restore Marker'));
    }

    public function test_downloading_a_stored_backup_returns_the_same_password_protected_format(): void
    {
        $downloadResponse = $this->actingAs($this->admin)->post(route('admin.backup.download'), [
            'data_privacy_password' => self::PRIVACY_PASSWORD,
        ]);
        $downloadResponse->assertOk();
        $backup = Backup::first();

        $storedDownload = $this->actingAs($this->admin)->get(route('admin.backup.stored.download', $backup));
        $storedDownload->assertOk();

        // The stored copy is the same locked .7z as the immediate download, names
        // hidden — no format drift between the two paths.
        $path = $storedDownload->getFile()->getPathname();
        $bytes = file_get_contents($path);
        $this->assertStringStartsWith("7z\xBC\xAF\x27\x1C", $bytes);
        $this->assertStringNotContainsString('manifest', $bytes);
        $this->assertArrayHasKey('manifest.json', BackupZips::entries($path));
    }

    public function test_coordinator_is_forbidden_from_stored_backup_routes(): void
    {
        $downloadResponse = $this->actingAs($this->admin)->post(route('admin.backup.download'), [
            'data_privacy_password' => self::PRIVACY_PASSWORD,
        ]);
        $downloadResponse->assertOk();
        $backup = Backup::first();

        $this->actingAs($this->coordinator)
            ->get(route('admin.backup.stored.download', $backup))
            ->assertForbidden();

        $this->actingAs($this->coordinator)
            ->post(route('admin.backup.stored.restore', $backup), [
                'confirmation' => 'RESTORE',
                'data_privacy_password' => self::PRIVACY_PASSWORD,
            ])
            ->assertForbidden();
    }

    public function test_old_backups_are_pruned_beyond_the_retention_cap(): void
    {
        // Pre-seed 25 backup rows/files directly — cheaper than 25 real downloads —
        // exercising the exact same pruneOldBackups() logic a real download triggers.
        for ($i = 1; $i <= 25; $i++) {
            $path = "backups/seed-{$i}.zip";
            Storage::disk('local')->put($path, "dummy-{$i}");

            Backup::create([
                'filename' => "seed-{$i}.zip",
                'storage_path' => $path,
                'created_by' => $this->admin->personnel_id,
                'file_size' => 10,
            ]);
        }

        $this->assertSame(25, Backup::count());

        // One real download triggers pruneOldBackups() as a side effect, bringing the
        // total to 26 before pruning kicks in.
        $response = $this->actingAs($this->admin)->post(route('admin.backup.download'), [
            'data_privacy_password' => self::PRIVACY_PASSWORD,
        ]);
        $response->assertOk();

        // Capped at 20 (the MAX_STORED_BACKUPS retention rule) after pruning.
        $this->assertSame(20, Backup::count());

        // Of the 26 created in this test (25 seeded + 1 real download, in that
        // chronological order), the 20 most recent are kept: seed-7..seed-25 (19) plus
        // the real download (1). seed-1..seed-6 (the 6 oldest) must be pruned — both
        // their DB rows and their files on disk. This doesn't depend on absolute
        // backup_id values, since MySQL auto-increment isn't reset between tests that
        // share this test suite's database even though each test's rows roll back.
        $this->assertFalse(Backup::where('filename', 'seed-1.zip')->exists());
        $this->assertFalse(Backup::where('filename', 'seed-6.zip')->exists());
        $this->assertTrue(Backup::where('filename', 'seed-7.zip')->exists());
        $this->assertTrue(Backup::where('filename', 'seed-25.zip')->exists());

        $this->assertFalse(Storage::disk('local')->exists('backups/seed-1.zip'));
        $this->assertFalse(Storage::disk('local')->exists('backups/seed-6.zip'));
        $this->assertTrue(Storage::disk('local')->exists('backups/seed-7.zip'));
        $this->assertTrue(Storage::disk('local')->exists('backups/seed-25.zip'));
    }

    /**
     * Build a zip containing one entry with the given name and content, optionally
     * password-protected (pass null for an unprotected zip). Defaults to
     * EM_TRAD_PKWARE (ZipCrypto) to match what the app itself now writes; pass
     * EM_AES_256 explicitly to simulate an old-format backup from before this change.
     * Returns the archive's raw bytes.
     */
    protected function buildZipBytes(
        string $content,
        string $entryName,
        ?string $password,
        int $encryptionMethod = ZipArchive::EM_TRAD_PKWARE
    ): string {
        $contentPath = tempnam(sys_get_temp_dir(), 'test_backup_entry_');
        $zipPath = tempnam(sys_get_temp_dir(), 'test_backup_zip_');
        file_put_contents($contentPath, $content);

        $zip = new ZipArchive();
        $zip->open($zipPath, ZipArchive::OVERWRITE);
        $zip->addFile($contentPath, $entryName);

        if ($password !== null) {
            $zip->setEncryptionName($entryName, $encryptionMethod);
            $zip->setPassword($password);
        }

        $zip->close();

        $bytes = file_get_contents($zipPath);
        unlink($contentPath);
        unlink($zipPath);

        return $bytes;
    }

    /**
     * Flip one byte in the middle of the first entry's stored data (past the
     * 12-byte ZipCrypto header), so the archive still opens and lists its .sql
     * entry but the entry no longer matches its CRC.
     */
    protected function corruptFirstEntry(string $bytes): string
    {
        $scratch = tempnam(sys_get_temp_dir(), 'test_backup_corrupt_');
        file_put_contents($scratch, $bytes);
        $zip = new ZipArchive();
        $this->assertTrue($zip->open($scratch) === true);
        $compressedSize = $zip->statIndex(0)['comp_size'];
        $zip->close();
        @unlink($scratch);

        $this->assertSame("PK\x03\x04", substr($bytes, 0, 4), 'expected a local file header first');
        $dataStart = 30 + unpack('v', substr($bytes, 26, 2))[1] + unpack('v', substr($bytes, 28, 2))[1];
        $this->assertGreaterThan(24, $compressedSize, 'the entry is too small to corrupt past its header');
        $at = $dataStart + 12 + intdiv($compressedSize - 12, 2);
        $bytes[$at] = chr(ord($bytes[$at]) ^ 0xFF);

        return $bytes;
    }
}
