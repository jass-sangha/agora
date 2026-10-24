<?php

namespace Chat\Traits;

trait BroadcastsChatEvents
{
    /**
     * Default to synchronous delivery for local/demo apps, with an explicit queue mode
     * available for production workloads that want Laravel's queue worker to fan out
     * the broadcast job asynchronously.
     */
    public function shouldBroadcastNow(): bool
    {
        return config('chat.broadcasting.mode', 'sync') === 'sync';
    }

    /**
     * Only queue the broadcast when the host explicitly opts into queued delivery.
     */
    public function broadcastQueue(): ?string
    {
        if (config('chat.broadcasting.mode', 'sync') !== 'queue') {
            return null;
        }

        return config('chat.broadcasting.queue');
    }
}
