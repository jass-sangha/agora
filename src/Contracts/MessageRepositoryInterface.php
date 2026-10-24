<?php

namespace Chat\Contracts;

use Chat\Models\Conversation;
use Chat\Models\Message;
use Chat\Models\MessageReceipt;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Collection;

interface MessageRepositoryInterface
{
    public function create(array $data): Message;

    public function findById(int $id): Message;

    public function paginateForConversation(
        Conversation $conversation,
        Model $chatable,
        int $perPage,
        ?int $beforeId = null
    ): Paginator;

    public function search(Model $chatable, string $query, ?int $conversationId, int $perPage): Paginator;

    public function clearForChatable(Conversation $conversation, Model $chatable): void;

    /**
     * @param  string  $kind  'media' (images/videos/gifs), 'docs', or 'links'
     * @param  string|null  $search  Matches attachment filename, conversation name, or participant name.
     */
    public function media(Model $chatable, string $kind, ?int $conversationId, int $perPage, ?string $search = null): Paginator;

    /**
     * Per-message recipient/delivered/read counts, batched for a whole page of messages at
     * once — see MessageRepository's implementation for why this replaces loading full receipt
     * rows (with their chatable models) just to derive a sent/delivered/read status.
     *
     * @param  list<int>  $messageIds
     * @return array<int, array{recipient_count: int, delivered_count: int, read_count: int}>
     */
    public function receiptSummariesFor(array $messageIds): array;

    /**
     * Nulls out read_at on any receipt the viewer shouldn't see as "read", per the recipient's
     * own read-receipts privacy setting and the viewer's own reciprocity — see
     * MessageRepository's implementation.
     *
     * @param  Collection<int, MessageReceipt>  $receipts
     * @return Collection<int, MessageReceipt>
     */
    public function applyReadReceiptPrivacy(Collection $receipts, ?Model $viewer): Collection;
}
