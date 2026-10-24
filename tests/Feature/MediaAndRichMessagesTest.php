<?php

use Chat\Contracts\MessageServiceInterface;
use Chat\Models\Message;
use Chat\Models\MessageAttachment;
use Chat\Tests\Fixtures\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

function mediaUser(string $email): User
{
    return User::query()->create(['name' => $email, 'email' => $email, 'password' => bcrypt('secret')]);
}

it('uploads an attachment and sends it as an image message', function () {
    Storage::fake('chat');

    $alice = mediaUser('alice-media@example.com');
    $bob = mediaUser('bob-media@example.com');

    $conversationId = $this->actingAs($alice)->postJson('/api/chat/conversations', [
        'type' => 'private',
        'participants' => [chatableRef($bob)],
    ])->json('data.id');

    $upload = $this->actingAs($alice)->postJson('/api/chat/attachments', [
        'file' => UploadedFile::fake()->image('photo.jpg', 200, 200),
    ])->assertCreated();

    $attachmentId = $upload->json('data.id');

    $message = $this->actingAs($alice)->postJson("/api/chat/conversations/{$conversationId}/messages", [
        'type' => 'image',
        'attachment_ids' => [$attachmentId],
    ])->assertCreated();

    expect($message->json('data.type'))->toBe('image')
        ->and($message->json('data.attachments'))->toHaveCount(1);

    // Bob cannot attach Alice's already-uploaded, already-attached file to his own message.
    $this->actingAs($bob)->postJson("/api/chat/conversations/{$conversationId}/messages", [
        'type' => 'image',
        'attachment_ids' => [$attachmentId],
    ])->assertStatus(403);

    // Alice herself cannot reattach the same file to a second message either.
    $this->actingAs($alice)->postJson("/api/chat/conversations/{$conversationId}/messages", [
        'type' => 'image',
        'attachment_ids' => [$attachmentId],
    ])->assertStatus(422);
});

it('lists media without an extra COUNT(*) query', function () {
    // media() used to run paginate(), which computes an exact total on every request — an
    // infinite-scroll grid (MediaDocsLinksGrid.vue) only ever needs "is there another page",
    // which simplePaginate()'s links.next answers without that COUNT(*).
    Storage::fake('chat');

    $alice = mediaUser('alice-mediacount@example.com');
    $bob = mediaUser('bob-mediacount@example.com');

    $conversationId = $this->actingAs($alice)->postJson('/api/chat/conversations', [
        'type' => 'private',
        'participants' => [chatableRef($bob)],
    ])->json('data.id');

    $attachmentId = $this->actingAs($alice)->postJson('/api/chat/attachments', [
        'file' => UploadedFile::fake()->image('photo.jpg', 200, 200),
    ])->json('data.id');

    $this->actingAs($alice)->postJson("/api/chat/conversations/{$conversationId}/messages", [
        'type' => 'image',
        'attachment_ids' => [$attachmentId],
    ])->assertCreated();

    DB::flushQueryLog();
    DB::enableQueryLog();
    $response = $this->actingAs($alice)
        ->getJson("/api/chat/messages/media?kind=media&conversation_id={$conversationId}")
        ->assertOk();
    $queries = collect(DB::getQueryLog());
    DB::disableQueryLog();

    $isPaginationCount = fn ($entry) => str_contains(strtolower($entry['query']), 'count(*)')
        && ! str_contains(strtolower($entry['query']), 'group by');

    expect($queries->filter($isPaginationCount))->toBeEmpty()
        ->and($response->json('data'))->toHaveCount(1)
        ->and($response->json('links.next'))->toBeNull();
});

it('forwards a media message with its attachment intact', function () {
    Storage::fake('chat');

    $alice = mediaUser('alice-fwdmedia@example.com');
    $bob = mediaUser('bob-fwdmedia@example.com');
    $carol = mediaUser('carol-fwdmedia@example.com');

    $chatAB = $this->actingAs($alice)->postJson('/api/chat/conversations', [
        'type' => 'private',
        'participants' => [chatableRef($bob)],
    ])->json('data.id');

    $chatAC = $this->actingAs($alice)->postJson('/api/chat/conversations', [
        'type' => 'private',
        'participants' => [chatableRef($carol)],
    ])->json('data.id');

    $attachmentId = $this->actingAs($alice)->postJson('/api/chat/attachments', [
        'file' => UploadedFile::fake()->image('photo.jpg', 200, 200),
    ])->json('data.id');

    $originalId = $this->actingAs($alice)->postJson("/api/chat/conversations/{$chatAB}/messages", [
        'type' => 'image',
        'attachment_ids' => [$attachmentId],
    ])->json('data.id');

    $forwarded = $this->actingAs($alice)
        ->postJson("/api/chat/messages/{$originalId}/forward", ['conversation_ids' => [$chatAC]])
        ->assertOk();

    expect($forwarded->json('data.0.type'))->toBe('image')
        ->and($forwarded->json('data.0.attachments'))->toHaveCount(1)
        ->and($forwarded->json('data.0.attachments.0.original_filename'))->toContain('photo');

    // The original message must keep its own attachment untouched.
    $original = $this->actingAs($alice)->getJson("/api/chat/conversations/{$chatAB}/messages")->json('data');
    expect(collect($original)->firstWhere('id', $originalId)['attachments'])->toHaveCount(1);
});

it('does not copy an attachment whose row was deleted after the source message was loaded', function () {
    // copyAttachments() used to iterate $source->attachments — an Eloquent relation that, once
    // accessed, stays cached on that model instance. A concurrent deleteForEveryone() on the
    // source message (attachment row deleted, then the now-unreferenced file removed from disk)
    // happening between that cache being warmed and the forward actually running would leave the
    // cached collection pointing at a row/file that no longer exists — copyAttachments() re-runs
    // a fresh query now instead, so it can only ever copy what's actually still there. This test
    // simulates that ordering directly (real concurrent-transaction timing isn't reproducible
    // against SQLite in this suite) by deleting the row out from under an already-loaded model.
    Storage::fake('chat');

    $alice = mediaUser('alice-staleattach@example.com');
    $bob = mediaUser('bob-staleattach@example.com');
    $carol = mediaUser('carol-staleattach@example.com');

    $sourceChat = $this->actingAs($alice)->postJson('/api/chat/conversations', [
        'type' => 'private',
        'participants' => [chatableRef($bob)],
    ])->json('data.id');

    $targetChat = $this->actingAs($alice)->postJson('/api/chat/conversations', [
        'type' => 'private',
        'participants' => [chatableRef($carol)],
    ])->json('data.id');

    $attachmentId = $this->actingAs($alice)->postJson('/api/chat/attachments', [
        'file' => UploadedFile::fake()->image('photo.jpg', 200, 200),
    ])->json('data.id');

    $sourceId = $this->actingAs($alice)->postJson("/api/chat/conversations/{$sourceChat}/messages", [
        'type' => 'image',
        'attachment_ids' => [$attachmentId],
    ])->json('data.id');

    // Warm the attachments relation cache on this model instance, as if it had been loaded
    // before the concurrent delete below.
    $source = Message::with('attachments')->findOrFail($sourceId);
    expect($source->attachments)->toHaveCount(1);

    // Simulates the other side of the race: a concurrent deleteForEveryone() already removed
    // the attachment row (and would have removed the file too) by the time the forward runs.
    MessageAttachment::query()->where('message_id', $sourceId)->delete();

    app(MessageServiceInterface::class)->forward($source, [$targetChat], $alice);

    $forwardedList = $this->actingAs($alice)->getJson("/api/chat/conversations/{$targetChat}/messages")->json('data');
    expect($forwardedList[0]['attachments'])->toBe([]);
});

it('rejects an oversized attachment upload', function () {
    Storage::fake('chat');

    $alice = mediaUser('alice-oversize@example.com');

    $this->actingAs($alice)->postJson('/api/chat/attachments', [
        'file' => UploadedFile::fake()->create('huge.jpg', 20 * 1024, 'image/jpeg'),
    ])->assertStatus(422);
});

it('rejects an upload that would exceed the per-user storage quota, and does not leave the file on disk', function () {
    Storage::fake('chat');
    config(['chat.media.max_storage_per_user_mb' => 1]);

    $alice = mediaUser('alice-quota@example.com');

    $this->actingAs($alice)->postJson('/api/chat/attachments', [
        'file' => UploadedFile::fake()->create('first.jpg', 900, 'image/jpeg'), // ~0.9MB, under quota
    ])->assertCreated();

    $filesAfterFirstUpload = count(Storage::disk('chat')->allFiles());

    $rejected = $this->actingAs($alice)->postJson('/api/chat/attachments', [
        'file' => UploadedFile::fake()->create('second.jpg', 300, 'image/jpeg'), // pushes past 1MB
    ])->assertStatus(422);

    expect($rejected->json('message'))->toContain('Storage quota exceeded');

    // store() names files by a random hash, not the original filename, so the only reliable way
    // to prove the rejected upload's file didn't stick around is the file count: it's written to
    // disk (for the quota check's own consistency, see AttachmentService::upload()) before the
    // DB check runs, and must be deleted again on rejection rather than left orphaned.
    expect(count(Storage::disk('chat')->allFiles()))->toBe($filesAfterFirstUpload);
});

it('rejects a file whose mime type is not on the allow-list', function () {
    Storage::fake('chat');

    $alice = mediaUser('alice-mimereject@example.com');

    // A mime type absent from chat.media.mime_types must be rejected outright rather than
    // silently falling back to the generic 'document' bucket — that fallback is what let
    // text/html and image/svg+xml through as stored XSS in the first place.
    $this->actingAs($alice)->postJson('/api/chat/attachments', [
        'file' => UploadedFile::fake()->create('archive.rar', 10, 'application/x-rar-compressed'),
    ])->assertStatus(422);
});

it('rejects html and svg uploads that could execute as stored XSS', function () {
    Storage::fake('chat');

    $alice = mediaUser('alice-xss@example.com');

    $this->actingAs($alice)->postJson('/api/chat/attachments', [
        'file' => UploadedFile::fake()->createWithContent('payload.html', '<script>alert(1)</script>'),
    ])->assertStatus(422);

    $this->actingAs($alice)->postJson('/api/chat/attachments', [
        'file' => UploadedFile::fake()->createWithContent('payload.svg', '<svg onload="alert(1)"></svg>'),
    ])->assertStatus(422);
});

it('reports a clear error when a single file was rejected by upload_max_filesize, instead of "must be a file"', function () {
    $alice = mediaUser('alice-inisize@example.com');

    // Simulates what PHP itself produces for a file over upload_max_filesize: the request
    // completes fine (unlike post_max_size, which Illuminate\Http\Middleware\ValidatePostSize
    // already rejects before this code runs), but the file arrives pre-marked as errored.
    $file = new UploadedFile(
        sys_get_temp_dir().'/does-not-matter',
        'huge.jpg',
        'image/jpeg',
        UPLOAD_ERR_INI_SIZE,
        true,
    );

    $response = $this->actingAs($alice)->postJson('/api/chat/attachments', [
        'file' => $file,
    ])->assertStatus(422);

    expect($response->json('errors.file.0'))->toContain('larger than the server allows');
});

it('deletes an attachment file from disk when the message is deleted for everyone', function () {
    Storage::fake('chat');

    $alice = mediaUser('alice-delfile@example.com');
    $bob = mediaUser('bob-delfile@example.com');

    $conversationId = $this->actingAs($alice)->postJson('/api/chat/conversations', [
        'type' => 'private',
        'participants' => [chatableRef($bob)],
    ])->json('data.id');

    $attachmentId = $this->actingAs($alice)->postJson('/api/chat/attachments', [
        'file' => UploadedFile::fake()->image('photo.jpg', 200, 200),
    ])->json('data.id');

    $messageId = $this->actingAs($alice)->postJson("/api/chat/conversations/{$conversationId}/messages", [
        'type' => 'image',
        'attachment_ids' => [$attachmentId],
    ])->json('data.id');

    expect(Storage::disk('chat')->allFiles())->toHaveCount(1);

    $this->actingAs($alice)->deleteJson("/api/chat/messages/{$messageId}")->assertNoContent();

    // Gone from disk, and gone from the API response — not just hidden client-side while the
    // URL still resolves for anyone who re-fetches or hits the API directly.
    expect(Storage::disk('chat')->allFiles())->toHaveCount(0);

    $list = $this->actingAs($alice)->getJson("/api/chat/conversations/{$conversationId}/messages")->assertOk();
    expect($list->json('data.0.attachments'))->toBe([]);
});

it('checks orphaned attachment files with one query per disk, not one per attachment', function () {
    Storage::fake('chat');

    $alice = mediaUser('alice-delfilebatch@example.com');
    $bob = mediaUser('bob-delfilebatch@example.com');

    $conversationId = $this->actingAs($alice)->postJson('/api/chat/conversations', [
        'type' => 'private',
        'participants' => [chatableRef($bob)],
    ])->json('data.id');

    $attachmentIds = collect(range(1, 5))->map(
        fn ($i) => $this->actingAs($alice)->postJson('/api/chat/attachments', [
            'file' => UploadedFile::fake()->image("photo{$i}.jpg", 200, 200),
        ])->json('data.id')
    )->all();

    $messageId = $this->actingAs($alice)->postJson("/api/chat/conversations/{$conversationId}/messages", [
        'type' => 'image',
        'attachment_ids' => $attachmentIds,
    ])->json('data.id');

    expect(Storage::disk('chat')->allFiles())->toHaveCount(5);

    DB::flushQueryLog();
    DB::enableQueryLog();
    $this->actingAs($alice)->deleteJson("/api/chat/messages/{$messageId}")->assertNoContent();
    // deleteOrphanedFiles() used to run one exists() query per attachment to check whether
    // another row still referenced its file — one grouped-by-disk lookup now covers the
    // whole batch (all 5 share the 'chat' disk here). Matched by disk+path so this doesn't
    // also pick up the unrelated "fetch this message's own attachments" query.
    $referenceChecks = collect(DB::getQueryLog())->filter(
        fn ($entry) => str_contains($entry['query'], 'chat_message_attachments')
            && str_contains($entry['query'], '"disk" =')
            && str_contains($entry['query'], '"path" in')
    );
    DB::disableQueryLog();

    expect($referenceChecks)->toHaveCount(1)
        ->and(Storage::disk('chat')->allFiles())->toHaveCount(0);
});

it('keeps a forwarded attachment file on disk when only the original is deleted for everyone', function () {
    Storage::fake('chat');

    $alice = mediaUser('alice-fwdkeep@example.com');
    $bob = mediaUser('bob-fwdkeep@example.com');
    $carol = mediaUser('carol-fwdkeep@example.com');

    $chatAB = $this->actingAs($alice)->postJson('/api/chat/conversations', [
        'type' => 'private',
        'participants' => [chatableRef($bob)],
    ])->json('data.id');

    $chatAC = $this->actingAs($alice)->postJson('/api/chat/conversations', [
        'type' => 'private',
        'participants' => [chatableRef($carol)],
    ])->json('data.id');

    $attachmentId = $this->actingAs($alice)->postJson('/api/chat/attachments', [
        'file' => UploadedFile::fake()->image('photo.jpg', 200, 200),
    ])->json('data.id');

    $originalId = $this->actingAs($alice)->postJson("/api/chat/conversations/{$chatAB}/messages", [
        'type' => 'image',
        'attachment_ids' => [$attachmentId],
    ])->json('data.id');

    $this->actingAs($alice)
        ->postJson("/api/chat/messages/{$originalId}/forward", ['conversation_ids' => [$chatAC]])
        ->assertOk();

    $this->actingAs($alice)->deleteJson("/api/chat/messages/{$originalId}")->assertNoContent();

    // The forwarded copy's row points at the same underlying file — deleting the original
    // must not pull the file out from under it.
    expect(Storage::disk('chat')->allFiles())->toHaveCount(1);

    $forwardedList = $this->actingAs($alice)->getJson("/api/chat/conversations/{$chatAC}/messages")->assertOk();
    expect($forwardedList->json('data.0.attachments'))->toHaveCount(1);
});

it('sends a location message and a contact message', function () {
    $alice = mediaUser('alice-loc@example.com');
    $bob = mediaUser('bob-loc@example.com');

    $conversationId = $this->actingAs($alice)->postJson('/api/chat/conversations', [
        'type' => 'private',
        'participants' => [chatableRef($bob)],
    ])->json('data.id');

    $location = $this->actingAs($alice)->postJson("/api/chat/conversations/{$conversationId}/messages", [
        'type' => 'location',
        'metadata' => ['lat' => 37.7749, 'lng' => -122.4194, 'name' => 'San Francisco'],
    ])->assertCreated();

    expect($location->json('data.metadata.lat'))->toBe(37.7749);

    $contact = $this->actingAs($alice)->postJson("/api/chat/conversations/{$conversationId}/messages", [
        'type' => 'contact',
        'metadata' => ['name' => 'Jane Doe', 'phones' => ['+1234567890']],
    ])->assertCreated();

    expect($contact->json('data.metadata.name'))->toBe('Jane Doe');

    $this->actingAs($alice)->postJson("/api/chat/conversations/{$conversationId}/messages", [
        'type' => 'location',
        'metadata' => ['name' => 'Missing coordinates'],
    ])->assertStatus(422);
});

it('caps the number and length of phones on a contact message', function () {
    $alice = mediaUser('alice-contactcap@example.com');
    $bob = mediaUser('bob-contactcap@example.com');

    $conversationId = $this->actingAs($alice)->postJson('/api/chat/conversations', [
        'type' => 'private',
        'participants' => [chatableRef($bob)],
    ])->json('data.id');

    // Body's own max_body_length doesn't protect non-text messages — metadata.phones had no
    // count or per-item limit at all, so a contact message could otherwise carry an arbitrarily
    // large phones array into the stored row and the realtime broadcast payload.
    $this->actingAs($alice)->postJson("/api/chat/conversations/{$conversationId}/messages", [
        'type' => 'contact',
        'metadata' => ['name' => 'Too Many', 'phones' => array_fill(0, 11, '+1234567890')],
    ])->assertInvalid(['metadata.phones']);

    $this->actingAs($alice)->postJson("/api/chat/conversations/{$conversationId}/messages", [
        'type' => 'contact',
        'metadata' => ['name' => 'Too Long', 'phones' => [str_repeat('1', 51)]],
    ])->assertInvalid(['metadata.phones.0']);

    $this->actingAs($alice)->postJson("/api/chat/conversations/{$conversationId}/messages", [
        'type' => 'contact',
        'metadata' => ['name' => 'Just Right', 'phones' => array_fill(0, 10, '+1234567890')],
    ])->assertCreated();
});

it('rejects a message whose serialized metadata exceeds the configured byte limit', function () {
    // The per-field limits (max:255, max:1000, etc.) bound individual keys, but a message using
    // several legitimately-sized fields at once — or a future metadata key this list doesn't yet
    // cover — could still add up to an oversized stored row and broadcast payload. This is the
    // catch-all backstop on the whole serialized metadata, not any one field.
    config(['chat.message.max_metadata_bytes' => 200]);

    $alice = mediaUser('alice-metasize@example.com');
    $bob = mediaUser('bob-metasize@example.com');

    $conversationId = $this->actingAs($alice)->postJson('/api/chat/conversations', [
        'type' => 'private',
        'participants' => [chatableRef($bob)],
    ])->json('data.id');

    $this->actingAs($alice)->postJson("/api/chat/conversations/{$conversationId}/messages", [
        'type' => 'contact',
        'metadata' => ['name' => 'Oversized', 'phones' => array_fill(0, 10, str_repeat('9', 20))],
    ])->assertInvalid(['metadata']);
});

it('rejects a call metadata participant whose type is not a configured chatable alias', function () {
    $alice = mediaUser('alice-calltype@example.com');
    $bob = mediaUser('bob-calltype@example.com');

    $conversationId = $this->actingAs($alice)->postJson('/api/chat/conversations', [
        'type' => 'private',
        'participants' => [chatableRef($bob)],
    ])->json('data.id');

    $this->actingAs($alice)->postJson("/api/chat/conversations/{$conversationId}/messages", [
        'type' => 'call',
        'metadata' => [
            'duration_seconds' => 30,
            'participants' => [['type' => 'not-a-real-alias', 'id' => $bob->id]],
        ],
    ])->assertInvalid(['metadata.participants.0.type']);
});

it('aggregates media across every conversation the user participates in, not just one', function () {
    Storage::fake('chat');

    $alice = mediaUser('alice-allmedia@example.com');
    $bob = mediaUser('bob-allmedia@example.com');
    $carol = mediaUser('carol-allmedia@example.com');
    $dave = mediaUser('dave-allmedia@example.com');

    $chatAB = $this->actingAs($alice)->postJson('/api/chat/conversations', [
        'type' => 'private',
        'participants' => [chatableRef($bob)],
    ])->json('data.id');

    $chatAC = $this->actingAs($alice)->postJson('/api/chat/conversations', [
        'type' => 'private',
        'participants' => [chatableRef($carol)],
    ])->json('data.id');

    $attachmentInAB = $this->actingAs($alice)->postJson('/api/chat/attachments', [
        'file' => UploadedFile::fake()->image('ab.jpg', 200, 200),
    ])->json('data.id');

    $attachmentInAC = $this->actingAs($alice)->postJson('/api/chat/attachments', [
        'file' => UploadedFile::fake()->image('ac.jpg', 200, 200),
    ])->json('data.id');

    $this->actingAs($alice)->postJson("/api/chat/conversations/{$chatAB}/messages", [
        'type' => 'image', 'attachment_ids' => [$attachmentInAB],
    ])->assertCreated();

    $this->actingAs($alice)->postJson("/api/chat/conversations/{$chatAC}/messages", [
        'type' => 'image', 'attachment_ids' => [$attachmentInAC],
    ])->assertCreated();

    // Alice never opens/lists chatAB or chatAC again before requesting "all media" —
    // the endpoint must aggregate across both conversations from the database, not a client cache.
    $all = $this->actingAs($alice)->getJson('/api/chat/messages/media?kind=media')->assertOk();
    expect($all->json('data'))->toHaveCount(2);

    $scoped = $this->actingAs($alice)
        ->getJson("/api/chat/messages/media?kind=media&conversation_id={$chatAB}")
        ->assertOk();
    expect($scoped->json('data'))->toHaveCount(1);

    // Dave is not a participant of either conversation — he must not see Alice's media.
    $daveView = $this->actingAs($dave)->getJson('/api/chat/messages/media?kind=media')->assertOk();
    expect($daveView->json('data'))->toHaveCount(0);
});

it('searches media by filename or by chat/contact name', function () {
    Storage::fake('chat');

    $alice = mediaUser('alice-mediasearch@example.com');
    $bob = mediaUser('bob-mediasearch@example.com');
    $carol = mediaUser('carol-mediasearch@example.com');

    $chatWithBob = $this->actingAs($alice)->postJson('/api/chat/conversations', [
        'type' => 'private',
        'participants' => [chatableRef($bob)],
    ])->json('data.id');

    $chatWithCarol = $this->actingAs($alice)->postJson('/api/chat/conversations', [
        'type' => 'private',
        'participants' => [chatableRef($carol)],
    ])->json('data.id');

    $vacationPhoto = $this->actingAs($alice)->postJson('/api/chat/attachments', [
        'file' => UploadedFile::fake()->image('vacation-photo.jpg', 200, 200),
    ])->json('data.id');

    $receiptScan = $this->actingAs($alice)->postJson('/api/chat/attachments', [
        'file' => UploadedFile::fake()->image('receipt-scan.jpg', 200, 200),
    ])->json('data.id');

    $this->actingAs($alice)->postJson("/api/chat/conversations/{$chatWithBob}/messages", [
        'type' => 'image', 'attachment_ids' => [$vacationPhoto],
    ])->assertCreated();

    $this->actingAs($alice)->postJson("/api/chat/conversations/{$chatWithCarol}/messages", [
        'type' => 'image', 'attachment_ids' => [$receiptScan],
    ])->assertCreated();

    // Filename match finds only the message with that attachment, regardless of chat.
    $byFilename = $this->actingAs($alice)->getJson('/api/chat/messages/media?kind=media&q=vacation')->assertOk();
    expect($byFilename->json('data'))->toHaveCount(1)
        ->and($byFilename->json('data.0.attachments.0.original_filename'))->toContain('vacation-photo');

    // Contact-name match finds every media item shared in that chat, regardless of filename.
    $byContactName = $this->actingAs($alice)->getJson('/api/chat/messages/media?kind=media&q=carol-mediasearch')->assertOk();
    expect($byContactName->json('data'))->toHaveCount(1)
        ->and($byContactName->json('data.0.attachments.0.original_filename'))->toContain('receipt-scan');

    $noMatch = $this->actingAs($alice)->getJson('/api/chat/messages/media?kind=media&q=nonexistent-term')->assertOk();
    expect($noMatch->json('data'))->toHaveCount(0);
});

it('lists a link in the media links tab even without a fetched preview', function () {
    $alice = mediaUser('alice-linkslist@example.com');
    $bob = mediaUser('bob-linkslist@example.com');

    $conversationId = $this->actingAs($alice)->postJson('/api/chat/conversations', [
        'type' => 'private',
        'participants' => [chatableRef($bob)],
    ])->json('data.id');

    // No metadata.link_preview attached — mirrors a message sent before the composer's
    // debounced OG-preview fetch had a chance to resolve.
    $this->actingAs($alice)->postJson("/api/chat/conversations/{$conversationId}/messages", [
        'type' => 'text',
        'body' => 'check this out https://example.com/page',
    ])->assertCreated();

    $this->actingAs($alice)->postJson("/api/chat/conversations/{$conversationId}/messages", [
        'type' => 'text',
        'body' => 'no link here',
    ])->assertCreated();

    $links = $this->actingAs($alice)->getJson('/api/chat/messages/media?kind=links')->assertOk();

    expect($links->json('data'))->toHaveCount(1)
        ->and($links->json('data.0.body'))->toContain('https://example.com/page');
});

it('recomputes the links tab membership when a message is edited to add or remove a url', function () {
    $alice = mediaUser('alice-linksedit@example.com');
    $bob = mediaUser('bob-linksedit@example.com');

    $conversationId = $this->actingAs($alice)->postJson('/api/chat/conversations', [
        'type' => 'private',
        'participants' => [chatableRef($bob)],
    ])->json('data.id');

    $messageId = $this->actingAs($alice)->postJson("/api/chat/conversations/{$conversationId}/messages", [
        'type' => 'text',
        'body' => 'no link yet',
    ])->assertCreated()->json('data.id');

    $this->actingAs($alice)
        ->getJson('/api/chat/messages/media?kind=links')
        ->assertOk()
        ->assertJsonCount(0, 'data');

    $this->actingAs($alice)
        ->patchJson("/api/chat/messages/{$messageId}", ['body' => 'now with a link https://example.com'])
        ->assertOk();

    $this->actingAs($alice)
        ->getJson('/api/chat/messages/media?kind=links')
        ->assertOk()
        ->assertJsonCount(1, 'data');

    $this->actingAs($alice)
        ->patchJson("/api/chat/messages/{$messageId}", ['body' => 'link removed now'])
        ->assertOk();

    $this->actingAs($alice)
        ->getJson('/api/chat/messages/media?kind=links')
        ->assertOk()
        ->assertJsonCount(0, 'data');
});

it('persists a link preview attached to a sent text message', function () {
    $alice = mediaUser('alice-preview-persist@example.com');
    $bob = mediaUser('bob-preview-persist@example.com');

    $conversationId = $this->actingAs($alice)->postJson('/api/chat/conversations', [
        'type' => 'private',
        'participants' => [chatableRef($bob)],
    ])->json('data.id');

    $sent = $this->actingAs($alice)->postJson("/api/chat/conversations/{$conversationId}/messages", [
        'type' => 'text',
        'body' => 'check this out https://example.com',
        'metadata' => ['link_preview' => [
            'url' => 'https://example.com',
            'title' => 'Example Title',
            'description' => 'Example Desc',
            'image' => 'https://example.com/og.png',
            'site_name' => 'Example',
        ]],
    ])->assertCreated();

    expect($sent->json('data.metadata.link_preview.title'))->toBe('Example Title')
        ->and($sent->json('data.metadata.link_preview.image'))->toBe('https://example.com/og.png');

    // Must still be there on re-fetch, not just in the create response.
    $list = $this->actingAs($bob)->getJson("/api/chat/conversations/{$conversationId}/messages")->assertOk();
    expect($list->json('data.0.metadata.link_preview.title'))->toBe('Example Title');
});

it('rejects an oversized link_preview field submitted directly, bypassing the fetcher', function () {
    $alice = mediaUser('alice-preview-oversized@example.com');
    $bob = mediaUser('bob-preview-oversized@example.com');

    $conversationId = $this->actingAs($alice)->postJson('/api/chat/conversations', [
        'type' => 'private',
        'participants' => [chatableRef($bob)],
    ])->json('data.id');

    // The composer normally attaches whatever OpenGraphLinkPreviewFetcher (size-capped)
    // returned, but metadata.link_preview is submitted as plain client data — nothing stops a
    // client from sending arbitrary-length strings here directly instead.
    $this->actingAs($alice)->postJson("/api/chat/conversations/{$conversationId}/messages", [
        'type' => 'text',
        'body' => 'check this out https://example.com',
        'metadata' => ['link_preview' => [
            'url' => 'https://example.com/'.str_repeat('a', 2048),
            'title' => str_repeat('a', 501),
            'description' => str_repeat('a', 501),
            'image' => 'https://example.com/'.str_repeat('a', 2048),
            'site_name' => str_repeat('a', 256),
        ]],
    ])->assertInvalid([
        'metadata.link_preview.url',
        'metadata.link_preview.title',
        'metadata.link_preview.description',
        'metadata.link_preview.image',
        'metadata.link_preview.site_name',
    ]);
});

it('rejects more than 30 attachment_ids on a single message', function () {
    $alice = mediaUser('alice-toomanyattach@example.com');
    $bob = mediaUser('bob-toomanyattach@example.com');

    $conversationId = $this->actingAs($alice)->postJson('/api/chat/conversations', [
        'type' => 'private',
        'participants' => [chatableRef($bob)],
    ])->json('data.id');

    // Validation rejects on array size alone, before touching the DB, so these don't need to
    // be real attachment ids.
    $this->actingAs($alice)->postJson("/api/chat/conversations/{$conversationId}/messages", [
        'type' => 'image',
        'attachment_ids' => range(1, 31),
    ])->assertInvalid(['attachment_ids']);
});

it('rejects a call log with more than 200 participants', function () {
    $alice = mediaUser('alice-callparticipants@example.com');
    $bob = mediaUser('bob-callparticipants@example.com');

    $conversationId = $this->actingAs($alice)->postJson('/api/chat/conversations', [
        'type' => 'private',
        'participants' => [chatableRef($bob)],
    ])->json('data.id');

    $participants = collect(range(1, 201))->map(fn ($id) => ['type' => 'user', 'id' => $id])->all();

    $this->actingAs($alice)->postJson("/api/chat/conversations/{$conversationId}/messages", [
        'type' => 'call',
        'metadata' => ['duration_seconds' => 30, 'participants' => $participants],
    ])->assertInvalid(['metadata.participants']);
});

it('rejects an oversized url when requesting a link preview', function () {
    $alice = mediaUser('alice-previewurloversized@example.com');

    $this->actingAs($alice)
        ->postJson('/api/chat/link-preview', ['url' => 'https://example.com/'.str_repeat('a', 2048)])
        ->assertInvalid(['url']);
});

it('fetches and caches a link preview', function () {
    $alice = mediaUser('alice-preview@example.com');

    Http::fake([
        'example.com/*' => Http::response(
            '<html><head><meta property="og:title" content="Example Title"><meta property="og:description" content="Example Desc"></head></html>',
            200
        ),
    ]);

    $response = $this->actingAs($alice)
        ->postJson('/api/chat/link-preview', ['url' => 'https://example.com/page'])
        ->assertOk();

    expect($response->json('data.title'))->toBe('Example Title')
        ->and($response->json('data.description'))->toBe('Example Desc');

    // Second call should be served from cache, not a second HTTP request.
    $this->actingAs($alice)->postJson('/api/chat/link-preview', ['url' => 'https://example.com/page'])->assertOk();

    Http::assertSentCount(1);
});

it('retries a link preview that failed instead of caching the empty result', function () {
    $alice = mediaUser('alice-preview-retry@example.com');

    // One fake registration with a sequence — Http::fake() resets its own request log each
    // time it's called, so this is the correct way to assert a real second network attempt
    // rather than a replayed cache hit.
    Http::fake([
        'dead-site.example/*' => Http::sequence()
            ->push('', 500)
            ->push('<html><head><meta property="og:title" content="Back Up"></head></html>', 200),
    ]);

    $first = $this->actingAs($alice)
        ->postJson('/api/chat/link-preview', ['url' => 'https://dead-site.example/page'])
        ->assertOk();
    expect($first->json('data.title'))->toBeNull();

    // A second attempt should retry over the network rather than replay the cached failure —
    // a dead site now responding shouldn't stay "no preview" for the rest of the cache TTL.
    $second = $this->actingAs($alice)
        ->postJson('/api/chat/link-preview', ['url' => 'https://dead-site.example/page'])
        ->assertOk();
    expect($second->json('data.title'))->toBe('Back Up');

    Http::assertSentCount(2);
});

it('blocks a link preview targeting a private/loopback IP instead of issuing the request (SSRF guard)', function () {
    $alice = mediaUser('alice-ssrf-private@example.com');

    // No matcher registered — any request that reaches the HTTP client gets Laravel's default
    // fake 200 response rather than a real connection attempt, so this stays hermetic even if
    // the guard has a bug; assertNothingSent() below is what actually proves the guard fired.
    Http::fake();

    $response = $this->actingAs($alice)
        ->postJson('/api/chat/link-preview', ['url' => 'http://127.0.0.1/admin'])
        ->assertOk();

    expect($response->json('data.title'))->toBeNull();
    Http::assertNothingSent();
});

it('blocks a link preview targeting the cloud metadata endpoint', function () {
    $alice = mediaUser('alice-ssrf-metadata@example.com');

    Http::fake();

    $response = $this->actingAs($alice)
        ->postJson('/api/chat/link-preview', ['url' => 'http://169.254.169.254/latest/meta-data/'])
        ->assertOk();

    expect($response->json('data.title'))->toBeNull();
    Http::assertNothingSent();
});

it('rejects a non-http(s) scheme for link previews', function () {
    $alice = mediaUser('alice-ssrf-scheme@example.com');

    $this->actingAs($alice)
        ->postJson('/api/chat/link-preview', ['url' => 'file:///etc/passwd'])
        ->assertStatus(422);
});

it('blocks a link preview redirect that points at a private IP instead of following it', function () {
    $alice = mediaUser('alice-ssrf-redirect@example.com');

    Http::fake([
        'http://1.1.1.1/*' => Http::response('', 302, ['Location' => 'http://127.0.0.1/internal']),
    ]);

    $response = $this->actingAs($alice)
        ->postJson('/api/chat/link-preview', ['url' => 'http://1.1.1.1/page'])
        ->assertOk();

    expect($response->json('data.title'))->toBeNull();
    // Only the first hop should ever be requested — the redirect target must be validated and
    // rejected before it's followed, not fetched and then discarded.
    Http::assertSentCount(1);
});
