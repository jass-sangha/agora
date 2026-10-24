<?php

use Chat\Models\Message;
use Chat\Models\MessageAttachment;
use Chat\Tests\Fixtures\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

function privateAttachmentUser(string $email): User
{
    return User::query()->create(['name' => $email, 'email' => $email, 'password' => bcrypt('secret')]);
}

it('keeps the plain public disk URL when serve_privately is off', function () {
    Storage::fake('chat');

    $alice = privateAttachmentUser('alice-pub@example.com');

    $attachmentId = $this->actingAs($alice)->postJson('/api/chat/attachments', [
        'file' => UploadedFile::fake()->image('photo.jpg', 200, 200),
    ])->json('data.id');

    $attachment = MessageAttachment::query()->findOrFail($attachmentId);
    $url = $attachment->url;

    expect($url)->not->toContain('signature=')
        ->and($url)->not->toContain('/api/chat/attachments/');

    $download = $this->actingAs($alice)->get($attachment->download_url)->assertOk();
    expect($download->headers->get('content-disposition'))->toContain('attachment')
        ->and($download->headers->get('content-disposition'))->toContain('photo.jpg');
});

it('serves an attached attachment through a signed, policy-checked URL when serve_privately is on', function () {
    config(['chat.media.serve_privately' => true]);
    Storage::fake('chat');

    $alice = privateAttachmentUser('alice-priv@example.com');
    $bob = privateAttachmentUser('bob-priv@example.com');
    $eve = privateAttachmentUser('eve-priv@example.com');

    $conversationId = $this->actingAs($alice)->postJson('/api/chat/conversations', [
        'type' => 'private',
        'participants' => [chatableRef($bob)],
    ])->json('data.id');

    $attachmentId = $this->actingAs($alice)->postJson('/api/chat/attachments', [
        'file' => UploadedFile::fake()->image('photo.jpg', 200, 200),
    ])->json('data.id');

    $message = $this->actingAs($alice)->postJson("/api/chat/conversations/{$conversationId}/messages", [
        'type' => 'image',
        'attachment_ids' => [$attachmentId],
    ])->assertCreated();

    $url = $message->json('data.attachments.0.url');
    $downloadUrl = $message->json('data.attachments.0.download_url');

    expect($url)->toContain('/api/chat/attachments/')
        ->and($url)->toContain('signature=');
    expect($downloadUrl)->toContain('/api/chat/attachments/')
        ->and($downloadUrl)->toContain('/download?')
        ->and($downloadUrl)->toContain('signature=');

    // The recipient, an active participant, can fetch it.
    $this->actingAs($bob)->get($url)->assertOk();
    $download = $this->actingAs($bob)->get($downloadUrl)->assertOk();
    expect($download->headers->get('content-disposition'))->toContain('attachment')
        ->and($download->headers->get('content-disposition'))->toContain('photo.jpg');

    // A third party who was never in the conversation cannot, even with this exact signed URL —
    // the per-request policy check is the real gate, not just a valid signature.
    $this->actingAs($eve)->get($url)->assertForbidden();
    $this->actingAs($eve)->get($downloadUrl)->assertForbidden();

    // A tampered signature is rejected before the controller (and its policy check) ever runs.
    $this->actingAs($bob)->get($url.'tampered')->assertStatus(403);
});

it('lets only the uploader view a private-mode attachment before it is attached to any message', function () {
    config(['chat.media.serve_privately' => true]);
    Storage::fake('chat');

    $alice = privateAttachmentUser('alice-preview@example.com');
    $bob = privateAttachmentUser('bob-preview@example.com');

    $attachmentId = $this->actingAs($alice)->postJson('/api/chat/attachments', [
        'file' => UploadedFile::fake()->image('photo.jpg', 200, 200),
    ])->json('data.id');

    $attachment = MessageAttachment::query()->findOrFail($attachmentId);

    $this->actingAs($alice)->get($attachment->url)->assertOk();
    $this->actingAs($bob)->get($attachment->url)->assertForbidden();
});

it('revokes a viewer\'s attachment access the moment they delete the message for themselves', function () {
    config(['chat.media.serve_privately' => true]);
    Storage::fake('chat');

    $alice = privateAttachmentUser('alice-delme@example.com');
    $bob = privateAttachmentUser('bob-delme@example.com');

    $conversationId = $this->actingAs($alice)->postJson('/api/chat/conversations', [
        'type' => 'private',
        'participants' => [chatableRef($bob)],
    ])->json('data.id');

    $attachmentId = $this->actingAs($alice)->postJson('/api/chat/attachments', [
        'file' => UploadedFile::fake()->image('photo.jpg', 200, 200),
    ])->json('data.id');

    $message = $this->actingAs($alice)->postJson("/api/chat/conversations/{$conversationId}/messages", [
        'type' => 'image',
        'attachment_ids' => [$attachmentId],
    ])->assertCreated();

    $messageId = $message->json('data.id');
    $bobUrl = $message->json('data.attachments.0.url');

    $this->actingAs($bob)->get($bobUrl)->assertOk();

    $this->actingAs($bob)->deleteJson("/api/chat/messages/{$messageId}/me")->assertNoContent();

    // Same URL, still well inside its signature's TTL — the signature alone is no longer
    // enough once MessagePolicy::view's own isAccessible() check fails for this viewer.
    $this->actingAs($bob)->get($bobUrl)->assertForbidden();

    // Alice, who didn't delete it for herself, is unaffected — this is bob's own visibility,
    // not the message's.
    $aliceUrl = MessageAttachment::query()->findOrFail($attachmentId)->url;
    $this->actingAs($alice)->get($aliceUrl)->assertOk();
});

it('revokes attachment access the instant a disappearing message expires, without waiting for the prune command', function () {
    // Mirrors SearchDisappearingAndNotificationsTest's "hides an expired disappearing message
    // immediately" case, but for the attachment file rather than the message's own timeline
    // visibility — chat:prune-expired-messages only physically deletes the row (and its
    // attachment file) on whatever schedule the host configures, so the row and file both still
    // exist here. A plain public URL would keep serving that file regardless; the signed,
    // policy-checked route must not.
    config(['chat.media.serve_privately' => true]);
    Storage::fake('chat');

    $alice = privateAttachmentUser('alice-expired@example.com');
    $bob = privateAttachmentUser('bob-expired@example.com');

    $conversationId = $this->actingAs($alice)->postJson('/api/chat/conversations', [
        'type' => 'private',
        'participants' => [chatableRef($bob)],
    ])->json('data.id');

    $this->actingAs($alice)
        ->patchJson("/api/chat/conversations/{$conversationId}/disappearing", ['ttl_seconds' => 60])
        ->assertOk();

    $attachmentId = $this->actingAs($alice)->postJson('/api/chat/attachments', [
        'file' => UploadedFile::fake()->image('photo.jpg', 200, 200),
    ])->json('data.id');

    $message = $this->actingAs($alice)->postJson("/api/chat/conversations/{$conversationId}/messages", [
        'type' => 'image',
        'attachment_ids' => [$attachmentId],
    ])->assertCreated();

    $messageId = $message->json('data.id');
    $url = $message->json('data.attachments.0.url');

    $this->actingAs($bob)->get($url)->assertOk();

    // Elapse the TTL without running the prune command — the row (and its attachment file)
    // still physically exist.
    Message::query()->whereKey($messageId)->update(['expires_at' => now()->subMinute()]);

    $this->actingAs($bob)->get($url)->assertForbidden();
});
