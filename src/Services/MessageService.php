<?php

namespace Chat\Services;

use Chat\Chat;
use Chat\Contracts\AttachmentServiceInterface;
use Chat\Contracts\BlockedUserServiceInterface;
use Chat\Contracts\ConversationRepositoryInterface;
use Chat\Contracts\MessageRepositoryInterface;
use Chat\Contracts\MessageServiceInterface;
use Chat\Contracts\ParticipantRepositoryInterface;
use Chat\Enums\MessageType;
use Chat\Events\MessageDeleted;
use Chat\Events\MessageSent;
use Chat\Events\MessageUpdated;
use Chat\Models\Conversation;
use Chat\Models\Message;
use Chat\Models\MessageAttachment;
use Chat\Models\MessageDeletion;
use Chat\Models\MessageEdit;
use Chat\Models\MessageReceipt;
use Chat\Notifications\NewChatMessageNotification;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

class MessageService implements MessageServiceInterface
{
    public function __construct(
        protected MessageRepositoryInterface $messages,
        protected ParticipantRepositoryInterface $participants,
        protected ConversationRepositoryInterface $conversations,
        protected BlockedUserServiceInterface $blockedUsers,
        protected AttachmentServiceInterface $attachments,
    ) {}

    public function send(Conversation $conversation, Model $chatable, array $data): Message
    {
        // Computed once and threaded through the blocked-user guard and the receipts/broadcast
        // step below, rather than each querying activeChatables() itself — a private send used
        // to run that participant query twice.
        $others = $this->otherActiveChatables($conversation, $chatable);

        $this->guardAgainstBlockedPrivateSend($conversation, $chatable, $others);

        $replyToId = $this->validatedReplyToId($conversation, $data['reply_to_message_id'] ?? null);

        return DB::transaction(function () use ($conversation, $chatable, $data, $replyToId, $others) {
            $message = $this->messages->create([
                'conversation_id' => $conversation->id,
                'chatable_type' => $chatable->getMorphClass(),
                'chatable_id' => $chatable->getKey(),
                'type' => $data['type'] ?? MessageType::Text->value,
                'body' => $data['body'] ?? null,
                'has_link' => Message::hasLinkInBody($data['body'] ?? null),
                'reply_to_message_id' => $replyToId,
                'metadata' => $data['metadata'] ?? null,
                'expires_at' => $this->resolveExpiry($conversation),
            ]);

            if (! empty($data['attachment_ids'])) {
                $this->attachments->attachToMessage($data['attachment_ids'], $message, $chatable);
            }

            $this->createReceiptsForOthers($message, $others);

            $conversation->forceFill(['last_activity_at' => now()])->save();

            // Deferred to actually run after commit, not just moved after the DB work in this
            // closure — MessageSent implements ShouldBroadcast and NewChatMessageNotification
            // implements ShouldQueue, and Laravel's queue connections default to
            // after_commit=false, so dispatching them from inside a still-open transaction risks
            // a worker picking the job up and querying this message before it's visible to any
            // other connection (or processing a notification for a send that then rolls back).
            // DB::afterCommit() also correctly waits for the *outermost* transaction when send()
            // is itself called from within another one (forward()'s per-destination transaction),
            // not just this method's own — a bare "move it after the transaction() call" wouldn't.
            DB::afterCommit(function () use ($message, $others) {
                broadcast(new MessageSent($message, $others))->toOthers();

                $this->notifyOthers($message, $others);
            });

            return $message;
        });
    }

    public function listForConversation(
        Conversation $conversation,
        Model $chatable,
        int $perPage,
        ?int $beforeId = null
    ): Paginator {
        return $this->messages->paginateForConversation($conversation, $chatable, $perPage, $beforeId);
    }

    public function find(int $id): Message
    {
        return $this->messages->findById($id);
    }

    public function search(Model $chatable, string $query, ?int $conversationId, int $perPage): Paginator
    {
        return $this->messages->search($chatable, $query, $conversationId, $perPage);
    }

    public function update(Message $message, string $body): Message
    {
        // The edit window bounds how long a message stays editable, not how many times within
        // that window — without this, repeated edits inside the window could accumulate an
        // unbounded number of chat_message_edits rows for one message.
        $maxEdits = config('chat.message.max_edits');

        if ($maxEdits !== null) {
            abort_if(
                $message->edits()->count() >= $maxEdits,
                422,
                'This message has reached the maximum number of edits.'
            );
        }

        // Snapshot what it said *before* this edit overwrites it — a plain "edited_at" timestamp
        // alone has nothing to show if someone wants to see the earlier version.
        MessageEdit::query()->create([
            'message_id' => $message->id,
            'previous_body' => $message->body,
            'edited_at' => now(),
        ]);

        $message->update([
            'body' => $body,
            'has_link' => Message::hasLinkInBody($body),
            'edited_at' => now(),
        ]);

        broadcast(new MessageUpdated($message))->toOthers();

        return $message;
    }

    public function deleteForEveryone(Message $message): void
    {
        DB::transaction(function () use ($message) {
            // Locked, not the plain $message->attachments relation: forward()'s copyAttachments()
            // can concurrently create a new attachment row pointing at this same disk+path for a
            // different message, while deleteOrphanedFiles() below is deciding whether that path
            // is still referenced. Without a lock, both operations can read a stale "not
            // referenced yet"/"source still exists" view and race — the delete concluding the
            // file is orphaned and removing it right as the forward's new row (pointing at that
            // same now-gone file) commits. Locking these rows forces whichever transaction
            // reaches them first to fully finish (delete rows + decide orphaned; or read + copy)
            // before the other proceeds, so each side always sees the other's committed result
            // rather than an interleaved one.
            $attachments = MessageAttachment::query()
                ->where('message_id', $message->id)
                ->lockForUpdate()
                ->get();

            $message->attachments()->delete();
            $this->attachments->deleteOrphanedFiles($attachments);

            $message->update([
                'deleted_for_everyone_at' => now(),
                'body' => null,
                'metadata' => null,
            ]);

            DB::afterCommit(fn () => broadcast(new MessageDeleted($message->id, $message->conversation_id))->toOthers());
        });
    }

    public function deleteForMe(Message $message, Model $chatable): void
    {
        MessageDeletion::query()->updateOrCreate(
            ['message_id' => $message->id, 'chatable_type' => $chatable->getMorphClass(), 'chatable_id' => $chatable->getKey()],
            ['deleted_at' => now()],
        );
    }

    public function clearForChatable(Conversation $conversation, Model $chatable): void
    {
        $this->messages->clearForChatable($conversation, $chatable);
    }

    public function media(Model $chatable, string $kind, ?int $conversationId, int $perPage, ?string $search = null): Paginator
    {
        return $this->messages->media($chatable, $kind, $conversationId, $perPage, $search);
    }

    public function receiptSummariesFor(array $messageIds): array
    {
        return $this->messages->receiptSummariesFor($messageIds);
    }

    public function applyReadReceiptPrivacy(Collection $receipts, ?Model $viewer): Collection
    {
        return $this->messages->applyReadReceiptPrivacy($receipts, $viewer);
    }

    public function forward(Message $message, array $conversationIds, Model $chatable): array
    {
        if ($message->isDeletedForEveryone()) {
            abort(422, 'Cannot forward a deleted message.');
        }

        // One transaction per destination, not one wrapping the whole loop: forwarding to up to
        // 200 conversations (ForwardMessageRequest's cap) used to mean up to 200 sequential
        // message creates + receipt inserts + broadcasts + notification dispatches all inside a
        // single still-open transaction — held locks and an ever-growing undo/redo log for the
        // whole request, on a request whose failure mode (one destination the forwarder lost
        // access to mid-request) shouldn't roll back every *other* destination's already-good
        // send anyway. Each destination now commits independently as soon as its own send() +
        // is_forwarded flag + attachment copy are done.
        $forwarded = [];

        // Batched instead of one findById() + one isActiveParticipant() per destination: with
        // ForwardMessageRequest's cap of 200 conversation_ids, the per-id version meant up to
        // 400 sequential queries before a single message got created.
        $conversationIds = array_values(array_unique($conversationIds));
        $conversationsById = $this->conversations->findByIds($conversationIds);
        $activeConversationIds = array_flip($this->participants->activeParticipantConversationIds($conversationIds, $chatable));

        foreach ($conversationIds as $conversationId) {
            abort_unless(isset($conversationsById[$conversationId]), 404);
            abort_unless(isset($activeConversationIds[$conversationId]), 403);

            $conversation = $conversationsById[$conversationId];

            $forwarded[] = DB::transaction(function () use ($message, $conversation, $chatable) {
                $forwardedMessage = $this->send($conversation, $chatable, [
                    'type' => $message->type->value,
                    'body' => $message->body,
                    'metadata' => $message->metadata,
                ]);

                $forwardedMessage->update([
                    'is_forwarded' => true,
                    'forwarded_from_message_id' => $message->id,
                ]);

                $this->copyAttachments($message, $forwardedMessage, $chatable);

                return $forwardedMessage;
            });
        }

        return $forwarded;
    }

    /**
     * send() only links attachments the forwarder has just uploaded (attachment_ids), so a
     * forwarded media message would otherwise carry over its type/body/metadata but none of
     * the original attachment rows — the attachments still belong to the source message and
     * can't be re-linked to a second one. Clone the rows instead, pointing at the same
     * underlying file on disk, owned by the forwarder.
     */
    protected function copyAttachments(Message $source, Message $target, Model $chatable): void
    {
        // Locked, freshly queried — not the $source->attachments relation, which forward()'s own
        // loop can leave stale-cached across multiple destinations in one request anyway. This
        // is the other half of the deleteForEveryone() lock: if a concurrent delete-for-everyone
        // on $source reaches these rows first, this blocks until that transaction finishes, then
        // sees the rows already gone and correctly copies nothing, rather than reading a
        // still-there row that's about to be deleted (and its file removed) out from under this
        // copy.
        $attachments = MessageAttachment::query()
            ->where('message_id', $source->id)
            ->lockForUpdate()
            ->get();

        foreach ($attachments as $attachment) {
            MessageAttachment::query()->create([
                'message_id' => $target->id,
                'uploader_type' => $chatable->getMorphClass(),
                'uploader_id' => $chatable->getKey(),
                'disk' => $attachment->disk,
                'path' => $attachment->path,
                'original_filename' => $attachment->original_filename,
                'mime_type' => $attachment->mime_type,
                'size_bytes' => $attachment->size_bytes,
                'width' => $attachment->width,
                'height' => $attachment->height,
                'duration_seconds' => $attachment->duration_seconds,
                'thumbnail_path' => $attachment->thumbnail_path,
            ]);
        }
    }

    /**
     * @return Collection<int, Model>
     */
    protected function otherActiveChatables(Conversation $conversation, Model $sender): Collection
    {
        $senderIdentity = Chat::identify($sender);

        return $this->participants->activeChatables($conversation->id)
            ->reject(fn (Model $chatable) => Chat::identify($chatable) === $senderIdentity)
            ->values();
    }

    /**
     * @param  Collection<int, Model>  $others
     */
    protected function createReceiptsForOthers(Message $message, Collection $others): void
    {
        if ($others->isEmpty()) {
            return;
        }

        MessageReceipt::query()->insert($others->map(fn (Model $chatable) => [
            'message_id' => $message->id,
            'chatable_type' => $chatable->getMorphClass(),
            'chatable_id' => $chatable->getKey(),
            'delivered_at' => null,
            'read_at' => null,
        ])->all());
    }

    /**
     * @param  Collection<int, Model>  $others
     */
    protected function notifyOthers(Message $message, Collection $others): void
    {
        if ($others->isEmpty()) {
            return;
        }

        Notification::send($others, new NewChatMessageNotification($message));
    }

    /**
     * @param  Collection<int, Model>  $others
     */
    protected function guardAgainstBlockedPrivateSend(Conversation $conversation, Model $chatable, Collection $others): void
    {
        if (! $conversation->isPrivate()) {
            return;
        }

        $other = $others->first();

        if ($other === null) {
            return;
        }

        abort_if(
            $this->blockedUsers->isBlockedEitherWay($chatable, $other),
            403,
            'You cannot message this user.'
        );
    }

    protected function resolveExpiry(Conversation $conversation): ?string
    {
        if (! config('chat.disappearing_messages.enabled') || ! $conversation->disappearing_messages_ttl) {
            return null;
        }

        return now()->addSeconds($conversation->disappearing_messages_ttl);
    }

    // StoreMessageRequest only validates reply_to_message_id as an integer — nothing ties it to
    // the conversation being posted into. Without this, MessageResource's replyTo block (body
    // excerpt, metadata, attachment URLs/filenames) would render whatever message that id
    // happens to belong to, including one in a conversation the sender was never a participant
    // of — a cross-conversation content leak, not just a broken reference.
    protected function validatedReplyToId(Conversation $conversation, ?int $replyToId): ?int
    {
        if ($replyToId === null) {
            return null;
        }

        abort_unless(
            Message::query()->where('id', $replyToId)->where('conversation_id', $conversation->id)->exists(),
            422,
            'The message being replied to does not belong to this conversation.'
        );

        return $replyToId;
    }
}
