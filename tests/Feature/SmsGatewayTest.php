<?php

namespace Tests\Feature;

use App\Services\Sms\SemaphoreSmsGateway;
use App\Support\SmsSegments;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SmsGatewayTest extends TestCase
{
    public function test_semaphore_requires_an_explicit_acceptance_and_keeps_ambiguous_results_unknown(): void
    {
        Http::fakeSequence()->push([['message_id' => 1, 'status' => 'Queued']])->push([['message_id' => 2, 'status' => 'Failed']])
            ->push('invalid-json', 200)->push([], 503);
        $gateway = new SemaphoreSmsGateway;
        $this->assertTrue($gateway->send('639171234567', 'Campus notice'));
        $this->assertFalse($gateway->send('639171234567', 'Campus notice'));
        foreach ([1, 2] as $_) {
            try {
                $gateway->send('639171234567', 'Campus notice');
                $this->fail('Ambiguous acceptance must be reported.');
            } catch (\RuntimeException $exception) {
                $this->assertStringContainsString('SMS provider', $exception->getMessage());
            }
        }
    }

    public function test_sms_estimates_account_for_encoding_and_extended_characters(): void
    {
        $this->assertSame(1, SmsSegments::count(str_repeat('a', 160)));
        $this->assertSame(2, SmsSegments::count(str_repeat('a', 161)));
        $this->assertSame(2, SmsSegments::count(str_repeat('^', 81)));
        $this->assertSame(1, SmsSegments::count(str_repeat('漢', 70)));
        $this->assertSame(2, SmsSegments::count(str_repeat('漢', 71)));
        $this->assertSame(2, SmsSegments::count(str_repeat('😀', 36)));
    }
}
