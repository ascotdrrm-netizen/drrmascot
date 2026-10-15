<?php

namespace Tests\Feature;

use App\Models\Campus;
use App\Models\Incident\Incident;
use App\Models\Personnel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Thesis panel (Engineering): a photo says what it shows. The caption is an
 * optional field under every photo input (incidents, buildings, hazard
 * inspections) and shows under the photo.
 */
class PhotoCaptionTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_photo_form_offers_a_caption(): void
    {
        foreach (['incident/_form', 'building/_form', 'building/hazard-inspections/_form'] as $form) {
            $source = file_get_contents(resource_path("views/$form.blade.php"));
            $this->assertStringContainsString('name="photo_caption" maxlength="200"', $source, $form);
        }
    }

    public function test_an_incident_caption_is_saved_and_shown_under_the_photo(): void
    {
        $campus = Campus::create(['campus_name' => 'ASCOT Zabali Campus']);
        $admin = Personnel::create(['campus_id' => $campus->campus_id, 'full_name' => 'Caption Admin', 'role' => 'DRRMO Admin',
            'email' => 'caption-admin@ascot-test.local', 'password_hash' => Hash::make('password')]);

        $this->actingAs($admin)->post(route('admin.incidents.store'), [
            'hazard_type' => 'Fire', 'date_occurred' => now()->toDateString(), 'status' => 'Active', 'campus_id' => $campus->campus_id,
            'photo_caption' => 'Burnt wiring in the server room',
        ])->assertSessionHasNoErrors();
        $this->assertSame('Burnt wiring in the server room', Incident::sole()->photo_caption);

        $this->post(route('admin.incidents.store'), [
            'hazard_type' => 'Fire', 'date_occurred' => now()->toDateString(), 'status' => 'Active', 'campus_id' => $campus->campus_id,
            'photo_caption' => str_repeat('x', 201),
        ])->assertSessionHasErrors('photo_caption');

        // The caption shows once there is a photo to show it under.
        Incident::sole()->forceFill(['photo_path' => 'incidents/fire.jpg'])->save();
        $this->get(route('admin.incidents.show', Incident::sole()))->assertOk()
            ->assertSee('data-photo-caption', false)->assertSee('Burnt wiring in the server room');
    }
}
