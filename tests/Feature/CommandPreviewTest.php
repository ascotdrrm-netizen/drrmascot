<?php

namespace Tests\Feature;

use App\Models\Campus;
use App\Models\Personnel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * The live Reports Inbox, SOS Console and Campus Status board render for both
 * staff roles, even before any Reporter submissions arrive.
 */
class CommandPreviewTest extends TestCase
{
    use RefreshDatabase;

    public function test_both_roles_get_the_three_live_command_screens(): void
    {
        $campus = Campus::create(['campus_name' => 'ASCOT Zabali Campus']);
        $admin = Personnel::create(['campus_id' => $campus->campus_id, 'full_name' => 'Command Admin', 'role' => 'DRRMO Admin',
            'email' => 'command-admin@ascot-test.local', 'password_hash' => Hash::make('password')]);
        $coordinator = Personnel::forceCreate(['campus_id' => $campus->campus_id, 'full_name' => 'Command Coordinator', 'role' => 'Coordinator',
            'email' => 'command-coord@ascot-test.local', 'password_hash' => Hash::make('password'), 'is_approved' => true]);

        foreach (['admin' => $admin, 'coordinator' => $coordinator] as $prefix => $user) {
            foreach (['command.inbox' => 'Reports Inbox', 'command.sos' => 'SOS Console', 'command.campus-status' => 'Campus Status'] as $route => $heading) {
                $html = $this->actingAs($user)->get(route("$prefix.$route"))->assertOk()->assertSee($heading)->getContent();
                $this->assertStringNotContainsString('data-sample-data', $html);
                $this->assertStringContainsString('>Operations<', $html, 'the sidebar section');
            }
        }
    }

    public function test_the_sos_console_escalates_to_numbers_from_the_hotline_list(): void
    {
        $campus = Campus::create(['campus_name' => 'ASCOT Zabali Campus']);
        $admin = Personnel::create(['campus_id' => $campus->campus_id, 'full_name' => 'Command Admin', 'role' => 'DRRMO Admin',
            'email' => 'command-admin@ascot-test.local', 'password_hash' => Hash::make('password')]);

        $this->actingAs($admin)->get(route('admin.command.sos'))->assertOk()
            ->assertSee('Baler BFP')->assertSee('href="tel:09171192794"', false);
    }
}
