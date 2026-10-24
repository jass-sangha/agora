<?php

namespace Chat\Http\Controllers;

use Chat\Contracts\PinnedMessageServiceInterface;
use Chat\Events\MessagePinned;
use Chat\Events\MessageUnpinned;
use Chat\Http\Resources\MessageResource;
use Chat\Models\Conversation;
use Chat\Models\Message;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class PinnedMessageController extends Controller
{
    public function __construct(
        protected PinnedMessageServiceInterface $pinnedMessages,
    ) {}

    public function index(Request $request, Conversation $conversation)
    {
        Gate::authorize('view', $conversation);

        $pinned = $this->pinnedMessages->listForConversation($conversation, $request->user());

        return MessageResource::collection($pinned->map(fn ($pinnedMessage) => $pinnedMessage->message));
    }

    public function store(Request $request, Message $message)
    {
        Gate::authorize('pin', $message);

        $this->pinnedMessages->pin($message, $request->user());

        broadcast(new MessagePinned($message))->toOthers();

        return response()->noContent();
    }

    public function destroy(Message $message)
    {
        Gate::authorize('pin', $message);

        $this->pinnedMessages->unpin($message);

        broadcast(new MessageUnpinned($message->id, $message->conversation_id))->toOthers();

        return response()->noContent();
    }
}
