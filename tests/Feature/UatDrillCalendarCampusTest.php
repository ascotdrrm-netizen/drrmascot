<?php

namespace Tests\Feature;

use App\Models\Campus;
use App\Models\Drill\Drill;
use App\Models\Personnel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * UAT 2026-10-18: the Admin's month calendar showed two EARTHQUAKE drills on
 * one day that looked like a duplicate. With "All campuses" each pill now
 * names its campus, so two campuses' drills can be told apart.
 */
class UatDrillCalendarCampusTest extends TestCase
{
    use RefreshDatabase;

    public function test_all_campus_month_pills_name_the_campus(): void
    {
        $zabali = Campus::create(['campus_name' => 'ASCOT Zabali Campus']);
        $bazal = Campus::create(['campus_name' => 'ASCOT Bazal Campus']);
        $admin = Personnel::create(['campus_id' => $zabali->campus_id, 'full_name' => 'Drill Admin', 'role' => 'DRRMO Admin',
            'email' => 'drill-admin@example.test', 'password_hash' => Hash::make('password')]);
        Drill::create(['campus_id' => $zabali->campus_id, 'drill_type' => 'Earthquake', 'scheduled_date' => '2026-10-21', 'scheduled_time' => '12:45', 'venue' => 'Gym']);
        Drill::create(['campus_id' => $bazal->campus_id, 'drill_type' => 'Earthquake', 'scheduled_date' => '2026-10-21', 'scheduled_time' => '17:00', 'venue' => 'Gym']);

        $html = $this->actingAs($admin)->get(route('admin.drills.index', ['month' => '2026-10']))->assertOk()->getContent();

        $this->assertMatchesRegularExpression('/data-drill-pill[^>]*>.*?12:45 PM · Gym · Zabali/s', $html);
        $this->assertMatchesRegularExpression('/data-drill-pill[^>]*>.*?5:00 PM · Gym · Bazal/s', $html);
    }
}
