<?php

namespace Chat\Http\Resources;

use Chat\Contracts\UserSettingsServiceInterface;
use Chat\Enums\MessageType;
use Chat\Models\EventRsvp;
use Chat\Models\MessageReaction;
use Chat\Models\MessageReceipt;
use Chat\Models\PollVote;
use Chat\Models\StarredMessage;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MessageResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $viewer = $request->user();

        return [
            'id' => $this->id,
            'conversation_id' => $this->conversation_id,
            'chatable_type' => $this->chatable_type,
            'chatable_id' => $this->chatable_id,
            'type' => $this->type?->value,
            'body' => $this->isDeletedForEveryone() ? null : $this->body,
            'is_forwarded' => $this->is_forwarded,
            'metadata' => $this->isDeletedForEveryone() ? null : $this->metadata,
            'edited_at' => $this->edited_at,
            'deleted_for_everyone' => $this->isDeletedForEveryone(),
            'reply_to' => $this->whenLoaded('replyTo', function () use ($viewer) {
                if ($this->replyTo === null) {
                    return null;
                }

                // Hidden if deleted for everyone OR deleted for me (the current viewer) — the
                // latter wasn't checked at all before, so a message the viewer had deleted for
                // themselves could still leak its body/metadata back to them (or, worse, to
                // anyone else who could still see it) through someone else's reply to it.
                $hidden = $this->replyTo->isDeletedForEveryone()
                    || $this->replyTargetDeletedForViewer($viewer);

                return [
                    'id' => $this->replyTo->id,
                    'chatable_type' => $this->replyTo->chatable_type,
                    'chatable_id' => $this->replyTo->chatable_id,
                    'type' => $this->replyTo->type?->value,
                    'body' => $hidden ? null : str($this->replyTo->body ?? '')->limit(100)->toString(),
                    'metadata' => $hidden ? null : $this->replyTo->metadata,
                    'deleted_for_everyone' => $this->replyTo->isDeletedForEveryone(),
                    'attachments' => $this->replyTo->relationLoaded('attachments') && ! $hidden
                        ? $this->replyTo->attachments->map(fn ($attachment) => [
                            'id' => $attachment->id,
                            'url' => $attachment->url,
                            'download_url' => $attachment->download_url,
                            'thumbnail_url' => $attachment->thumbnail_url,
                            'mime_type' => $attachment->mime_type,
                            'original_filename' => $attachment->original_filename,
                        ])->values()
                        : [],
                ];
            }),
            'attachments' => $this->whenLoaded('attachments', fn () => $this->isDeletedForEveryone() ? [] : $this->attachments->map(fn ($attachment) => [
                'id' => $attachment->id,
                'url' => $attachment->url,
                'download_url' => $attachment->download_url,
                'thumbnail_url' => $attachment->thumbnail_url,
                'mime_type' => $attachment->mime_type,
                'original_filename' => $attachment->original_filename,
                'size_bytes' => $attachment->size_bytes,
                'width' => $attachment->width,
                'height' => $attachment->height,
                'duration_seconds' => $attachment->duration_seconds,
            ])),
            // Per-reactor detail (who reacted with what) lives at GET /messages/{message}/reactions
            // instead, fetched on demand only when "reaction details" is opened — not shipped for
            // every message on every page load. `self` is enough for the inline pill/picker state.
            'reactions' => $this->whenLoaded('reactions', fn () => $this->reactions
                ->groupBy('emoji')
                ->map(fn ($group, $emoji) => [
                    'emoji' => $emoji,
                    'count' => $group->count(),
                    'self' => $viewer !== null && $group->contains(fn (MessageReaction $r) => $this->isChatable($r, $viewer)),
                ])
                ->values()),
            // Per-receipt delivered_at/read_at detail lives at GET /messages/{message}/receipts
            // instead, fetched on demand only when a user opens "message info" for one message —
            // not shipped for every message on every page load. See that endpoint's docblock.
            'status' => $this->resolveReceiptStatus($viewer),
            'is_starred_by_me' => $this->whenLoaded('starredBy', fn () => $viewer !== null
                && $this->starredBy->contains(fn (StarredMessage $s) => $this->isChatable($s, $viewer))),
            'is_pinned' => $this->whenLoaded('pinnedIn', fn () => $this->pinnedIn !== null, false),
            'poll' => $this->whenLoaded('pollVotes', fn () => $this->type === MessageType::Poll ? $this->pollTally($viewer) : null),
            'event' => $this->whenLoaded('eventRsvps', fn () => $this->type === MessageType::Event ? $this->eventTally($viewer) : null),
            'conversation' => $this->whenLoaded('conversation', fn () => [
                'id' => $this->conversation->id,
                'name' => $this->conversation->name,
                'type' => $this->conversation->type?->value,
                // Field names must match ParticipantResource's shape (chatable_type/chatable_id),
                // not a short type/id pair, since every frontend consumer keys off chatableKeyOf()
                // which reads those exact names — a mismatch silently produces an
                // "undefined:undefined" chatable key that then 422s on /users.
                'participants' => $this->conversation->relationLoaded('participants')
                    ? $this->conversation->participants->map(fn ($p) => ['chatable_type' => $p->chatable_type, 'chatable_id' => $p->chatable_id])->values()
                    : [],
            ]),
            'expires_at' => $this->expires_at,
            'created_at' => $this->created_at,
        ];
    }

    protected function isChatable(mixed $row, Model $chatable): bool
    {
        return $row->chatable_type === $chatable->getMorphClass() && $row->chatable_id === $chatable->getKey();
    }

    /**
     * Prefers the viewer-scoped replyTo.deletions eager load the timeline/search queries attach
     * (see MessageRepository) — no extra query there, since it's already batched for the whole
     * page. Falls back to a direct, bounded (one message, one row) query for the single-message
     * responses (store/update/forward) that don't eager-load it.
     */
    protected function replyTargetDeletedForViewer(?Model $viewer): bool
    {
        if ($viewer === null) {
            return false;
        }

        if ($this->replyTo->relationLoaded('deletions')) {
            return $this->replyTo->deletions->isNotEmpty();
        }

        return $this->replyTo->deletions()
            ->where('chatable_type', $viewer->getMorphClass())
            ->where('chatable_id', $viewer->getKey())
            ->exists();
    }

    protected function pollTally(?Model $viewer): array
    {
        $optionCount = count($this->metadata['options'] ?? []);

        $options = collect(range(0, max($optionCount - 1, -1)))->map(function (int $index) use ($viewer) {
            $group = $this->pollVotes->where('option_index', $index);

            return [
                'index' => $index,
                'count' => $group->count(),
                'self' => $viewer !== null && $group->contains(fn (PollVote $v) => $this->isChatable($v, $viewer)),
            ];
        })->values();

        return [
            'options' => $options,
            'total_voters' => $this->pollVotes->unique(fn (PollVote $v) => $v->chatable_type.':'.$v->chatable_id)->count(),
        ];
    }

    protected function eventTally(?Model $viewer): array
    {
        $myStatus = $viewer === null ? null : optional(
            $this->eventRsvps->first(fn (EventRsvp $r) => $this->isChatable($r, $viewer))
        )->status;

        return collect(['going', 'maybe', 'declined'])->mapWithKeys(function (string $status) {
            $group = $this->eventRsvps->where('status', $status);

            return [$status => [
                'count' => $group->count(),
            ]];
        })->put('my_status', $myStatus)->toArray();
    }

    /**
     * Prefers the batched receipt_summary counts (see MessageRepository::receiptSummariesFor())
     * set on the model as a virtual attribute — same pattern as unread_count on conversations —
     * over loading the full receipts relation, which the timeline/search/conversation-list
     * endpoints no longer eager-load for exactly this reason. Falls back to the row-based
     * whenLoaded('receipts') path for the single-message responses (store/update/forward, and
     * this resource's other consumers) that still eager-load the relation directly.
     */
    protected function resolveReceiptStatus(?Model $viewer): mixed
    {
        if (isset($this->receipt_summary)) {
            return $this->receiptStatusFromSummary($this->receipt_summary, $viewer);
        }

        return $this->whenLoaded('receipts', fn () => $this->receiptStatus($viewer));
    }

    /**
     * @param  array{recipient_count: int, delivered_count: int, read_count: int}  $summary
     */
    protected function receiptStatusFromSummary(array $summary, ?Model $viewer): string
    {
        if ($summary['recipient_count'] === 0) {
            return 'sent';
        }

        $settings = app(UserSettingsServiceInterface::class);

        // Reciprocity: if the viewer has turned off their own read-receipt sharing,
        // WhatsApp caps everything they see at "delivered" regardless of actual reads.
        $viewerAllowsReadReceipts = $viewer === null || $settings->allowsReadReceipts($viewer);

        if ($viewerAllowsReadReceipts && $summary['read_count'] === $summary['recipient_count']) {
            return 'read';
        }

        if ($summary['delivered_count'] === $summary['recipient_count']) {
            return 'delivered';
        }

        return 'sent';
    }

    protected function receiptStatus(?Model $viewer): string
    {
        $receipts = $this->receipts;

        if ($receipts->isEmpty()) {
            return 'sent';
        }

        $settings = app(UserSettingsServiceInterface::class);

        // Reciprocity: if the viewer has turned off their own read-receipt sharing,
        // WhatsApp caps everything they see at "delivered" regardless of actual reads.
        $viewerAllowsReadReceipts = $viewer === null || $settings->allowsReadReceipts($viewer);

        $countsAsRead = fn (MessageReceipt $receipt) => $receipt->read_at !== null
            && $receipt->chatable !== null
            && $settings->allowsReadReceipts($receipt->chatable);

        if ($viewerAllowsReadReceipts && $receipts->every($countsAsRead)) {
            return 'read';
        }

        if ($receipts->every(fn (MessageReceipt $receipt) => $receipt->delivered_at !== null)) {
            return 'delivered';
        }

        return 'sent';
    }
}
