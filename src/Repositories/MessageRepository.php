<?php

namespace Chat\Repositories;

use Chat\Chat;
use Chat\Contracts\MessageRepositoryInterface;
use Chat\Contracts\UserSettingsServiceInterface;
use Chat\Models\Conversation;
use Chat\Models\Message;
use Chat\Models\MessageDeletion;
use Chat\Models\MessageReceipt;
use Chat\Models\UserSetting;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class MessageRepository implements MessageRepositoryInterface
{
    public function __construct(
        protected UserSettingsServiceInterface $settings,
    ) {}

    public function create(array $data): Message
    {
        return Message::query()->create($data);
    }

    public function findById(int $id): Message
    {
        return Message::query()->findOrFail($id);
    }

    // simplePaginate(), not paginate(): the chat scroll is a before_id cursor UI (see
    // useMessages.js) that only ever reads data.data and derives its next cursor from the last
    // message's own id — it never touches total/last_page. paginate()'s extra SELECT COUNT(*)
    // (through the whereDoesntHave deletions subquery, on every single page load) buys nothing
    // here, so simplePaginate()'s "fetch perPage+1, no count" is a same-shape, zero-cost swap.
    public function paginateForConversation(
        Conversation $conversation,
        Model $chatable,
        int $perPage,
        ?int $beforeId = null
    ): Paginator {
        $query = Chat::excludeExpiredMessages(
            Message::query()
                ->where('conversation_id', $conversation->id)
                ->whereDoesntHave('deletions', fn ($q) => Chat::whereChatable($q, $chatable))
        )
            // receipts.chatable deliberately absent — see receiptSummariesFor() and
            // MessageController::index(), which attach a batched per-message summary instead of
            // eager-loading every receipt row for a whole page of messages.
            // replyTo.deletions is scoped to just this viewer's own row (if any) — batched for
            // the whole page in one query, so MessageResource can tell whether a reply target
            // was deleted for *this* viewer without a query per message. See its
            // replyTargetDeletedForViewer().
            ->with([
                'chatable', 'attachments', 'reactions', 'replyTo.attachments',
                // Nested relation-constraint closures receive a Relation instance, not a plain
                // Eloquent Builder — Chat::whereChatable() type-hints Builder, so this inlines
                // the same two where()s instead (Relation forwards where() via __call, but not
                // the Builder type hint itself).
                'replyTo.deletions' => fn ($q) => $q
                    ->where('chatable_type', $chatable->getMorphClass())
                    ->where('chatable_id', $chatable->getKey()),
                'starredBy', 'pinnedIn', 'pollVotes', 'eventRsvps',
            ])
            ->orderByDesc('id');

        if ($beforeId !== null) {
            $query->where('id', '<', $beforeId);
        }

        return $query->simplePaginate($perPage);
    }

    // simplePaginate() for the same reason as paginateForConversation() above — search() (see
    // useMessages.js::search()) also only reads data.data, never total/last_page.
    public function search(Model $chatable, string $query, ?int $conversationId, int $perPage): Paginator
    {
        $builder = Chat::excludeExpiredMessages(
            Message::query()
                ->whereHas('conversation.participants', fn ($q) => Chat::whereChatable($q, $chatable)->whereNull('left_at'))
                ->whereDoesntHave('deletions', fn ($q) => Chat::whereChatable($q, $chatable))
                ->whereNull('deleted_for_everyone_at')
        )
            // 'conversation.participants' is column-restricted: MessageResource only ever
            // reads chatable_type/chatable_id off of it (to build the frontend's
            // chatableKey()), so there's no reason to pull role/muted_until/archived_at/etc.
            // for every participant of every conversation a search result happens to land in.
            // receipts.chatable deliberately absent — see receiptSummariesFor(). replyTo.deletions
            // scoped to this viewer — see paginateForConversation() above.
            ->with([
                'chatable', 'attachments', 'reactions', 'replyTo.attachments',
                // Nested relation-constraint closures receive a Relation instance, not a plain
                // Eloquent Builder — Chat::whereChatable() type-hints Builder, so this inlines
                // the same two where()s instead (Relation forwards where() via __call, but not
                // the Builder type hint itself).
                'replyTo.deletions' => fn ($q) => $q
                    ->where('chatable_type', $chatable->getMorphClass())
                    ->where('chatable_id', $chatable->getKey()),
                'starredBy', 'pinnedIn', 'pollVotes', 'eventRsvps',
                'conversation', 'conversation.participants:id,conversation_id,chatable_type,chatable_id',
            ])
            ->orderByDesc('id');

        $this->matchBody($builder, $query);

        if ($conversationId !== null) {
            $builder->where('conversation_id', $conversationId);
        }

        return $builder->simplePaginate($perPage);
    }

    /**
     * MySQL/Postgres get a real FULLTEXT/tsvector index (see create_chat_messages_table)
     * instead of a leading-wildcard LIKE scan, which can't use any index and gets slower as
     * message volume grows. SQLite (used in tests) has no full-text support, so it falls back
     * to an escaped LIKE — fine at test/tiny-install scale.
     */
    protected function matchBody(Builder $builder, string $query): void
    {
        if (in_array(DB::connection()->getDriverName(), ['mysql', 'pgsql'], true)) {
            $builder->whereFullText('body', $query);

            return;
        }

        $builder->whereRaw('body LIKE ? ESCAPE ?', [$this->likeTerm($query), '\\']);
    }

    // Un-escaped % and _ in a user-supplied search term are interpreted as SQL LIKE wildcards
    // rather than literal characters, over/under-matching for anyone actually searching for
    // those characters (e.g. "50%"). Not injection — the value is still bound as a parameter —
    // just wrong results.
    protected function likeTerm(string $value): string
    {
        return '%'.addcslashes($value, '\\%_').'%';
    }

    // simplePaginate(), not paginate() — same reasoning as paginateForConversation()/search()
    // above. MediaDocsLinksGrid.vue's own "is there more" check only ever compares page numbers
    // (an infinite-scroll "load more" grid, not numbered page-jump navigation), which
    // simplePaginate()'s links.next tells it just as well as an exact total would, without the
    // COUNT(*) query paginate() runs on every request.
    public function media(Model $chatable, string $kind, ?int $conversationId, int $perPage, ?string $search = null): Paginator
    {
        $builder = Chat::excludeExpiredMessages(
            Message::query()
                ->whereHas('conversation.participants', fn ($q) => Chat::whereChatable($q, $chatable)->whereNull('left_at'))
                ->whereDoesntHave('deletions', fn ($q) => Chat::whereChatable($q, $chatable))
                ->whereNull('deleted_for_everyone_at')
        )
            ->with(['chatable', 'attachments', 'conversation'])
            ->orderByDesc('id');

        match ($kind) {
            'media' => $builder->whereIn('type', ['image', 'video', 'gif']),
            'docs' => $builder->where('type', 'document'),
            // has_link is computed at write time (see Message::hasLinkInBody(), set in
            // MessageService::send()/update()) from the same "any URL in the body" rule this
            // used to check here with a leading-wildcard `body LIKE '%http%'` scan — indexed
            // now instead of a full table scan.
            'links' => $builder->where('type', 'text')->where('has_link', true),
        };

        if ($conversationId !== null) {
            $builder->where('conversation_id', $conversationId);
        }

        if ($search !== null && $search !== '') {
            $matches = Chat::matchingChatablePairs($search);
            $term = $this->likeTerm($search);

            $builder->where(function ($outer) use ($term, $matches) {
                $outer->whereHas('attachments', fn ($q) => $q->whereRaw('original_filename LIKE ? ESCAPE ?', [$term, '\\']))
                    ->orWhereRaw('body LIKE ? ESCAPE ?', [$term, '\\'])
                    ->orWhereHas('conversation', fn ($q) => $q->whereRaw('name LIKE ? ESCAPE ?', [$term, '\\']));

                if (! empty($matches)) {
                    $outer->orWhereHas('conversation.participants', function ($participantQuery) use ($matches) {
                        $participantQuery->where(function ($inner) use ($matches) {
                            foreach ($matches as [$type, $id]) {
                                $inner->orWhere(fn ($q) => $q->where('chatable_type', $type)->where('chatable_id', $id));
                            }
                        });
                    });
                }
            });
        }

        return $builder->simplePaginate($perPage);
    }

    public function clearForChatable(Conversation $conversation, Model $chatable): void
    {
        // chunkById(200), not pluck() over the whole conversation then map() into an
        // equally-sized array of upsert rows: a conversation with hundreds of thousands of
        // messages would otherwise hold every id, then every row, in memory at once before a
        // single query runs. Advancing by id never re-processes or skips a row here — each
        // chunk's own upsert gives its messages a deletion row for this chatable, which is
        // exactly what whereDoesntHave('deletions', ...) already excludes them on, so the next
        // chunk's query naturally never sees them again.
        Message::query()
            ->where('conversation_id', $conversation->id)
            ->whereDoesntHave('deletions', fn ($q) => Chat::whereChatable($q, $chatable))
            ->chunkById(200, function (Collection $messages) use ($chatable) {
                $rows = $messages->map(fn (Message $message) => [
                    'message_id' => $message->id,
                    'chatable_type' => $chatable->getMorphClass(),
                    'chatable_id' => $chatable->getKey(),
                    'deleted_at' => now(),
                ])->all();

                MessageDeletion::query()->upsert($rows, ['message_id', 'chatable_type', 'chatable_id'], ['deleted_at']);
            });
    }

    /**
     * Per-message recipient/delivered/read counts instead of the full receipt rows (each with
     * its chatable model) MessageResource::receiptStatus() used to need to derive sent/
     * delivered/read — a 50-message page in a 200-participant conversation used to mean loading
     * up to ~10,000 receipt+chatable rows just to answer three counts per message.
     *
     * @param  list<int>  $messageIds
     * @return array<int, array{recipient_count: int, delivered_count: int, read_count: int}>
     */
    public function receiptSummariesFor(array $messageIds): array
    {
        if ($messageIds === []) {
            return [];
        }

        $receiptsTable = (new MessageReceipt)->getTable();

        // recipient_count/delivered_count don't depend on any privacy setting, so one grouped
        // aggregate covers every message in the batch — no chatable model needed at all.
        $counts = DB::table($receiptsTable)
            ->whereIn('message_id', $messageIds)
            ->select('message_id')
            ->selectRaw('COUNT(*) as recipient_count')
            ->selectRaw('SUM(CASE WHEN delivered_at IS NOT NULL THEN 1 ELSE 0 END) as delivered_count')
            ->groupBy('message_id')
            ->get()
            ->keyBy('message_id');

        $readCounts = $this->readCountsRespectingPrivacy($messageIds);

        $summaries = [];

        foreach ($messageIds as $id) {
            $row = $counts->get($id);

            $summaries[$id] = [
                'recipient_count' => $row ? (int) $row->recipient_count : 0,
                'delivered_count' => $row ? (int) $row->delivered_count : 0,
                'read_count' => $readCounts[$id] ?? 0,
            ];
        }

        return $summaries;
    }

    /**
     * A receipt only "counts as read" if the recipient's *current* read-receipts privacy
     * setting allows it — UserSetting::readReceiptsVisible() as a boolean expression:
     * show_read_receipts AND (read_receipts_hidden_until IS NULL OR it's already in the past).
     * A LEFT JOIN + GROUP BY evaluates that per receipt without ever materializing a receipt or
     * UserSetting model — the previous version loaded every read receipt row into PHP just to
     * look each one's privacy setting up and tally allowed ones by hand, which was the actual
     * DB→PHP transfer the receipt summary was built to avoid in the first place.
     *
     * @param  list<int>  $messageIds
     * @return array<int, int> read count keyed by message_id
     */
    protected function readCountsRespectingPrivacy(array $messageIds): array
    {
        $receiptsTable = (new MessageReceipt)->getTable();
        $settingsTable = (new UserSetting)->getTable();
        $defaultVisible = (bool) config('chat.privacy.read_receipts_default', true);
        $now = now();

        $rows = DB::table("{$receiptsTable} as r")
            ->leftJoin("{$settingsTable} as s", function ($join) {
                $join->on('s.chatable_type', '=', 'r.chatable_type')
                    ->on('s.chatable_id', '=', 'r.chatable_id');
            })
            ->whereIn('r.message_id', $messageIds)
            ->whereNotNull('r.read_at')
            ->where(function ($visible) use ($defaultVisible, $now) {
                $visible->where(function ($q) use ($now) {
                    $q->where('s.show_read_receipts', true)
                        ->where(function ($hidden) use ($now) {
                            $hidden->whereNull('s.read_receipts_hidden_until')
                                ->orWhere('s.read_receipts_hidden_until', '<=', $now);
                        });
                });

                // No settings row at all means the recipient never touched their privacy
                // defaults — same fallback UserSettingsService seeds new rows with.
                if ($defaultVisible) {
                    $visible->orWhereNull('s.id');
                }
            })
            ->select('r.message_id')
            ->selectRaw('COUNT(*) as read_count')
            ->groupBy('r.message_id')
            ->get();

        return $rows->pluck('read_count', 'message_id')->map(fn ($count) => (int) $count)->all();
    }

    /**
     * @param  Collection<int, MessageReceipt>  $rows  any collection with chatable_type/chatable_id
     * @return array<string, UserSetting> keyed by "type|id"
     */
    protected function settingsByChatableKey(Collection $rows): array
    {
        $settingsByKey = [];

        foreach ($rows->groupBy('chatable_type') as $type => $group) {
            UserSetting::query()
                ->where('chatable_type', $type)
                ->whereIn('chatable_id', $group->pluck('chatable_id')->unique())
                ->get()
                ->each(function (UserSetting $setting) use (&$settingsByKey) {
                    $settingsByKey[$setting->chatable_type.'|'.$setting->chatable_id] = $setting;
                });
        }

        return $settingsByKey;
    }

    /**
     * Nulls out read_at on any receipt the viewer shouldn't see as "read" — either because the
     * recipient's own read-receipts privacy setting hides it, or (reciprocity) because the
     * viewer has their own read-receipt sharing turned off. Same two rules
     * MessageResource::receiptStatusFromSummary() already applies to the aggregate status;
     * GET /messages/{message}/receipts returns raw receipt rows instead of going through that
     * resource method, so it needs to apply them itself rather than silently skip them.
     * delivered_at is left untouched — this package has no "hide delivery" privacy setting.
     *
     * @param  Collection<int, MessageReceipt>  $receipts
     * @return Collection<int, MessageReceipt>
     */
    public function applyReadReceiptPrivacy(Collection $receipts, ?Model $viewer): Collection
    {
        $viewerAllowsReadReceipts = $viewer === null || $this->settings->allowsReadReceipts($viewer);

        if (! $viewerAllowsReadReceipts) {
            return $receipts->each(function (MessageReceipt $receipt) {
                $receipt->read_at = null;
            });
        }

        $settingsByKey = $this->settingsByChatableKey($receipts);
        $default = (bool) config('chat.privacy.read_receipts_default', true);

        return $receipts->each(function (MessageReceipt $receipt) use ($settingsByKey, $default) {
            if ($receipt->read_at === null) {
                return;
            }

            $key = $receipt->chatable_type.'|'.$receipt->chatable_id;
            $allows = isset($settingsByKey[$key]) ? $settingsByKey[$key]->readReceiptsVisible() : $default;

            if (! $allows) {
                $receipt->read_at = null;
            }
        });
    }
}
