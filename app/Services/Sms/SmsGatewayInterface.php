<?php

namespace App\Services\Sms;

interface SmsGatewayInterface
{
    /**
     * Send a single SMS message. Returns true if the message was accepted
     * for delivery by the gateway, false otherwise.
     */
    public function send(string $phoneNumber, string $message): bool;
}
