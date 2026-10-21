<?php

namespace Tests\Feature;

use App\Models\Alert;
use App\Models\Building\Building;
use App\Models\Campus;
use App\Models\Incident\Incident;
use App\Models\Personnel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * UAT 2026-10-22: "Download PDF" looked like plain text. Every one is now a
 * button with a download icon (components/download-pdf), small in rows.
 */
class DownloadPdfButtonTest extends TestCase
{
    use RefreshDatabase;

    public function test_download_pdf_is_a_button_with_an_icon_everywhere(): void
    {
        $campus = Campus::create(['campus_name' => 'ASCOT Zabali Campus']);
        $admin = Personnel::create(['campus_id' => $campus->campus_id, 'full_name' => 'Pdf Admin', 'role' => 'DRRMO Admin',
            'email' => 'pdf-admin@ascot-test.local', 'password_hash' => Hash::make('password')]);
        $incident = Incident::create(['campus_id' => $campus->campus_id, 'hazard_type' => 'Fire', 'date_occurred' => '2026-10-21',
            'status' => 'Active', 'description' => 'Fire']);
        Alert::create(['incident_id' => $incident->incident_id, 'message' => 'Evacuate', 'sent_at' => now(), 'recipient_count' => 6, 'delivery_status' => 'Sent']);
        Building::create(['campus_id' => $campus->campus_id, 'building_name' => 'Gym', 'hazard_type' => 'Fire', 'risk_level' => 'High']);

        $pages = [
            route('admin.incidents.show', $incident) => 2,   // the record + its alert row
            route('admin.incidents.index') => 1,
            route('admin.buildings.index') => 2,             // card and table layouts
        ];
        foreach ($pages as $url => $count) {
            $html = $this->actingAs($admin)->get($url)->assertOk()->getContent();
            preg_match_all('/<a [^>]*data-download-pdf[^>]*>(.*?)<\/a>/s', $html, $buttons);
            $this->assertCount($count, $buttons[0], $url);
            foreach ($buttons[0] as $i => $button) {
                $this->assertStringContainsString('btn btn-secondary', $button, $url);
                $this->assertStringContainsString('<svg', $buttons[1][$i], "$url: the download icon");
                $this->assertStringContainsString('Download PDF', $buttons[1][$i]);
            }
        }
        $this->assertStringNotContainsString('class="link-muted">Download PDF', $this->get(route('admin.incidents.show', $incident))->getContent());
    }
}
