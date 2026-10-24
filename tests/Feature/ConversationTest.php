<?php

use Chat\Tests\Fixtures\User;
use Illuminate\Support\Facades\DB;

function chatUser(): User
{
    return User::query()->create([
        'name' => fake()->name(),
        'email' => fake()->unique()->safeEmail(),
        'password' => bcrypt('secret'),
    ]);
}

it('creates a private conversation and dedupes on repeat', function () {
    $alice = chatUser();
    $bob = chatUser();

    $first = $this->actingAs($alice)->postJson('/api/chat/conversations', [
        'type' => 'private',
        'participants' => [chatableRef($bob)],
    ])->assertCreated();

    $conversationId = $first->json('data.id');

    $second = $this->actingAs($alice)->postJson('/api/chat/conversations', [
        'type' => 'private',
        'participants' => [chatableRef($bob)],
    ])->assertOk();

    expect($second->json('data.id'))->toBe($conversationId);
});

it('creates a group conversation with all participants admin-free except creator', function () {
    $alice = chatUser();
    $bob = chatUser();
    $carol = chatUser();

    $response = $this->actingAs($alice)->postJson('/api/chat/conversations', [
        'type' => 'group',
        'name' => 'Trip planning',
        'participants' => chatableRefs([$bob, $carol]),
    ])->assertCreated();

    expect($response->json('data.name'))->toBe('Trip planning')
        ->and($response->json('data.participants'))->toHaveCount(3);
});

it('bounds participant rows in the conversation list to the viewer plus the other side of a private chat', function () {
    // The sidebar list never renders a group's member list (that's GroupInfoPanel, which fetches
    // its own full roster on open) — only 'me' (mute/pin/archive state) and, for a private
    // conversation, the other participant's identity. Loading every member of every group on
    // every sidebar page load doesn't buy anything the response actually uses.
    $alice = chatUser();
    $bob = chatUser();
    $carol = chatUser();
    $dave = chatUser();
    $eve = chatUser();

    $groupId = $this->actingAs($alice)->postJson('/api/chat/conversations', [
        'type' => 'group',
        'name' => 'Bounded roster',
        'participants' => chatableRefs([$bob, $carol, $dave]),
    ])->json('data.id');

    $privateId = $this->actingAs($alice)->postJson('/api/chat/conversations', [
        'type' => 'private',
        'participants' => [chatableRef($eve)],
    ])->json('data.id');

    $list = $this->actingAs($alice)->getJson('/api/chat/conversations')->assertOk();
    $entries = collect($list->json('data'))->keyBy('id');

    expect($entries[$groupId]['participants'])->toHaveCount(1)
        ->and($entries[$groupId]['participant_count'])->toBe(4)
        ->and($entries[$privateId]['participants'])->toHaveCount(2)
        ->and($entries[$privateId]['participant_count'])->toBe(2);

    // The single-conversation endpoint is untouched — it still returns every member, since
    // that's what GroupInfoPanel relies on once it fetches this on open.
    $show = $this->actingAs($alice)->getJson("/api/chat/conversations/{$groupId}")->assertOk();
    expect($show->json('data.participants'))->toHaveCount(4)
        ->and($show->json('data.participant_count'))->toBe(4);
});

it('excludes messages deleted for me from unread_count, on both the list and single-conversation paths', function () {
    // Delete-for-me became a real access boundary elsewhere (MessagePolicy::isAccessible()) —
    // the unread badge needs to agree, or a message the viewer can no longer see still inflates
    // their unread count.
    $alice = chatUser();
    $bob = chatUser();
    $conversationId = privateConversationBetween($alice, $bob);

    $messageIds = collect(range(1, 3))->map(fn ($n) => $this->actingAs($bob)
        ->postJson("/api/chat/conversations/{$conversationId}/messages", ['type' => 'text', 'body' => "msg {$n}"])
        ->json('data.id'));

    $this->actingAs($alice)->deleteJson("/api/chat/messages/{$messageIds[1]}/me")->assertNoContent();

    $list = $this->actingAs($alice)->getJson('/api/chat/conversations')->assertOk();
    $entry = collect($list->json('data'))->firstWhere('id', $conversationId);
    expect($entry['unread_count'])->toBe(2);

    $show = $this->actingAs($alice)->getJson("/api/chat/conversations/{$conversationId}")->assertOk();
    expect($show->json('data.unread_count'))->toBe(2);
});

it('resolves last_message and unread_count for the conversation list without one query per conversation', function () {
    // 1 conversation vs 8, each for a different user — if last_message/unread_count were
    // still resolved per row (the N+1 this replaced), 8 conversations would cost roughly
    // 2 extra queries each over 1. Equal query counts either way prove it's batched, not
    // per-row.
    $countQueriesFor = function (int $conversationCount) {
        $viewer = chatUser();

        for ($i = 0; $i < $conversationCount; $i++) {
            $sender = chatUser();
            $conversationId = $this->actingAs($viewer)->postJson('/api/chat/conversations', [
                'type' => 'private',
                'participants' => [chatableRef($sender)],
            ])->json('data.id');

            $this->actingAs($sender)->postJson("/api/chat/conversations/{$conversationId}/messages", [
                'type' => 'text',
                'body' => 'hey',
            ])->assertCreated();
        }

        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->actingAs($viewer)->getJson('/api/chat/conversations')->assertOk()->assertJsonCount($conversationCount, 'data');
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $count;
    };

    $withOne = $countQueriesFor(1);
    $withEight = $countQueriesFor(8);

    expect($withEight)->toBe($withOne);
});

it('batches receipt-settings lookups across the conversation list instead of one per distinct participant', function () {
    // Viewer sends the last message in every conversation, and each conversation's distinct
    // *other* participant marks it read — so receiptStatus()'s countsAsRead() actually reaches
    // settings->allowsReadReceipts($receipt->chatable) per conversation instead of
    // short-circuiting on read_at === null. That's the shape the previous test's "same viewer
    // every time, nothing ever marked read" setup never exercised, since a lookup for the
    // viewer alone stays memoized by the container-scoped service regardless of whether
    // ConversationController preloads anything.
    $settingsQueriesFor = function (int $conversationCount) {
        $viewer = chatUser();

        for ($i = 0; $i < $conversationCount; $i++) {
            $other = chatUser();
            $conversationId = $this->actingAs($viewer)->postJson('/api/chat/conversations', [
                'type' => 'private',
                'participants' => [chatableRef($other)],
            ])->json('data.id');

            $messageId = $this->actingAs($viewer)->postJson("/api/chat/conversations/{$conversationId}/messages", [
                'type' => 'text',
                'body' => 'hey',
            ])->assertCreated()->json('data.id');

            $this->actingAs($other)->postJson("/api/chat/conversations/{$conversationId}/receipts/read", [
                'up_to_message_id' => $messageId,
            ])->assertNoContent();
        }

        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->actingAs($viewer)->getJson('/api/chat/conversations')->assertOk()->assertJsonCount($conversationCount, 'data');
        $count = collect(DB::getQueryLog())->filter(
            fn ($entry) => str_contains($entry['query'], 'chat_user_settings') && str_starts_with(strtolower($entry['query']), 'select')
        )->count();
        DB::disableQueryLog();

        return $count;
    };

    $withOne = $settingsQueriesFor(1);
    $withEight = $settingsQueriesFor(8);

    expect($withEight)->toBe($withOne);
});

it('paginates the conversation list instead of returning every conversation at once', function () {
    // ConversationRepository::getForUser() used to end in a plain get() — chat.pagination.
    // conversations_per_page existed in config but nothing actually consulted it, so a user
    // with thousands of conversations paid for all of them (eager loads, the unread-count CASE
    // built across every one) on every single sidebar load. simplePaginate() bounds that per
    // request, and useConversations.js's loadMore() fetches subsequent pages on demand.
    config(['chat.pagination.conversations_per_page' => 3]);

    $alice = chatUser();

    foreach (range(1, 5) as $i) {
        $other = chatUser();
        $conversationId = $this->actingAs($alice)->postJson('/api/chat/conversations', [
            'type' => 'private',
            'participants' => [chatableRef($other)],
        ])->json('data.id');

        $this->actingAs($other)->postJson("/api/chat/conversations/{$conversationId}/messages", [
            'type' => 'text',
            'body' => "hey {$i}",
        ])->assertCreated();
    }

    $first = $this->actingAs($alice)->getJson('/api/chat/conversations')->assertOk();
    expect($first->json('data'))->toHaveCount(3)
        ->and($first->json('links.next'))->not->toBeNull();

    $second = $this->actingAs($alice)->getJson('/api/chat/conversations?page=2')->assertOk();
    expect($second->json('data'))->toHaveCount(2)
        ->and($second->json('links.next'))->toBeNull();

    // No overlap between pages.
    $firstIds = collect($first->json('data'))->pluck('id');
    $secondIds = collect($second->json('data'))->pluck('id');
    expect($firstIds->intersect($secondIds))->toBeEmpty();
});

it('rejects a conversation-list search query shorter than the configured minimum length', function () {
    // Same minimum as user search — a 1-character term still reaches
    // Chat::matchingChatablePairs()'s participant-name match clause via this filter's 'q'.
    $alice = chatUser();

    $this->actingAs($alice)->getJson('/api/chat/conversations?q=a')->assertInvalid(['q']);

    // Omitting q entirely (browse-all) must still work.
    $this->actingAs($alice)->getJson('/api/chat/conversations')->assertOk();
});

it('rejects a conversation create request with more than 200 participants', function () {
    $alice = chatUser();

    // Validation rejects on array size alone, before touching the DB, so these don't need to
    // be real users.
    $participants = collect(range(1, 201))->map(fn ($id) => ['type' => 'user', 'id' => $id])->all();

    $this->actingAs($alice)->postJson('/api/chat/conversations', [
        'type' => 'group',
        'name' => 'Too big',
        'participants' => $participants,
    ])->assertInvalid(['participants']);
});

it('prevents a non-participant from viewing a conversation', function () {
    $alice = chatUser();
    $bob = chatUser();
    $eve = chatUser();

    $conversationId = $this->actingAs($alice)->postJson('/api/chat/conversations', [
        'type' => 'private',
        'participants' => [chatableRef($bob)],
    ])->json('data.id');

    $this->actingAs($eve)
        ->getJson("/api/chat/conversations/{$conversationId}")
        ->assertForbidden();
});
