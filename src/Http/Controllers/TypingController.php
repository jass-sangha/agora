<?php

namespace Chat\Http\Controllers;

use Chat\Contracts\UserSettingsServiceInterface;
use Chat\Events\UserStoppedTyping;
use Chat\Events\UserTyping;
use Chat\Models\Conversation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class TypingController extends Controller
{
    public function __construct(
        protected UserSettingsServiceInterface $settings,
    ) {}

    public function update(Request $request, Conversation $conversation)
    {
        Gate::authorize('view', $conversation);

        $data = $request->validate([
            'state' => ['required', Rule::in(['start', 'stop'])],
        ]);

        $chatable = $request->user();

        if (! $this->settings->allowsTypingIndicator($chatable)) {
            return response()->noContent();
        }

        if ($data['state'] === 'start') {
            broadcast(new UserTyping($conversation->id, $chatable))->toOthers();
        } else {
            broadcast(new UserStoppedTyping($conversation->id, $chatable))->toOthers();
        }

        return response()->noContent();
    }
}
