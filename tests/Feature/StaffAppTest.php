<?php

namespace Tests\Feature;

use App\Models\Campus;
use App\Models\Personnel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Panel round 2: the DRRMO Admin and coordinators get an installable web
 * app of their own, next to the students' phone app.
 */
class StaffAppTest extends TestCase
{
    use RefreshDatabase;

    protected function person(string $role, string $email): Personnel
    {
        $campus = Campus::firstOrCreate(['campus_name' => 'ASCOT Zabali Campus']);

        return Personnel::forceCreate(['campus_id' => $campus->campus_id, 'full_name' => "$role Person", 'role' => $role,
            'email' => $email, 'password_hash' => Hash::make('password'), 'is_approved' => true, 'email_verified_at' => now()]);
    }

    public function test_the_staff_manifest_is_a_separate_installable_app(): void
    {
        $staff = json_decode(file_get_contents(public_path('manifest-staff.webmanifest')), true, flags: JSON_THROW_ON_ERROR);
        $students = json_decode(file_get_contents(public_path('manifest.webmanifest')), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame('/staff', $staff['id']);
        $this->assertSame('/staff', $staff['start_url']);
        $this->assertNotSame($students['id'], $staff['id']);
        $this->assertSame('standalone', $staff['display']);
        $this->assertContains('512x512', array_column($staff['icons'], 'sizes'));
        $this->assertSame(['/staff?to=sos', '/staff?to=inbox', '/staff?to=drills'], array_column($staff['shortcuts'], 'url'));
    }

    public function test_staff_pages_link_the_staff_manifest_and_register_the_service_worker(): void
    {
        $html = $this->actingAs($this->person('Coordinator', 'c@example.test'))->get(route('coordinator.dashboard'))->assertOk()->getContent();

        $this->assertStringContainsString('manifest-staff.webmanifest', $html);
        $this->assertStringContainsString("serviceWorker.register", $html);
        $this->assertStringContainsString('data-staff-install', $html);
        $this->assertStringNotContainsString('href="' . asset('manifest.webmanifest') . '"', $html);
    }

    public function test_the_start_page_sends_each_role_home_or_to_a_shortcut(): void
    {
        $this->get(route('staff.start'))->assertRedirect(route('login'));
        $this->actingAs($this->person('DRRMO Admin', 'a@example.test'))->get(route('staff.start'))->assertRedirect(route('admin.dashboard'));
        $coordinator = $this->person('Coordinator', 'c2@example.test');
        $this->actingAs($coordinator)->get(route('staff.start', ['to' => 'sos']))->assertRedirect(route('coordinator.command.sos'));
        $this->actingAs($coordinator)->get(route('staff.start', ['to' => 'drills']))->assertRedirect(route('coordinator.drills.index'));
        $this->actingAs($coordinator)->get(route('staff.start', ['to' => 'elsewhere']))->assertRedirect(route('coordinator.dashboard'));
        $this->actingAs($this->person('Reporter', 'r@example.test'))->get(route('staff.start'))->assertRedirect('/app');
    }

    public function test_charts_load_only_on_pages_that_draw_them(): void
    {
        $coordinator = $this->person('Coordinator', 'c3@example.test');
        $this->actingAs($coordinator)->get(route('coordinator.dashboard'))->assertSee('vendor/chart.js/chart.umd.js', false);
        $this->actingAs($coordinator)->get(route('help'))->assertDontSee('vendor/chart.js/chart.umd.js', false);
    }

    public function test_a_malformed_shortcut_goes_to_the_dashboard(): void
    {
        $this->actingAs($this->person('Coordinator', 'c4@example.test'))->get('/staff?to[]=sos')->assertRedirect(route('coordinator.dashboard'));
    }

    public function test_the_queued_sos_email_carries_record_ids_not_whole_records(): void
    {
        $this->assertContains(\Illuminate\Queue\SerializesModels::class, class_uses(\App\Mail\SosAlert::class));
    }
}
