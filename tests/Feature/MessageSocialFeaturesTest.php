<?php

use Chat\Models\Message;
use Chat\Tests\Fixtures\User;
use Illuminate\Support\Facades\DB;

function socialUser(string $email): User
{
    return User::query()->create(['name' => $email, 'email' => $email, 'password' => bcrypt('secret')]);
}

function privateConversationBetween(User $a, User $b): int
{
    return test()->actingAs($a)->postJson('/api/chat/conversations', [
        'type' => 'private',
        'participants' => [chatableRef($b)],
    ])->json('data.id');
}

it('toggles a reaction on and off', function () {
    $alice = socialUser('alice-react@example.com');
    $bob = socialUser('bob-react@example.com');
    $conversationId = privateConversationBetween($alice, $bob);

    $messageId = $this->actingAs($alice)
        ->postJson("/api/chat/conversations/{$conversationId}/messages", ['type' => 'text', 'body' => 'hi'])
        ->json('data.id');

    $reacted = $this->actingAs($bob)
        ->postJson("/api/chat/messages/{$messageId}/reactions", ['emoji' => '👍'])
        ->assertOk();

    expect($reacted->json('data.0.emoji'))->toBe('👍')
        ->and($reacted->json('data.0.count'))->toBe(1);

    $removed = $this->actingAs($bob)
        ->deleteJson("/api/chat/messages/{$messageId}/reactions")
        ->assertOk();

    expect($removed->json('data'))->toBe([]);
});

it('omits per-reactor detail from the timeline but exposes it via the dedicated reactions endpoint', function () {
    // Mirrors the receipts split (GET /messages/{message}/receipts): the timeline only ever
    // needs emoji/count/self to render the pill and picker state, not who reacted — that detail
    // is fetched on demand only when "reaction details" is opened.
    $alice = socialUser('alice-reactdetail@example.com');
    $bob = socialUser('bob-reactdetail@example.com');
    $conversationId = privateConversationBetween($alice, $bob);

    $messageId = $this->actingAs($alice)
        ->postJson("/api/chat/conversations/{$conversationId}/messages", ['type' => 'text', 'body' => 'hi'])
        ->json('data.id');

    $this->actingAs($bob)->postJson("/api/chat/messages/{$messageId}/reactions", ['emoji' => '👍'])->assertOk();

    $timeline = $this->actingAs($alice)->getJson("/api/chat/conversations/{$conversationId}/messages")->assertOk();
    $reaction = collect($timeline->json('data'))->firstWhere('id', $messageId)['reactions'][0];

    expect($reaction)->toHaveKeys(['emoji', 'count', 'self'])
        ->and($reaction)->not->toHaveKey('chatables');

    $detail = $this->actingAs($alice)->getJson("/api/chat/messages/{$messageId}/reactions")->assertOk();

    expect($detail->json('data.0.chatables'))->toHaveCount(1)
        ->and($detail->json('data.0.chatables.0.id'))->toBe($bob->id);
});

it('replies to a message and forwards it to another conversation', function () {
    $alice = socialUser('alice-fwd@example.com');
    $bob = socialUser('bob-fwd@example.com');
    $carol = socialUser('carol-fwd@example.com');

    $chatAB = privateConversationBetween($alice, $bob);
    $chatAC = privateConversationBetween($alice, $carol);

    $originalId = $this->actingAs($alice)
        ->postJson("/api/chat/conversations/{$chatAB}/messages", ['type' => 'text', 'body' => 'original'])
        ->json('data.id');

    $reply = $this->actingAs($bob)->postJson("/api/chat/conversations/{$chatAB}/messages", [
        'type' => 'text',
        'body' => 'replying',
        'reply_to_message_id' => $originalId,
    ])->assertCreated();

    expect($reply->json('data.reply_to.body'))->toBe('original');

    $forwarded = $this->actingAs($alice)
        ->postJson("/api/chat/messages/{$originalId}/forward", ['conversation_ids' => [$chatAC]])
        ->assertOk();

    expect($forwarded->json('data.0.is_forwarded'))->toBeTrue()
        ->and($forwarded->json('data.0.body'))->toBe('original');
});

it('hides a reply target\'s body and metadata once it is deleted for everyone', function () {
    // MessageResource nulled body/metadata on the message itself when deleted for everyone, but
    // the nested reply_to block returned the target's real body/metadata unconditionally —
    // deleting the original for everyone did nothing to stop it leaking back out through anyone
    // else's reply to it.
    $alice = socialUser('alice-replydel@example.com');
    $bob = socialUser('bob-replydel@example.com');
    $conversationId = privateConversationBetween($alice, $bob);

    $originalId = $this->actingAs($alice)
        ->postJson("/api/chat/conversations/{$conversationId}/messages", ['type' => 'text', 'body' => 'secret plan'])
        ->json('data.id');

    $replyId = $this->actingAs($bob)->postJson("/api/chat/conversations/{$conversationId}/messages", [
        'type' => 'text',
        'body' => 'replying',
        'reply_to_message_id' => $originalId,
    ])->json('data.id');

    $this->actingAs($alice)->deleteJson("/api/chat/messages/{$originalId}")->assertNoContent();

    $listed = $this->actingAs($bob)->getJson("/api/chat/conversations/{$conversationId}/messages")->assertOk();
    $reply = collect($listed->json('data'))->firstWhere('id', $replyId);

    expect($reply['reply_to']['deleted_for_everyone'])->toBeTrue()
        ->and($reply['reply_to']['body'])->toBeNull()
        ->and($reply['reply_to']['metadata'])->toBeNull()
        ->and($reply['reply_to']['attachments'])->toBe([]);
});

it('hides a reply target\'s body and metadata for a viewer who deleted it for themselves, but not for others', function () {
    // Compounds with the deleted-for-everyone case: a message only deleted for one participant
    // (delete-for-me) still exists and is fully visible to everyone else, so someone else's
    // reply to it should keep showing the real content to THEM — but not leak it back to the
    // viewer who specifically asked to have it removed from their own view.
    $alice = socialUser('alice-replydelme@example.com');
    $bob = socialUser('bob-replydelme@example.com');
    $conversationId = privateConversationBetween($alice, $bob);

    $originalId = $this->actingAs($alice)
        ->postJson("/api/chat/conversations/{$conversationId}/messages", ['type' => 'text', 'body' => 'secret plan'])
        ->json('data.id');

    $replyId = $this->actingAs($bob)->postJson("/api/chat/conversations/{$conversationId}/messages", [
        'type' => 'text',
        'body' => 'replying',
        'reply_to_message_id' => $originalId,
    ])->json('data.id');

    // Alice deletes the original for herself only — Bob never deleted anything.
    $this->actingAs($alice)->deleteJson("/api/chat/messages/{$originalId}/me")->assertNoContent();

    $alicesView = $this->actingAs($alice)->getJson("/api/chat/conversations/{$conversationId}/messages")->assertOk();
    $aliceReply = collect($alicesView->json('data'))->firstWhere('id', $replyId);
    expect($aliceReply['reply_to']['body'])->toBeNull()
        ->and($aliceReply['reply_to']['metadata'])->toBeNull()
        // Deleting for herself doesn't make it "deleted for everyone" — Bob still sees it fine.
        ->and($aliceReply['reply_to']['deleted_for_everyone'])->toBeFalse();

    $bobsView = $this->actingAs($bob)->getJson("/api/chat/conversations/{$conversationId}/messages")->assertOk();
    $bobReply = collect($bobsView->json('data'))->firstWhere('id', $replyId);
    expect($bobReply['reply_to']['body'])->toBe('secret plan');
});

it('rejects replying to a message that belongs to a different conversation', function () {
    // StoreMessageRequest only validates reply_to_message_id as an integer, so this must be
    // enforced server-side: MessageResource's replyTo block exposes the referenced message's
    // body excerpt, metadata, and attachments, so accepting a cross-conversation id would leak
    // content from a conversation the poster (or, worse, its other participants reading the
    // reply) was never part of.
    $alice = socialUser('alice-replyleak@example.com');
    $bob = socialUser('bob-replyleak@example.com');
    $eve = socialUser('eve-replyleak@example.com');

    $chatAB = privateConversationBetween($alice, $bob);
    $chatAE = privateConversationBetween($alice, $eve);

    $secretId = $this->actingAs($bob)
        ->postJson("/api/chat/conversations/{$chatAB}/messages", ['type' => 'text', 'body' => 'secret between alice and bob'])
        ->json('data.id');

    $response = $this->actingAs($alice)->postJson("/api/chat/conversations/{$chatAE}/messages", [
        'type' => 'text',
        'body' => 'trying to leak',
        'reply_to_message_id' => $secretId,
    ])->assertStatus(422);

    expect($response->json('message'))->toContain('does not belong to this conversation');

    // Eve must never see the secret message's content, whether via the rejected message's own
    // (never-created) reply_to or by fetching the conversation afterward.
    $messages = $this->actingAs($eve)->getJson("/api/chat/conversations/{$chatAE}/messages")->assertOk();
    expect(collect($messages->json('data'))->pluck('body'))->not->toContain('secret between alice and bob');
});

it('keeps earlier successful forwards committed when a later destination in the same request fails', function () {
    // forward() used to wrap every destination in one shared transaction, so a permission
    // failure partway through a multi-destination forward rolled back every destination that
    // had already succeeded in that same request — discarding real, already-broadcast sends for
    // no reason related to those destinations. Each destination now commits independently, so a
    // later failure no longer undoes earlier successes.
    $alice = socialUser('alice-fwdpartial@example.com');
    $bob = socialUser('bob-fwdpartial@example.com');
    $carol = socialUser('carol-fwdpartial@example.com');
    $dave = socialUser('dave-fwdpartial@example.com');
    $eve = socialUser('eve-fwdpartial@example.com');

    $sourceChat = privateConversationBetween($alice, $bob);
    $goodTargetA = privateConversationBetween($alice, $carol);
    $goodTargetB = privateConversationBetween($alice, $dave);
    // Alice is deliberately not a participant here — forwarding to it must 403.
    $forbiddenTarget = privateConversationBetween($eve, $dave);

    $originalId = $this->actingAs($alice)
        ->postJson("/api/chat/conversations/{$sourceChat}/messages", ['type' => 'text', 'body' => 'partial forward'])
        ->json('data.id');

    $this->actingAs($alice)
        ->postJson("/api/chat/messages/{$originalId}/forward", [
            'conversation_ids' => [$goodTargetA, $goodTargetB, $forbiddenTarget],
        ])
        ->assertForbidden();

    $inA = $this->actingAs($alice)->getJson("/api/chat/conversations/{$goodTargetA}/messages")->assertOk();
    $inB = $this->actingAs($alice)->getJson("/api/chat/conversations/{$goodTargetB}/messages")->assertOk();

    expect(collect($inA->json('data'))->pluck('body'))->toContain('partial forward')
        ->and(collect($inB->json('data'))->pluck('body'))->toContain('partial forward');
});

it('forwards to several conversations with one batched eager-load, not one per forwarded message', function () {
    // Forwarding to N conversations necessarily costs real per-target work (an INSERT, receipt
    // rows, a conversation touch, a broadcast for each), so total query count legitimately
    // grows with N and can't be asserted flat — comparing it wouldn't isolate the eager-load
    // step from that unrelated, expected-to-scale creation cost. Instead this counts queries
    // against five of EAGER's relation tables specifically (starredBy/pinnedIn/pollVotes/
    // eventRsvps/reactions — tables nothing else in the forward path ever touches): loading
    // them per forwarded message inside a map() (the bug this replaced) fires one query per
    // relation per message, so N targets would cost 5xN such queries; a single batched load()
    // on the whole collection costs 5 regardless of N.
    $eagerRelationQueryCountFor = function (int $targetCount) {
        $alice = socialUser('alice-fwdbatch-'.$targetCount.'@example.com');
        $sourceChat = privateConversationBetween($alice, socialUser('fwdbatch-source-'.$targetCount.'@example.com'));

        $originalId = $this->actingAs($alice)
            ->postJson("/api/chat/conversations/{$sourceChat}/messages", ['type' => 'text', 'body' => 'broadcast me'])
            ->json('data.id');

        $targets = collect(range(1, $targetCount))->map(
            fn ($i) => privateConversationBetween($alice, socialUser("fwdbatch-target-{$targetCount}-{$i}@example.com"))
        );

        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->actingAs($alice)
            ->postJson("/api/chat/messages/{$originalId}/forward", ['conversation_ids' => $targets->all()])
            ->assertOk()
            ->assertJsonCount($targetCount, 'data');
        $eagerQueries = collect(DB::getQueryLog())->filter(fn ($entry) => str_contains(
            $entry['query'],
            'chat_starred_messages'
        ) || str_contains($entry['query'], 'chat_pinned_messages')
            || str_contains($entry['query'], 'chat_poll_votes')
            || str_contains($entry['query'], 'chat_event_rsvps')
            || str_contains($entry['query'], 'chat_message_reactions'));
        DB::disableQueryLog();

        return $eagerQueries->count();
    };

    $withOne = $eagerRelationQueryCountFor(1);
    $withFive = $eagerRelationQueryCountFor(5);

    expect($withFive)->toBe($withOne);
});

it('reports correct receipt status for forwarded messages via the batched summary, not receipts.chatable', function () {
    // MessageController::EAGER used to include receipts.chatable — forward() could return up to
    // 200 messages in one response, each pulling every recipient's full row just to derive its
    // own status. This proves forward() now goes through the same receipt_summary attribute as
    // the timeline instead, and that the status it reports is actually correct.
    $alice = socialUser('alice-fwdstatus@example.com');
    $bob = socialUser('bob-fwdstatus@example.com');
    $carol = socialUser('carol-fwdstatus@example.com');

    $sourceChat = privateConversationBetween($alice, $bob);
    $targetChat = privateConversationBetween($alice, $carol);

    $originalId = $this->actingAs($alice)
        ->postJson("/api/chat/conversations/{$sourceChat}/messages", ['type' => 'text', 'body' => 'forward me'])
        ->json('data.id');

    $forwarded = $this->actingAs($alice)
        ->postJson("/api/chat/messages/{$originalId}/forward", ['conversation_ids' => [$targetChat]])
        ->assertOk();

    expect($forwarded->json('data.0.status'))->toBe('sent');

    $this->actingAs($carol)->postJson("/api/chat/conversations/{$targetChat}/receipts/read", [
        'up_to_message_id' => $forwarded->json('data.0.id'),
    ])->assertNoContent();

    $reread = $this->actingAs($alice)
        ->getJson("/api/chat/conversations/{$targetChat}/messages")
        ->assertOk();
    expect(collect($reread->json('data'))->firstWhere('id', $forwarded->json('data.0.id'))['status'])->toBe('read');
});

it('reports correct receipt status when sending and editing a message, not via receipts.chatable', function () {
    // Same underlying fix as forwarding — store()/update() used to rely on receipts.chatable via
    // MessageController::EAGER too.
    $alice = socialUser('alice-sendstatus@example.com');
    $bob = socialUser('bob-sendstatus@example.com');
    $conversationId = privateConversationBetween($alice, $bob);

    $sent = $this->actingAs($alice)
        ->postJson("/api/chat/conversations/{$conversationId}/messages", ['type' => 'text', 'body' => 'v1'])
        ->assertCreated();
    expect($sent->json('data.status'))->toBe('sent');

    $messageId = $sent->json('data.id');

    $updated = $this->actingAs($alice)
        ->patchJson("/api/chat/messages/{$messageId}", ['body' => 'v2'])
        ->assertOk();
    expect($updated->json('data.status'))->toBe('sent');

    $this->actingAs($bob)->postJson("/api/chat/conversations/{$conversationId}/receipts/delivered")->assertNoContent();

    $reread = $this->actingAs($alice)
        ->getJson("/api/chat/conversations/{$conversationId}/messages")
        ->assertOk();
    expect(collect($reread->json('data'))->firstWhere('id', $messageId)['status'])->toBe('delivered');
});

it('rejects a forward request with more than 200 target conversations', function () {
    $alice = socialUser('alice-fwdtoomany@example.com');
    $sourceChat = privateConversationBetween($alice, socialUser('fwdtoomany-source@example.com'));

    $originalId = $this->actingAs($alice)
        ->postJson("/api/chat/conversations/{$sourceChat}/messages", ['type' => 'text', 'body' => 'hi'])
        ->json('data.id');

    // Validation rejects on array size alone, before touching the DB, so these don't need to
    // be real conversation ids.
    $this->actingAs($alice)
        ->postJson("/api/chat/messages/{$originalId}/forward", ['conversation_ids' => range(1, 201)])
        ->assertInvalid(['conversation_ids']);
});

it('deletes a message for me without affecting the other participant, and for everyone globally', function () {
    $alice = socialUser('alice-del@example.com');
    $bob = socialUser('bob-del@example.com');
    $conversationId = privateConversationBetween($alice, $bob);

    $messageId = $this->actingAs($alice)
        ->postJson("/api/chat/conversations/{$conversationId}/messages", ['type' => 'text', 'body' => 'secret'])
        ->json('data.id');

    $this->actingAs($bob)->deleteJson("/api/chat/messages/{$messageId}/me")->assertNoContent();

    $bobsList = $this->actingAs($bob)->getJson("/api/chat/conversations/{$conversationId}/messages")->assertOk();
    expect($bobsList->json('data'))->toHaveCount(0);

    $alicesList = $this->actingAs($alice)->getJson("/api/chat/conversations/{$conversationId}/messages")->assertOk();
    expect($alicesList->json('data'))->toHaveCount(1);

    $this->actingAs($alice)->deleteJson("/api/chat/messages/{$messageId}")->assertNoContent();

    $alicesListAfter = $this->actingAs($alice)->getJson("/api/chat/conversations/{$conversationId}/messages")->assertOk();
    expect($alicesListAfter->json('data.0.body'))->toBeNull()
        ->and($alicesListAfter->json('data.0.deleted_for_everyone'))->toBeTrue();
});

it('blocks direct access to a message a user has deleted for themselves, not just the timeline', function () {
    // "Delete for me" only used to hide a message from the default timeline query
    // (whereDoesntHave('deletions', ...)) — direct access by message id was completely
    // unaffected, so a user could delete a message for themselves and then still forward its
    // full content into another conversation, react to it, star it, vote on it, or read its
    // edit history/receipts. That defeats the whole point of "delete for me": it was cosmetic,
    // not a real access boundary.
    $alice = socialUser('alice-delbypass@example.com');
    $bob = socialUser('bob-delbypass@example.com');
    $conversationId = privateConversationBetween($alice, $bob);
    $otherConversationId = privateConversationBetween($alice, socialUser('carol-delbypass@example.com'));

    $messageId = $this->actingAs($bob)
        ->postJson("/api/chat/conversations/{$conversationId}/messages", ['type' => 'text', 'body' => 'sensitive'])
        ->json('data.id');

    $pollId = $this->actingAs($bob)
        ->postJson("/api/chat/conversations/{$conversationId}/messages", [
            'type' => 'poll',
            'metadata' => ['question' => 'q?', 'options' => ['a', 'b']],
        ])->json('data.id');

    $eventId = $this->actingAs($bob)
        ->postJson("/api/chat/conversations/{$conversationId}/messages", [
            'type' => 'event',
            'metadata' => ['title' => 'e', 'starts_at' => now()->addDay()->toIso8601String()],
        ])->json('data.id');

    // Alice deletes all three for herself only — Bob (the sender) is unaffected throughout.
    $this->actingAs($alice)->deleteJson("/api/chat/messages/{$messageId}/me")->assertNoContent();
    $this->actingAs($alice)->deleteJson("/api/chat/messages/{$pollId}/me")->assertNoContent();
    $this->actingAs($alice)->deleteJson("/api/chat/messages/{$eventId}/me")->assertNoContent();

    // The most serious case: forwarding must not resurrect content the forwarder deleted for
    // themselves into a conversation they do share with someone else.
    $this->actingAs($alice)
        ->postJson("/api/chat/messages/{$messageId}/forward", ['conversation_ids' => [$otherConversationId]])
        ->assertForbidden();

    $this->actingAs($alice)->getJson("/api/chat/messages/{$messageId}/receipts")->assertForbidden();
    $this->actingAs($alice)->getJson("/api/chat/messages/{$messageId}/edits")->assertForbidden();
    $this->actingAs($alice)->postJson("/api/chat/messages/{$messageId}/reactions", ['emoji' => '👍'])->assertForbidden();
    $this->actingAs($alice)->postJson("/api/chat/messages/{$messageId}/star")->assertForbidden();
    $this->actingAs($alice)->postJson("/api/chat/messages/{$pollId}/poll/vote", ['option_index' => 0])->assertForbidden();
    $this->actingAs($alice)->postJson("/api/chat/messages/{$eventId}/event/rsvp", ['status' => 'going'])->assertForbidden();

    // Bob, who never deleted it, is completely unaffected.
    $this->actingAs($bob)->postJson("/api/chat/messages/{$messageId}/reactions", ['emoji' => '👍'])->assertOk();
});

it('blocks the sender from editing their own message after deleting it for themselves', function () {
    // update() was the one ability the deleted-for-me fix missed — unlike a self-only action,
    // editing broadcasts MessageUpdated to every other participant, so a sender could still
    // change what everyone else sees via a message id they merely remembered, even after
    // explicitly asking to have it removed from their own view.
    $alice = socialUser('alice-editdelbypass@example.com');
    $bob = socialUser('bob-editdelbypass@example.com');
    $conversationId = privateConversationBetween($alice, $bob);

    $messageId = $this->actingAs($alice)
        ->postJson("/api/chat/conversations/{$conversationId}/messages", ['type' => 'text', 'body' => 'original'])
        ->json('data.id');

    $this->actingAs($alice)->deleteJson("/api/chat/messages/{$messageId}/me")->assertNoContent();

    $this->actingAs($alice)
        ->patchJson("/api/chat/messages/{$messageId}", ['body' => 'edited after self-delete'])
        ->assertForbidden();

    // Bob, who never deleted it, still sees the original body untouched.
    $bobsList = $this->actingAs($bob)->getJson("/api/chat/conversations/{$conversationId}/messages")->assertOk();
    expect(collect($bobsList->json('data'))->firstWhere('id', $messageId)['body'])->toBe('original');
});

it('only lets the sender delete for everyone within the configured window', function () {
    $alice = socialUser('alice-delwindow@example.com');
    $bob = socialUser('bob-delwindow@example.com');
    $conversationId = privateConversationBetween($alice, $bob);

    $messageId = $this->actingAs($alice)
        ->postJson("/api/chat/conversations/{$conversationId}/messages", ['type' => 'text', 'body' => 'oops'])
        ->json('data.id');

    // Past config('chat.message.delete_for_everyone_window_minutes') (5) — the sender can no
    // longer delete for everyone, only for themselves.
    $this->travel(6)->minutes();

    $this->actingAs($alice)->deleteJson("/api/chat/messages/{$messageId}")->assertForbidden();
    $this->actingAs($alice)->deleteJson("/api/chat/messages/{$messageId}/me")->assertNoContent();
});

it('edits a message body within the edit window', function () {
    $alice = socialUser('alice-edit@example.com');
    $bob = socialUser('bob-edit@example.com');
    $conversationId = privateConversationBetween($alice, $bob);

    $messageId = $this->actingAs($alice)
        ->postJson("/api/chat/conversations/{$conversationId}/messages", ['type' => 'text', 'body' => 'oops'])
        ->json('data.id');

    $this->actingAs($alice)
        ->patchJson("/api/chat/messages/{$messageId}", ['body' => 'fixed'])
        ->assertOk()
        ->assertJsonPath('data.body', 'fixed');

    $this->actingAs($bob)
        ->patchJson("/api/chat/messages/{$messageId}", ['body' => 'hijacked'])
        ->assertForbidden();
});

it('rejects an edited body longer than the configured max length', function () {
    config(['chat.message.max_body_length' => 20]);

    $alice = socialUser('alice-edit-maxlen@example.com');
    $bob = socialUser('bob-edit-maxlen@example.com');
    $conversationId = privateConversationBetween($alice, $bob);

    $messageId = $this->actingAs($alice)
        ->postJson("/api/chat/conversations/{$conversationId}/messages", ['type' => 'text', 'body' => 'oops'])
        ->json('data.id');

    $this->actingAs($alice)
        ->patchJson("/api/chat/messages/{$messageId}", ['body' => str_repeat('x', 21)])
        ->assertInvalid(['body']);
});

it('caps the number of edits a single message can accumulate', function () {
    // edit_window_minutes bounds how long a message stays editable, not how many times within
    // that window — without max_edits, repeated edits inside the window could otherwise leave a
    // message with an unbounded number of chat_message_edits history rows.
    config(['chat.message.max_edits' => 2]);

    $alice = socialUser('alice-edit-cap@example.com');
    $bob = socialUser('bob-edit-cap@example.com');
    $conversationId = privateConversationBetween($alice, $bob);

    $messageId = $this->actingAs($alice)
        ->postJson("/api/chat/conversations/{$conversationId}/messages", ['type' => 'text', 'body' => 'v1'])
        ->json('data.id');

    $this->actingAs($alice)->patchJson("/api/chat/messages/{$messageId}", ['body' => 'v2'])->assertOk();
    $this->actingAs($alice)->patchJson("/api/chat/messages/{$messageId}", ['body' => 'v3'])->assertOk();

    $this->actingAs($alice)
        ->patchJson("/api/chat/messages/{$messageId}", ['body' => 'v4'])
        ->assertStatus(422);

    $history = $this->actingAs($alice)->getJson("/api/chat/messages/{$messageId}/edits")->assertOk();
    expect($history->json('data'))->toHaveCount(2);
});

it('records each previous body in edit history, visible to any participant', function () {
    $alice = socialUser('alice-edit-history@example.com');
    $bob = socialUser('bob-edit-history@example.com');
    $conversationId = privateConversationBetween($alice, $bob);

    $messageId = $this->actingAs($alice)
        ->postJson("/api/chat/conversations/{$conversationId}/messages", ['type' => 'text', 'body' => 'first draft'])
        ->json('data.id');

    $this->actingAs($alice)
        ->patchJson("/api/chat/messages/{$messageId}", ['body' => 'second draft'])
        ->assertOk();

    $this->actingAs($alice)
        ->patchJson("/api/chat/messages/{$messageId}", ['body' => 'final'])
        ->assertOk();

    // Bob never sent or edited it, but can still see what it used to say.
    $history = $this->actingAs($bob)
        ->getJson("/api/chat/messages/{$messageId}/edits")
        ->assertOk()
        ->json('data');

    expect($history)->toHaveCount(2);
    expect(collect($history)->pluck('previous_body')->all())->toBe(['second draft', 'first draft']);
});

it('stars and unstars a message', function () {
    $alice = socialUser('alice-star@example.com');
    $bob = socialUser('bob-star@example.com');
    $conversationId = privateConversationBetween($alice, $bob);

    $messageId = $this->actingAs($alice)
        ->postJson("/api/chat/conversations/{$conversationId}/messages", ['type' => 'text', 'body' => 'important'])
        ->json('data.id');

    $this->actingAs($bob)->postJson("/api/chat/messages/{$messageId}/star")->assertNoContent();

    $starred = $this->actingAs($bob)->getJson('/api/chat/starred-messages')->assertOk();
    expect($starred->json('data'))->toHaveCount(1);

    // The embedded conversation.participants shape must match ParticipantResource
    // (chatable_type/chatable_id) — the frontend's chatableKeyOf() reads those exact keys to
    // figure out who the "other" participant of a starred DM is.
    $participant = $starred->json('data.0.conversation.participants.0');
    expect($participant)->toHaveKeys(['chatable_type', 'chatable_id'])
        ->and($participant)->not->toHaveKey('type');

    $this->actingAs($bob)->deleteJson("/api/chat/messages/{$messageId}/star")->assertNoContent();

    $starredAfter = $this->actingAs($bob)->getJson('/api/chat/starred-messages')->assertOk();
    expect($starredAfter->json('data'))->toHaveCount(0);
});

it('drops a starred message from the list once it becomes inaccessible to the viewer', function () {
    // The StarredMessage row outlives the message it points at becoming inaccessible — deleted
    // for everyone, deleted for me, expired, or the viewer having since left the conversation —
    // so without filtering, the starred list was a second, unfiltered path back to content
    // MessagePolicy::isAccessible() already blocks direct access to everywhere else.
    $alice = socialUser('alice-starvis@example.com');
    $bob = socialUser('bob-starvis@example.com');
    $carol = socialUser('carol-starvis@example.com');
    $conversationId = privateConversationBetween($alice, $bob);
    $groupId = $this->actingAs($alice)->postJson('/api/chat/conversations', [
        'type' => 'group',
        'name' => 'Starvis group',
        'participants' => chatableRefs([$bob, $carol]),
    ])->json('data.id');

    $deletedForMeId = $this->actingAs($alice)
        ->postJson("/api/chat/conversations/{$conversationId}/messages", ['type' => 'text', 'body' => 'del-for-me'])
        ->json('data.id');
    $deletedForEveryoneId = $this->actingAs($alice)
        ->postJson("/api/chat/conversations/{$conversationId}/messages", ['type' => 'text', 'body' => 'del-for-everyone'])
        ->json('data.id');
    $leftConversationId = $this->actingAs($carol)
        ->postJson("/api/chat/conversations/{$groupId}/messages", ['type' => 'text', 'body' => 'left-chat'])
        ->json('data.id');
    $stillVisibleId = $this->actingAs($alice)
        ->postJson("/api/chat/conversations/{$conversationId}/messages", ['type' => 'text', 'body' => 'still visible'])
        ->json('data.id');

    $this->actingAs($bob)->postJson("/api/chat/messages/{$deletedForMeId}/star")->assertNoContent();
    $this->actingAs($bob)->postJson("/api/chat/messages/{$deletedForEveryoneId}/star")->assertNoContent();
    $this->actingAs($bob)->postJson("/api/chat/messages/{$leftConversationId}/star")->assertNoContent();
    $this->actingAs($bob)->postJson("/api/chat/messages/{$stillVisibleId}/star")->assertNoContent();

    $this->actingAs($bob)->deleteJson("/api/chat/messages/{$deletedForMeId}/me")->assertNoContent();
    $this->actingAs($alice)->deleteJson("/api/chat/messages/{$deletedForEveryoneId}")->assertNoContent();
    $this->actingAs($bob)->postJson("/api/chat/conversations/{$groupId}/leave")->assertNoContent();

    $starred = $this->actingAs($bob)->getJson('/api/chat/starred-messages')->assertOk();
    $ids = collect($starred->json('data'))->pluck('id');

    // Asserting on IDs rather than body content: MessageResource nulls the body of a
    // deleted-for-everyone message regardless of whether the starred list filters it out,
    // so a body-based assertion for that case would pass trivially either way.
    expect($ids)->toContain($stillVisibleId)
        ->not->toContain($deletedForMeId, $deletedForEveryoneId, $leftConversationId);
});

it('pins and unpins a message, and rejects pinning beyond the per-conversation cap', function () {
    $alice = socialUser('alice-pin@example.com');
    $bob = socialUser('bob-pin@example.com');
    $conversationId = privateConversationBetween($alice, $bob);

    $messageIds = collect(range(1, 4))->map(fn ($n) => $this->actingAs($alice)
        ->postJson("/api/chat/conversations/{$conversationId}/messages", ['type' => 'text', 'body' => "msg {$n}"])
        ->json('data.id'));

    $messageIds->take(3)->each(
        fn ($messageId) => $this->actingAs($bob)->postJson("/api/chat/messages/{$messageId}/pin")->assertNoContent()
    );

    $pinned = $this->actingAs($bob)->getJson("/api/chat/conversations/{$conversationId}/pinned-messages")->assertOk();
    expect($pinned->json('data'))->toHaveCount(3);

    $this->actingAs($bob)->postJson("/api/chat/messages/{$messageIds->last()}/pin")->assertStatus(422);

    $this->actingAs($bob)->deleteJson("/api/chat/messages/{$messageIds->first()}/pin")->assertNoContent();

    $pinnedAfter = $this->actingAs($bob)->getJson("/api/chat/conversations/{$conversationId}/pinned-messages")->assertOk();
    expect($pinnedAfter->json('data'))->toHaveCount(2);
});

it('drops a pinned message from the list once it becomes inaccessible to the viewer', function () {
    // Same architectural gap as starred messages: a PinnedMessage row outlives the message it
    // points at becoming inaccessible to a *particular* viewer (deleted for me, or deleted for
    // everyone), so the pinned list needs its own filter rather than relying on the row simply
    // existing.
    $alice = socialUser('alice-pinvis@example.com');
    $bob = socialUser('bob-pinvis@example.com');
    $conversationId = privateConversationBetween($alice, $bob);

    $deletedForMeId = $this->actingAs($alice)
        ->postJson("/api/chat/conversations/{$conversationId}/messages", ['type' => 'text', 'body' => 'del-for-me'])
        ->json('data.id');
    $deletedForEveryoneId = $this->actingAs($alice)
        ->postJson("/api/chat/conversations/{$conversationId}/messages", ['type' => 'text', 'body' => 'del-for-everyone'])
        ->json('data.id');
    $stillVisibleId = $this->actingAs($alice)
        ->postJson("/api/chat/conversations/{$conversationId}/messages", ['type' => 'text', 'body' => 'still visible'])
        ->json('data.id');

    $this->actingAs($bob)->postJson("/api/chat/messages/{$deletedForMeId}/pin")->assertNoContent();
    $this->actingAs($bob)->postJson("/api/chat/messages/{$deletedForEveryoneId}/pin")->assertNoContent();
    $this->actingAs($bob)->postJson("/api/chat/messages/{$stillVisibleId}/pin")->assertNoContent();

    $this->actingAs($bob)->deleteJson("/api/chat/messages/{$deletedForMeId}/me")->assertNoContent();
    $this->actingAs($alice)->deleteJson("/api/chat/messages/{$deletedForEveryoneId}")->assertNoContent();

    // Bob deleted this one for himself, but Alice never touched it — she must still see it pinned.
    $alicesPinned = $this->actingAs($alice)->getJson("/api/chat/conversations/{$conversationId}/pinned-messages")->assertOk();
    expect(collect($alicesPinned->json('data'))->pluck('id'))->toContain($deletedForMeId);

    $bobsPinned = $this->actingAs($bob)->getJson("/api/chat/conversations/{$conversationId}/pinned-messages")->assertOk();
    $ids = collect($bobsPinned->json('data'))->pluck('id');

    // Asserting on IDs rather than body content: MessageResource nulls the body of a
    // deleted-for-everyone message regardless of whether the pinned list filters it out,
    // so a body-based assertion for that case would pass trivially either way.
    expect($ids)->toContain($stillVisibleId)
        ->not->toContain($deletedForMeId, $deletedForEveryoneId);
});

it('mutes, archives, and pins a conversation for the requesting participant only', function () {
    $alice = socialUser('alice-flags@example.com');
    $bob = socialUser('bob-flags@example.com');
    $conversationId = privateConversationBetween($alice, $bob);

    $muted = $this->actingAs($alice)
        ->patchJson("/api/chat/conversations/{$conversationId}/mute", ['muted_until' => now()->addHour()->toIso8601String()])
        ->assertOk();
    expect($muted->json('data.me.muted_until'))->not->toBeNull();

    $bobsView = $this->actingAs($bob)->getJson("/api/chat/conversations/{$conversationId}")->assertOk();
    expect($bobsView->json('data.me.muted_until'))->toBeNull();

    $this->actingAs($alice)
        ->patchJson("/api/chat/conversations/{$conversationId}/archive", ['archived' => true])
        ->assertOk()
        ->assertJsonPath('data.me.archived_at', fn ($value) => $value !== null);

    $this->actingAs($alice)
        ->patchJson("/api/chat/conversations/{$conversationId}/pin", ['pinned' => true])
        ->assertOk()
        ->assertJsonPath('data.me.pinned_at', fn ($value) => $value !== null);
});

it('sets a conversation wallpaper for the requesting participant only, then resets it back to null', function () {
    $alice = socialUser('alice-wallpaper@example.com');
    $bob = socialUser('bob-wallpaper@example.com');
    $conversationId = privateConversationBetween($alice, $bob);

    $this->actingAs($alice)
        ->patchJson("/api/chat/conversations/{$conversationId}/wallpaper", ['wallpaper' => 'lines|info'])
        ->assertOk()
        ->assertJsonPath('data.me.wallpaper', 'lines|info');

    // Bob's own view of the same conversation is unaffected — wallpaper is per-participant, not
    // shared conversation state.
    $bobsView = $this->actingAs($bob)->getJson("/api/chat/conversations/{$conversationId}")->assertOk();
    expect($bobsView->json('data.me.wallpaper'))->toBeNull();

    // The "Reset to default" action in the picker sends wallpaper: null — must actually clear the
    // column back to null (falls back to the client's own default wallpaper preference), not get
    // rejected or silently coerced to an empty string.
    $this->actingAs($alice)
        ->patchJson("/api/chat/conversations/{$conversationId}/wallpaper", ['wallpaper' => null])
        ->assertOk()
        ->assertJsonPath('data.me.wallpaper', null);
});

it('marks a conversation manually unread without disturbing read receipts, then clears it on mark-read', function () {
    $alice = socialUser('alice-unread@example.com');
    $bob = socialUser('bob-unread@example.com');
    $conversationId = privateConversationBetween($alice, $bob);

    $messageId = $this->actingAs($bob)
        ->postJson("/api/chat/conversations/{$conversationId}/messages", ['type' => 'text', 'body' => 'hi'])
        ->json('data.id');

    // Alice genuinely reads it — Bob's message now shows as read to Bob.
    $this->actingAs($alice)->postJson("/api/chat/conversations/{$conversationId}/receipts/read", [
        'up_to_message_id' => $messageId,
    ])->assertNoContent();

    $bobsReadStatus = $this->actingAs($bob)
        ->getJson("/api/chat/conversations/{$conversationId}/messages")
        ->json('data.0.status');
    expect($bobsReadStatus)->toBe('read');

    // Alice marks it unread again — purely cosmetic on her side.
    $this->actingAs($alice)
        ->patchJson("/api/chat/conversations/{$conversationId}/unread", ['unread' => true])
        ->assertOk()
        ->assertJsonPath('data.unread_count', 1);

    // Bob's read receipt must be untouched — marking unread never un-reads anything for Bob.
    $bobsReadStatusAfter = $this->actingAs($bob)
        ->getJson("/api/chat/conversations/{$conversationId}/messages")
        ->json('data.0.status');
    expect($bobsReadStatusAfter)->toBe('read');

    // Opening the conversation again (the normal mark-read flow) clears the manual flag.
    $this->actingAs($alice)->postJson("/api/chat/conversations/{$conversationId}/receipts/read", [
        'up_to_message_id' => $messageId,
    ])->assertNoContent();

    $this->actingAs($alice)
        ->getJson("/api/chat/conversations/{$conversationId}")
        ->assertJsonPath('data.unread_count', 0);
});

it('excludes an expired disappearing message from GET /conversations/{id}\'s unread_count', function () {
    // ConversationRepository::unreadCountsFor() (the batched path the conversation list uses)
    // already excluded expired messages — but ConversationResource::unreadCountFor(), the
    // fallback this single-conversation endpoint falls through to when unread_count isn't
    // already precomputed, didn't, so the two paths could disagree on the same conversation.
    $alice = socialUser('alice-unreadexpired@example.com');
    $bob = socialUser('bob-unreadexpired@example.com');
    $conversationId = privateConversationBetween($alice, $bob);

    $this->actingAs($bob)
        ->patchJson("/api/chat/conversations/{$conversationId}/disappearing", ['ttl_seconds' => 60])
        ->assertOk();

    $messageId = $this->actingAs($bob)
        ->postJson("/api/chat/conversations/{$conversationId}/messages", ['type' => 'text', 'body' => 'vanish soon'])
        ->json('data.id');

    Message::query()->whereKey($messageId)->update(['expires_at' => now()->subMinute()]);

    $this->actingAs($alice)
        ->getJson("/api/chat/conversations/{$conversationId}")
        ->assertJsonPath('data.unread_count', 0);
});

it('favourites a conversation independently of pinning it', function () {
    $alice = socialUser('alice-fav@example.com');
    $bob = socialUser('bob-fav@example.com');
    $conversationId = privateConversationBetween($alice, $bob);

    $this->actingAs($alice)
        ->patchJson("/api/chat/conversations/{$conversationId}/favourite", ['favourited' => true])
        ->assertOk()
        ->assertJsonPath('data.me.favourited_at', fn ($value) => $value !== null)
        ->assertJsonPath('data.me.pinned_at', fn ($value) => $value === null);

    $bobsView = $this->actingAs($bob)->getJson("/api/chat/conversations/{$conversationId}")->assertOk();
    expect($bobsView->json('data.me.favourited_at'))->toBeNull();

    $this->actingAs($alice)
        ->patchJson("/api/chat/conversations/{$conversationId}/favourite", ['favourited' => false])
        ->assertOk()
        ->assertJsonPath('data.me.favourited_at', fn ($value) => $value === null);
});
