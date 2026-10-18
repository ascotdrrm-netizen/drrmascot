<?php

namespace App\Services\Sms;

/** Which SMS_GATEWAY values send real texts, shared by the OTP, broadcast and preview checks. */
class SmsDriver
{
    private const KEYS = ['semaphore' => 'services.semaphore.api_key', 'textbee' => 'services.textbee.api_key'];

    /** A real provider is selected (anything else only writes the log). */
    public static function isReal(): bool
    {
        return array_key_exists((string) config('services.sms.driver', 'log'), self::KEYS);
    }

    /** A real provider is selected and its API key is set. */
    public static function isReady(): bool
    {
        return self::isReal() && filled(config(self::KEYS[config('services.sms.driver')]));
    }
}
