<?php

namespace Chat\Http\Controllers;

use Chat\Contracts\MessageServiceInterface;
use Chat\Http\Requests\ForwardMessageRequest;
use Chat\Http\Requests\StoreMessageRequest;
use Chat\Http\Requests\UpdateMessageRequest;
use Chat\Http\Resources\MessageEditResource;
use Chat\Http\Resources\MessageReceiptResource;
use Chat\Http\Resources\MessageResource;
use Chat\Models\Conversation;
use Chat\Models\Message;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;

class MessageController extends Controller
{
    // receipts.chatable deliberately absent — every MessageResource consumer gets its
    // sent/delivered/read status via the batched receipt_summary attribute instead (see
    // attachReceiptSummaries()), not by eager-loading each message's full receipt rows.
    public const EAGER = ['chatable', 'attachments', 'reactions', 'replyTo.attachments', 'starredBy', 'pinnedIn', 'pollVotes', 'eventRsvps'];

    public function __construct(
        protected MessageServiceInterface $messages,
    ) {}

    public function index(Request $request, Conversation $conversation)
    {
        Gate::authorize('view', $conversation);

        $perPage = (int) config('chat.pagination.messages_per_page', 50);
        $beforeId = $request->integer('before_id') ?: null;

        $messages = $this->messages->listForConversation(
            $conversation,
            $request->user(),
            $perPage,
            $beforeId,
        );

        $this->attachReceiptSummaries($messages->getCollection());

        return MessageResource::collection($messages);
    }

    public function store(StoreMessageRequest $request, Conversation $conversation)
    {
        Gate::authorize('view', $conversation);

        $message = $this->messages->send(
            $conversation,
            $request->user(),
            $request->validated(),
        );

        $message->load(self::EAGER);
        $this->attachReceiptSummaries(collect([$message]));

        return (new MessageResource($message))
            ->response()
            ->setStatusCode(201);
    }

    public function update(UpdateMessageRequest $request, Message $message)
    {
        Gate::authorize('update', $message);

        $updated = $this->messages->update($message, $request->validated()['body']);

        $updated->load(self::EAGER);
        $this->attachReceiptSummaries(collect([$updated]));

        return new MessageResource($updated);
    }

    public function edits(Message $message)
    {
        // Authorized against the message itself (MessagePolicy::view()), not just
        // $message->conversation (ConversationPolicy::view()) — the latter only checks active
        // participation, not whether the caller has deleted this specific message for
        // themselves, which should block direct access to it just as much as it hides it from
        // their timeline.
        Gate::authorize('view', $message);

        return MessageEditResource::collection(
            $message->edits()->orderByDesc('edited_at')->get()
        );
    }

    // Split out from MessageResource's timeline payload on purpose: per-receipt delivered_at/
    // read_at detail is only ever consumed by the "message info" modal for one message a user
    // explicitly opened, but was previously being computed and shipped for every message on
    // every page load — up to participant-cap-many rows per message, times a page of messages.
    public function receipts(Request $request, Message $message)
    {
        // See edits() above for why this authorizes against the message, not the conversation.
        Gate::authorize('view', $message);

        // The aggregate status (MessageResource::receiptStatusFromSummary()) already respects
        // each recipient's read-receipts privacy and the viewer's own reciprocity — this raw
        // per-receipt endpoint bypassed both, returning every recipient's actual read_at
        // regardless of whether they'd disabled read-receipt sharing. Same two rules, applied
        // here instead since this endpoint doesn't go through that resource method.
        $receipts = $this->messages->applyReadReceiptPrivacy($message->receipts, $request->user());

        return MessageReceiptResource::collection($receipts);
    }

    public function destroy(Message $message)
    {
        Gate::authorize('delete', $message);

        $this->messages->deleteForEveryone($message);

        return response()->noContent();
    }

    public function destroyForMe(Request $request, Message $message)
    {
        Gate::authorize('deleteForMe', $message);

        $this->messages->deleteForMe($message, $request->user());

        return response()->noContent();
    }

    public function forward(ForwardMessageRequest $request, Message $message)
    {
        Gate::authorize('forward', $message);

        $forwarded = $this->messages->forward(
            $message,
            array_map('intval', $request->validated()['conversation_ids']),
            $request->user(),
        );

        // newCollection() (not the plain collect() helper, which never returns an Eloquent
        // collection) so a single load() batches all EAGER relations across every forwarded
        // message in one query per relation, instead of firing them per message.
        $forwarded = (new Message)->newCollection($forwarded)->load(self::EAGER);
        $this->attachReceiptSummaries($forwarded);

        return MessageResource::collection($forwarded);
    }

    public function clear(Request $request, Conversation $conversation)
    {
        Gate::authorize('view', $conversation);

        $this->messages->clearForChatable($conversation, $request->user());

        return response()->noContent();
    }

    public function search(Request $request)
    {
        // Same minimum as user search — a too-short term still ends up in
        // Chat::matchingChatablePairs() (capped, but still a wasted 'contains' scan for very
        // little signal) via the conversation-participant-name match clause, not just this
        // endpoint's own body full-text/LIKE query.
        $request->validate(['q' => ['required', 'string', 'min:'.(int) config('chat.user_search.min_length', 2)]]);

        $perPage = (int) config('chat.pagination.messages_per_page', 50);
        $conversationId = $request->integer('conversation_id') ?: null;

        $messages = $this->messages->search(
            $request->user(),
            $request->string('q')->toString(),
            $conversationId,
            $perPage,
        );

        $this->attachReceiptSummaries($messages->getCollection());

        return MessageResource::collection($messages);
    }

    public function media(Request $request)
    {
        $request->validate([
            'kind' => ['required', 'string', 'in:media,docs,links'],
            // Optional here (browsing without a filter is valid), but when present must meet
            // the same minimum as user search for the same reason as search() above.
            'q' => ['sometimes', 'string', 'min:'.(int) config('chat.user_search.min_length', 2)],
        ]);

        $perPage = (int) config('chat.pagination.messages_per_page', 50);
        $conversationId = $request->integer('conversation_id') ?: null;

        $messages = $this->messages->media(
            $request->user(),
            $request->string('kind')->toString(),
            $conversationId,
            $perPage,
            $request->string('q')->toString() ?: null,
        );

        return MessageResource::collection($messages);
    }

    // No response — a page of messages, a single just-sent/edited message, or up to 200
    // forwarded messages alike — eager-loads receipts.chatable (see MessageController::EAGER
    // and MessageRepository::paginateForConversation()/search()): a 50-message page in a
    // 200-participant conversation, or 200 forwarded messages, would otherwise mean loading up
    // to tens of thousands of receipt+chatable rows just to derive a sent/delivered/read status
    // per message. This attaches the batched recipient/delivered/read counts (one aggregate
    // query pair, see receiptSummariesFor()) as a virtual receipt_summary attribute instead —
    // same pattern as ConversationService::listForUser()'s unread_count — which MessageResource
    // reads in place of the receipts relation.
    protected function attachReceiptSummaries(Collection $messages): void
    {
        $summaries = $this->messages->receiptSummariesFor($messages->pluck('id')->all());

        $messages->each(function (Message $message) use ($summaries) {
            $message->receipt_summary = $summaries[$message->id] ?? [
                'recipient_count' => 0,
                'delivered_count' => 0,
                'read_count' => 0,
            ];
        });
    }
}
