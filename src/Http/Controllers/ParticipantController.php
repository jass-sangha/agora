<?php

namespace Chat\Http\Controllers;

use Chat\Chat;
use Chat\Contracts\ParticipantRepositoryInterface;
use Chat\Contracts\ParticipantServiceInterface;
use Chat\Http\Requests\AddParticipantsRequest;
use Chat\Http\Requests\ChangeParticipantRoleRequest;
use Chat\Http\Resources\MessageResource;
use Chat\Http\Resources\ParticipantResource;
use Chat\Models\Conversation;
use Chat\Models\Message;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class ParticipantController extends Controller
{
    public function __construct(
        protected ParticipantServiceInterface $participantService,
        protected ParticipantRepositoryInterface $participants,
    ) {}

    public function index(Conversation $conversation)
    {
        Gate::authorize('view', $conversation);

        return ParticipantResource::collection($this->participants->activeForConversation($conversation->id));
    }

    public function store(AddParticipantsRequest $request, Conversation $conversation)
    {
        Gate::authorize('manageParticipants', $conversation);

        $message = $this->participantService->addParticipants(
            $conversation,
            Chat::resolveMany($request->validated()['participants']),
            $request->user(),
        );

        return ParticipantResource::collection($this->participants->activeForConversation($conversation->id))
            ->additional(['message' => new MessageResource($this->systemMessage($message))]);
    }

    public function destroy(Conversation $conversation, string $chatableType, int $chatableId, Request $request)
    {
        Gate::authorize('manageParticipants', $conversation);

        $target = Chat::resolveChatable($chatableType, $chatableId);

        $message = $this->participantService->removeParticipant($conversation, $target, $request->user());

        return response()->json(['message' => new MessageResource($this->systemMessage($message))]);
    }

    public function updateRole(ChangeParticipantRoleRequest $request, Conversation $conversation, string $chatableType, int $chatableId)
    {
        Gate::authorize('manageParticipants', $conversation);

        $target = Chat::resolveChatable($chatableType, $chatableId);

        $message = $this->participantService->changeRole($conversation, $target, $request->validated()['role']);

        return response()->json(['message' => new MessageResource($this->systemMessage($message))]);
    }

    // SendsSystemMessages never creates receipts for these (participant_added/_removed/
    // _role_changed) messages, so their status is always 'sent' — set directly rather than
    // querying receiptSummariesFor() for an answer already known without touching the database.
    protected function systemMessage(Message $message): Message
    {
        $message->load(MessageController::EAGER);
        $message->receipt_summary = ['recipient_count' => 0, 'delivered_count' => 0, 'read_count' => 0];

        return $message;
    }

    public function leave(Request $request, Conversation $conversation)
    {
        Gate::authorize('view', $conversation);

        $this->participantService->leaveGroup($conversation, $request->user());

        return response()->noContent();
    }
}
