<?php

namespace Tests\Feature;

use App\Models\Campus;
use App\Models\Personnel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * The sidebar's user card (also the phone drawer's — one <aside>) shows the
 * signed-in person's uploaded photo in the initials circle, falling back to
 * the initials when there is no photo or its file is missing.
 */
class SidebarPhotoTest extends TestCase
{
    use RefreshDatabase;

    protected Campus $campus;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('records');
        $this->campus = Campus::create(['campus_name' => 'ASCOT Zabali Campus']);
    }

    protected function person(string $name, string $role, ?string $photoPath): Personnel
    {
        return Personnel::forceCreate([
            'campus_id' => $this->campus->campus_id, 'full_name' => $name, 'role' => $role,
            'email' => strtolower(str_replace(' ', '.', $name)) . '@ascot-test.local', 'password_hash' => Hash::make('password'),
            'photo_path' => $photoPath,
        ]);
    }

    /** The user card in the sidebar: [the circle element, the card's text]. */
    protected function card(Personnel $user, string $route): array
    {
        $document = new \DOMDocument();
        libxml_use_internal_errors(true);
        $document->loadHTML('<?xml encoding="utf-8"?>' . $this->actingAs($user)->get(route($route))->assertOk()->getContent());
        libxml_clear_errors();
        $xpath = new \DOMXPath($document);

        $cards = $xpath->query("//aside[@id='sidebar']//div[@data-user-card]");
        $this->assertSame(1, $cards->length, 'exactly one user card in the sidebar');
        $circle = $xpath->query('./*[1]', $cards->item(0))->item(0);

        return [$circle, $cards->item(0), $xpath];
    }

    public function test_a_person_with_a_photo_sees_it_in_the_sidebar_card(): void
    {
        Storage::disk('records')->put('personnel/maria-santos-1-abc123.jpg', 'JPEGDATA');
        $user = $this->person('Maria Santos', 'Coordinator', 'personnel/maria-santos-1-abc123.jpg');

        [$circle, $card, $xpath] = $this->card($user, 'coordinator.dashboard');

        $this->assertSame('img', $circle->nodeName);
        $this->assertTrue($circle->hasAttribute('data-user-photo'));
        // A signed, expiring link through FileController, never a public /storage URL.
        $this->assertStringContainsString('/files/personnel/maria-santos-1-abc123.jpg?', $circle->getAttribute('src'));
        $this->assertStringContainsString('signature=', $circle->getAttribute('src'));
        // Same 36px circle as the initials, cropped to fill rather than squashed.
        foreach (['h-9', 'w-9', 'shrink-0', 'rounded-full', 'object-cover'] as $class) {
            $this->assertContains($class, explode(' ', $circle->getAttribute('class')), "missing $class");
        }
        // Decorative (the name is beside it), and not lazy-loaded.
        $this->assertSame('', $circle->getAttribute('alt'));
        $this->assertTrue($circle->hasAttribute('alt'));
        $this->assertFalse($circle->hasAttribute('loading'));
        // The photo replaces the initials, and the name is still shown.
        $this->assertSame(0, $xpath->query('.//*[@data-user-initials]', $card)->length);
        $this->assertStringContainsString('Maria Santos', $card->textContent);
    }

    public function test_a_person_without_a_photo_sees_their_initials_unchanged(): void
    {
        $user = $this->person('Juan dela Cruz', 'DRRMO Admin', null);

        [$circle, $card, $xpath] = $this->card($user, 'admin.dashboard');

        $this->assertSame('span', $circle->nodeName);
        $this->assertSame('JC', trim($circle->textContent));
        $this->assertSame('flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-accent-soft text-xs font-semibold text-accent-ink', $circle->getAttribute('class'), 'the initials circle: 36px, accent tint');
        $this->assertSame('true', $circle->getAttribute('aria-hidden'));
        $this->assertSame(0, $xpath->query('.//img', $card)->length);
    }

    public function test_a_photo_path_whose_file_is_missing_shows_the_initials_not_a_broken_image(): void
    {
        $user = $this->person('Ana Reyes', 'Coordinator', 'personnel/ana-reyes-9-gone00.jpg'); // no file on the disk

        [$circle, $card, $xpath] = $this->card($user, 'coordinator.dashboard');

        $this->assertSame('span', $circle->nodeName);
        $this->assertSame('AR', trim($circle->textContent));
        $this->assertSame(0, $xpath->query('.//img', $card)->length, 'a broken <img> is rendered');
        $this->assertNull($user->existingPhotoUrl());
        $this->assertNotNull($user->photo_url, 'photo_url itself is unchanged (the profile page still uses it)');
    }
}
