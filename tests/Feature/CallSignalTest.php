<?php

use Chat\Events\CallSignal;
use Chat\Tests\Fixtures\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

const CALL_SIGNAL_TEST_ID = '00000000-0000-4000-8000-000000000001';
const CALL_SIGNAL_TEST_STARTED_AT = 1_800_000_000_000;
const CALL_SIGNAL_TEST_INITIATOR = 'user:1';

beforeEach(function () {
    config(['chat.calls.enabled' => true]);
});

function callSignalUser(string $email): User
{
    return User::query()->create(['name' => $email, 'email' => $email, 'password' => bcrypt('secret')]);
}

it('relays a call signal to the conversation channel without writing to the database', function () {
    $alice = callSignalUser('alice-call@example.com');
    $bob = callSignalUser('bob-call@example.com');

    $conversationId = $this->actingAs($alice)->postJson('/api/chat/conversations', [
        'type' => 'private',
        'participants' => [chatableRef($bob)],
    ])->json('data.id');

    Event::fake();
    DB::enableQueryLog();

    $this->actingAs($alice)
        ->postJson("/api/chat/conversations/{$conversationId}/call/signal", [
            'payload' => [
                'kind' => 'join',
                'video' => false,
                'call_id' => CALL_SIGNAL_TEST_ID,
                'call_started_at' => CALL_SIGNAL_TEST_STARTED_AT,
                'call_initiator' => CALL_SIGNAL_TEST_INITIATOR,
            ],
        ])
        ->assertNoContent();

    $writes = collect(DB::getQueryLog())->filter(
        fn ($entry) => str_starts_with(strtolower($entry['query']), 'insert')
            || str_starts_with(strtolower($entry['query']), 'update')
            || str_starts_with(strtolower($entry['query']), 'delete')
    );

    expect($writes)->toHaveCount(0);

    Event::assertDispatched(CallSignal::class, function (CallSignal $event) use ($conversationId) {
        return $event->conversationId === $conversationId
            && $event->payload === [
                'kind' => 'join',
                'video' => false,
                'call_id' => CALL_SIGNAL_TEST_ID,
                'call_started_at' => CALL_SIGNAL_TEST_STARTED_AT,
                'call_initiator' => CALL_SIGNAL_TEST_INITIATOR,
            ];
    });

    DB::disableQueryLog();
});

it('relays a targeted call signal to only the specified recipient, for a group call mesh', function () {
    $alice = callSignalUser('alice-call-3@example.com');
    $bob = callSignalUser('bob-call-3@example.com');
    $carol = callSignalUser('carol-call-3@example.com');

    $conversationId = $this->actingAs($alice)->postJson('/api/chat/conversations', [
        'type' => 'group',
        'name' => 'Call mesh group',
        'participants' => chatableRefs([$bob, $carol]),
    ])->json('data.id');

    Event::fake();

    $this->actingAs($alice)
        ->postJson("/api/chat/conversations/{$conversationId}/call/signal", [
            'payload' => [
                'kind' => 'offer',
                'call_id' => CALL_SIGNAL_TEST_ID,
                'call_started_at' => CALL_SIGNAL_TEST_STARTED_AT,
                'call_initiator' => CALL_SIGNAL_TEST_INITIATOR,
                'sdp' => 'v=0...',
            ],
            'to_type' => $bob->getMorphClass(),
            'to_id' => $bob->getKey(),
        ])
        ->assertNoContent();

    Event::assertDispatched(CallSignal::class, function (CallSignal $event) use ($conversationId, $bob, $carol) {
        return $event->conversationId === $conversationId
            && $event->recipientChannels === ["chatable.{$bob->getMorphClass()}.{$bob->getKey()}"]
            && ! in_array("chatable.{$carol->getMorphClass()}.{$carol->getKey()}", $event->recipientChannels, true);
    });
});

it('rejects malformed or untargeted peer negotiation signals', function () {
    $alice = callSignalUser('alice-call-validation@example.com');
    $bob = callSignalUser('bob-call-validation@example.com');

    $conversationId = $this->actingAs($alice)->postJson('/api/chat/conversations', [
        'type' => 'private',
        'participants' => [chatableRef($bob)],
    ])->json('data.id');

    $this->actingAs($alice)
        ->postJson("/api/chat/conversations/{$conversationId}/call/signal", [
            'payload' => [
                'kind' => 'offer',
                'call_id' => CALL_SIGNAL_TEST_ID,
                'call_started_at' => CALL_SIGNAL_TEST_STARTED_AT,
                'call_initiator' => CALL_SIGNAL_TEST_INITIATOR,
                'sdp' => 'v=0...',
            ],
        ])
        ->assertInvalid(['to_type', 'to_id']);

    $this->actingAs($alice)
        ->postJson("/api/chat/conversations/{$conversationId}/call/signal", [
            'payload' => [
                'kind' => 'unknown',
                'call_id' => CALL_SIGNAL_TEST_ID,
                'call_started_at' => CALL_SIGNAL_TEST_STARTED_AT,
                'call_initiator' => CALL_SIGNAL_TEST_INITIATOR,
            ],
        ])
        ->assertInvalid(['payload.kind']);

    $this->actingAs($alice)
        ->postJson("/api/chat/conversations/{$conversationId}/call/signal", [
            'payload' => [
                'kind' => 'ice',
                'call_id' => CALL_SIGNAL_TEST_ID,
                'call_started_at' => CALL_SIGNAL_TEST_STARTED_AT,
                'call_initiator' => CALL_SIGNAL_TEST_INITIATOR,
                'candidate' => ['candidate' => 'candidate:1'],
            ],
            'to_type' => $bob->getMorphClass(),
            'to_id' => 999999,
        ])
        ->assertNotFound();
});

it('returns authenticated short-lived coturn credentials without exposing the shared secret', function () {
    $alice = callSignalUser('alice-turn@example.com');
    config([
        'chat.calls.ice_servers' => [['urls' => 'stun:stun.example.com:3478']],
        'chat.calls.turn.urls' => ['turn:turn.example.com:3478', 'turns:turn.example.com:5349'],
        'chat.calls.turn.shared_secret' => 'server-only-secret',
        'chat.calls.turn.credential_ttl' => 900,
    ]);
    $this->travelTo(now()->startOfSecond());
    $expiresAt = now()->timestamp + 900;
    $username = $expiresAt.':'.$alice->getAuthIdentifier();
    $credential = base64_encode(hash_hmac('sha1', $username, 'server-only-secret', true));

    $response = $this->actingAs($alice)
        ->getJson('/api/chat/calls/ice-servers')
        ->assertSuccessful()
        ->assertJsonPath('ice_servers.0.urls', 'stun:stun.example.com:3478')
        ->assertJsonPath('ice_servers.1.urls.0', 'turn:turn.example.com:3478')
        ->assertJsonPath('ice_servers.1.username', $username)
        ->assertJsonPath('ice_servers.1.credential', $credential);

    expect($response->getContent())->not->toContain('server-only-secret');
});

it('disables call signaling and ICE configuration when calls are disabled', function () {
    $alice = callSignalUser('alice-disabled-call@example.com');
    $bob = callSignalUser('bob-disabled-call@example.com');

    $conversationId = $this->actingAs($alice)->postJson('/api/chat/conversations', [
        'type' => 'private',
        'participants' => [chatableRef($bob)],
    ])->json('data.id');

    config(['chat.calls.enabled' => false]);

    $this->actingAs($alice)
        ->getJson('/api/chat/calls/ice-servers')
        ->assertNotFound();

    $this->actingAs($alice)
        ->postJson("/api/chat/conversations/{$conversationId}/call/signal", [
            'payload' => [
                'kind' => 'join',
                'call_id' => CALL_SIGNAL_TEST_ID,
                'call_started_at' => CALL_SIGNAL_TEST_STARTED_AT,
                'call_initiator' => CALL_SIGNAL_TEST_INITIATOR,
            ],
        ])
        ->assertNotFound();
});

it('refuses to relay a call signal for a non-participant', function () {
    $alice = callSignalUser('alice-call-2@example.com');
    $bob = callSignalUser('bob-call-2@example.com');
    $eve = callSignalUser('eve-call-2@example.com');

    $conversationId = $this->actingAs($alice)->postJson('/api/chat/conversations', [
        'type' => 'private',
        'participants' => [chatableRef($bob)],
    ])->json('data.id');

    $this->actingAs($eve)
        ->postJson("/api/chat/conversations/{$conversationId}/call/signal", [
            'payload' => ['type' => 'offer', 'sdp' => 'v=0...'],
        ])
        ->assertForbidden();
});

it('rejects a call signal payload larger than the configured byte limit', function () {
    // A real SDP offer/answer or ICE candidate is a few KB at most — nothing previously bounded
    // 'payload' beyond "must be an array", so an arbitrarily large nested structure would get
    // broadcast to another participant's channel on every call.
    $alice = callSignalUser('alice-call-3@example.com');
    $bob = callSignalUser('bob-call-3@example.com');

    $conversationId = $this->actingAs($alice)->postJson('/api/chat/conversations', [
        'type' => 'private',
        'participants' => [chatableRef($bob)],
    ])->json('data.id');

    $oversized = [
        'kind' => 'offer',
        'call_id' => CALL_SIGNAL_TEST_ID,
        'call_started_at' => CALL_SIGNAL_TEST_STARTED_AT,
        'call_initiator' => CALL_SIGNAL_TEST_INITIATOR,
        'sdp' => str_repeat('x', config('chat.calls.max_payload_bytes', 65536) + 1),
    ];

    $this->actingAs($alice)
        ->postJson("/api/chat/conversations/{$conversationId}/call/signal", [
            'payload' => $oversized,
            'to_type' => $bob->getMorphClass(),
            'to_id' => $bob->getKey(),
        ])
        ->assertInvalid(['payload']);

    // A payload right at the limit still goes through.
    $validPayload = [
        'kind' => 'offer',
        'call_id' => CALL_SIGNAL_TEST_ID,
        'call_started_at' => CALL_SIGNAL_TEST_STARTED_AT,
        'call_initiator' => CALL_SIGNAL_TEST_INITIATOR,
        'sdp' => str_repeat('x', config('chat.calls.max_payload_bytes', 65536) - 200),
    ];

    $this->actingAs($alice)
        ->postJson("/api/chat/conversations/{$conversationId}/call/signal", [
            'payload' => $validPayload,
            'to_type' => $bob->getMorphClass(),
            'to_id' => $bob->getKey(),
        ])
        ->assertNoContent();
});
