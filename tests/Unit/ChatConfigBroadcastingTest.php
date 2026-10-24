<?php

use Chat\Support\ChatConfig;
use Chat\Traits\BroadcastsChatEvents;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;

it('trusts an explicit broadcasting.default of reverb, pusher, or ably even without a key set yet', function (string $driver) {
    config(['broadcasting.default' => $driver]);
    config(["broadcasting.connections.{$driver}" => null]);

    expect(ChatConfig::build(null)['broadcasting']['driver'])->toBe($driver);
})->with(['reverb', 'pusher', 'ably']);

it('surfaces the key of the explicitly configured driver, not any other configured one', function () {
    config(['broadcasting.default' => 'ably']);
    config(['broadcasting.connections.ably' => ['driver' => 'ably', 'key' => 'ably-key']]);
    config(['broadcasting.connections.pusher' => ['driver' => 'pusher', 'key' => 'pusher-key']]);

    $broadcasting = ChatConfig::build(null)['broadcasting'];

    expect($broadcasting['driver'])->toBe('ably')
        ->and($broadcasting['key'])->toBe('ably-key');
});

it('auto-detects pusher when broadcasting.default is unsupported/unset but only pusher has a key', function () {
    config(['broadcasting.default' => 'log']);
    config(['broadcasting.connections.reverb' => null]);
    config(['broadcasting.connections.pusher' => ['driver' => 'pusher', 'key' => 'pusher-key', 'options' => ['cluster' => 'eu']]]);
    config(['broadcasting.connections.ably' => null]);

    $broadcasting = ChatConfig::build(null)['broadcasting'];

    expect($broadcasting['driver'])->toBe('pusher')
        ->and($broadcasting['key'])->toBe('pusher-key')
        ->and($broadcasting['cluster'])->toBe('eu');
});

it('auto-detects ably when broadcasting.default is unsupported/unset but only ably has a key', function () {
    config(['broadcasting.default' => 'log']);
    config(['broadcasting.connections.reverb' => null]);
    config(['broadcasting.connections.pusher' => null]);
    config(['broadcasting.connections.ably' => ['driver' => 'ably', 'key' => 'ably-key']]);

    $broadcasting = ChatConfig::build(null)['broadcasting'];

    expect($broadcasting['driver'])->toBe('ably')
        ->and($broadcasting['key'])->toBe('ably-key');
});

it('falls back to reverb when broadcasting.default is unsupported/unset and nothing has a key configured', function () {
    config(['broadcasting.default' => 'log']);
    config(['broadcasting.connections.reverb' => null]);
    config(['broadcasting.connections.pusher' => null]);
    config(['broadcasting.connections.ably' => null]);

    expect(ChatConfig::build(null)['broadcasting']['driver'])->toBe('reverb');
});

it('provides configured ICE servers to the call client', function () {
    $iceServers = [
        [
            'urls' => 'turn:turn.example.com:3478',
            'username' => 'temporary-user',
            'credential' => 'temporary-credential',
        ],
    ];

    config(['chat.calls.ice_servers' => $iceServers]);

    expect(ChatConfig::build(null)['calls']['iceServers'])->toBe($iceServers);
});

it('defaults chat broadcasts to sync and only queues when the host opts in', function () {
    config(['chat.broadcasting.mode' => 'sync']);

    $syncEvent = new class implements ShouldBroadcast
    {
        use BroadcastsChatEvents;

        public function broadcastOn(): array
        {
            return [];
        }

        public function broadcastAs(): string
        {
            return 'test';
        }

        public function broadcastWith(): array
        {
            return [];
        }
    };

    expect($syncEvent->shouldBroadcastNow())->toBeTrue()
        ->and($syncEvent->broadcastQueue())->toBeNull();

    config(['chat.broadcasting.mode' => 'queue', 'chat.broadcasting.queue' => 'chat-broadcasts']);

    $queuedEvent = new class implements ShouldBroadcast
    {
        use BroadcastsChatEvents;

        public function broadcastOn(): array
        {
            return [];
        }

        public function broadcastAs(): string
        {
            return 'test';
        }

        public function broadcastWith(): array
        {
            return [];
        }
    };

    expect($queuedEvent->shouldBroadcastNow())->toBeFalse()
        ->and($queuedEvent->broadcastQueue())->toBe('chat-broadcasts');
});
