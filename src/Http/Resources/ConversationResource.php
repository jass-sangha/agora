<?php

namespace Chat\Http\Resources;

use Chat\Chat;
use Chat\Contracts\MessageRepositoryInterface;
use Chat\Models\ConversationParticipant;
use Chat\Models\Message;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;

class ConversationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $viewer = $request->user();
        $me = $viewer ? $this->participants->first(
            fn (ConversationParticipant $p) => $p->chatable_type === $viewer->getMorphClass() && $p->chatable_id === $viewer->getKey()
        ) : null;

        return [
            'id' => $this->id,
            'type' => $this->type?->value,
            'name' => $this->name,
            'description' => $this->description,
            'avatar_url' => $this->avatar_path ? Storage::disk(config('chat.media.disk'))->url($this->avatar_path) : null,
            'disappearing_messages_ttl' => $this->disappearing_messages_ttl,
            'last_activity_at' => $this->last_activity_at,
            'last_message' => $this->relationLoaded('lastMessage')
                ? ($this->lastMessage ? new MessageResource($this->lastMessage) : null)
                : $this->resolveLastMessage($viewer),
            'participants' => ParticipantResource::collection($this->whenLoaded('participants')),
            // withCount() (conversation list) sets participants_count independent of how many
            // participant rows were actually eager-loaded; everywhere else loads the full roster,
            // so counting it directly is accurate there too.
            'participant_count' => $this->participants_count ?? $this->participants->count(),
            'unread_count' => $this->getAttribute('unread_count') ?? ($me ? $this->unreadCountFor($me) : 0),
            'me' => $me ? [
                'role' => $me->role?->value,
                'muted_until' => $me->muted_until,
                'archived_at' => $me->archived_at,
                'pinned_at' => $me->pinned_at,
                'favourited_at' => $me->favourited_at,
                'hidden_at' => $me->hidden_at,
                'wallpaper' => $me->wallpaper,
            ] : null,
            'created_at' => $this->created_at,
        ];
    }

    /**
     * The eager-loaded `lastMessage` relation is global and ignores per-viewer "delete for me" /
     * "clear chat" state, so a viewer who cleared their chat would still see the old preview.
     * This resolves it fresh per viewer instead, excluding what they've deleted, so a cleared
     * chat shows empty for them without affecting the other participant's view.
     */
    protected function resolveLastMessage(?Model $viewer): ?MessageResource
    {
        $query = Chat::excludeExpiredMessages(
            Message::query()->where('conversation_id', $this->id)
        )->orderByDesc('id');

        if ($viewer) {
            $query->whereDoesntHave('deletions', fn ($q) => Chat::whereChatable($q, $viewer));
        }

        $message = $query->first();

        if ($message === null) {
            return null;
        }

        // Same aggregate approach as the conversation list's lastMessagesFor() — a single-message
        // fetch here still bypassed it, loading up to participant-cap-many receipt+chatable rows
        // just for this one message's status.
        $summary = app(MessageRepositoryInterface::class)->receiptSummariesFor([$message->id])[$message->id]
            ?? ['recipient_count' => 0, 'delivered_count' => 0, 'read_count' => 0];

        $message->receipt_summary = $summary;

        return new MessageResource($message);
    }

    protected function unreadCountFor(ConversationParticipant $participant): int
    {
        // Matches ConversationRepository::unreadCountsFor() — the batched version the
        // conversation list already uses — so this fallback (a single-conversation response,
        // used outside the list) computes the same number that path would, not a stricter or
        // looser one.
        $query = Chat::excludeExpiredMessages(
            Message::query()->where('conversation_id', $this->id)
        )
            ->whereNull('deleted_for_everyone_at')
            ->whereDoesntHave('deletions', fn ($q) => $q
                ->where('chatable_type', $participant->chatable_type)
                ->where('chatable_id', $participant->chatable_id));

        if ($participant->last_read_message_id) {
            $query->where('id', '>', $participant->last_read_message_id);
        }

        $count = $query
            ->where(fn ($q) => $q
                ->where('chatable_type', '!=', $participant->chatable_type)
                ->orWhere('chatable_id', '!=', $participant->chatable_id))
            ->count();

        // A manual "mark as unread" doesn't move last_read_message_id, since that field also
        // drives the read receipts shown to the sender — it just floors the count at 1 so the
        // conversation still shows unread even when the real cursor-based count is 0.
        if ($participant->manually_unread_at && $count === 0) {
            return 1;
        }

        return $count;
    }
}
