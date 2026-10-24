<?php

namespace Chat\Services;

use Chat\Chat;
use Chat\Contracts\PinnedMessageServiceInterface;
use Chat\Models\Conversation;
use Chat\Models\Message;
use Chat\Models\PinnedMessage;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class PinnedMessageService implements PinnedMessageServiceInterface
{
    public const MAX_PINNED_PER_CONVERSATION = 3;

    public function pin(Message $message, Model $chatable): void
    {
        $existing = PinnedMessage::query()
            ->where('conversation_id', $message->conversation_id)
            ->where('message_id', $message->id)
            ->exists();

        if ($existing) {
            return;
        }

        DB::transaction(function () use ($message, $chatable) {
            $count = PinnedMessage::query()
                ->where('conversation_id', $message->conversation_id)
                ->lockForUpdate()
                ->count();

            abort_if(
                $count >= self::MAX_PINNED_PER_CONVERSATION,
                422,
                'Only '.self::MAX_PINNED_PER_CONVERSATION.' messages can be pinned in a chat.'
            );

            PinnedMessage::query()->create([
                'conversation_id' => $message->conversation_id,
                'message_id' => $message->id,
                'pinner_type' => $chatable->getMorphClass(),
                'pinner_id' => $chatable->getKey(),
            ]);
        });
    }

    public function unpin(Message $message): void
    {
        PinnedMessage::query()
            ->where('conversation_id', $message->conversation_id)
            ->where('message_id', $message->id)
            ->delete();
    }

    public function listForConversation(Conversation $conversation, Model $viewer): Collection
    {
        // A PinnedMessage row outlives the message it points at becoming inaccessible to this
        // particular viewer — deleted for everyone, deleted for me, or expired — so without this
        // the pinned list is a second, unfiltered path back to content MessagePolicy::
        // isAccessible() already blocks direct access to everywhere else. No active-participant
        // check needed here (unlike StarredMessageService, whose list spans every conversation
        // the viewer has ever starred in, including ones they've since left) — the controller's
        // Gate::authorize('view', $conversation) already requires it for this whole endpoint.
        return PinnedMessage::query()
            ->where('conversation_id', $conversation->id)
            ->whereHas('message', function ($q) use ($viewer) {
                Chat::excludeExpiredMessages($q)
                    ->whereNull('deleted_for_everyone_at')
                    ->whereDoesntHave('deletions', fn ($q) => Chat::whereChatable($q, $viewer));
            })
            ->with('message.attachments', 'message.reactions')
            ->oldest('id')
            ->get();
    }
}
