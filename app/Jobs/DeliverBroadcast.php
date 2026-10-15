<?php

namespace App\Jobs;

use App\Services\BroadcastSender;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class DeliverBroadcast implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 90;

    public function __construct(public int $broadcastId) {}

    public function handle(BroadcastSender $sender): void
    {
        // Only one external delivery per job keeps the worker timeout bounded.
        $sender->sendNext($this->broadcastId);
        if ($sender->hasPending($this->broadcastId)) {
            self::dispatch($this->broadcastId);
        }
    }
}
