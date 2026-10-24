<?php

namespace Chat\Http\Requests;

use Chat\Chat;
use Chat\Services\ParticipantService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AddParticipantsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'participants' => ['required', 'array', 'min:1', 'max:'.ParticipantService::MAX_PARTICIPANTS_PER_CONVERSATION],
            'participants.*.type' => ['required', 'string', Rule::in(array_keys(Chat::chatableModels()))],
            'participants.*.id' => ['required', 'integer'],
        ];
    }
}
