<?php

namespace Chat\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Chat\Traits\BroadcastsChatEvents;

class MessagesDelivered implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels, BroadcastsChatEvents;

    public function __construct(
        public int $conversationId,
        public Model $chatable,
    ) {}

    public function broadcastOn(): array
    {
        return [new PresenceChannel("conversation.{$this->conversationId}")];
    }

    public function broadcastAs(): string
    {
        return 'messages.delivered';
    }

    public function broadcastWith(): array
    {
        return [
            'conversation_id' => $this->conversationId,
            'chatable_type' => $this->chatable->getMorphClass(),
            'chatable_id' => $this->chatable->getKey(),
            'delivered_at' => now()->toIso8601String(),
        ];
    }
}
