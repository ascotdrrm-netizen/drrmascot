<?php

namespace App\Services\Sms;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * TextBee (textbee.dev) gateway: a free Android app sends each text from the
 * office phone's own SIM. Requires TEXTBEE_API_KEY; TEXTBEE_DEVICE_ID picks
 * the phone when more than one is linked (otherwise TextBee uses the default).
 */
class TextBeeSmsGateway implements SmsGatewayInterface
{
    public function send(string $phoneNumber, string $message): bool
    {
        $response = Http::withHeaders(['x-api-key' => (string) config('services.textbee.api_key')])
            ->connectTimeout(5)->timeout(20)
            ->post('https://api.textbee.dev/api/v1/gateway/send-sms', array_filter([
                'recipients' => ['+'.ltrim($phoneNumber, '+')],
                'message' => $message,
                'deviceId' => config('services.textbee.device_id'),
            ], fn ($value) => filled($value)));

        // https://textbee.dev/docs/api-reference: accepted means queued for the
        // phone, not delivered. 400 = phone disabled/offline, 401 = bad key,
        // 429 = free-plan daily or monthly limit.
        if ($response->serverError()) {
            throw new \RuntimeException('SMS provider result unconfirmed.');
        }
        if (! $response->successful()) {
            Log::warning('TextBee refused the SMS', ['status' => $response->status(),
                'error' => mb_substr((string) ($response->json('error') ?? $response->json('message') ?? $response->body()), 0, 300)]);

            return false;
        }
        $data = $response->json('data');
        if (! is_array($data)) {
            throw new \RuntimeException('SMS provider returned an unrecognized result.');
        }

        return ($data['success'] ?? false) === true || (int) ($data['successCount'] ?? 0) > 0;
    }
}
