<?php

use Chat\Models\Message;
use Chat\Models\MessageAttachment;
use Chat\Notifications\NewChatMessageNotification;
use Chat\Tests\Fixtures\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;

function m6User(string $email): User
{
    return User::query()->create(['name' => $email, 'email' => $email, 'password' => bcrypt('secret')]);
}

it('searches messages scoped to conversations the user participates in', function () {
    $alice = m6User('alice-search@example.com');
    $bob = m6User('bob-search@example.com');
    $eve = m6User('eve-search@example.com');

    $chatAB = $this->actingAs($alice)->postJson('/api/chat/conversations', [
        'type' => 'private',
        'participants' => [chatableRef($bob)],
    ])->json('data.id');

    $chatAE = $this->actingAs($alice)->postJson('/api/chat/conversations', [
        'type' => 'private',
        'participants' => [chatableRef($eve)],
    ])->json('data.id');

    $this->actingAs($alice)->postJson("/api/chat/conversations/{$chatAB}/messages", [
        'type' => 'text', 'body' => 'let us meet at the pizzeria tonight',
    ])->assertCreated();

    $this->actingAs($alice)->postJson("/api/chat/conversations/{$chatAE}/messages", [
        'type' => 'text', 'body' => 'completely unrelated topic',
    ])->assertCreated();

    $results = $this->actingAs($bob)->getJson('/api/chat/messages/search?q=pizzeria')->assertOk();

    expect($results->json('data'))->toHaveCount(1)
        ->and($results->json('data.0.body'))->toContain('pizzeria');

    // Bob is not a participant of chatAE — searching cannot surface it even with a matching term.
    $crossChatLeak = $this->actingAs($bob)->getJson('/api/chat/messages/search?q=unrelated')->assertOk();
    expect($crossChatLeak->json('data'))->toHaveCount(0);
});

it('prunes disappearing messages once their ttl has elapsed', function () {
    $alice = m6User('alice-disappear@example.com');
    $bob = m6User('bob-disappear@example.com');

    $conversationId = $this->actingAs($alice)->postJson('/api/chat/conversations', [
        'type' => 'private',
        'participants' => [chatableRef($bob)],
    ])->json('data.id');

    $this->actingAs($alice)
        ->patchJson("/api/chat/conversations/{$conversationId}/disappearing", ['ttl_seconds' => 60])
        ->assertOk();

    $messageId = $this->actingAs($alice)
        ->postJson("/api/chat/conversations/{$conversationId}/messages", ['type' => 'text', 'body' => 'vanish soon'])
        ->json('data.id');

    expect(Message::query()->find($messageId)->expires_at)->not->toBeNull();

    // Simulate elapsed TTL without waiting real time.
    Message::query()->whereKey($messageId)->update(['expires_at' => now()->subMinute()]);

    Artisan::call('chat:prune-expired-messages');

    expect(Message::query()->find($messageId))->toBeNull();

    $list = $this->actingAs($bob)->getJson("/api/chat/conversations/{$conversationId}/messages")->assertOk();
    expect(collect($list->json('data'))->pluck('id'))->not->toContain($messageId);
});

it('hides an expired disappearing message immediately, before the prune command ever runs', function () {
    // chat:prune-expired-messages only physically deletes expired rows on whatever schedule the
    // host configures (see README's "Scheduled commands") — it isn't wired up automatically.
    // Between expires_at passing and that command next running (or never, if a host forgets to
    // schedule it), the row still exists. A disappearing message's TTL promise can't depend on
    // that job's timing: every read path needs to already treat it as gone, and direct access by
    // id needs to be blocked the same way "delete for me" is.
    $alice = m6User('alice-expiredview@example.com');
    $bob = m6User('bob-expiredview@example.com');

    $conversationId = $this->actingAs($alice)->postJson('/api/chat/conversations', [
        'type' => 'private',
        'participants' => [chatableRef($bob)],
    ])->json('data.id');

    $this->actingAs($alice)
        ->patchJson("/api/chat/conversations/{$conversationId}/disappearing", ['ttl_seconds' => 60])
        ->assertOk();

    $messageId = $this->actingAs($alice)
        ->postJson("/api/chat/conversations/{$conversationId}/messages", ['type' => 'text', 'body' => 'vanish soon'])
        ->json('data.id');

    // Elapse the TTL without running the prune command — the row still exists in the database.
    Message::query()->whereKey($messageId)->update(['expires_at' => now()->subMinute()]);
    expect(Message::query()->find($messageId))->not->toBeNull();

    // Timeline, search, media, and the conversation preview must all already exclude it.
    $list = $this->actingAs($bob)->getJson("/api/chat/conversations/{$conversationId}/messages")->assertOk();
    expect(collect($list->json('data'))->pluck('id'))->not->toContain($messageId);

    $search = $this->actingAs($bob)->getJson('/api/chat/messages/search?q=vanish')->assertOk();
    expect($search->json('data'))->toBeEmpty();

    $conversation = $this->actingAs($bob)->getJson("/api/chat/conversations/{$conversationId}")->assertOk();
    expect($conversation->json('data.last_message'))->toBeNull();

    // Direct access by id must also 403, the same as a message deleted for the viewer.
    $this->actingAs($bob)->getJson("/api/chat/messages/{$messageId}/receipts")->assertForbidden();
    $this->actingAs($bob)->postJson("/api/chat/messages/{$messageId}/reactions", ['emoji' => '👍'])->assertForbidden();
    $this->actingAs($bob)->postJson("/api/chat/messages/{$messageId}/forward", [
        'conversation_ids' => [$conversationId],
    ])->assertForbidden();
});

it("deletes an expired message's attachment file from disk, not just the database rows", function () {
    Storage::fake('chat');

    $alice = m6User('alice-disappearmedia@example.com');
    $bob = m6User('bob-disappearmedia@example.com');

    $conversationId = $this->actingAs($alice)->postJson('/api/chat/conversations', [
        'type' => 'private',
        'participants' => [chatableRef($bob)],
    ])->json('data.id');

    $this->actingAs($alice)
        ->patchJson("/api/chat/conversations/{$conversationId}/disappearing", ['ttl_seconds' => 60])
        ->assertOk();

    $attachmentId = $this->actingAs($alice)->postJson('/api/chat/attachments', [
        'file' => UploadedFile::fake()->image('vanish.jpg', 200, 200),
    ])->json('data.id');

    $messageId = $this->actingAs($alice)->postJson("/api/chat/conversations/{$conversationId}/messages", [
        'type' => 'image', 'attachment_ids' => [$attachmentId],
    ])->json('data.id');

    expect(Storage::disk('chat')->allFiles())->toHaveCount(1);

    Message::query()->whereKey($messageId)->update(['expires_at' => now()->subMinute()]);

    Artisan::call('chat:prune-expired-messages');

    expect(Storage::disk('chat')->allFiles())->toHaveCount(0);
});

it('prunes attachments that were uploaded but never attached to a message', function () {
    Storage::fake('chat');

    $alice = m6User('alice-orphan@example.com');

    $this->actingAs($alice)->postJson('/api/chat/attachments', [
        'file' => UploadedFile::fake()->image('never-sent.jpg', 200, 200),
    ])->assertCreated();

    expect(Storage::disk('chat')->allFiles())->toHaveCount(1);

    // Not old enough yet — chat.media.orphan_ttl_minutes defaults to 1440 (24h). The orphan
    // sweep runs as part of chat:prune-expired-messages (merged so hosts only schedule one
    // command), not a separate command.
    Artisan::call('chat:prune-expired-messages');
    expect(Storage::disk('chat')->allFiles())->toHaveCount(1);

    MessageAttachment::query()->update(['created_at' => now()->subDays(2)]);

    Artisan::call('chat:prune-expired-messages');
    expect(Storage::disk('chat')->allFiles())->toHaveCount(0);
});

it('notifies other participants when a message is sent', function () {
    Notification::fake();

    $alice = m6User('alice-notify@example.com');
    $bob = m6User('bob-notify@example.com');

    $conversationId = $this->actingAs($alice)->postJson('/api/chat/conversations', [
        'type' => 'private',
        'participants' => [chatableRef($bob)],
    ])->json('data.id');

    $this->actingAs($alice)
        ->postJson("/api/chat/conversations/{$conversationId}/messages", ['type' => 'text', 'body' => 'ping'])
        ->assertCreated();

    Notification::assertSentTo($bob, NewChatMessageNotification::class);
    Notification::assertNotSentTo($alice, NewChatMessageNotification::class);
});

it('hides archived conversations from the default list and includes them when requested', function () {
    $alice = m6User('alice-archivelist@example.com');
    $bob = m6User('bob-archivelist@example.com');

    $conversationId = $this->actingAs($alice)->postJson('/api/chat/conversations', [
        'type' => 'private',
        'participants' => [chatableRef($bob)],
    ])->json('data.id');

    $this->actingAs($alice)->patchJson("/api/chat/conversations/{$conversationId}/archive", ['archived' => true])->assertOk();

    $defaultList = $this->actingAs($alice)->getJson('/api/chat/conversations')->assertOk();
    expect(collect($defaultList->json('data'))->pluck('id'))->not->toContain($conversationId);

    $archivedList = $this->actingAs($alice)->getJson('/api/chat/conversations?archived=1')->assertOk();
    expect(collect($archivedList->json('data'))->pluck('id'))->toContain($conversationId);
});
