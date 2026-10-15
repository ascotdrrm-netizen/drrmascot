<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Advisory\DisasterAdvisory;
use App\Models\Building\Building;
use App\Models\Campus;
use App\Models\Drill\Drill;
use App\Models\Incident\Incident;
use App\Models\Personnel;
use App\Support\TrashPurge;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use LogicException;
use Tests\TestCase;

/**
 * Trash retention (php artisan trash:purge): records in Trash longer than
 * config('trash.retention_days') are deleted permanently, children by the
 * declared cascades, files once the delete commits, one "System (automatic)"
 * log entry each. A building that damage reports point at is kept. The Trash
 * pages promise a countdown only while the purge is actually running.
 */
class TrashPurgeTest extends TestCase
{
    use RefreshDatabase;

    protected Campus $campus;

    protected Personnel $admin;

    protected Personnel $coordinator;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
        Storage::fake('records');
        Storage::fake('local');
        config(['trash.retention_days' => 30]);
        $this->travelTo(Carbon::parse('2026-10-11 09:00:00', 'Asia/Manila'));

        $this->campus = Campus::create(['campus_name' => 'ASCOT Zabali Campus']);
        $make = fn (string $name, string $role) => Personnel::create(['campus_id' => $this->campus->campus_id, 'full_name' => $name, 'role' => $role, 'email' => strtolower(str_replace(' ', '-', $name)) . '@ascot-test.local', 'password_hash' => Hash::make('password')]);
        $this->admin = $make('Purge Admin', 'DRRMO Admin');
        $this->coordinator = $make('Purge Coordinator', 'Coordinator');
    }

    // ============================== the window ================================

    public function test_a_record_younger_than_the_window_is_untouched(): void
    {
        $building = $this->trashed($this->building('Library', 'buildings/library.jpg'), 29);
        $incident = $this->trashed($this->incident('Fire'), 1);

        $this->artisan('trash:purge')->assertExitCode(0);

        $this->assertNotNull(Building::onlyTrashed()->find($building->building_id));
        $this->assertNotNull(Incident::onlyTrashed()->find($incident->incident_id));
        Storage::disk('records')->assertExists('buildings/library.jpg');
        $this->assertSame(0, ActivityLog::where('action', 'permanently_deleted')->count());
    }

    public function test_records_past_the_window_are_gone_with_their_children(): void
    {
        $standing = $this->building('Admin Building');
        $incident = $this->incident('Typhoon');
        $this->damageReport($incident, $standing);
        DB::table('alerts')->insert(['incident_id' => $incident->incident_id, 'message' => 'Evacuate', 'sent_at' => now(), 'recipient_count' => 3]);
        $this->trashed($incident, 31);

        $gym = $this->building('Gym');
        $this->inspection($gym);
        $this->trashed($gym, 45);

        $drill = $this->drill();
        DB::table('drill_documentations')->insert(['drill_id' => $drill->drill_id, 'date_conducted' => '2026-09-01', 'narrative' => 'Held.']);
        $this->trashed($drill, 60);

        $advisory = $this->trashed($this->advisory(), 30);

        $this->artisan('trash:purge')->assertExitCode(0);

        $this->assertNull(Incident::withTrashed()->find($incident->incident_id));
        $this->assertSame(0, DB::table('damage_reports')->where('incident_id', $incident->incident_id)->count());
        $this->assertSame(0, DB::table('alerts')->where('incident_id', $incident->incident_id)->count());
        $this->assertNull(Building::withTrashed()->find($gym->building_id));
        $this->assertSame(0, DB::table('hazard_inspections')->where('building_id', $gym->building_id)->count());
        $this->assertNull(Drill::withTrashed()->find($drill->drill_id));
        $this->assertSame(0, DB::table('drill_documentations')->where('drill_id', $drill->drill_id)->count());
        $this->assertNull(DisasterAdvisory::withTrashed()->find($advisory->advisory_id));
        // The building the incident's damage report pointed at was never in Trash.
        $this->assertNotNull(Building::find($standing->building_id));
    }

    public function test_only_a_record_in_trash_can_be_permanently_deleted(): void
    {
        $building = $this->building('Library');

        $this->expectException(LogicException::class);
        TrashPurge::purge($building);
    }

    // ============================= the block rule =============================

    public function test_a_building_with_damage_reports_is_kept_and_its_damage_reports_survive(): void
    {
        $building = $this->trashed($this->building('Science Lab'), 90);
        // One on a live incident, one on an incident that is itself in Trash.
        $this->damageReport($this->incident('Earthquake'), $building);
        $this->damageReport($this->trashed($this->incident('Fire'), 5), $building);

        $this->artisan('trash:purge')->expectsOutputToContain('Kept: 2 damage reports depend on this.')->assertExitCode(0);

        $this->assertNotNull(Building::onlyTrashed()->find($building->building_id));
        $this->assertSame(2, DB::table('damage_reports')->where('building_id', $building->building_id)->count());
        $this->assertSame(0, ActivityLog::where('action', 'permanently_deleted')->count());

        // Its Trash row says why it stays.
        $this->ranRecently();
        $this->actingAs($this->admin)->get(route('admin.buildings.trash'))
            ->assertSee('Kept: 2 damage reports depend on this.');
    }

    // ================================= files ==================================

    public function test_a_buildings_photo_and_its_inspections_photos_are_all_removed_from_disk(): void
    {
        $building = $this->building('Gym', 'buildings/gym.jpg');
        $this->inspection($building, 'hazard-inspections/exit.jpg');
        $this->inspection($building, 'hazard-inspections/stairs.jpg');
        $incident = $this->incident('Fire', 'incidents/fire.jpg');
        $this->trashed($building, 31);
        $this->trashed($incident, 31);

        $this->artisan('trash:purge')->assertExitCode(0);

        foreach (['buildings/gym.jpg', 'hazard-inspections/exit.jpg', 'hazard-inspections/stairs.jpg', 'incidents/fire.jpg'] as $path) {
            Storage::disk('records')->assertMissing($path);
        }
    }

    public function test_a_drills_attachments_are_removed_with_their_folder(): void
    {
        $drill = $this->drill();
        foreach (['documentation/a.jpg', 'after_activity/b.pdf'] as $i => $file) {
            $path = "drill-attachments/{$drill->drill_id}/{$file}";
            Storage::disk('records')->put($path, 'FILE');
            DB::table('drill_attachments')->insert(['drill_id' => $drill->drill_id, 'section' => strtok($file, '/'), 'path' => $path, 'original_name' => basename($file), 'mime_type' => 'image/jpeg', 'size_bytes' => 4]);
        }
        $other = $this->drill();
        Storage::disk('records')->put("drill-attachments/{$other->drill_id}/documentation/c.jpg", 'FILE');
        $this->trashed($drill, 31);

        $this->artisan('trash:purge')->assertExitCode(0);

        $this->assertSame(0, DB::table('drill_attachments')->where('drill_id', $drill->drill_id)->count());
        Storage::disk('records')->assertMissing("drill-attachments/{$drill->drill_id}/documentation/a.jpg");
        Storage::disk('records')->assertMissing("drill-attachments/{$drill->drill_id}/after_activity/b.pdf");
        $this->assertFalse(Storage::disk('records')->directoryExists("drill-attachments/{$drill->drill_id}"));
        // Another drill's folder is not touched.
        Storage::disk('records')->assertExists("drill-attachments/{$other->drill_id}/documentation/c.jpg");
    }

    public function test_a_missing_file_does_not_block_the_purge(): void
    {
        $building = $this->building('Gym', 'buildings/gone.jpg');
        Storage::disk('records')->delete('buildings/gone.jpg');
        $this->trashed($building, 31);

        $this->artisan('trash:purge')->assertExitCode(0);

        $this->assertNull(Building::withTrashed()->find($building->building_id));
        $this->assertStringContainsString('1 file (1 already missing)', ActivityLog::where('action', 'permanently_deleted')->sole()->description);
    }

    public function test_files_stay_when_the_delete_is_rolled_back(): void
    {
        $building = $this->trashed($this->building('Gym', 'buildings/gym.jpg'), 31);

        try {
            DB::transaction(function () use ($building) {
                TrashPurge::purge($building);
                throw new \RuntimeException('roll back');
            });
        } catch (\RuntimeException) {
        }

        $this->assertNotNull(Building::onlyTrashed()->find($building->building_id));
        Storage::disk('records')->assertExists('buildings/gym.jpg');
        $this->assertSame(0, ActivityLog::where('action', 'permanently_deleted')->count());
    }

    // ============================== advisories ================================

    public function test_an_advisorys_linked_incidents_survive_unlinked(): void
    {
        $advisory = $this->advisory();
        $live = $this->incident('Typhoon', null, $advisory);
        $inTrash = $this->trashed($this->incident('Typhoon', null, $advisory), 3);
        $this->trashed($advisory, 31);

        $this->artisan('trash:purge')->assertExitCode(0);

        $this->assertNull(DisasterAdvisory::withTrashed()->find($advisory->advisory_id));
        $this->assertNull(Incident::find($live->incident_id)->advisory_id);
        $this->assertNull(Incident::onlyTrashed()->find($inTrash->incident_id)->advisory_id);
        $this->assertStringContainsString('2 linked incidents kept, unlinked', ActivityLog::where('action', 'permanently_deleted')->sole()->description);
    }

    // ============================== the log ===================================

    public function test_the_log_entry_survives_the_record_and_reads_as_the_system(): void
    {
        $building = $this->building('Gym', 'buildings/gym.jpg');
        $this->inspection($building, 'hazard-inspections/exit.jpg');
        $this->trashed($building, 31);
        $before = ActivityLog::count();

        $this->artisan('trash:purge')->assertExitCode(0);

        // Exactly one entry: no automatic "deleted" that reads like a second trashing.
        $this->assertSame($before + 1, ActivityLog::count());
        $log = ActivityLog::latest('activity_log_id')->first();
        $this->assertSame('permanently_deleted', $log->action);
        $this->assertNull($log->personnel_id);
        $this->assertSame('System (automatic)', $log->actor_name);
        $this->assertSame('System (automatic)', $log->actor_label);
        $this->assertSame('Building', $log->subject_type);
        $this->assertSame($building->building_id, $log->subject_id);
        $this->assertSame("Permanently deleted Building 'Gym' (in Trash since Sep 10, 2026): 1 inspection, 2 files", $log->description);

        // On the Activity Log: under System, not under deleted accounts; red badge; filterable.
        $page = $this->actingAs($this->admin)->get(route('admin.activity-log.index', ['action' => 'permanently_deleted']));
        $page->assertSee('System (automatic)')->assertSee('Permanently Deleted')->assertSee('bg-red-100 text-red-700', false)
            ->assertDontSee('(deleted account)');
        $this->actingAs($this->admin)->get(route('admin.activity-log.index', ['personnel_id' => 'system']))->assertSee("Building 'Gym'");
        $this->actingAs($this->admin)->get(route('admin.activity-log.index', ['personnel_id' => 'deleted']))->assertDontSee("Building 'Gym'");
    }

    // ============================ the command =================================

    public function test_a_dry_run_deletes_nothing_and_says_what_it_would_do(): void
    {
        $gym = $this->trashed($this->building('Gym', 'buildings/gym.jpg'), 31);
        $lab = $this->trashed($this->building('Science Lab'), 31);
        $this->damageReport($this->incident('Fire'), $lab);

        $this->artisan('trash:purge', ['--dry-run' => true])
            ->expectsOutputToContain("[would delete] Building 'Gym'")
            ->expectsOutputToContain("[would keep]   Building 'Science Lab'")
            ->expectsOutputToContain('Would be: 1 deleted, 1 kept')
            ->assertExitCode(0);

        $this->assertNotNull(Building::onlyTrashed()->find($gym->building_id));
        Storage::disk('records')->assertExists('buildings/gym.jpg');
        $this->assertSame(0, ActivityLog::where('action', 'permanently_deleted')->count());
        // A dry run is not a run: the Trash pages still say retention isn't running.
        $this->assertNull(TrashPurge::lastSuccess());
    }

    public function test_a_run_that_is_months_late_catches_up_one_record_at_a_time(): void
    {
        foreach ([31, 60, 120] as $days) {
            $this->trashed($this->incident("Fire {$days}"), $days);
        }
        $this->trashed($this->incident('Recent'), 2);

        $this->artisan('trash:purge')->expectsOutputToContain('3 deleted, 0 kept, 0 skipped, 0 failed')->assertExitCode(0);
        $this->assertSame(3, ActivityLog::where('action', 'permanently_deleted')->count());
        $this->assertSame(1, Incident::onlyTrashed()->count());

        // Running again changes nothing.
        $this->artisan('trash:purge')->expectsOutputToContain('0 deleted')->assertExitCode(0);
        $this->assertSame(3, ActivityLog::where('action', 'permanently_deleted')->count());
    }

    public function test_a_run_started_while_another_holds_the_lock_does_nothing(): void
    {
        $incident = $this->trashed($this->incident('Fire'), 31);
        $lock = Cache::lock(TrashPurge::LOCK, 60);
        $this->assertTrue($lock->get());

        try {
            $this->artisan('trash:purge')->expectsOutputToContain('Another trash:purge run is in progress')->assertExitCode(0);
        } finally {
            $lock->release();
        }

        $this->assertNotNull(Incident::onlyTrashed()->find($incident->incident_id));
        $this->assertNull(TrashPurge::lastSuccess());

        // Once released, the next run goes ahead.
        $this->artisan('trash:purge')->assertExitCode(0);
        $this->assertNull(Incident::withTrashed()->find($incident->incident_id));
    }

    public function test_the_command_is_scheduled_daily(): void
    {
        $events = collect(app(\Illuminate\Console\Scheduling\Schedule::class)->events())
            ->filter(fn ($event) => str_contains($event->command, 'trash:purge'));

        $this->assertCount(1, $events);
        $this->assertSame('0 0 * * *', $events->first()->expression);
    }

    // ============================ the Trash pages =============================

    public function test_the_trash_pages_show_a_countdown_only_while_retention_is_running(): void
    {
        $this->trashed($this->incident('Fire'), 18);   // removes in 12 days
        $pages = [
            [$this->admin, route('admin.incidents.trash')],
            [$this->admin, route('admin.buildings.trash')],
            [$this->admin, route('admin.drills.trash')],
            [$this->admin, route('admin.advisories.trash')],
            [$this->coordinator, route('coordinator.incidents.trash')],
        ];

        // Never ran: amber note, no Removal column.
        foreach ($pages as [$user, $url]) {
            $this->actingAs($user)->get($url)->assertOk()
                ->assertSee('data-trash-retention="stopped"', false)->assertSee('Retention is not currently running')
                ->assertSee('it has never run')->assertDontSee('>Removal</th>', false);
        }

        // Ran an hour ago: the window, the last run, and the countdown.
        $this->ranRecently(hoursAgo: 1);
        foreach ($pages as [$user, $url]) {
            $this->actingAs($user)->get($url)->assertOk()
                ->assertSee('data-trash-retention="running"', false)->assertSee('removed permanently 30 days after it was deleted')
                ->assertSee('Automatic removal last ran Oct 11, 2026 8:00 AM')->assertSee('>Removal</th>', false);
        }
        $this->actingAs($this->admin)->get(route('admin.incidents.trash'))
            ->assertSee('Removes in 12 days')->assertSee('Oct 23, 2026');

        // Ran three days ago: stale, so amber again and no countdown.
        $this->ranRecently(hoursAgo: 72);
        $this->actingAs($this->admin)->get(route('admin.incidents.trash'))
            ->assertSee('data-trash-retention="stopped"', false)->assertSee('it last ran Oct 8, 2026 9:00 AM')
            ->assertDontSee('>Removal</th>', false)->assertDontSee('Removes in');
    }

    public function test_a_real_run_turns_the_countdown_on(): void
    {
        $this->trashed($this->incident('Fire'), 18);

        $this->artisan('trash:purge')->assertExitCode(0);

        $this->assertTrue(TrashPurge::isRunning());
        $this->actingAs($this->admin)->get(route('admin.incidents.trash'))->assertSee('Removes in 12 days');
    }

    // ======================= the pause after a restore ========================

    public function test_a_restore_pauses_retention_naming_the_source(): void
    {
        config(['privacy.data_privacy_password_hash' => Hash::make('privacy-secret')]);
        $this->actingAs($this->admin)->post(route('admin.backup.download'), ['data_privacy_password' => 'privacy-secret'])->assertOk();
        $backup = \App\Models\Backup::latest('backup_id')->firstOrFail();
        $this->assertNull(TrashPurge::pause());

        // Restoring a stored backup.
        $this->actingAs($this->admin)->from(route('admin.backup.index'))
            ->post(route('admin.backup.stored.restore', $backup), ['confirmation' => 'RESTORE', 'data_privacy_password' => 'privacy-secret', 'mode' => 'records'])
            ->assertSessionHasNoErrors();
        $this->assertSame($backup->filename, TrashPurge::pause()['source']);
        $this->assertSame(now()->toIso8601String(), TrashPurge::pause()['at']);

        // Uploading one: the uploaded file's name.
        $zip = \Illuminate\Http\UploadedFile::fake()->createWithContent('from-the-usb-stick.zip', Storage::disk('local')->get($backup->storage_path));
        $this->actingAs($this->admin)->from(route('admin.backup.index'))
            ->post(route('admin.backup.restore'), ['backup_file' => $zip, 'confirmation' => 'RESTORE', 'data_privacy_password' => 'privacy-secret', 'mode' => 'records'])
            ->assertSessionHasNoErrors();
        $this->assertSame('from-the-usb-stick.zip', TrashPurge::pause()['source']);
    }

    public function test_a_restores_message_says_retention_is_paused(): void
    {
        config(['privacy.data_privacy_password_hash' => Hash::make('privacy-secret')]);
        $this->actingAs($this->admin)->post(route('admin.backup.download'), ['data_privacy_password' => 'privacy-secret'])->assertOk();
        $backup = \App\Models\Backup::latest('backup_id')->firstOrFail();

        foreach (['records', 'everything'] as $mode) {
            $this->actingAs($this->admin)->from(route('admin.backup.index'))
                ->post(route('admin.backup.stored.restore', $backup), ['confirmation' => 'RESTORE', 'data_privacy_password' => 'privacy-secret', 'mode' => $mode])
                ->assertSessionHasNoErrors()
                ->assertSessionHas('status', fn ($s) => str_ends_with($s, ' Trash retention is paused until someone resumes it.'));
            // "everything" replaces the personnel table; sign in again as the restored admin.
            $this->admin = Personnel::where('email', $this->admin->email)->firstOrFail();
        }
    }

    public function test_a_paused_run_deletes_nothing_and_records_no_successful_run(): void
    {
        $this->ranRecently(hoursAgo: 5);
        $lastSuccess = TrashPurge::status()['last_success_at'];
        TrashPurge::pauseAfterRestore('ascot-drrms-backup-2026-10-11.zip');
        $incident = $this->trashed($this->incident('Fire', 'incidents/fire.jpg'), 45);

        $this->artisan('trash:purge')
            ->expectsOutputToContain('Retention is paused after the restore of ascot-drrms-backup-2026-10-11.zip on Oct 11, 2026. Nothing was deleted.')
            ->assertExitCode(0);

        $this->assertNotNull(Incident::onlyTrashed()->find($incident->incident_id));
        Storage::disk('records')->assertExists('incidents/fire.jpg');
        $this->assertSame(0, ActivityLog::where('action', 'permanently_deleted')->count());
        $this->assertSame($lastSuccess, TrashPurge::status()['last_success_at']);
        $this->assertNotNull(TrashPurge::pause());
    }

    public function test_while_paused_the_trash_pages_name_the_restore_and_show_no_countdown(): void
    {
        $this->trashed($this->incident('Fire'), 18);
        $this->ranRecently(hoursAgo: 1);      // it was running until the restore
        TrashPurge::pauseAfterRestore('ascot-drrms-backup-2026-10-11.zip');
        $this->artisan('trash:purge')->assertExitCode(0);   // a paused run in between

        foreach ([[$this->admin, route('admin.incidents.trash')], [$this->admin, route('admin.buildings.trash')], [$this->admin, route('admin.drills.trash')],
                  [$this->admin, route('admin.advisories.trash')], [$this->coordinator, route('coordinator.incidents.trash')]] as [$user, $url]) {
            $this->actingAs($user)->get($url)->assertOk()
                ->assertSee('data-trash-retention="paused"', false)
                ->assertSee('Retention is paused after the restore of ascot-drrms-backup-2026-10-11.zip on Oct 11, 2026.')
                ->assertSee('Nothing is being removed from Trash.')
                ->assertDontSee('>Removal</th>', false)->assertDontSee('Removes in');
        }
        // How to resume is for the Admin's eyes.
        $this->actingAs($this->admin)->get(route('admin.incidents.trash'))->assertSee('php artisan trash:purge --resume');
        $this->actingAs($this->coordinator)->get(route('coordinator.incidents.trash'))->assertDontSee('trash:purge --resume');
    }

    public function test_a_dry_run_while_paused_says_so_and_still_lists_what_would_go(): void
    {
        TrashPurge::pauseAfterRestore('ascot-drrms-backup-2026-10-11.zip');
        $incident = $this->trashed($this->incident('Fire'), 45);

        $this->artisan('trash:purge', ['--dry-run' => true])
            ->expectsOutputToContain('Retention is paused after the restore of ascot-drrms-backup-2026-10-11.zip on Oct 11, 2026. Once it is resumed, a run would do this:')
            ->expectsOutputToContain("[would delete] Incident 'Fire'")
            ->expectsOutputToContain('Would be: 1 deleted, 0 kept')
            ->assertExitCode(0);

        $this->assertNotNull(Incident::onlyTrashed()->find($incident->incident_id));
        $this->assertNotNull(TrashPurge::pause());
    }

    public function test_resume_without_a_pause_fails_and_changes_nothing(): void
    {
        $this->ranRecently(hoursAgo: 5);
        $before = Storage::disk(TrashPurge::STATUS_DISK)->get(TrashPurge::STATUS_PATH);
        $incident = $this->trashed($this->incident('Fire'), 45);

        $this->artisan('trash:purge', ['--resume' => true])
            ->expectsOutputToContain('Retention is not paused: there is no pause to clear. Nothing was changed.')
            ->assertExitCode(1);

        $this->assertNotNull(Incident::onlyTrashed()->find($incident->incident_id));
        $this->assertSame($before, Storage::disk(TrashPurge::STATUS_DISK)->get(TrashPurge::STATUS_PATH));
        $this->assertSame(0, ActivityLog::where('action', 'permanently_deleted')->count());
    }

    public function test_resume_lists_what_will_go_clears_the_pause_and_runs(): void
    {
        TrashPurge::pauseAfterRestore('ascot-drrms-backup-2026-10-11.zip');
        $old = $this->trashed($this->incident('Fire'), 45);
        $lab = $this->trashed($this->building('Science Lab'), 45);
        $this->damageReport($this->incident('Earthquake'), $lab);

        $this->assertSame(0, \Illuminate\Support\Facades\Artisan::call('trash:purge', ['--resume' => true]));
        $output = \Illuminate\Support\Facades\Artisan::output();

        // In this order: the dry-run lines, the count, the pause being cleared, then the run.
        $order = ["[would keep]   Building 'Science Lab'", "[would delete] Incident 'Fire'", '1 would be deleted, 1 kept.',
            'Clearing the pause set by the restore of ascot-drrms-backup-2026-10-11.zip on Oct 11, 2026 9:00 AM.', "[deleted]      Incident 'Fire'"];
        $positions = array_map(fn ($line) => strpos($output, $line), $order);
        $this->assertNotContains(false, $positions, $output);
        $sorted = $positions;
        sort($sorted);
        $this->assertSame($sorted, $positions, $output);

        $this->assertNull(TrashPurge::pause());
        $this->assertNull(Incident::withTrashed()->find($old->incident_id));
        $this->assertNotNull(Building::onlyTrashed()->find($lab->building_id));
        $this->assertTrue(TrashPurge::isRunning());
    }

    public function test_after_resume_the_pages_show_the_countdown_again(): void
    {
        TrashPurge::pauseAfterRestore('ascot-drrms-backup-2026-10-11.zip');
        $this->trashed($this->incident('Fire'), 18);
        $this->actingAs($this->admin)->get(route('admin.incidents.trash'))->assertSee('data-trash-retention="paused"', false);

        $this->artisan('trash:purge', ['--resume' => true])->assertExitCode(0);

        $this->actingAs($this->admin)->get(route('admin.incidents.trash'))
            ->assertSee('data-trash-retention="running"', false)->assertDontSee('Retention is paused')
            ->assertSee('>Removal</th>', false)->assertSee('Removes in 12 days');
    }

    public function test_a_restore_during_a_run_stops_it_before_the_next_record(): void
    {
        $first = $this->trashed($this->incident('First'), 60);
        $second = $this->trashed($this->incident('Second'), 45);
        // The first deletion's log entry is where we "restore" mid-run.
        ActivityLog::created(function (ActivityLog $log) {
            if ($log->action === 'permanently_deleted') {
                TrashPurge::pauseAfterRestore('mid-run.zip');
            }
        });

        $this->artisan('trash:purge')->expectsOutputToContain('[stopped]')->assertExitCode(0);

        $this->assertNull(Incident::withTrashed()->find($first->incident_id));
        $this->assertNotNull(Incident::onlyTrashed()->find($second->incident_id));
        $this->assertNull(TrashPurge::lastSuccess());
    }

    // ================================ helpers =================================

    /** Put $record in Trash $days ago, without model events. */
    protected function trashed(Model $record, int $days): Model
    {
        $record::withTrashed()->whereKey($record->getKey())->toBase()->update(['deleted_at' => now()->subDays($days)]);

        return $record::withTrashed()->find($record->getKey());
    }

    protected function ranRecently(int $hoursAgo = 1): void
    {
        Storage::disk(TrashPurge::STATUS_DISK)->put(TrashPurge::STATUS_PATH, json_encode(['last_success_at' => now()->subHours($hoursAgo)->toIso8601String()]));
    }

    protected function building(string $name, ?string $photo = null): Building
    {
        if ($photo) {
            Storage::disk('records')->put($photo, 'PHOTO');
        }

        return Building::forceCreate(['campus_id' => $this->campus->campus_id, 'building_name' => $name, 'risk_level' => 'Low', 'photo_path' => $photo, 'created_by' => $this->admin->personnel_id]);
    }

    protected function inspection(Building $building, ?string $photo = null): void
    {
        if ($photo) {
            Storage::disk('records')->put($photo, 'PHOTO');
        }
        DB::table('hazard_inspections')->insert(['building_id' => $building->building_id, 'inspection_date' => '2026-09-01', 'risk_level' => 'Low', 'photo_path' => $photo]);
    }

    protected function incident(string $hazard, ?string $photo = null, ?DisasterAdvisory $advisory = null): Incident
    {
        if ($photo) {
            Storage::disk('records')->put($photo, 'PHOTO');
        }

        return Incident::forceCreate(['campus_id' => $this->campus->campus_id, 'hazard_type' => $hazard, 'date_occurred' => '2026-09-05', 'status' => 'Active',
            'photo_path' => $photo, 'advisory_id' => $advisory?->advisory_id, 'created_by' => $this->coordinator->personnel_id]);
    }

    protected function damageReport(Incident $incident, Building $building): void
    {
        DB::table('damage_reports')->insert(['incident_id' => $incident->incident_id, 'building_id' => $building->building_id, 'damage_level' => 'Minor']);
    }

    protected function drill(): Drill
    {
        return Drill::forceCreate(['campus_id' => $this->campus->campus_id, 'drill_type' => 'Fire', 'scheduled_date' => '2026-09-01', 'scheduled_time' => '09:00', 'venue' => 'Covered court', 'created_by' => $this->admin->personnel_id]);
    }

    protected function advisory(): DisasterAdvisory
    {
        return DisasterAdvisory::forceCreate([
            'campus_id' => $this->campus->campus_id, 'hazard_type' => 'Typhoon', 'headline' => 'Typhoon Egay', 'measurements' => ['wind_signal' => '2'],
            'source' => 'PAGASA', 'issued_at' => now()->subDays(40), 'valid_until' => now()->subDays(39), 'status' => 'Active',
            'instructions' => 'Stay indoors.', 'created_by' => $this->admin->personnel_id,
        ]);
    }
}
