<?php

namespace Tests\Feature;

use App\Models\Campus;
use App\Models\Personnel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * UAT 2026-10-18: the Reporter's report form had no "Pick on map" on its
 * Where step (staff forms have one). The picker opens on the reporter's own
 * campus and hands the pin back to the form as its location.
 */
class UatReportMapPickerTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_where_step_offers_pick_on_map_centred_on_the_reporters_campus(): void
    {
        $campus = Campus::create(['campus_name' => 'ASCOT Casiguran Campus']);
        $reporter = Personnel::create(['campus_id' => $campus->campus_id, 'full_name' => 'Ana Cruz', 'role' => 'Reporter',
            'email' => 'ana@example.test', 'password_hash' => Hash::make('password')]);
        $reporter->forceFill(['is_approved' => true, 'email_verified_at' => now(), 'validated_by_coordinator_at' => now()])->save();

        $html = $this->actingAs($reporter)->get(route('app.report'))->assertOk()->getContent();

        $this->assertStringContainsString('Pick on map', $html);
        $this->assertStringContainsString('data-report-map-picker', $html);
        $this->assertStringContainsString('map-picked.window', $html);
        $this->assertStringContainsString(e(json_encode(config('campus_map.centres.Casiguran'))), $html);
        $this->assertStringContainsString('vendor/leaflet/leaflet.js', $html);
    }
}
