<?php

use Chat\Http\Controllers\AttachmentController;
use Chat\Http\Controllers\BlockedUserController;
use Chat\Http\Controllers\CallController;
use Chat\Http\Controllers\ChatListController;
use Chat\Http\Controllers\ConversationController;
use Chat\Http\Controllers\EventRsvpController;
use Chat\Http\Controllers\LinkPreviewController;
use Chat\Http\Controllers\MessageController;
use Chat\Http\Controllers\MessageReactionController;
use Chat\Http\Controllers\MessageReceiptController;
use Chat\Http\Controllers\NotificationController;
use Chat\Http\Controllers\ParticipantController;
use Chat\Http\Controllers\PinnedMessageController;
use Chat\Http\Controllers\PollVoteController;
use Chat\Http\Controllers\PresenceController;
use Chat\Http\Controllers\ProfileController;
use Chat\Http\Controllers\StarredMessageController;
use Chat\Http\Controllers\TypingController;
use Chat\Http\Controllers\UserSearchController;
use Illuminate\Broadcasting\BroadcastController;
use Illuminate\Support\Facades\Route;

Route::middleware(config('chat.middleware', ['api', 'auth:sanctum']))
    ->prefix(config('chat.route_prefix', 'api/chat'))
    ->group(function () {
        Route::get('conversations', [ConversationController::class, 'index']);
        Route::post('conversations', [ConversationController::class, 'store']);
        Route::get('conversations/{conversation}', [ConversationController::class, 'show']);
        Route::patch('conversations/{conversation}', [ConversationController::class, 'update']);
        Route::post('conversations/{conversation}/avatar', [ConversationController::class, 'updateAvatar']);
        Route::delete('conversations/{conversation}/avatar', [ConversationController::class, 'destroyAvatar']);
        Route::patch('conversations/{conversation}/mute', [ConversationController::class, 'mute']);
        Route::patch('conversations/{conversation}/archive', [ConversationController::class, 'archive']);
        Route::patch('conversations/{conversation}/pin', [ConversationController::class, 'pin']);
        Route::patch('conversations/{conversation}/unread', [ConversationController::class, 'unread']);
        Route::patch('conversations/{conversation}/favourite', [ConversationController::class, 'favourite']);
        Route::patch('conversations/{conversation}/hide', [ConversationController::class, 'hide']);
        Route::patch('conversations/{conversation}/wallpaper', [ConversationController::class, 'wallpaper']);

        Route::get('conversations/{conversation}/messages', [MessageController::class, 'index']);
        Route::post('conversations/{conversation}/messages', [MessageController::class, 'store'])
            ->middleware(array_filter([config('chat.rate_limits.message_send')]));
        Route::delete('conversations/{conversation}/messages', [MessageController::class, 'clear']);

        Route::patch('messages/{message}', [MessageController::class, 'update']);
        Route::get('messages/{message}/edits', [MessageController::class, 'edits']);
        Route::get('messages/{message}/receipts', [MessageController::class, 'receipts']);
        Route::delete('messages/{message}', [MessageController::class, 'destroy']);
        Route::delete('messages/{message}/me', [MessageController::class, 'destroyForMe']);
        Route::post('messages/{message}/forward', [MessageController::class, 'forward']);

        Route::get('messages/{message}/reactions', [MessageReactionController::class, 'index']);
        Route::post('messages/{message}/reactions', [MessageReactionController::class, 'store'])
            ->middleware(array_filter([config('chat.rate_limits.reactions')]));
        Route::delete('messages/{message}/reactions', [MessageReactionController::class, 'destroy'])
            ->middleware(array_filter([config('chat.rate_limits.reactions')]));

        Route::post('messages/{message}/poll/vote', [PollVoteController::class, 'store']);
        Route::post('messages/{message}/event/rsvp', [EventRsvpController::class, 'store']);

        Route::get('starred-messages', [StarredMessageController::class, 'index']);
        Route::post('messages/{message}/star', [StarredMessageController::class, 'store']);
        Route::delete('messages/{message}/star', [StarredMessageController::class, 'destroy']);

        Route::get('conversations/{conversation}/pinned-messages', [PinnedMessageController::class, 'index']);
        Route::post('messages/{message}/pin', [PinnedMessageController::class, 'store']);
        Route::delete('messages/{message}/pin', [PinnedMessageController::class, 'destroy']);

        Route::post('conversations/{conversation}/receipts/delivered', [MessageReceiptController::class, 'markDelivered']);
        Route::post('conversations/{conversation}/receipts/read', [MessageReceiptController::class, 'markRead']);

        Route::post('conversations/{conversation}/typing', [TypingController::class, 'update']);

        Route::get('calls/ice-servers', [CallController::class, 'iceServers'])
            ->middleware(array_filter([config('chat.calls.ice_server_rate_limit')]));
        Route::post('conversations/{conversation}/call/signal', [CallController::class, 'signal'])
            ->middleware(array_filter([config('chat.calls.signal_rate_limit')]));

        Route::patch('notifications/mute', [NotificationController::class, 'muteAll']);

        Route::post('presence/heartbeat', [PresenceController::class, 'heartbeat']);
        Route::get('users/{chatableType}/{chatableId}/presence', [PresenceController::class, 'show']);
        Route::get('users', [UserSearchController::class, 'index']);
        Route::post('profile/avatar', [ProfileController::class, 'updateAvatar']);
        Route::delete('profile/avatar', [ProfileController::class, 'destroyAvatar']);
        Route::get('profile/settings', [ProfileController::class, 'showSettings']);
        Route::patch('profile/settings', [ProfileController::class, 'updateSettings']);

        Route::get('conversations/{conversation}/participants', [ParticipantController::class, 'index']);
        Route::post('conversations/{conversation}/participants', [ParticipantController::class, 'store']);
        Route::delete('conversations/{conversation}/participants/{chatableType}/{chatableId}', [ParticipantController::class, 'destroy']);
        Route::patch('conversations/{conversation}/participants/{chatableType}/{chatableId}/role', [ParticipantController::class, 'updateRole']);
        Route::post('conversations/{conversation}/leave', [ParticipantController::class, 'leave']);

        Route::get('blocked-users', [BlockedUserController::class, 'index']);
        Route::post('blocked-users', [BlockedUserController::class, 'store']);
        Route::delete('blocked-users/{chatableType}/{chatableId}', [BlockedUserController::class, 'destroy']);

        Route::get('lists', [ChatListController::class, 'index']);
        Route::post('lists', [ChatListController::class, 'store']);
        Route::delete('lists/{list}', [ChatListController::class, 'destroy']);
        Route::post('lists/{list}/conversations', [ChatListController::class, 'addConversation']);
        Route::delete('lists/{list}/conversations/{conversationId}', [ChatListController::class, 'removeConversation']);

        Route::post('attachments', [AttachmentController::class, 'store'])
            ->middleware(array_filter([config('chat.rate_limits.attachment_upload')]));

        // Signed attachment routes reject a missing/expired/tampered signature before the
        // controller runs; its own MessagePolicy::view check is the second, independent layer.
        Route::get('attachments/{attachment}', [AttachmentController::class, 'show'])
            ->middleware('signed')
            ->name('chat.attachments.show');
        Route::get('attachments/{attachment}/download', [AttachmentController::class, 'download'])
            ->middleware('signed')
            ->name('chat.attachments.download');
        Route::get('attachments/{attachment}/thumbnail', [AttachmentController::class, 'thumbnail'])
            ->middleware('signed')
            ->name('chat.attachments.thumbnail');

        // Prefixed (unlike the group's own throttle:120,1,chat above and the entries in
        // chat.rate_limits) so it doesn't share a throttle key with either — see the note on
        // `middleware` in config/chat.php for why that matters.
        Route::post('link-preview', [LinkPreviewController::class, 'store'])->middleware('throttle:10,1,chat-link-preview');

        Route::get('messages/search', [MessageController::class, 'search'])
            ->middleware(array_filter([config('chat.rate_limits.search')]));
        Route::get('messages/media', [MessageController::class, 'media']);
        Route::patch('conversations/{conversation}/disappearing', [ConversationController::class, 'disappearing']);
        Route::post('broadcasting/auth', [BroadcastController::class, 'authenticate']);
    });
