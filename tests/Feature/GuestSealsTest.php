<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class GuestSealsTest extends TestCase
{
    use RefreshDatabase;

    /** @return array<string, array{string}> */
    public static function guestPages(): array
    {
        return [
            'login' => ['/login'],
            'register' => ['/register'],
            'forgot password' => ['/forgot-password'],
            'reset password' => ['/reset-password/some-token?email=someone@ascot-test.local'],
        ];
    }

    #[DataProvider('guestPages')]
    public function test_the_seal_pair_renders_once_centred_above_the_heading(string $uri): void
    {
        $html = $this->get($uri)->assertOk()->getContent();

        // Exactly one seal row, holding both logos, and no logo anywhere else.
        $this->assertSame(1, substr_count($html, 'data-auth-seals'));
        $this->assertSame(1, substr_count($html, 'images/ascot-logo-256.png'));
        $this->assertSame(1, substr_count($html, 'images/drrm-logo-256.png'));

        $this->assertMatchesRegularExpression(
            '/<div class="auth-seals[^"]*\bflex\b[^"]*\bitems-center\b[^"]*\bjustify-center\b[^"]*" data-auth-seals>\s*'
            .'<img src="[^"]*images\/ascot-logo-256\.png"[^>]*>\s*'
            .'<img src="[^"]*images\/drrm-logo-256\.png"[^>]*>\s*<\/div>/',
            $html
        );

        // The seals come directly before the ASCOT-DRRMS heading, and inside the centred column.
        $seals = strpos($html, 'data-auth-seals');
        $heading = strpos($html, 'ASCOT<span class="text-[#fb923c]">-DRRMS</span>');
        $column = strpos($html, 'drrms-fade-in w-full max-w-md');
        $this->assertNotFalse($heading);
        $this->assertTrue($column < $seals && $seals < $heading);
        $sealsEnd = strpos($html, '</div>', $seals);
        $this->assertStringNotContainsString('<img', substr($html, $sealsEnd, $heading - $sealsEnd));

        // Nothing is pinned to the top-right corner any more.
        $this->assertDoesNotMatchRegularExpression('/class="(?=[^"]*\bfixed\b)(?=[^"]*\bright-\d)(?=[^"]*\btop-\d)[^"]*"/', $html);
    }
}
