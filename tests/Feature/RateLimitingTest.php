<?php

use Chat\Tests\Fixtures\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

function rateLimitUser(string $email): User
{
    return User::query()->create(['name' => $email, 'email' => $email, 'password' => bcrypt('secret')]);
}

/**
 * Reads the configured max attempts off a `throttle:max,decayMinutes,prefix` middleware
 * string, so these tests track config/chat.php's actual limits instead of duplicating them.
 */
function throttleMax(string $configKey): int
{
    preg_match('/throttle:(\d+),/', config($configKey), $matches);

    return (int) $matches[1];
}

it('throttles message sends past the configured per-minute limit', function () {
    $alice = rateLimitUser('alice-rl-send@example.com');
    $bob = rateLimitUser('bob-rl-send@example.com');

    $conversationId = $this->actingAs($alice)->postJson('/api/chat/conversations', [
        'type' => 'private',
        'participants' => [chatableRef($bob)],
    ])->json('data.id');

    $max = throttleMax('chat.rate_limits.message_send');

    for ($i = 0; $i < $max; $i++) {
        $this->actingAs($alice)->postJson("/api/chat/conversations/{$conversationId}/messages", [
            'type' => 'text',
            'body' => "message {$i}",
        ])->assertCreated();
    }

    $this->actingAs($alice)->postJson("/api/chat/conversations/{$conversationId}/messages", [
        'type' => 'text',
        'body' => 'one too many',
    ])->assertStatus(429);
});

it('throttles attachment uploads past the configured per-minute limit', function () {
    Storage::fake('chat');

    $alice = rateLimitUser('alice-rl-attach@example.com');
    $max = throttleMax('chat.rate_limits.attachment_upload');

    for ($i = 0; $i < $max; $i++) {
        $this->actingAs($alice)->postJson('/api/chat/attachments', [
            'file' => UploadedFile::fake()->image("photo{$i}.jpg", 200, 200),
        ])->assertCreated();
    }

    $this->actingAs($alice)->postJson('/api/chat/attachments', [
        'file' => UploadedFile::fake()->image('one-too-many.jpg', 200, 200),
    ])->assertStatus(429);
});

it('throttles message search past the configured per-minute limit', function () {
    $alice = rateLimitUser('alice-rl-search@example.com');
    $max = throttleMax('chat.rate_limits.search');

    for ($i = 0; $i < $max; $i++) {
        $this->actingAs($alice)->getJson('/api/chat/messages/search?q=findme')->assertOk();
    }

    $this->actingAs($alice)->getJson('/api/chat/messages/search?q=findme')->assertStatus(429);
});

it('throttles reactions past the configured per-minute limit', function () {
    $alice = rateLimitUser('alice-rl-react@example.com');
    $bob = rateLimitUser('bob-rl-react@example.com');

    $conversationId = $this->actingAs($alice)->postJson('/api/chat/conversations', [
        'type' => 'private',
        'participants' => [chatableRef($bob)],
    ])->json('data.id');

    $messageId = $this->actingAs($alice)->postJson("/api/chat/conversations/{$conversationId}/messages", [
        'type' => 'text',
        'body' => 'react to me',
    ])->json('data.id');

    $max = throttleMax('chat.rate_limits.reactions');

    for ($i = 0; $i < $max; $i++) {
        $this->actingAs($bob)->postJson("/api/chat/messages/{$messageId}/reactions", ['emoji' => '👍'])->assertOk();
    }

    $this->actingAs($bob)->postJson("/api/chat/messages/{$messageId}/reactions", ['emoji' => '👍'])->assertStatus(429);
});

it('keeps each rate-limited action on its own throttle bucket', function () {
    // Regression guard for the shared-key bug this throttle setup used to have: Laravel's
    // default throttle key is just the authenticated user id, with no route component, so two
    // `throttle:...` middlewares with no distinguishing prefix silently share one counter —
    // exhausting one action's limit would then also 429 an unrelated action for the same user.
    // Every entry in chat.rate_limits (and link-preview's own throttle) carries a unique
    // prefix specifically to prevent that.
    $alice = rateLimitUser('alice-rl-isolated@example.com');
    $bob = rateLimitUser('bob-rl-isolated@example.com');

    $conversationId = $this->actingAs($alice)->postJson('/api/chat/conversations', [
        'type' => 'private',
        'participants' => [chatableRef($bob)],
    ])->json('data.id');

    $sendMax = throttleMax('chat.rate_limits.message_send');

    for ($i = 0; $i < $sendMax; $i++) {
        $this->actingAs($alice)->postJson("/api/chat/conversations/{$conversationId}/messages", [
            'type' => 'text',
            'body' => "message {$i}",
        ])->assertCreated();
    }

    $this->actingAs($alice)->postJson("/api/chat/conversations/{$conversationId}/messages", [
        'type' => 'text',
        'body' => 'one too many',
    ])->assertStatus(429);

    // Same user, same minute, but a different action — must still go through.
    $this->actingAs($alice)->getJson('/api/chat/messages/search?q=findme')->assertOk();
});
