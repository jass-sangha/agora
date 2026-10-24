<?php

namespace Chat\Http\Requests;

use Chat\Support\UploadLimitGuard;
use Illuminate\Foundation\Http\FormRequest;

class StoreAttachmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        UploadLimitGuard::assertWithinServerLimits($this, 'file');
    }

    public function rules(): array
    {
        return [
            'file' => ['required', 'file'],
        ];
    }
}
