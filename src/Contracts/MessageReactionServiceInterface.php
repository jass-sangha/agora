<?php

namespace Chat\Contracts;

use Chat\Models\Message;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

interface MessageReactionServiceInterface
{
    public function toggle(Message $message, Model $chatable, ?string $emoji): Collection;

    public function detail(Message $message): Collection;
}
