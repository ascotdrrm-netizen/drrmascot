<?php

namespace Tests\Feature;

use App\Models\Campus;
use App\Models\Personnel;
use App\Support\Backup\BackupArchive;
use App\Support\Backup\DataSet;
use App\Support\Backup\Snapshot;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\BackupZips;
use Tests\TestCase;
use ZipArchive;

/**
 * php artisan backup:snapshot: the verified copy (the same zip as Download
 * Backup), the before/after row-count window, a database that doesn't answer,
 * rotation, the uploaded-files copy, and the Admin dashboard banner. Bad
 * copies are simulated by swapping in a Snapshot whose writeArchive() bends
 * the zip in whatever way the test needs.
 */
class BackupSnapshotTest extends TestCase
{
    use RefreshDatabase;

    protected string $dir;

    protected Personnel $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::parse('2026-10-09 21:00:00', 'Asia/Manila'));
        $this->dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'ascot-snapshot-test-' . uniqid() . DIRECTORY_SEPARATOR . 'backups';
        Storage::fake('local');
        Storage::fake('public');
        Storage::disk('local')->put('drill-attachments/7/documentation/photo.jpg', 'JPEGDATA');
        Storage::disk('local')->put('exports/old.csv', 'a,b');
        Storage::disk('public')->put('incidents/fire.jpg', 'FIREPHOTO');
        config([
            'backup.dir' => $this->dir,
            'backup.pg_dump' => null,
            'backup.mirror' => ['private' => 'local', 'public' => 'public'],
        ]);

        $campus = Campus::create(['campus_name' => 'ASCOT Zabali Campus']);
        $this->admin = Personnel::create(['campus_id' => $campus->campus_id, 'full_name' => 'Snap Admin', 'role' => 'DRRMO Admin', 'email' => 'snap-admin@ascot-test.local', 'password_hash' => Hash::make('password')]);
    }

    protected function tearDown(): void
    {
        $this->deleteTree(dirname($this->dir));
        parent::tearDown();
    }

    // ============================ success + verify ============================

    public function test_a_verified_copy_and_the_uploaded_files_are_saved_and_recorded(): void
    {
        $this->artisan('backup:snapshot')->assertExitCode(0);

        $file = $this->dir . '/ascot_drrms-2026-10-09-2100.7z';
        $this->assertFileExists($file);
        $this->assertSame([], glob($this->dir . '/.partial-*'), 'no partial left behind');
        $status = Snapshot::readStatus($this->dir);
        $this->assertSame('success', $status['last_outcome']);
        $this->assertTrue($status['last_dump']['idle']);
        $this->assertSame(count(DataSet::tables()), $status['last_dump']['tables']);
        $this->assertSame(array_sum(DataSet::rowCounts()), $status['last_dump']['rows']);
        $this->assertSame(['files' => 3, 'bytes' => 8 + 3 + 9, 'copied' => 3, 'copied_bytes' => 20, 'set_aside' => 0], array_diff_key($status['files'], ['at' => 1]));
        $this->assertSame('JPEGDATA', file_get_contents($this->dir . '/files/private/drill-attachments/7/documentation/photo.jpg'));
        $this->assertSame('FIREPHOTO', file_get_contents($this->dir . '/files/public/incidents/fire.jpg'));

        // It is an ordinary backup: it opens and holds the rows — never the accounts.
        $read = BackupArchive::read($file);
        $this->assertSame('ASCOT Zabali Campus', $read['rows']['campuses'][0]['campus_name']);
        $this->assertArrayNotHasKey('personnel', $read['rows']);
    }

    public function test_an_unreadable_copy_is_rejected_and_kept_aside(): void
    {
        $this->bend(fn (string $path) => file_put_contents($path, 'not a zip'));

        $this->artisan('backup:snapshot')->assertExitCode(1);

        $this->assertSame([], $this->dumpFiles());
        $this->assertFileExists($this->dir . '/' . Snapshot::FAILED_FILE);
        $this->assertStringContainsString('could not be opened', Snapshot::readStatus($this->dir)['last_error']);
        $this->assertDirectoryDoesNotExist($this->dir . '/files', 'files are copied only after a verified copy');
    }

    public function test_a_copy_missing_a_table_is_rejected(): void
    {
        $this->bend(function (string $path) {
            $this->rewriteManifest($path, function (array $manifest) {
                unset($manifest['tables']['campuses']);

                return $manifest;
            });
        });

        $this->artisan('backup:snapshot')->assertExitCode(1);

        $this->assertSame([], $this->dumpFiles());
        $this->assertStringContainsString('missing 1 of', Snapshot::readStatus($this->dir)['last_error']);
    }

    // ========================= before/after count window ======================

    public function test_on_an_idle_database_the_counts_must_match_exactly(): void
    {
        $this->bend(fn (string $path) => $this->setRows($path, 'campuses', 0));

        $this->artisan('backup:snapshot')->assertExitCode(1);

        $this->assertStringContainsString('table campuses has 0 rows in the backup but 1 in the database', Snapshot::readStatus($this->dir)['last_error']);
    }

    public function test_while_someone_works_any_count_between_before_and_after_is_accepted(): void
    {
        // A campus is added while the copy is made: before 1, after 2.
        foreach ([1 => true, 2 => true, 0 => false, 3 => false] as $rowsInCopy => $accepted) {
            $this->deleteTree($this->dir);
            Campus::where('campus_name', 'like', 'Added %')->delete();
            $this->bend(function (string $path) use ($rowsInCopy) {
                $this->setRows($path, 'campuses', $rowsInCopy);
                Campus::create(['campus_name' => 'Added during the copy']);
            });

            $this->artisan('backup:snapshot')->assertExitCode($accepted ? 0 : 1);
            $this->assertCount($accepted ? 1 : 0, $this->dumpFiles(), "$rowsInCopy rows");
            if ($accepted) {
                $this->assertFalse(Snapshot::readStatus($this->dir)['last_dump']['idle']);
            } else {
                $this->assertStringContainsString('outside the 1–2', Snapshot::readStatus($this->dir)['last_error']);
            }
        }
    }

    // ======================= the database doesn't answer ======================

    public function test_when_the_database_does_not_answer_the_run_fails_cleanly(): void
    {
        $this->databaseDown(fn () => $this->artisan('backup:snapshot')->assertExitCode(1));

        $status = Snapshot::readStatus($this->dir);
        $this->assertSame('failed', $status['last_outcome']);
        $this->assertStringContainsString('did not answer', $status['last_error']);
        $this->assertSame([], $this->dumpFiles());
    }

    // ============================ skip + warnings ==============================

    public function test_a_run_soon_after_a_verified_copy_is_skipped_unless_forced(): void
    {
        $this->writeFile($this->dir . '/ascot_drrms-2026-10-09-1900.zip', 'earlier copy');

        $this->artisan('backup:snapshot')->assertExitCode(0);
        $this->assertSame('skipped', Snapshot::readStatus($this->dir)['last_outcome']);
        $this->assertFileDoesNotExist($this->dir . '/ascot_drrms-2026-10-09-2100.7z');

        $this->artisan('backup:snapshot --force')->assertExitCode(0);
        $this->assertFileExists($this->dir . '/ascot_drrms-2026-10-09-2100.7z');
    }

    public function test_a_much_smaller_copy_is_a_warning_never_a_failure(): void
    {
        $this->writeFile($this->dir . '/ascot_drrms-2026-10-09-1500.zip', str_repeat('x', 5_000_000));

        $this->artisan('backup:snapshot')->assertExitCode(0);

        $status = Snapshot::readStatus($this->dir);
        $this->assertSame('success', $status['last_outcome']);
        $this->assertCount(1, $status['warnings']);
        $this->assertStringContainsString('under half the size', $status['warnings'][0]);
    }

    // ================================ rotation ================================

    public function test_rotation_keeps_48_hours_plus_the_last_copy_of_each_of_14_days(): void
    {
        $names = [];
        // Every 3 hours for 20 days back (the older ones still MySQL .sql dumps).
        for ($t = CarbonImmutable::parse('2026-09-19 00:00', 'Asia/Manila'); $t->lt(CarbonImmutable::parse('2026-10-09 21:00', 'Asia/Manila')); $t = $t->addHours(3)) {
            $names[] = $name = 'ascot_drrms-' . $t->format('Y-m-d-Hi') . ($t->lt(CarbonImmutable::parse('2026-10-01', 'Asia/Manila')) ? '.sql' : '.zip');
            $this->writeFile($this->dir . '/' . $name, 'copy');
        }
        $this->writeFile($this->dir . '/notes.txt', 'not a copy');
        $this->writeFile($this->dir . '/ascot_drrms-before-drill-tracker.sql', 'manual dump, other name');

        // A failed run deletes nothing.
        $this->bend(fn (string $path) => file_put_contents($path, 'not a zip'));
        $this->artisan('backup:snapshot --force')->assertExitCode(1);
        $this->assertCount(count($names), $this->dumpFiles());

        $this->app->offsetUnset(Snapshot::class);
        $this->artisan('backup:snapshot --force')->assertExitCode(0);

        $kept = $this->dumpFiles();
        $recent = array_filter($kept, fn ($n) => $n >= 'ascot_drrms-2026-10-07-2100');
        $older = array_values(array_diff($kept, $recent));
        $this->assertCount(16 + 1, $recent, 'every copy of the last 48 hours, plus the new one');
        // The 14 days before today are Sep 25 – Oct 8; Oct 7 and Oct 8 end inside the 48 hours.
        $this->assertSame(array_map(fn ($d) => "ascot_drrms-2026-$d-2100." . ($d < '10-01' ? 'sql' : 'zip'), [
            '09-25', '09-26', '09-27', '09-28', '09-29', '09-30', '10-01', '10-02', '10-03', '10-04', '10-05', '10-06',
        ]), $older, 'the last copy of each earlier day within the 14 days before today');
        $this->assertFileExists($this->dir . '/notes.txt');
        $this->assertFileExists($this->dir . '/ascot_drrms-before-drill-tracker.sql');
        $this->assertNotContains('ascot_drrms-2026-09-24-2100.sql', $kept, 'outside the 14 days');
    }

    // ================================== files =================================

    public function test_changed_files_are_recopied_and_removed_files_are_set_aside_then_forgotten(): void
    {
        $this->artisan('backup:snapshot')->assertExitCode(0);

        Storage::disk('public')->delete('incidents/fire.jpg');
        Storage::disk('local')->put('exports/old.csv', 'a,b,c,d');
        touch(Storage::disk('local')->path('exports/old.csv'), time() + 60);
        $this->travelTo(Carbon::parse('2026-10-10 00:05:00', 'Asia/Manila'));
        $this->artisan('backup:snapshot')->assertExitCode(0);

        $files = Snapshot::readStatus($this->dir)['files'];
        $this->assertSame([2, 1, 1], [$files['files'], $files['copied'], $files['set_aside']]);
        $this->assertSame('a,b,c,d', file_get_contents($this->dir . '/files/private/exports/old.csv'));
        $this->assertFileDoesNotExist($this->dir . '/files/public/incidents/fire.jpg');
        $aside = $this->dir . '/files-removed/2026-10-10/public/incidents/fire.jpg';
        $this->assertSame('FIREPHOTO', file_get_contents($aside), 'a deleted upload stays recoverable');

        $this->travelTo(Carbon::parse('2026-10-25 09:00:00', 'Asia/Manila'));
        $this->artisan('backup:snapshot')->assertExitCode(0);
        $this->assertDirectoryDoesNotExist($this->dir . '/files-removed/2026-10-10', 'forgotten after 14 days');
    }

    // ================================= banner =================================

    public function test_the_admin_banner_shows_when_no_recent_verified_copy_exists(): void
    {
        $banner = fn () => $this->actingAs($this->admin)->get(route('admin.dashboard'))->assertOk();

        $banner()->assertSee('No verified database backup yet')->assertSee('data-backup-banner', false);

        $this->artisan('backup:snapshot')->assertExitCode(0);
        $banner()->assertDontSee('data-backup-banner', false);

        // 25 hours later: still quiet between runs.
        $this->travelTo(Carbon::parse('2026-10-10 22:00:00', 'Asia/Manila'));
        $banner()->assertDontSee('data-backup-banner', false);

        // 27 hours and a failed attempt: banner, with the reason.
        $this->travelTo(Carbon::parse('2026-10-11 00:00:00', 'Asia/Manila'));
        $this->databaseDown(fn () => $this->artisan('backup:snapshot')->assertExitCode(1));
        $banner()->assertSee('Database backup is out of date')->assertSee('Oct 9, 9:00 PM')->assertSee('did not answer');
    }

    public function test_the_banner_is_admin_only_and_off_when_snapshots_are_not_configured(): void
    {
        $coordinator = Personnel::create(['campus_id' => $this->admin->campus_id, 'full_name' => 'Snap Coord', 'role' => 'Coordinator', 'email' => 'snap-coord@ascot-test.local', 'password_hash' => Hash::make('password')]);
        $this->actingAs($coordinator)->get(route('coordinator.dashboard'))->assertOk()->assertDontSee('data-backup-banner', false);

        config(['backup.dir' => null]);
        $this->actingAs($this->admin)->get(route('admin.dashboard'))->assertOk()->assertDontSee('data-backup-banner', false);
        $this->artisan('backup:snapshot')->assertExitCode(1);
    }

    public function test_the_backup_page_lists_the_automatic_copies(): void
    {
        $this->artisan('backup:snapshot')->assertExitCode(0);

        $this->actingAs($this->admin)->get(route('admin.backup.index'))->assertOk()
            ->assertSee('Automatic Copies on This PC')
            ->assertSee('ascot_drrms-2026-10-09-2100.7z')
            ->assertSee('3 files');
    }

    // ================================ helpers =================================

    /** The next snapshot runs with $after applied to its freshly written copy. */
    protected function bend(\Closure $after): void
    {
        $this->app->bind(Snapshot::class, fn () => new class($after) extends Snapshot
        {
            public function __construct(protected \Closure $after)
            {
                parent::__construct();
            }

            protected function writeArchive(string $path): void
            {
                parent::writeArchive($path);
                ($this->after)($path);
            }
        });
    }

    /** Replace a table's rows in a copy with $count copies of its first row. */
    protected function setRows(string $path, string $table, int $count): void
    {
        BackupZips::rewrite($path, function (ZipArchive $zip) use ($table, $count) {
            $first = strtok((string) $zip->getFromName("data/{$table}.jsonl"), "\n");
            $zip->addFromString("data/{$table}.jsonl", $count ? str_repeat($first . "\n", $count) : '');
        });
    }

    protected function rewriteManifest(string $path, \Closure $change): void
    {
        BackupZips::rewrite($path, function (ZipArchive $zip) use ($change) {
            $manifest = $change(json_decode($zip->getFromName('manifest.json'), true));
            $zip->addFromString('manifest.json', json_encode($manifest));
        });
    }

    /** Runs $run with the default database pointing at a server that isn't there. */
    protected function databaseDown(\Closure $run): void
    {
        $default = config('database.default');
        config(['database.connections.snapshot_down' => [
            'driver' => 'pgsql', 'host' => '127.0.0.1', 'port' => 1, 'database' => 'none',
            'username' => 'none', 'password' => '', 'sslmode' => 'disable', 'prefix' => '', 'search_path' => 'public',
        ], 'database.default' => 'snapshot_down']);
        try {
            $run();
        } finally {
            config(['database.default' => $default]);
        }
    }

    /** @return list<string> verified copy names, sorted */
    protected function dumpFiles(): array
    {
        return array_values(array_filter(is_dir($this->dir) ? scandir($this->dir) : [], fn ($n) => preg_match(Snapshot::PATTERN, $n)));
    }

    protected function writeFile(string $path, string $body): void
    {
        if (! is_dir(dirname($path))) {
            mkdir(dirname($path), 0777, true);
        }
        file_put_contents($path, $body);
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
