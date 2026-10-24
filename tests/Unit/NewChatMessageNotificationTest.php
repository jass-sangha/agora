<?php

use Chat\Enums\MessageType;
use Chat\Models\Message;
use Chat\Notifications\NewChatMessageNotification;

it('always includes the broadcast channel and appends configured extra channels', function () {
    $message = new Message([
        'conversation_id' => 1,
        'chatable_type' => 'user',
        'chatable_id' => 1,
        'type' => MessageType::Text->value,
        'body' => 'hi',
    ]);

    $notification = new NewChatMessageNotification($message);

    expect($notification->via(null))->toBe(['broadcast']);

    config(['chat.notifications.channels' => ['fcm']]);

    expect($notification->via(null))->toBe(['broadcast', 'fcm']);
});

it('tags itself onto the configured chat notifications queue', function () {
    config(['chat.notifications.queue' => 'chat-notifications']);

    $message = new Message([
        'conversation_id' => 1,
        'chatable_type' => 'user',
        'chatable_id' => 1,
        'type' => MessageType::Text->value,
        'body' => 'hi',
    ]);

    expect((new NewChatMessageNotification($message))->queue)->toBe('chat-notifications');
});

it('leaves the queue unset when chat.notifications.queue is disabled', function () {
    config(['chat.notifications.queue' => null]);

    $message = new Message([
        'conversation_id' => 1,
        'chatable_type' => 'user',
        'chatable_id' => 1,
        'type' => MessageType::Text->value,
        'body' => 'hi',
    ]);

    expect((new NewChatMessageNotification($message))->queue)->toBeNull();
});
