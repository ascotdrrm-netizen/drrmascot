<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Campus;
use App\Models\Drill\Drill;
use App\Models\Drill\DrillAfterActivityReport;
use App\Models\Drill\DrillAttachment;
use App\Models\Drill\DrillDocumentation;
use App\Models\Personnel;
use App\Support\PdfImage;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\View;
use Tests\Concerns\ReadsReportHtml;
use Tests\TestCase;

/**
 * Drill Tracker: scheduling by both roles, campus scoping, the two separate
 * after-the-drill submissions with private attachments, the four PDFs, the
 * activity log, the dashboard, and the calendar.
 */
class DrillModuleTest extends TestCase
{
    use ReadsReportHtml, RefreshDatabase;

    protected Campus $zabali;

    protected Campus $bazal;

    protected Personnel $admin;

    protected Personnel $coordA;

    protected Personnel $coordB;

    protected Personnel $bazalCoord;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::parse('2026-09-25 10:00:00', 'Asia/Manila'));
        Storage::fake('local');
        Storage::fake('public');
        Storage::fake('records');

        $this->zabali = Campus::create(['campus_name' => 'ASCOT Zabali Campus']);
        $this->bazal = Campus::create(['campus_name' => 'ASCOT Bazal Campus']);

        $make = fn (Campus $c, string $name, string $role, string $email) => Personnel::create([
            'campus_id' => $c->campus_id, 'full_name' => $name, 'role' => $role, 'email' => $email, 'password_hash' => Hash::make('password'),
        ]);
        $this->admin = $make($this->zabali, 'Drill Admin', 'DRRMO Admin', 'dm-admin@ascot-test.local');
        $this->coordA = $make($this->zabali, 'Coordinator Alpha', 'Coordinator', 'dm-a@ascot-test.local');
        $this->coordB = $make($this->zabali, 'Coordinator Bravo', 'Coordinator', 'dm-b@ascot-test.local');
        $this->bazalCoord = $make($this->bazal, 'Bazal Coordinator', 'Coordinator', 'dm-bazal@ascot-test.local');
    }

    // ================================ helpers =================================

    protected function drill(Campus $campus, ?Personnel $by, string $date = '2026-09-20', string $venue = 'Covered Court', string $type = 'Earthquake', string $time = '09:00:00'): Drill
    {
        return Drill::forceCreate([
            'campus_id' => $campus->campus_id, 'drill_type' => $type, 'scheduled_date' => $date, 'scheduled_time' => $time, 'venue' => $venue,
            'created_by' => $by?->personnel_id, 'created_by_name' => $by?->full_name,
        ]);
    }

    protected function document(Drill $drill, string $date, string $narrative, ?int $count = null): DrillDocumentation
    {
        $doc = new DrillDocumentation(['date_conducted' => $date, 'narrative' => $narrative, 'participant_count' => $count]);
        $doc->forceFill(['drill_id' => $drill->drill_id, 'submitted_by_name' => 'Fixture'])->save();

        return $doc;
    }

    protected function report(Drill $drill, string $date, string $activities): DrillAfterActivityReport
    {
        $aar = new DrillAfterActivityReport(['activity_title' => $drill->drill_type . ' Drill', 'date_conducted' => $date, 'activities_conducted' => $activities]);
        $aar->forceFill(['drill_id' => $drill->drill_id])->save();

        return $aar;
    }

    protected function prefix(Personnel $who): string
    {
        return $who->role === 'DRRMO Admin' ? 'admin' : 'coordinator';
    }

    // ========================== scheduling and access =========================

    public function test_admin_can_schedule_a_drill_for_several_campuses_at_once(): void
    {
        $this->actingAs($this->admin)->post(route('admin.drills.store'), [
            'campus_ids' => [$this->zabali->campus_id, $this->bazal->campus_id], 'drill_type' => 'Fire',
            'scheduled_date' => '2026-10-07', 'scheduled_time' => '13:40', 'venue' => 'Every Campus Gym', 'partner_agency' => 'BFP Baler',
        ])->assertRedirect(route('admin.drills.index', ['month' => '2026-10']));

        $drills = Drill::where('venue', 'Every Campus Gym')->get();
        $this->assertEqualsCanonicalizing([$this->zabali->campus_id, $this->bazal->campus_id], $drills->pluck('campus_id')->all());
        $this->assertTrue($drills->every(fn ($d) => $d->created_by === $this->admin->personnel_id && $d->partner_agency === 'BFP Baler'));
        $this->assertSame(2, ActivityLog::where('action', 'drill_scheduled')->count());
    }

    /**
     * UAT 2026-10-21: the same Earthquake drill was entered twice on one day
     * ("Gym court" 12:45 PM, then "Gym" 5:00 PM) and showed as two. A second
     * drill of the same type that day is asked about first; a different type
     * is not.
     */
    public function test_a_second_drill_of_the_same_type_that_day_is_asked_about_first(): void
    {
        $first = $this->drill($this->zabali, $this->admin, '2026-10-21', 'Gym court', 'Earthquake', '12:45:00');
        $second = ['campus_ids' => [$this->zabali->campus_id], 'drill_type' => 'Earthquake',
            'scheduled_date' => '2026-10-21', 'scheduled_time' => '17:00', 'venue' => 'Gym'];

        $this->actingAs($this->admin)->from(route('admin.drills.create'))->post(route('admin.drills.store'), $second)
            ->assertRedirect(route('admin.drills.create'))
            ->assertSessionHasErrors(['confirm_same_day' => 'ASCOT Zabali Campus already has an Earthquake drill on Oct 21, 2026 (12:45 PM, Gym court). If this is a separate drill, tick "Schedule it anyway" and save again.']);
        $this->assertSame(1, Drill::count());

        // As AJAX: a 422 on the checkbox, which the form then shows.
        $this->actingAs($this->admin)->withHeaders(['X-Requested-With' => 'XMLHttpRequest', 'Accept' => 'application/json'])
            ->post(route('admin.drills.store'), $second)
            ->assertUnprocessable()->assertJsonValidationErrors('confirm_same_day');
        $this->flushHeaders();
        $this->actingAs($this->admin)->get(route('admin.drills.create'))->assertSee('name="confirm_same_day"', false);

        $this->actingAs($this->admin)->post(route('admin.drills.store'), $second + ['confirm_same_day' => '1'])->assertRedirect();
        $this->assertSame(2, Drill::count());

        // Another type that day, or the same type on another campus, is not asked about.
        $this->actingAs($this->admin)->post(route('admin.drills.store'), ['drill_type' => 'Fire'] + $second)->assertSessionHasNoErrors();
        $this->actingAs($this->admin)->post(route('admin.drills.store'), ['campus_ids' => [$this->bazal->campus_id]] + $second)->assertSessionHasNoErrors();

        // Editing a drill onto that day asks too.
        $other = $this->drill($this->zabali, $this->admin, '2026-10-25', 'Field', 'Earthquake', '08:00:00');
        $this->actingAs($this->admin)->put(route('admin.drills.update', $other), ['drill_type' => 'Earthquake',
            'scheduled_date' => '2026-10-21', 'scheduled_time' => '08:00', 'venue' => 'Field'])->assertSessionHasErrors('confirm_same_day');
        $this->assertSame('2026-10-25', $other->fresh()->scheduled_date->toDateString());
        $this->assertSame($first->drill_id, Drill::orderBy('drill_id')->first()->drill_id);

        // A drill already sharing its day saves without being asked again.
        $this->actingAs($this->admin)->put(route('admin.drills.update', $first), ['drill_type' => 'Earthquake',
            'scheduled_date' => '2026-10-21', 'scheduled_time' => '12:45', 'venue' => 'Gym court, north side'])->assertSessionHasNoErrors();
        $this->assertSame('Gym court, north side', $first->fresh()->venue);
    }

    public function test_coordinator_can_schedule_a_drill_for_their_own_campus(): void
    {
        $response = $this->actingAs($this->coordA)->post(route('coordinator.drills.store'), [
            'drill_type' => 'Other', 'drill_type_other' => 'Tsunami', 'scheduled_date' => '2026-10-02', 'scheduled_time' => '08:00', 'venue' => 'Seawall',
        ]);
        $drill = Drill::where('venue', 'Seawall')->firstOrFail();

        $response->assertRedirect(route('coordinator.drills.show', $drill));
        $this->assertSame($this->zabali->campus_id, $drill->campus_id);
        $this->assertSame('Tsunami', $drill->drill_type);
        $this->assertSame($this->coordA->personnel_id, $drill->created_by);
        $this->actingAs($this->coordA)->get(route('coordinator.drills.create'))->assertOk()->assertSee('For your campus')->assertDontSee('name="campus_ids[]"', false);
    }

    public function test_coordinator_cannot_schedule_a_drill_for_another_campus(): void
    {
        $base = ['drill_type' => 'Fire', 'scheduled_date' => '2026-10-02', 'scheduled_time' => '08:00', 'venue' => 'Sneaky'];

        $this->actingAs($this->coordA)->post(route('coordinator.drills.store'), $base + ['campus_ids' => [$this->bazal->campus_id]])->assertForbidden();
        $this->actingAs($this->coordA)->post(route('coordinator.drills.store'), $base + ['campus_id' => $this->bazal->campus_id])->assertForbidden();
        $this->actingAs($this->coordA)->post(route('coordinator.drills.store'), $base + ['campus_ids' => [$this->zabali->campus_id, $this->bazal->campus_id]])->assertForbidden();
        $this->assertSame(0, Drill::where('venue', 'Sneaky')->count());
        // Their own campus named explicitly is fine.
        $this->actingAs($this->coordA)->post(route('coordinator.drills.store'), $base + ['campus_ids' => [$this->zabali->campus_id]])->assertRedirect();
        $this->assertSame([$this->zabali->campus_id], Drill::where('venue', 'Sneaky')->pluck('campus_id')->all());
    }

    public function test_coordinator_gets_403_on_another_campus_drill_everywhere(): void
    {
        $drill = $this->drill($this->bazal, $this->bazalCoord);
        $this->document($drill, '2026-09-20', 'Bazal narrative');
        $this->report($drill, '2026-09-20', 'Bazal activities');
        $this->actingAs($this->bazalCoord)->put(route('coordinator.drills.documentation.update', $drill), [
            'date_conducted' => '2026-09-20', 'narrative' => 'Bazal narrative', 'attachments' => [UploadedFile::fake()->image('bazal.jpg', 400, 300)],
        ])->assertRedirect();
        $file = DrillAttachment::firstOrFail();
        $trashed = $this->drill($this->bazal, $this->bazalCoord, '2026-09-21', 'Bazal trashed');
        $trashed->delete();

        $attempts = [
            ['get', route('coordinator.drills.show', $drill)],
            ['get', route('coordinator.drills.edit', $drill)],
            ['put', route('coordinator.drills.update', $drill), ['drill_type' => 'Fire', 'scheduled_date' => '2026-09-20', 'scheduled_time' => '09:00', 'venue' => 'Hijacked']],
            ['delete', route('coordinator.drills.destroy', $drill)],
            ['get', route('coordinator.drills.memorandum', $drill)],
            ['get', route('coordinator.drills.documentation.edit', $drill)],
            ['put', route('coordinator.drills.documentation.update', $drill), ['date_conducted' => '2026-09-20', 'narrative' => 'Hijacked']],
            ['get', route('coordinator.drills.documentation.pdf', $drill)],
            ['get', route('coordinator.drills.after-activity.edit', $drill)],
            ['put', route('coordinator.drills.after-activity.update', $drill), ['activity_title' => 'X', 'date_conducted' => '2026-09-20', 'activities_conducted' => 'Hijacked']],
            ['get', route('coordinator.drills.after-activity.pdf', $drill)],
            ['get', route('coordinator.drills.attachments.show', [$drill, $file])],
            ['delete', route('coordinator.drills.attachments.destroy', [$drill, $file])],
            ['post', route('coordinator.drills.restore', $trashed->drill_id)],
        ];
        foreach ($attempts as $attempt) {
            $this->actingAs($this->coordA)->{$attempt[0]}($attempt[1], $attempt[2] ?? [])->assertForbidden();
        }

        $this->assertSame('Covered Court', $drill->fresh()->venue);
        $this->assertSame('Bazal narrative', $drill->documentation()->value('narrative'));
        $this->assertSame(1, DrillAttachment::count());
        // A tampered campus filter doesn't widen the calendar either.
        $this->assertCount(0, $this->actingAs($this->coordA)->get(route('coordinator.drills.index', ['campus_id' => $this->bazal->campus_id]))->viewData('drills'));
    }

    public function test_every_coordinator_of_the_campus_sees_every_campus_drill(): void
    {
        $byAdmin = $this->drill($this->zabali, $this->admin, '2026-09-10', 'Admin drill');
        $byA = $this->drill($this->zabali, $this->coordA, '2026-09-12', 'Alpha drill');
        $this->drill($this->bazal, $this->bazalCoord, '2026-09-14', 'Bazal drill');

        foreach ([$this->coordA, $this->coordB] as $who) {
            $this->assertEqualsCanonicalizing([$byAdmin->drill_id, $byA->drill_id], $this->actingAs($who)->get(route('coordinator.drills.index'))->viewData('drills')->pluck('drill_id')->all());
            $this->actingAs($who)->get(route('coordinator.drills.show', $byA))->assertOk();
        }
    }

    public function test_coordinator_can_edit_and_delete_only_drills_they_created(): void
    {
        $own = $this->drill($this->zabali, $this->coordA, '2026-09-28', 'Alpha own');
        $theirs = $this->drill($this->zabali, $this->coordB, '2026-09-28', 'Bravo own');
        $update = ['drill_type' => 'Evacuation', 'scheduled_date' => '2026-09-29', 'scheduled_time' => '10:00', 'venue' => 'Changed'];

        $this->actingAs($this->coordA)->get(route('coordinator.drills.show', $own))->assertSee('Edit schedule');
        $this->actingAs($this->coordA)->get(route('coordinator.drills.show', $theirs))->assertOk()->assertDontSee('Edit schedule');
        $this->actingAs($this->coordA)->get(route('coordinator.drills.edit', $theirs))->assertForbidden();
        $this->actingAs($this->coordA)->put(route('coordinator.drills.update', $theirs), $update)->assertForbidden();
        $this->actingAs($this->coordA)->delete(route('coordinator.drills.destroy', $theirs))->assertForbidden();

        $this->actingAs($this->coordA)->put(route('coordinator.drills.update', $own), $update)->assertRedirect(route('coordinator.drills.show', $own));
        $this->assertSame(['Evacuation', 'Changed'], [$own->fresh()->drill_type, $own->fresh()->venue]);
        $this->actingAs($this->coordA)->delete(route('coordinator.drills.destroy', $own))->assertRedirect();
        $this->assertSoftDeleted('drills', ['drill_id' => $own->drill_id]);
        $this->assertNotSoftDeleted('drills', ['drill_id' => $theirs->drill_id]);
    }

    public function test_admin_scheduled_drills_are_editable_by_the_admin_only(): void
    {
        $drill = $this->drill($this->zabali, $this->admin);
        $legacy = $this->drill($this->zabali, null, '2026-09-21', 'No creator on record');

        foreach ([$drill, $legacy] as $d) {
            $this->actingAs($this->coordA)->get(route('coordinator.drills.edit', $d))->assertForbidden();
            $this->actingAs($this->coordA)->delete(route('coordinator.drills.destroy', $d))->assertForbidden();
            $this->actingAs($this->admin)->get(route('admin.drills.edit', $d))->assertOk();
        }
        $this->actingAs($this->admin)->delete(route('admin.drills.destroy', $legacy))->assertRedirect();
        $this->assertSoftDeleted('drills', ['drill_id' => $legacy->drill_id]);
    }

    public function test_coordinator_trash_lists_and_restores_only_drills_they_created(): void
    {
        $own = $this->drill($this->zabali, $this->coordA, '2026-09-10', 'Alpha trashed');
        $theirs = $this->drill($this->zabali, $this->coordB, '2026-09-11', 'Bravo trashed');
        $admins = $this->drill($this->zabali, $this->admin, '2026-09-12', 'Admin trashed');
        foreach ([$own, $theirs, $admins] as $d) {
            $d->delete();
        }

        $this->assertSame(['Alpha trashed'], $this->actingAs($this->coordA)->get(route('coordinator.drills.trash'))->assertOk()->viewData('drills')->pluck('venue')->all());
        $this->actingAs($this->coordA)->post(route('coordinator.drills.restore', $theirs->drill_id))->assertForbidden();
        $this->actingAs($this->coordA)->post(route('coordinator.drills.restore', $admins->drill_id))->assertForbidden();
        $this->actingAs($this->coordA)->post(route('coordinator.drills.restore', $own->drill_id))->assertRedirect(route('coordinator.drills.trash'));
        $this->assertNotSoftDeleted('drills', ['drill_id' => $own->drill_id]);

        $this->assertCount(2, $this->actingAs($this->admin)->get(route('admin.drills.trash'))->viewData('drills'));
    }

    // ==================== documentation and After Activity Report =============

    public function test_any_campus_coordinator_can_submit_documentation_and_is_recorded_as_submitter(): void
    {
        $drill = $this->drill($this->zabali, $this->admin);

        $this->actingAs($this->coordB)->put(route('coordinator.drills.documentation.update', $drill), [
            'date_conducted' => '2026-09-20', 'time_conducted' => '09:10', 'narrative' => 'Bravo wrote this.', 'participant_count' => 250,
        ])->assertRedirect(route('coordinator.drills.show', $drill));

        $doc = $drill->documentation()->firstOrFail();
        $this->assertSame([$this->coordB->personnel_id, 'Coordinator Bravo', 250], [$doc->submitted_by, $doc->submitted_by_name, $doc->participant_count]);
        $this->actingAs($this->coordA)->get(route('coordinator.drills.show', $drill))->assertSee('Submitted by Coordinator Bravo')->assertSee('Bravo wrote this.');
    }

    public function test_documentation_and_after_activity_report_save_independently_with_their_own_attachments(): void
    {
        $drill = $this->drill($this->zabali, $this->admin);

        $this->actingAs($this->coordA)->put(route('coordinator.drills.documentation.update', $drill), [
            'date_conducted' => '2026-09-20', 'narrative' => 'DOC-ONLY narrative',
            'attachments' => [UploadedFile::fake()->image('assembly.jpg', 800, 600), UploadedFile::fake()->create('attendance.pdf', 200, 'application/pdf')],
        ])->assertSessionHasNoErrors();
        $this->assertNull($drill->afterActivityReport()->first(), 'documentation alone creates no After Activity Report');

        $this->actingAs($this->coordB)->put(route('coordinator.drills.after-activity.update', $drill), [
            'activity_title' => 'Earthquake Drill', 'date_conducted' => '2026-09-20', 'activities_conducted' => 'AAR-ONLY activities',
            'participants_students' => 200, 'participants_employees' => 40,
            'attachments' => [UploadedFile::fake()->image('critique.png', 600, 400)],
        ])->assertSessionHasNoErrors();

        $drill->refresh();
        $this->assertSame('DOC-ONLY narrative', $drill->documentation->narrative);
        $this->assertSame('AAR-ONLY activities', $drill->afterActivityReport->activities_conducted);
        $this->assertSame(240, $drill->afterActivityReport->totalParticipants());
        $this->assertEqualsCanonicalizing(['assembly.jpg', 'attendance.pdf'], $drill->documentation->attachments->pluck('original_name')->all());
        $this->assertSame(['critique.png'], $drill->afterActivityReport->attachments->pluck('original_name')->all());

        foreach (DrillAttachment::all() as $file) {
            Storage::disk('records')->assertExists($file->path);
            $this->assertStringStartsWith("drill-attachments/{$drill->drill_id}/{$file->section}/", $file->path);
        }
        $this->assertSame([], Storage::disk('public')->allFiles(), 'nothing on the public disk');
    }

    public function test_each_drill_has_at_most_one_documentation_and_one_after_activity_report(): void
    {
        $drill = $this->drill($this->zabali, $this->admin);
        foreach (['First', 'Second'] as $version) {
            $this->actingAs($this->coordA)->put(route('coordinator.drills.documentation.update', $drill), ['date_conducted' => '2026-09-20', 'narrative' => "$version narrative"])->assertRedirect();
            $this->actingAs($this->coordB)->put(route('coordinator.drills.after-activity.update', $drill), ['activity_title' => "$version title", 'date_conducted' => '2026-09-20', 'activities_conducted' => 'Done.'])->assertRedirect();
        }

        $this->assertSame(1, DrillDocumentation::where('drill_id', $drill->drill_id)->count());
        $this->assertSame(1, DrillAfterActivityReport::where('drill_id', $drill->drill_id)->count());
        $this->assertSame('Second narrative', $drill->documentation()->value('narrative'));
        $this->assertSame('Second title', $drill->afterActivityReport()->value('activity_title'));
        // The first submitter stays recorded.
        $this->assertSame('Coordinator Alpha', $drill->documentation()->value('submitted_by_name'));
    }

    public function test_attachments_reject_wrong_types_and_oversized_files(): void
    {
        $drill = $this->drill($this->zabali, $this->admin);
        $put = fn (array $files) => $this->actingAs($this->coordA)->put(route('coordinator.drills.documentation.update', $drill), [
            'date_conducted' => '2026-09-20', 'narrative' => 'Files test', 'attachments' => $files,
        ]);

        $put([UploadedFile::fake()->create('virus.exe', 10, 'application/x-msdownload')])->assertSessionHasErrors('attachments.0');
        $put([UploadedFile::fake()->create('notes.docx', 10, 'application/vnd.openxmlformats-officedocument.wordprocessingml.document')])->assertSessionHasErrors('attachments.0');
        $put([UploadedFile::fake()->image('huge.jpg')->size(6000)])->assertSessionHasErrors('attachments.0');
        $put([UploadedFile::fake()->create('huge.pdf', 11000, 'application/pdf')])->assertSessionHasErrors('attachments.0');
        $put(array_map(fn ($i) => UploadedFile::fake()->image("p{$i}.jpg", 50, 50), range(1, 11)))->assertSessionHasErrors('attachments');

        $this->assertSame(0, DrillAttachment::count());
        $this->assertNull($drill->documentation()->first(), 'a rejected upload saves nothing');

        // Within the limits: a 4 MB photo and a 9 MB PDF.
        $put([UploadedFile::fake()->image('ok.jpg')->size(4000), UploadedFile::fake()->create('ok.pdf', 9000, 'application/pdf')])->assertSessionHasNoErrors();
        $this->assertSame(2, DrillAttachment::count());
    }

    public function test_attachments_are_private_and_served_only_after_sign_in_and_campus_check(): void
    {
        $drill = $this->drill($this->zabali, $this->admin);
        $this->actingAs($this->coordA)->put(route('coordinator.drills.documentation.update', $drill), [
            'date_conducted' => '2026-09-20', 'narrative' => 'Private files', 'attachments' => [UploadedFile::fake()->create('roster.pdf', 50, 'application/pdf')],
        ]);
        $file = DrillAttachment::firstOrFail();

        Storage::disk('public')->assertMissing($file->path);
        $this->assertFalse(str_contains(Storage::disk('records')->path($file->path), 'storage' . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'public'));

        auth()->logout();
        $this->get(route('coordinator.drills.attachments.show', [$drill, $file]))->assertRedirect(route('login'));
        $this->actingAs($this->bazalCoord)->get(route('coordinator.drills.attachments.show', [$drill, $file]))->assertForbidden();
        $this->actingAs($this->coordB)->get(route('coordinator.drills.attachments.show', [$drill, $file]))->assertOk();
        $this->actingAs($this->admin)->get(route('admin.drills.attachments.show', [$drill, $file]) . '?download=1')->assertOk()->assertDownload('roster.pdf');

        // An attachment can only be reached through its own drill.
        $other = $this->drill($this->zabali, $this->admin, '2026-09-21', 'Other');
        $this->actingAs($this->admin)->get(route('admin.drills.attachments.show', [$other, $file]))->assertNotFound();
    }

    public function test_removing_an_attachment_deletes_the_file(): void
    {
        $drill = $this->drill($this->zabali, $this->admin);
        $this->actingAs($this->coordA)->put(route('coordinator.drills.after-activity.update', $drill), [
            'activity_title' => 'Drill', 'date_conducted' => '2026-09-20', 'activities_conducted' => 'Done.', 'attachments' => [UploadedFile::fake()->image('gone.jpg', 100, 100)],
        ]);
        $file = DrillAttachment::firstOrFail();

        $this->actingAs($this->coordB)->delete(route('coordinator.drills.attachments.destroy', [$drill, $file]))
            ->assertRedirect(route('coordinator.drills.after-activity.edit', $drill));
        Storage::disk('records')->assertMissing($file->path);
        $this->assertSame(0, DrillAttachment::count());
        $this->assertNotNull($drill->afterActivityReport()->first(), 'the report itself stays');
    }

    // ================================ printing ================================

    public function test_documentation_pdf_downloads_for_one_drill(): void
    {
        $drill = $this->drill($this->zabali, $this->admin, '2026-09-20', 'Covered Court', 'Fire');
        $this->actingAs($this->coordA)->get(route('coordinator.drills.documentation.pdf', $drill))->assertNotFound();
        $this->document($drill, '2026-09-20', 'Held.');

        $response = $this->actingAs($this->coordA)->get(route('coordinator.drills.documentation.pdf', $drill))->assertOk();
        $this->assertSame('application/pdf', $response->headers->get('Content-Type'));
        $this->assertStringContainsString('ASCOT-Drill-Documentation_Fire_2026-09-20_ASCOT-Zabali-Campus.pdf', $response->headers->get('Content-Disposition'));
    }

    public function test_after_activity_report_pdf_downloads_for_one_drill(): void
    {
        $drill = $this->drill($this->zabali, $this->admin, '2026-09-20', 'Covered Court', 'Fire');
        $this->report($drill, '2026-09-20', 'Done.');

        $response = $this->actingAs($this->admin)->get(route('admin.drills.after-activity.pdf', $drill))->assertOk();
        $this->assertSame('application/pdf', $response->headers->get('Content-Type'));
        $this->assertStringContainsString('ASCOT-Drill-After-Activity-Report_Fire_2026-09-20_ASCOT-Zabali-Campus.pdf', $response->headers->get('Content-Disposition'));
    }

    public function test_consolidated_documentation_report_contains_only_documentation(): void
    {
        $drill = $this->drill($this->zabali, $this->admin);
        $this->document($drill, '2026-09-20', 'DOC-NARRATIVE-123');
        $this->report($drill, '2026-09-20', 'AAR-ACTIVITIES-456');
        $q = ['period_type' => 'monthly', 'month' => 9, 'year' => 2026];

        $html = $this->periodPdfHtml($this->admin, 'drill-documentation', $q);
        $this->assertStringContainsString('DOC-NARRATIVE-123', $html);
        $this->assertStringNotContainsString('AAR-ACTIVITIES-456', $html);
        $this->assertStringContainsString('Drill Documentation', $html);
        $this->assertStringNotContainsString('data-after-activity-entry', $html);
    }

    public function test_consolidated_after_activity_report_contains_only_after_activity_reports(): void
    {
        $drill = $this->drill($this->zabali, $this->admin);
        $this->document($drill, '2026-09-20', 'DOC-NARRATIVE-123');
        $this->report($drill, '2026-09-20', 'AAR-ACTIVITIES-456');
        $q = ['period_type' => 'monthly', 'month' => 9, 'year' => 2026];

        $html = $this->periodPdfHtml($this->admin, 'after-activity', $q);
        $this->assertStringContainsString('AAR-ACTIVITIES-456', $html);
        $this->assertStringNotContainsString('DOC-NARRATIVE-123', $html);
        $this->assertStringNotContainsString('data-documentation-entry', $html);
    }

    public function test_consolidated_reports_apply_each_period_filter(): void
    {
        // Held in August (week 33) and in January; the scheduled date doesn't matter.
        $aug = $this->drill($this->zabali, $this->admin, '2026-07-30', 'Aug venue');
        $this->document($aug, '2026-08-12', 'AUGUST-DOC');
        $this->report($aug, '2026-08-12', 'AUGUST-AAR');
        $jan = $this->drill($this->zabali, $this->admin, '2026-01-15', 'Jan venue');
        $this->document($jan, '2026-01-15', 'JANUARY-DOC');
        $this->report($jan, '2026-01-15', 'JANUARY-AAR');

        $periods = [
            'weekly' => [['period_type' => 'weekly', 'week_date' => '2026-08-12'], true, false],
            'monthly' => [['period_type' => 'monthly', 'month' => 8, 'year' => 2026], true, false],
            'quarterly' => [['period_type' => 'quarterly', 'quarter' => 1, 'year' => 2026], false, true],
            'yearly' => [['period_type' => 'yearly', 'year' => 2026], true, true],
            'custom' => [['period_type' => 'custom', 'start_date' => '2026-08-01', 'end_date' => '2026-08-31'], true, false],
            'yearly 2025' => [['period_type' => 'yearly', 'year' => 2025], false, false],
        ];
        foreach (['drill-documentation' => 'DOC', 'after-activity' => 'AAR'] as $module => $kind) {
            foreach ($periods as $name => [$query, $hasAugust, $hasJanuary]) {
                $html = $this->periodPdfHtml($this->admin, $module, $query);
                $this->assertSame($hasAugust, str_contains($html, "AUGUST-{$kind}"), "$module $name: August");
                $this->assertSame($hasJanuary, str_contains($html, "JANUARY-{$kind}"), "$module $name: January");
            }
        }
    }

    public function test_consolidated_reports_apply_the_campus_filter_and_lock_coordinators_to_their_campus(): void
    {
        foreach ([[$this->zabali, 'ZABALI'], [$this->bazal, 'BAZAL']] as [$campus, $tag]) {
            $drill = $this->drill($campus, null, '2026-09-20', "$tag venue");
            $this->document($drill, '2026-09-20', "$tag-DOC");
            $this->report($drill, '2026-09-20', "$tag-AAR");
        }
        $q = ['period_type' => 'monthly', 'month' => 9, 'year' => 2026];

        foreach (['drill-documentation' => 'DOC', 'after-activity' => 'AAR'] as $module => $kind) {
            $all = $this->periodPdfHtml($this->admin, $module, $q);
            $this->assertStringContainsString("ZABALI-{$kind}", $all);
            $this->assertStringContainsString("BAZAL-{$kind}", $all);

            $bazalOnly = $this->periodPdfHtml($this->admin, $module, $q + ['campus_id' => $this->bazal->campus_id]);
            $this->assertStringContainsString("BAZAL-{$kind}", $bazalOnly);
            $this->assertStringNotContainsString("ZABALI-{$kind}", $bazalOnly);

            // A coordinator asking for another campus still gets only their own.
            $tampered = $this->periodPdfHtml($this->coordA, $module, $q + ['campus_id' => $this->bazal->campus_id]);
            $this->assertStringContainsString("ZABALI-{$kind}", $tampered);
            $this->assertStringNotContainsString("BAZAL-{$kind}", $tampered);
        }
    }

    public function test_attached_images_are_shrunk_for_printing_and_attached_pdfs_are_listed_by_name(): void
    {
        $big = UploadedFile::fake()->image('wide.jpg', 3000, 2000);
        $uri = PdfImage::dataUri($big->getRealPath());
        [$w, $h] = getimagesizefromstring(base64_decode(substr($uri, strpos($uri, ',') + 1)));
        $this->assertSame([1200, 800], [$w, $h], 'long side capped at 1200px, proportions kept');

        $drill = $this->drill($this->zabali, $this->admin);
        $this->actingAs($this->coordA)->put(route('coordinator.drills.documentation.update', $drill), [
            'date_conducted' => '2026-09-20', 'narrative' => 'With files',
            'attachments' => [$big, UploadedFile::fake()->create('incident-command-plan.pdf', 120, 'application/pdf')],
        ])->assertSessionHasNoErrors();

        $captured = [];
        View::composer('reports.records.drill-documentation', function ($v) use (&$captured) { $captured = $v->getData(); });
        $this->actingAs($this->admin)->get(route('admin.drills.documentation.pdf', $drill))->assertOk();
        $this->assertCount(1, $captured['images']);
        $this->assertStringStartsWith('data:image/jpeg;base64,', $captured['images'][0]['src']);
        $html = view('reports.records.drill-documentation', $captured + ['totalPages' => 1])->render();
        $this->assertStringContainsString('incident-command-plan.pdf', $html);
        $this->assertStringContainsString('Photos (1)', $html);
    }

    // ============================ activity log ================================

    public function test_activity_log_records_scheduling_both_submissions_and_every_download(): void
    {
        $this->actingAs($this->coordA)->post(route('coordinator.drills.store'), ['drill_type' => 'Fire', 'scheduled_date' => '2026-09-20', 'scheduled_time' => '09:00', 'venue' => 'Logged']);
        $drill = Drill::where('venue', 'Logged')->firstOrFail();
        $this->actingAs($this->coordA)->put(route('coordinator.drills.documentation.update', $drill), [
            'date_conducted' => '2026-09-20', 'narrative' => 'Logged.', 'attachments' => [UploadedFile::fake()->create('a.pdf', 10, 'application/pdf')],
        ]);
        $this->actingAs($this->coordA)->put(route('coordinator.drills.after-activity.update', $drill), ['activity_title' => 'Fire Drill', 'date_conducted' => '2026-09-20', 'activities_conducted' => 'Logged.']);

        $actions = fn () => ActivityLog::where('personnel_id', $this->coordA->personnel_id)->pluck('action')->all();
        $this->assertSame(['drill_scheduled', 'drill_documented', 'drill_after_activity_submitted'], $actions());

        $file = DrillAttachment::firstOrFail();
        $q = ['period_type' => 'monthly', 'month' => 9, 'year' => 2026];
        foreach ([
            route('coordinator.drills.memorandum', $drill),
            route('coordinator.drills.documentation.pdf', $drill),
            route('coordinator.drills.after-activity.pdf', $drill),
            route('coordinator.drills.attachments.show', [$drill, $file]) . '?download=1',
            route('reports.module.pdf', ['module' => 'drill-documentation'] + $q),
            route('reports.module.pdf', ['module' => 'after-activity'] + $q),
            route('reports.module.pdf', ['module' => 'drills'] + $q),
        ] as $url) {
            $this->actingAs($this->coordA)->get($url)->assertOk();
        }
        // Viewing a thumbnail is not a download and is not logged.
        $this->actingAs($this->coordA)->get(route('coordinator.drills.attachments.show', [$drill, $file]))->assertOk();

        $this->assertSame(7, ActivityLog::where('personnel_id', $this->coordA->personnel_id)->where('action', 'report_exported')->count());
    }

    // ================================ dashboard ===============================

    public function test_drills_conducted_counts_documented_drills_by_the_date_held(): void
    {
        // Scheduled long ago but held (documented) inside the last 30 days: counts.
        $late = $this->drill($this->zabali, $this->admin, '2026-06-01', 'Late');
        $this->document($late, '2026-09-20', 'Held late.');
        // Held, but documented as held outside the range: doesn't.
        $old = $this->drill($this->zabali, $this->admin, '2026-09-20', 'Old');
        $this->document($old, '2026-07-01', 'Held earlier.');
        // Scheduled in range but never documented: doesn't.
        $this->drill($this->zabali, $this->admin, '2026-09-21', 'Undocumented');
        // Another campus.
        $this->document($this->drill($this->bazal, null, '2026-09-20', 'Bazal'), '2026-09-20', 'Bazal held.');

        $kpi = fn (Personnel $who) => collect($this->actingAs($who)->get(route($this->prefix($who) . '.dashboard', ['range' => '30d']))->viewData('analytics')['kpis'])->keyBy('key')['drills']['current'];
        $this->assertSame(1, $kpi($this->coordA));
        $this->assertSame(2, $kpi($this->admin));
    }

    public function test_coordinator_dashboard_lists_campus_drills_missing_documentation_or_report(): void
    {
        $missingBoth = $this->drill($this->zabali, $this->admin, '2026-09-10', 'Missing both');
        $missingReport = $this->drill($this->zabali, $this->coordB, '2026-09-12', 'Missing report');
        $this->document($missingReport, '2026-09-12', 'Documented.');
        $complete = $this->drill($this->zabali, $this->admin, '2026-09-14', 'Complete');
        $this->document($complete, '2026-09-14', 'Done.');
        $this->report($complete, '2026-09-14', 'Done.');
        $this->drill($this->zabali, $this->admin, '2026-10-01', 'Future');
        $this->drill($this->bazal, null, '2026-09-10', 'Bazal missing');

        $page = $this->actingAs($this->coordA)->get(route('coordinator.dashboard'))->assertOk();
        $queue = $page->viewData('analytics')['lists']['drills_to_document'];
        $this->assertSame(2, $queue['total']);
        $this->assertSame([$missingBoth->drill_id, $missingReport->drill_id], $queue['items']->pluck('drill_id')->all());
        $page->assertSee('Drills to document (2)')->assertSee('Documentation + Report missing')->assertSee('Report missing');
    }

    // ================================ calendar ================================

    public function test_calendar_renders_for_both_roles_with_type_colours_and_legend(): void
    {
        $this->drill($this->zabali, $this->admin, '2026-09-24', 'ASCOT Gym', 'Earthquake', '13:40:00');
        $this->drill($this->zabali, $this->admin, '2026-09-24', 'Field', 'Custom Tsunami');

        foreach ([$this->admin, $this->coordA] as $who) {
            $html = $this->actingAs($who)->get(route($this->prefix($who) . '.drills.index'))->assertOk()->getContent();

            $this->assertStringContainsString('data-calendar', $html);
            $this->assertStringContainsString('data-month-heading="2026-09"', $html);
            $this->assertMatchesRegularExpression('/<span class="text-3xl[^"]*">SEP<\/span>\s*<span class="text-3xl[^"]*">2026<\/span>/', $html);
            $this->assertSame(35, substr_count($html, 'data-day="'), 'Aug 30 – Oct 3: five whole weeks, Sun to Sat');
            $this->assertMatchesRegularExpression('/data-day="2026-08-30"\s+data-outside-month/', $html, 'leading days of August, faded');
            $this->assertMatchesRegularExpression('/data-day="2026-09-25"\s+aria-current="date" data-today/', $html);
            $this->assertMatchesRegularExpression('/data-drill-pill="Earthquake"[^>]*class="[^"]*border-violet-500/', $html);
            $this->assertMatchesRegularExpression('/data-drill-pill="Other"[^>]*class="[^"]*border-slate-500/', $html);
            $this->assertStringContainsString('1:40 PM · ASCOT Gym', $html);
            foreach (['Earthquake', 'Fire', 'Evacuation', 'Other'] as $type) {
                $this->assertMatchesRegularExpression('/data-legend.*' . $type . '<\/li>/s', $html, "legend: $type");
            }
            // The workflow is gone from the page.
            foreach (['Acknowledged', 'Needs review', 'Your role', 'Participants'] as $gone) {
                $this->assertStringNotContainsString($gone, $html);
            }
        }
    }

    public function test_coordinator_calendar_shows_only_their_campus_drills(): void
    {
        $own = $this->drill($this->zabali, $this->admin, '2026-09-24', 'Zabali venue');
        $this->drill($this->bazal, $this->bazalCoord, '2026-09-24', 'Bazal venue');

        $page = $this->actingAs($this->coordA)->get(route('coordinator.drills.index'))->assertOk();
        $this->assertSame([$own->drill_id], $page->viewData('drills')->pluck('drill_id')->all());
        $page->assertSee('Zabali venue')->assertDontSee('Bazal venue');

        // The Admin sees both, and can narrow by campus.
        $this->assertCount(2, $this->actingAs($this->admin)->get(route('admin.drills.index'))->viewData('drills'));
        $this->assertCount(1, $this->actingAs($this->admin)->get(route('admin.drills.index', ['campus_id' => $this->bazal->campus_id]))->viewData('drills'));
    }

    public function test_month_navigation_crosses_the_year_boundary(): void
    {
        $this->travelTo(Carbon::parse('2026-12-10 10:00:00', 'Asia/Manila'));
        $this->drill($this->zabali, $this->admin, '2027-01-05', 'New Year drill');

        $december = $this->actingAs($this->coordA)->get(route('coordinator.drills.index'))->assertOk()->getContent();
        $this->assertStringContainsString('data-month-heading="2026-12"', $december);
        $next = route('coordinator.drills.index', ['month' => '2027-01']);
        $this->assertMatchesRegularExpression('/href="' . preg_quote($next, '/') . '"[^>]*data-month-next/', $december);
        // December 2026 ends on a Thursday, so its last grid week shows Jan 1–2, faded.
        $this->assertMatchesRegularExpression('/data-day="2027-01-02"\s+data-outside-month/', $december);

        $january = $this->actingAs($this->coordA)->get($next)->assertOk();
        $january->assertSee('data-month-heading="2027-01"', false)->assertSee('New Year drill');
        $this->assertMatchesRegularExpression('/href="' . preg_quote(route('coordinator.drills.index', ['month' => '2026-12']), '/') . '"[^>]*data-month-prev/', $january->getContent());
        // "Today" goes back to the current month.
        $january->assertSee('href="' . route('coordinator.drills.index', ['month' => '2026-12']) . '" class="btn btn-secondary"', false);
        // A bad month value falls back to the current month.
        $this->actingAs($this->coordA)->get(route('coordinator.drills.index', ['month' => '2026-13']))->assertOk()->assertSee('data-month-heading="2026-12"', false);
    }

    public function test_a_day_with_more_than_three_drills_shows_an_overflow_link_to_that_days_list(): void
    {
        foreach (['A', 'B', 'C', 'D', 'E'] as $i => $tag) {
            $this->drill($this->zabali, $this->admin, '2026-09-15', "Busy {$tag}", 'Fire', sprintf('%02d:00:00', 8 + $i));
        }
        $this->drill($this->zabali, $this->admin, '2026-09-16', 'Quiet day');

        $html = $this->actingAs($this->coordA)->get(route('coordinator.drills.index'))->assertOk()->getContent();
        preg_match('/<li[^>]*data-day="2026-09-15".*?<\/li>/s', $html, $cell);
        $this->assertSame(3, substr_count($cell[0], 'data-drill-pill='), 'first three pills only');
        $this->assertStringContainsString('Busy A', $cell[0]);
        $this->assertStringNotContainsString('Busy D', $cell[0]);
        $dayList = route('coordinator.drills.index', ['view' => 'list', 'date' => '2026-09-15']);
        $this->assertStringContainsString('href="' . e($dayList) . '"', $cell[0]);
        $this->assertStringContainsString('+2 more', $cell[0]);
        preg_match('/<li[^>]*data-day="2026-09-16".*?<\/li>/s', $html, $quiet);
        $this->assertStringNotContainsString('more</a>', $quiet[0]);

        $list = $this->actingAs($this->coordA)->get($dayList)->assertOk();
        $this->assertSame(['Busy A', 'Busy B', 'Busy C', 'Busy D', 'Busy E'], $list->viewData('drills')->pluck('venue')->all());
        $list->assertSee('data-drill-list', false)->assertDontSee('Quiet day');
    }

    public function test_agenda_view_renders_below_the_md_breakpoint(): void
    {
        $this->drill($this->zabali, $this->admin, '2026-09-24', 'Agenda venue', 'Evacuation', '13:40:00');

        $html = $this->actingAs($this->coordA)->get(route('coordinator.drills.index'))->assertOk()->getContent();
        // Below md (768px) the 7-column grid is hidden and the agenda shows; from md up, the reverse.
        $this->assertMatchesRegularExpression('/class="card-flush hidden md:block" data-calendar/', $html);
        $this->assertMatchesRegularExpression('/class="md:hidden" data-agenda/', $html);
        preg_match('/data-agenda.*?data-legend/s', $html, $agenda);
        $this->assertStringContainsString('Thu, Sep 24', $agenda[0]);
        $this->assertStringContainsString('1:40 PM · Agenda venue', $agenda[0]);
        $this->assertMatchesRegularExpression('/data-drill-pill="Evacuation"[^>]*class="[^"]*border-sky-500/', $agenda[0]);
    }

    public function test_the_list_view_is_kept_in_the_url(): void
    {
        $this->drill($this->zabali, $this->admin, '2026-09-24', 'Listed');

        $page = $this->actingAs($this->coordA)->get(route('coordinator.drills.index', ['view' => 'list']))->assertOk();
        $page->assertSee('data-drill-list', false)->assertDontSee('data-calendar', false)->assertSee('Listed');
        $html = $page->getContent();
        $this->assertStringContainsString('href="' . e(route('coordinator.drills.index', ['view' => 'list', 'month' => '2026-10'])) . '"', $html, 'next month stays in list view');
        $this->assertMatchesRegularExpression('/aria-current="page"\s+data-view-link="list"/', $html);
    }
}
