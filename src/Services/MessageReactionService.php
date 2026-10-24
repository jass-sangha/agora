<?php

namespace Chat\Services;

use Chat\Contracts\MessageReactionServiceInterface;
use Chat\Events\MessageReacted;
use Chat\Models\Message;
use Chat\Models\MessageReaction;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

class MessageReactionService implements MessageReactionServiceInterface
{
    public function toggle(Message $message, Model $chatable, ?string $emoji): Collection
    {
        $existing = MessageReaction::query()
            ->where('message_id', $message->id)
            ->where('chatable_type', $chatable->getMorphClass())
            ->where('chatable_id', $chatable->getKey())
            ->first();

        if ($emoji === null || $existing?->emoji === $emoji) {
            $existing?->delete();
        } else {
            MessageReaction::query()->updateOrCreate(
                ['message_id' => $message->id, 'chatable_type' => $chatable->getMorphClass(), 'chatable_id' => $chatable->getKey()],
                ['emoji' => $emoji],
            );
        }

        $reactions = $this->grouped($message);

        broadcast(new MessageReacted($message->id, $message->conversation_id, $reactions))->toOthers();

        return $reactions;
    }

    /**
     * Full per-reactor detail for GET /messages/{message}/reactions — MessageResource's own
     * reactions block only ships emoji/count/self (see its docblock), so this is the only path
     * back to who actually reacted, fetched on demand when "reaction details" is opened.
     */
    public function detail(Message $message): Collection
    {
        return $this->grouped($message);
    }

    // No `self` field here on purpose — this same collection is both the HTTP response to the
    // actor *and* the broadcast payload shared by every other participant, and "self" can't be
    // scoped per-recipient in a single shared payload. `chatables` carries the objective voter
    // list instead, and the frontend derives "self" from it locally — the same pattern already
    // used for poll/event tallies, for the identical reason (see PollMessage.vue).
    protected function grouped(Message $message): Collection
    {
        return $message->reactions()->get()
            ->groupBy('emoji')
            ->map(fn ($group, $emoji) => [
                'emoji' => $emoji,
                'count' => $group->count(),
                'chatables' => $group->map(fn (MessageReaction $reaction) => [
                    'type' => $reaction->chatable_type,
                    'id' => $reaction->chatable_id,
                ])->values(),
            ])
            ->values();
    }
}
