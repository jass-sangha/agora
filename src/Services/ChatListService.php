<?php

namespace Chat\Services;

use Chat\Chat;
use Chat\Contracts\ChatListServiceInterface;
use Chat\Contracts\ParticipantRepositoryInterface;
use Chat\Models\ChatList;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class ChatListService implements ChatListServiceInterface
{
    public function __construct(
        protected ParticipantRepositoryInterface $participants,
    ) {}

    public function create(Model $chatable, string $name, array $conversationIds): ChatList
    {
        return DB::transaction(function () use ($chatable, $name, $conversationIds) {
            $this->guardAllParticipant($conversationIds, $chatable);
            $this->guardListCap($chatable);

            $list = ChatList::query()->create([
                'chatable_type' => $chatable->getMorphClass(),
                'chatable_id' => $chatable->getKey(),
                'name' => $name,
            ]);

            if (! empty($conversationIds)) {
                $list->conversations()->sync($conversationIds);
            }

            return $list;
        });
    }

    public function listForUser(Model $chatable, int $perPage): LengthAwarePaginator
    {
        return Chat::whereChatable(ChatList::query(), $chatable)
            ->with('conversations')
            ->latest('id')
            ->paginate($perPage);
    }

    public function delete(ChatList $list, Model $chatable): void
    {
        $this->guardOwnership($list, $chatable);

        $list->delete();
    }

    public function addConversation(ChatList $list, Model $chatable, int $conversationId): void
    {
        $this->guardOwnership($list, $chatable);
        $this->guardParticipant($conversationId, $chatable);

        $list->conversations()->syncWithoutDetaching([$conversationId]);
    }

    public function removeConversation(ChatList $list, Model $chatable, int $conversationId): void
    {
        $this->guardOwnership($list, $chatable);

        $list->conversations()->detach($conversationId);
    }

    protected function guardOwnership(ChatList $list, Model $chatable): void
    {
        abort_unless(Chat::identify($list->chatable) === Chat::identify($chatable), 403);
    }

    // Without this, syncing an arbitrary conversation_id onto a list the caller does own would
    // let any authenticated user probe whether that id exists (200 vs. a later 404/403 on
    // actually reading it) — every other conversation-scoped endpoint already 403s/404s before
    // returning anything, so this would be the one ID-enumeration oracle in the API.
    protected function guardParticipant(int $conversationId, Model $chatable): void
    {
        abort_unless($this->participants->isActiveParticipant($conversationId, $chatable), 403);
    }

    /**
     * Same check as guardParticipant(), batched — one query for the whole array instead of one
     * exists() per id (create() accepts up to 200 conversation_ids per StoreChatListRequest).
     *
     * @param  list<int>  $conversationIds
     */
    protected function guardAllParticipant(array $conversationIds, Model $chatable): void
    {
        if ($conversationIds === []) {
            return;
        }

        $unique = array_values(array_unique($conversationIds));
        $active = $this->participants->activeParticipantConversationIds($unique, $chatable);

        abort_unless(count($active) === count($unique), 403);
    }

    // StoreChatListRequest caps a single list's conversation_ids at 200, but nothing bounded how
    // many separate lists one chatable could create in total — repeated POST /lists calls
    // (limited only by the general per-minute throttle, not capped in total) could grow this
    // without limit. Without a cap, that unbounded set is exactly what listForUser() below
    // paginates through (with conversations eager-loaded) on every single GET /lists call.
    protected function guardListCap(Model $chatable): void
    {
        $max = config('chat.lists.max_per_user');

        if ($max === null) {
            return;
        }

        // Locks the chatable's own row so two concurrent create() calls from the same chatable
        // serialize on the count check instead of each reading the same pre-create count and
        // both landing under the cap — same pattern as AttachmentService's per-user storage
        // quota check.
        $chatable::query()->whereKey($chatable->getKey())->lockForUpdate()->first();

        $count = Chat::whereChatable(ChatList::query(), $chatable)->count();

        abort_if($count >= $max, 422, "You cannot have more than {$max} lists.");
    }
}
