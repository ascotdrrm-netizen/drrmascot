<?php

namespace App\Services;

use App\Models\Broadcast;
use App\Models\BroadcastDelivery;
use Illuminate\Support\Facades\Cache;

class ServerlessBroadcastWork
{
    /** One provider call per invocation, with the same DB claim as the worker. */
    public function run(?int $campus = null): bool
    {
        $lock = Cache::lock('serverless-broadcast-work:'.($campus ?? 'all'), 90);
        if (! $lock->get()) {
            return false;
        }
        try {
            $query = Broadcast::whereNotNull('published_at')->where('status', 'Queued')
                ->when($campus !== null, fn ($q) => $q->where('campus_id', $campus));
            // An interrupted invocation may have been accepted by the provider.
            BroadcastDelivery::whereIn('broadcast_id', (clone $query)->select('broadcast_id'))
                ->where('status', 'Processing')->where('started_at', '<', now()->subMinutes(10))
                ->update(['status' => 'Unknown', 'error' => 'Invocation stopped before confirming the provider result. Check provider history.']);
            $sender = app(BroadcastSender::class);
            foreach ($query->orderBy('broadcast_id')->limit(20)->get() as $broadcast) {
                $sender->refreshStatus($broadcast->getKey());
                if ($sender->hasPending($broadcast->getKey())) {
                    $sender->sendNext($broadcast->getKey());

                    return true;
                }
            }

            return false;
        } finally {
            $lock->release();
        }
    }
}
