<?php

namespace Chat\Contracts;

use Chat\Models\ChatList;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Pagination\LengthAwarePaginator;

interface ChatListServiceInterface
{
    /**
     * @param  int[]  $conversationIds
     */
    public function create(Model $chatable, string $name, array $conversationIds): ChatList;

    public function listForUser(Model $chatable, int $perPage): LengthAwarePaginator;

    public function delete(ChatList $list, Model $chatable): void;

    public function addConversation(ChatList $list, Model $chatable, int $conversationId): void;

    public function removeConversation(ChatList $list, Model $chatable, int $conversationId): void;
}
