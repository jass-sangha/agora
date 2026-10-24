<?php

namespace Chat\Contracts;

use Chat\Models\Message;
use Chat\Models\MessageAttachment;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;

interface AttachmentServiceInterface
{
    public function upload(UploadedFile $file, Model $chatable): MessageAttachment;

    /**
     * @param  int[]  $attachmentIds
     */
    public function attachToMessage(array $attachmentIds, Message $message, Model $chatable): void;

    /**
     * @param  Collection<int, MessageAttachment>  $attachments
     */
    public function deleteOrphanedFiles(Collection $attachments): void;
}
