<?php

namespace Tests\Feature;

use App\Models\Campus;
use App\Models\Hotline\HotlineGroup;
use App\Models\Personnel;
use App\Support\Hotlines;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Thesis panel (Engineering): the Admin and the coordinators edit the
 * hotlines. The Admin edits every group; a coordinator only the group that
 * serves their campus; what is saved is what the public pages show.
 */
class HotlineEditorTest extends TestCase
{
    use RefreshDatabase;

    protected Campus $zabali;

    protected Personnel $admin;

    protected Personnel $coordinator;

    protected HotlineGroup $baler;

    protected function setUp(): void
    {
        parent::setUp();

        $this->zabali = Campus::create(['campus_name' => 'ASCOT Zabali Campus']);
        $this->admin = Personnel::create(['campus_id' => $this->zabali->campus_id, 'full_name' => 'Hotline Admin', 'role' => 'DRRMO Admin',
            'email' => 'hotline-admin@ascot-test.local', 'password_hash' => Hash::make('password')]);
        $this->coordinator = Personnel::forceCreate(['campus_id' => $this->zabali->campus_id, 'full_name' => 'Hotline Coordinator', 'role' => 'Coordinator',
            'email' => 'hotline-coord@ascot-test.local', 'password_hash' => Hash::make('password'), 'is_approved' => true]);

        // The migration copied config/hotlines.php in; the campus exists only now, so link it.
        $this->baler = HotlineGroup::where('title', 'Baler')->firstOrFail();
        $this->baler->update(['campus_id' => $this->zabali->campus_id]);
    }

    public function test_the_migration_copied_every_group_and_the_pages_read_the_same_numbers(): void
    {
        $this->assertSame(array_column(config('hotlines.groups'), 'title'), array_column(Hotlines::groups(), 'title'));
        $this->assertSame(config('hotlines.groups'), Hotlines::groups());
    }

    public function test_the_admin_sees_every_group_and_a_coordinator_edits_only_their_campus(): void
    {
        $this->actingAs($this->admin)->get(route('admin.hotlines.index'))->assertOk()
            ->assertSee('Aurora Province')->assertSee('Baler')->assertSee('Casiguran');

        // A coordinator sees every group (they call the province and other towns
        // too), with Edit only on their own campus's group.
        $province = HotlineGroup::where('title', 'Aurora Province')->firstOrFail();
        $html = $this->actingAs($this->coordinator)->get(route('coordinator.hotlines.index'))->assertOk()->getContent();
        $this->assertSame(HotlineGroup::count(), substr_count($html, 'data-hotline-group-card='));
        $this->assertStringContainsString(route('coordinator.hotlines.edit', $this->baler), $html);
        $this->assertStringNotContainsString(route('coordinator.hotlines.edit', $province), $html);

        $this->get(route('coordinator.hotlines.edit', $province))->assertForbidden();
        $this->put(route('coordinator.hotlines.update', $province), ['entries' => [['label' => 'X', 'numbers' => '0917 000 0000']]])->assertForbidden();
    }

    public function test_a_coordinator_edits_their_campus_hotlines_and_the_home_page_shows_them(): void
    {
        $this->actingAs($this->coordinator)->put(route('coordinator.hotlines.update', $this->baler), [
            'note' => 'Nearest to Zabali Campus.',
            'entries' => [
                ['label' => 'MDRRMO Baler', 'numbers' => "0920-594-1906\n0917-555-0101"],
                ['label' => 'MDRRMO radio frequency', 'radio' => '147.110 MHz'],
                ['label' => '', 'numbers' => ''],
                ['label' => 'Campus Clinic', 'numbers' => ''],
            ],
        ])->assertRedirect(route('coordinator.hotlines.index'))->assertSessionHasNoErrors();

        $baler = collect(Hotlines::groups())->firstWhere('title', 'Baler');
        $this->assertSame([
            ['label' => 'MDRRMO Baler', 'numbers' => ['0920-594-1906', '0917-555-0101']],
            ['label' => 'MDRRMO radio frequency', 'radio' => '147.110 MHz'],
            ['label' => 'Campus Clinic', 'numbers' => []],
        ], $baler['entries']);

        Hotlines::flush();
        $this->get(route('landing'))->assertOk()->assertSee('0917-555-0101')->assertSee('Campus Clinic')->assertDontSee('Baler PNP');
    }

    public function test_something_that_is_not_a_phone_number_is_refused(): void
    {
        $this->actingAs($this->admin)->from(route('admin.hotlines.edit', $this->baler))
            ->put(route('admin.hotlines.update', $this->baler), ['entries' => [['label' => 'MDRRMO Baler', 'numbers' => 'call me']]])
            ->assertSessionHasErrors('entries.0.numbers');
        $this->assertSame(7, $this->baler->hotlines()->count(), 'nothing changed');
    }
}
