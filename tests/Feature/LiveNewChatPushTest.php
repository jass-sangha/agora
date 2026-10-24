<?php

use Chat\Events\ConversationCreated;
use Chat\Events\MessageSent;
use Chat\Events\ParticipantAdded;
use Chat\Tests\Fixtures\User;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Support\Facades\Event;

function pushUser(string $email): User
{
    return User::query()->create(['name' => $email, 'email' => $email, 'password' => bcrypt('secret')]);
}

it('broadcasts ConversationCreated onto each participants private chatable channel', function () {
    Event::fake([ConversationCreated::class]);

    $alice = pushUser('alice-push@example.com');
    $bob = pushUser('bob-push@example.com');

    $this->actingAs($alice)->postJson('/api/chat/conversations', [
        'type' => 'private',
        'participants' => [chatableRef($bob)],
    ])->assertCreated();

    Event::assertDispatched(ConversationCreated::class, function (ConversationCreated $event) use ($alice, $bob) {
        $channelNames = collect($event->broadcastOn())->map(fn (PrivateChannel $c) => $c->name);

        return $channelNames->contains("private-chatable.user.{$alice->id}")
            && $channelNames->contains("private-chatable.user.{$bob->id}");
    });
});

it('broadcasts ParticipantAdded onto the conversation channel and each new participants private channel', function () {
    Event::fake([ParticipantAdded::class]);

    $alice = pushUser('alice-push-grp@example.com');
    $bob = pushUser('bob-push-grp@example.com');
    $carol = pushUser('carol-push-grp@example.com');

    $conversationId = $this->actingAs($alice)->postJson('/api/chat/conversations', [
        'type' => 'group',
        'name' => 'Push test',
        'participants' => [chatableRef($bob)],
    ])->json('data.id');

    $this->actingAs($alice)->postJson("/api/chat/conversations/{$conversationId}/participants", [
        'participants' => [chatableRef($carol)],
    ])->assertOk();

    Event::assertDispatched(ParticipantAdded::class, function (ParticipantAdded $event) use ($carol) {
        $channelNames = collect($event->broadcastOn())->map(fn ($c) => $c->name);

        return $channelNames->contains("private-chatable.user.{$carol->id}");
    });
});

it('broadcasts MessageSent onto the conversation channel and the recipients own private channel', function () {
    Event::fake([MessageSent::class]);

    $alice = pushUser('alice-push-msg@example.com');
    $bob = pushUser('bob-push-msg@example.com');

    $conversationId = $this->actingAs($alice)->postJson('/api/chat/conversations', [
        'type' => 'private',
        'participants' => [chatableRef($bob)],
    ])->json('data.id');

    $this->actingAs($alice)->postJson("/api/chat/conversations/{$conversationId}/messages", [
        'type' => 'text',
        'body' => 'hey bob',
    ])->assertCreated();

    // Without both of these, the recipient only learns about a new message while that specific
    // conversation happens to be the one open in their ChatWindow — everywhere else (the sidebar
    // list for a conversation not currently open) silently never updates until a manual reload.
    Event::assertDispatched(MessageSent::class, function (MessageSent $event) use ($conversationId, $alice, $bob) {
        $channelNames = collect($event->broadcastOn())->map(fn ($c) => $c->name);

        return $channelNames->contains((new PresenceChannel("conversation.{$conversationId}"))->name)
            && $channelNames->contains("private-chatable.user.{$bob->id}")
            // The sender's own personal channel shouldn't be a broadcast target here — their
            // own UI already updates optimistically off the HTTP response, not the broadcast.
            && ! $channelNames->contains("private-chatable.user.{$alice->id}");
    });
});

it('never includes a reply-to message\'s body/metadata/attachments in the MessageSent broadcast payload', function () {
    // MessageResource's REST reply_to block correctly redacts body/metadata/attachments once
    // replyTargetDeletedForViewer() finds the *viewer* deleted the reply target for
    // themselves — a per-viewer check with no equivalent slot in a broadcast frame, which is
    // serialized once and pushed as-is to every recipient on the conversation's presence
    // channel at once. The broadcast used to ship the content regardless, leaking it straight
    // back to a viewer who'd deleted it for themselves the moment anyone replied to it. It must
    // never include that content at all now, not even when nothing was deleted — real content
    // only ever comes from the (correctly redacting) REST endpoint.
    Event::fake([MessageSent::class]);

    $alice = pushUser('alice-replyleak@example.com');
    $bob = pushUser('bob-replyleak@example.com');

    $conversationId = $this->actingAs($alice)->postJson('/api/chat/conversations', [
        'type' => 'private',
        'participants' => [chatableRef($bob)],
    ])->json('data.id');

    $originalId = $this->actingAs($alice)
        ->postJson("/api/chat/conversations/{$conversationId}/messages", ['type' => 'text', 'body' => 'secret original text'])
        ->json('data.id');

    $this->actingAs($bob)->postJson("/api/chat/conversations/{$conversationId}/messages", [
        'type' => 'text',
        'body' => 'replying',
        'reply_to_message_id' => $originalId,
    ])->assertCreated();

    Event::assertDispatched(MessageSent::class, function (MessageSent $event) use ($originalId) {
        $replyTo = $event->broadcastWith()['reply_to'];

        if ($replyTo === null) {
            return false;
        }

        return $replyTo['id'] === $originalId
            && ! array_key_exists('body', $replyTo)
            && ! array_key_exists('metadata', $replyTo)
            && ! array_key_exists('attachments', $replyTo);
    });
});
