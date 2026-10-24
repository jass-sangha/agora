<?php

namespace Chat\Contracts;

use Chat\Models\Conversation;
use Chat\Models\Message;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

interface PinnedMessageServiceInterface
{
    public function pin(Message $message, Model $chatable): void;

    public function unpin(Message $message): void;

    public function listForConversation(Conversation $conversation, Model $viewer): Collection;
}
