<?php

namespace App\Services\Sms;

use Illuminate\Support\Facades\Http;

/**
 * Semaphore (semaphore.co) SMS gateway for the Philippines. Requires
 * SEMAPHORE_API_KEY / config('services.semaphore.api_key') to be set.
 */
class SemaphoreSmsGateway implements SmsGatewayInterface
{
    public function send(string $phoneNumber, string $message): bool
    {
        // The sender name is sent only once Semaphore has approved it;
        // without one, texts go out under Semaphore's default name.
        $response = Http::asForm()->connectTimeout(5)->timeout(20)->post('https://api.semaphore.co/api/v4/messages', array_filter([
            'apikey' => config('services.semaphore.api_key'),
            'number' => $phoneNumber,
            'message' => $message,
            'sendername' => config('services.semaphore.sender_name'),
        ], fn ($value) => filled($value)));

        // https://semaphore.co/docs: Sent means accepted by the network, not read/delivered to a handset.
        if ($response->serverError()) {
            throw new \RuntimeException('SMS provider result unconfirmed.');
        }
        if (! $response->successful()) {
            return false;
        }
        $messages = $response->json();
        if (! is_array($messages) || ! array_is_list($messages) || count($messages) !== 1) {
            throw new \RuntimeException('SMS provider returned an unrecognized result.');
        }
        $message = $messages[0];
        if (in_array($message['status'] ?? null, ['Failed', 'Refunded'], true)) {
            return false;
        }
        if (! empty($message['message_id']) && in_array($message['status'] ?? null, ['Queued', 'Pending', 'Sent'], true)) {
            return true;
        }
        throw new \RuntimeException('SMS provider result unconfirmed.');
    }
}
