<?php

namespace Chat\Services;

use Chat\Chat;
use Chat\Contracts\MessageReceiptServiceInterface;
use Chat\Contracts\ParticipantRepositoryInterface;
use Chat\Contracts\UserSettingsServiceInterface;
use Chat\Events\MessagesDelivered;
use Chat\Events\MessagesRead;
use Chat\Models\Conversation;
use Chat\Models\Message;
use Chat\Models\MessageReceipt;
use Illuminate\Database\Eloquent\Model;

class MessageReceiptService implements MessageReceiptServiceInterface
{
    public function __construct(
        protected ParticipantRepositoryInterface $participants,
        protected UserSettingsServiceInterface $settings,
    ) {}

    public function markDelivered(Conversation $conversation, Model $chatable): void
    {
        $updated = Chat::whereChatable(MessageReceipt::query(), $chatable)
            ->whereNull('delivered_at')
            ->whereHas('message', fn ($q) => $q->where('conversation_id', $conversation->id))
            ->update(['delivered_at' => now()]);

        if ($updated > 0) {
            broadcast(new MessagesDelivered($conversation->id, $chatable))->toOthers();
        }
    }

    public function markRead(Conversation $conversation, Model $chatable, ?int $upToMessageId = null): void
    {
        $participant = $this->participants->findFor($conversation->id, $chatable);

        abort_if($participant === null, 403);

        $latestId = $upToMessageId ?? Message::query()
            ->where('conversation_id', $conversation->id)
            ->max('id');

        if ($latestId === null) {
            return;
        }

        $alreadyRead = $participant->last_read_message_id !== null
            && $latestId <= $participant->last_read_message_id;

        // A manual "mark as unread" only needs clearing here, not advancing the cursor — the
        // cursor may already cover everything (nothing new arrived since it was set), which is
        // exactly the $alreadyRead case below that would otherwise return before this flag is
        // ever touched.
        if ($participant->manually_unread_at) {
            $participant->update(['manually_unread_at' => null]);
        }

        if ($alreadyRead) {
            return;
        }

        $now = now();

        Chat::whereChatable(MessageReceipt::query(), $chatable)
            ->whereNull('delivered_at')
            ->whereHas('message', fn ($q) => $q->where('conversation_id', $conversation->id)->where('id', '<=', $latestId))
            ->update(['delivered_at' => $now]);

        Chat::whereChatable(MessageReceipt::query(), $chatable)
            ->whereNull('read_at')
            ->whereHas('message', fn ($q) => $q->where('conversation_id', $conversation->id)->where('id', '<=', $latestId))
            ->update(['read_at' => $now]);

        $participant->update([
            'last_read_message_id' => max($latestId, $participant->last_read_message_id ?? 0),
        ]);

        // MessageRepository::applyReadReceiptPrivacy() and MessageResource's own reciprocity
        // logic already gate read_at on chat.privacy.read_receipts_default/allowsReadReceipts()
        // for every REST path — this is the one path that fired regardless. Skipped outright
        // (not redacted after the fact) since a single broadcast frame goes to every active
        // participant on the conversation's presence channel at once, with no per-recipient slot
        // to redact for just the ones who'd otherwise see it via REST anyway.
        if ($this->settings->allowsReadReceipts($chatable)) {
            broadcast(new MessagesRead($conversation->id, $chatable, $latestId))->toOthers();
        }
    }
}
