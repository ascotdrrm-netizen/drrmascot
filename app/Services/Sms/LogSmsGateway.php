<?php

namespace App\Services\Sms;

use Illuminate\Support\Facades\Log;

/**
 * Default local-development gateway — writes the attempted message to the
 * application log instead of calling a real SMS API, and always succeeds.
 */
class LogSmsGateway implements SmsGatewayInterface
{
    public function send(string $phoneNumber, string $message): bool
    {
        Log::info('SMS (log driver): message sent', [
            'phone_number' => $phoneNumber,
            'message' => $message,
        ]);

        return true;
    }
}
