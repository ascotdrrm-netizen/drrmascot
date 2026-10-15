<?php

namespace App\Services;

use App\Models\Broadcast;
use App\Models\Personnel;
use App\Support\PhoneNumber;
use App\Support\SmsSegments;

class BroadcastAudience
{
    public function snapshot(Broadcast $broadcast, bool $lock = false): array
    {
        $query = Personnel::where('campus_id', $broadcast->campus_id)->where('is_approved', true)->orderBy('personnel_id');
        if ($lock) {
            $query->lockForUpdate();
        }
        $recipients = [];
        $seen = [];
        foreach ($query->get() as $person) {
            $channels = ['in_app' => null];
            if ($broadcast->send_email && $person->email_verified_at && filter_var($person->email, FILTER_VALIDATE_EMAIL)) {
                $channels['email'] = $person->email;
            }
            if ($broadcast->send_sms && $person->phone_verified_at && ($phone = PhoneNumber::normalize($person->contact_number))) {
                $channels['sms'] = $phone;
            }
            foreach ($channels as $channel => $destination) {
                $key = $channel.':'.($destination ?? $person->getKey());
                if (isset($seen[$key])) {
                    continue;
                }
                $seen[$key] = true;
                $recipients[] = ['personnel_id' => $person->getKey(), 'channel' => $channel, 'destination' => $destination];
            }
        }
        $counts = array_replace(['in_app' => 0, 'email' => 0, 'sms' => 0], array_count_values(array_column($recipients, 'channel')));
        $segments = SmsSegments::count($broadcast->sms_text ?? '');
        $rate = config('broadcasts.sms_price_per_segment');

        return ['recipients' => $recipients, 'counts' => $counts, 'segments' => $segments,
            'sms_units' => $counts['sms'] * $segments,
            'estimated_cost' => is_numeric($rate) && $rate >= 0 ? round($counts['sms'] * $segments * $rate, 2) : null,
            'fingerprint' => hash('sha256', json_encode([$broadcast->campus_id, $broadcast->title, $broadcast->body,
                $broadcast->sms_text, $broadcast->send_email, $broadcast->send_sms, $recipients, $rate]))];
    }
}
