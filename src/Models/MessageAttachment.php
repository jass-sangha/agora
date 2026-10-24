<?php

namespace Chat\Models;

use Chat\Chat;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;

class MessageAttachment extends Model
{
    protected $fillable = [
        'message_id',
        'uploader_id',
        'uploader_type',
        'disk',
        'path',
        'original_filename',
        'mime_type',
        'size_bytes',
        'width',
        'height',
        'duration_seconds',
        'thumbnail_path',
    ];

    public function getTable(): string
    {
        return Chat::table('message_attachments');
    }

    public function message(): BelongsTo
    {
        return $this->belongsTo(Message::class, 'message_id');
    }

    public function uploader(): MorphTo
    {
        return $this->morphTo();
    }

    public function getUrlAttribute(): string
    {
        if (! config('chat.media.serve_privately')) {
            return Storage::disk($this->disk)->url($this->path);
        }

        return $this->signedUrl('chat.attachments.show');
    }

    public function getThumbnailUrlAttribute(): ?string
    {
        if (! $this->thumbnail_path) {
            return null;
        }

        if (! config('chat.media.serve_privately')) {
            return Storage::disk($this->disk)->url($this->thumbnail_path);
        }

        return $this->signedUrl('chat.attachments.thumbnail');
    }

    public function getDownloadUrlAttribute(): string
    {
        return $this->signedUrl('chat.attachments.download');
    }

    // Regenerated fresh on every serialization (never persisted) — see chat.media.
    // signed_url_ttl_minutes in config/chat.php for why that mostly hides the TTL from a client
    // that keeps refetching, and AttachmentController for the MessagePolicy::view re-check this
    // is paired with.
    protected function signedUrl(string $routeName): string
    {
        return URL::temporarySignedRoute(
            $routeName,
            now()->addMinutes((int) config('chat.media.signed_url_ttl_minutes', 60)),
            ['attachment' => $this->id],
        );
    }
}
