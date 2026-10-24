<?php

namespace Chat\Events;

use Chat\Models\Message;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Chat\Traits\BroadcastsChatEvents;
use Illuminate\Support\Collection;

class MessageSent implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels, BroadcastsChatEvents;

    /**
     * @param  Collection<int, Model>  $recipients  Chatables also notified on their personal
     *                                              "chatable.{type}.{id}" channel, joined once at
     *                                              app boot — unlike the per-conversation channel
     *                                              below, which only an active viewer has joined.
     *                                              Without this, the sidebar (last message preview,
     *                                              ordering, unread badge) never updates live for a
     *                                              conversation that isn't the one currently open.
     */
    public function __construct(
        public Message $message,
        public Collection $recipients = new Collection,
    ) {
        // replyTo itself still needed (id/type/deleted_for_everyone below), but not its
        // attachments — serializeReplyTo() no longer includes them, see its own docblock.
        $this->message->loadMissing(['attachments', 'replyTo']);
    }

    public function broadcastOn(): array
    {
        return [
            new PresenceChannel("conversation.{$this->message->conversation_id}"),
            ...$this->recipients->map(
                fn (Model $chatable) => new PrivateChannel("chatable.{$chatable->getMorphClass()}.{$chatable->getKey()}")
            ),
        ];
    }

    public function broadcastAs(): string
    {
        return 'message.sent';
    }

    public function broadcastWith(): array
    {
        return [
            'id' => $this->message->id,
            'conversation_id' => $this->message->conversation_id,
            'chatable_type' => $this->message->chatable_type,
            'chatable_id' => $this->message->chatable_id,
            'type' => $this->message->type?->value,
            'body' => $this->message->body,
            'metadata' => $this->message->metadata,
            'reply_to_message_id' => $this->message->reply_to_message_id,
            'reply_to' => $this->message->replyTo ? $this->serializeReplyTo($this->message->replyTo) : null,
            'is_forwarded' => $this->message->is_forwarded,
            'attachments' => $this->message->attachments->map(fn ($attachment) => $this->serializeAttachment($attachment))->values(),
            'created_at' => $this->message->created_at?->toIso8601String(),
        ];
    }

    // Deliberately thinner than MessageResource's reply_to block: that REST shape omits
    // body/metadata/attachments when replyTargetDeletedForViewer() finds the viewer deleted the
    // reply target for themselves — a per-viewer check. A broadcast payload has no equivalent
    // slot: broadcastWith() is serialized once and pushed as-is to every channel in
    // broadcastOn() (the whole conversation's presence channel plus each recipient's personal
    // one), so there's no way to hand a different body to whichever recipients happen to have
    // deleted this particular reply target for themselves. Shipping the content unconditionally
    // here used to leak it straight back to exactly the viewer who'd asked to have it hidden,
    // the moment anyone replied to it — the REST endpoint got this right, the realtime push
    // didn't. Safer to never carry the content over the wire at all: only viewer-independent
    // fields here, real content only ever comes from the REST endpoint, which already redacts
    // correctly. ReplyPreview.vue falls back to a type label when body/attachments are absent.
    protected function serializeReplyTo(Message $replyTo): array
    {
        return [
            'id' => $replyTo->id,
            'chatable_type' => $replyTo->chatable_type,
            'chatable_id' => $replyTo->chatable_id,
            'type' => $replyTo->type?->value,
            'deleted_for_everyone' => $replyTo->isDeletedForEveryone(),
        ];
    }

    protected function serializeAttachment(mixed $attachment): array
    {
        return [
            'id' => $attachment->id,
            'url' => $attachment->url,
            'thumbnail_url' => $attachment->thumbnail_url,
            'mime_type' => $attachment->mime_type,
            'original_filename' => $attachment->original_filename,
            'size_bytes' => $attachment->size_bytes,
            'width' => $attachment->width,
            'height' => $attachment->height,
            'duration_seconds' => $attachment->duration_seconds,
        ];
    }
}
