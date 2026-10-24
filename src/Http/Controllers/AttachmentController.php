<?php

namespace Chat\Http\Controllers;

use Chat\Contracts\AttachmentServiceInterface;
use Chat\Http\Requests\StoreAttachmentRequest;
use Chat\Http\Resources\AttachmentResource;
use Chat\Models\MessageAttachment;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;

class AttachmentController extends Controller
{
    public function __construct(
        protected AttachmentServiceInterface $attachments,
    ) {}

    public function store(StoreAttachmentRequest $request)
    {
        $attachment = $this->attachments->upload(
            $request->file('file'),
            $request->user(),
        );

        return (new AttachmentResource($attachment))->response()->setStatusCode(201);
    }

    // Only reachable at all once chat.media.serve_privately is on — MessageAttachment::url()
    // is the only thing that ever points a client here (a signed URL to this same route name),
    // so with it left off nothing generates a link to hit this. The 'signed' route middleware
    // (see routes/api.php) rejects a missing/expired/tampered signature before this even runs;
    // the policy check below is the second, independent layer — re-run on every single fetch,
    // not just once at send time, so a still-valid signature stops being enough the moment the
    // message it belongs to is deleted-for-everyone, expires, or gets deleted for this viewer.
    public function show(Request $request, MessageAttachment $attachment)
    {
        $this->authorizeAttachmentAccess($request, $attachment);

        return Storage::disk($attachment->disk)->response($attachment->path, $attachment->original_filename);
    }

    public function download(Request $request, MessageAttachment $attachment)
    {
        $this->authorizeAttachmentAccess($request, $attachment);

        return Storage::disk($attachment->disk)->download($attachment->path, $attachment->original_filename);
    }

    public function thumbnail(Request $request, MessageAttachment $attachment)
    {
        abort_unless($attachment->thumbnail_path, 404);

        $this->authorizeAttachmentAccess($request, $attachment);

        return Storage::disk($attachment->disk)->response($attachment->thumbnail_path);
    }

    protected function authorizeAttachmentAccess(Request $request, MessageAttachment $attachment): void
    {
        // Not yet attached to any message (a preview between POST /attachments and the
        // follow-up message send) — MessagePolicy has nothing to check yet, so this falls back
        // to the one rule that already governs it elsewhere: only the uploader can reference it
        // (see AttachmentService::attachToMessage()'s own ownership check).
        if ($attachment->message_id === null) {
            $user = $request->user();

            abort_unless(
                $attachment->uploader_type === $user->getMorphClass()
                    && $attachment->uploader_id === $user->getKey(),
                403
            );

            return;
        }

        Gate::authorize('view', $attachment->message);
    }
}
