<?php

namespace App\Services;

use App\Mail\CampusBroadcast;
use App\Models\Broadcast;
use App\Models\BroadcastDelivery;
use App\Models\Personnel;
use App\Services\Sms\SmsDriver;
use App\Services\Sms\SmsGatewayInterface;
use App\Support\PhoneNumber;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

class BroadcastSender
{
    public function hasPending(int $id): bool
    {
        return BroadcastDelivery::where('broadcast_id', $id)->where('status', 'Pending')
            ->whereHas('broadcast', fn ($q) => $q->whereNotNull('published_at'))->exists();
    }

    public function sendNext(int $id): void
    {
        $delivery = DB::transaction(function () use ($id) {
            $broadcast = Broadcast::whereKey($id)->lockForUpdate()->first();
            if (! $broadcast?->published_at) {
                return null;
            }
            $delivery = $broadcast->deliveries()->where('status', 'Pending')->orderBy('delivery_id')->lockForUpdate()->first();
            if ($delivery) {
                $delivery->update(['status' => 'Processing', 'started_at' => now(), 'attempts' => $delivery->attempts + 1]);
            }

            return $delivery;
        });
        if (! $delivery) {
            return;
        }
        $broadcast = $delivery->broadcast;
        $person = Personnel::find($delivery->personnel_id);
        $eligible = $person && $person->is_approved && (int) $person->campus_id === (int) $broadcast->campus_id;
        $eligible = $eligible && match ($delivery->channel) {
            'email' => $person->email_verified_at && $person->email === $delivery->destination,
            'sms' => $person->phone_verified_at && PhoneNumber::normalize($person->contact_number) === $delivery->destination,
            default => false,
        };
        $status = 'Skipped';
        $error = 'Account or verified contact changed after publication.';
        if ($eligible) {
            try {
                if ($delivery->channel === 'email') {
                    $sent = Mail::to($delivery->destination)->send(new CampusBroadcast($broadcast));
                    $simulated = in_array(config('mail.mailers.'.config('mail.default').'.transport'), ['log', 'array'], true);
                    if (in_array(config('mail.mailers.'.config('mail.default').'.transport'), ['failover', 'roundrobin'], true)) {
                        throw new \RuntimeException('The selected fallback mail transport cannot confirm which provider accepted this message.');
                    }
                    $accepted = $sent !== null || $simulated;
                } else {
                    $accepted = app(SmsGatewayInterface::class)->send($delivery->destination, $broadcast->sms_text);
                    $simulated = ! SmsDriver::isReal();
                }
                $status = $accepted ? ($simulated ? 'Simulated' : 'Accepted') : 'Failed';
                $error = $accepted ? null : 'Provider rejected the request.';
            } catch (\Throwable $exception) {
                // A timeout can happen after acceptance. Automatic retries could send duplicates.
                $status = 'Unknown';
                $error = 'Provider result was not confirmed. Check provider history before sending another message.';
            }
        }
        BroadcastDelivery::whereKey($delivery->getKey())->where('status', 'Processing')->update([
            'status' => $status, 'error' => $error, 'sent_at' => in_array($status, ['Accepted', 'Simulated']) ? now() : null,
        ]);
        $this->refreshStatus($id);
    }

    public function refreshStatus(int $id): void
    {
        DB::transaction(function () use ($id) {
            $broadcast = Broadcast::whereKey($id)->lockForUpdate()->first();
            if (! $broadcast?->published_at) {
                return;
            }
            $statuses = $broadcast->deliveries()->pluck('status');
            $broadcast->update(['status' => $statuses->intersect(['Pending', 'Processing'])->isNotEmpty() ? 'Queued'
                : ($statuses->intersect(['Failed', 'Unknown'])->isNotEmpty() ? 'Needs review' : 'Complete')]);
        });
    }
}
