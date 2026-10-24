<?php

namespace Chat\Services;

use Chat\Chat;
use Chat\Contracts\StarredMessageServiceInterface;
use Chat\Models\Message;
use Chat\Models\StarredMessage;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Pagination\LengthAwarePaginator;

class StarredMessageService implements StarredMessageServiceInterface
{
    public function star(Message $message, Model $chatable): void
    {
        StarredMessage::query()->firstOrCreate([
            'message_id' => $message->id,
            'chatable_type' => $chatable->getMorphClass(),
            'chatable_id' => $chatable->getKey(),
        ]);
    }

    public function unstar(Message $message, Model $chatable): void
    {
        Chat::whereChatable(
            StarredMessage::query()->where('message_id', $message->id),
            $chatable
        )->delete();
    }

    public function listForUser(Model $chatable, int $perPage, ?int $conversationId = null): LengthAwarePaginator
    {
        // A StarredMessage row outlives the message it points at becoming inaccessible — deleted
        // for everyone, deleted for me, expired, or the viewer having since left the conversation
        // — so without this the star list is a second, unfiltered path back to content
        // MessagePolicy::isAccessible() already blocks direct access to everywhere else.
        $query = Chat::whereChatable(StarredMessage::query(), $chatable)
            ->whereHas('message', function ($q) use ($chatable, $conversationId) {
                Chat::excludeExpiredMessages($q)
                    ->whereNull('deleted_for_everyone_at')
                    ->whereDoesntHave('deletions', fn ($q) => Chat::whereChatable($q, $chatable))
                    ->whereHas('conversation.participants', fn ($q) => Chat::whereChatable($q, $chatable)->whereNull('left_at'));

                if ($conversationId !== null) {
                    $q->where('conversation_id', $conversationId);
                }
            })
            ->with('message.attachments', 'message.reactions', 'message.conversation.participants')
            ->latest('id');

        return $query->paginate($perPage);
    }
}
