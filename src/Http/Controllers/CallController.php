<?php

namespace Chat\Http\Controllers;

use Chat\Events\CallSignal;
use Chat\Models\Conversation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class CallController extends Controller
{
    public function iceServers(Request $request): JsonResponse
    {
        abort_unless(config('chat.calls.enabled', true), 404);

        $iceServers = config('chat.calls.ice_servers', []);
        $turn = config('chat.calls.turn', []);
        $urls = $turn['urls'] ?? [];
        $sharedSecret = $turn['shared_secret'] ?? null;
        $expiresAt = null;

        if ($urls !== [] && filled($sharedSecret)) {
            $ttl = max(60, min((int) ($turn['credential_ttl'] ?? 3600), 86400));
            $expiresAt = now()->timestamp + $ttl;
            $username = $expiresAt.':'.$request->user()->getAuthIdentifier();

            $iceServers[] = [
                'urls' => $urls,
                'username' => $username,
                'credential' => base64_encode(hash_hmac('sha1', $username, $sharedSecret, true)),
            ];
        } elseif ($urls !== []) {
            throw new \LogicException('CHAT_TURN_SHARED_SECRET is required when CHAT_TURN_URLS is configured.');
        }

        return response()->json([
            'ice_servers' => $iceServers,
            'expires_at' => $expiresAt,
        ]);
    }

    public function signal(Request $request, Conversation $conversation)
    {
        abort_unless(config('chat.calls.enabled', true), 404);

        Gate::authorize('view', $conversation);

        $data = $request->validate([
            'payload' => ['required', 'array:call_id,call_started_at,call_initiator,kind,video,sdp,candidate', function ($attribute, $value, $fail) {
                $maxBytes = config('chat.calls.max_payload_bytes', 65536);

                if (strlen(json_encode($value)) > $maxBytes) {
                    $fail("The {$attribute} must not exceed {$maxBytes} bytes when serialized.");
                }
            }],
            'payload.call_id' => ['required', 'uuid'],
            'payload.call_started_at' => ['required', 'integer', 'min:0'],
            'payload.call_initiator' => ['required', 'string', 'max:255'],
            'payload.kind' => ['required', 'string', Rule::in(['join', 'offer', 'answer', 'ice', 'decline', 'busy', 'leave', 'cancel'])],
            'payload.video' => ['sometimes', 'boolean'],
            'payload.sdp' => ['required_if:payload.kind,offer,answer', 'string'],
            'payload.candidate' => ['required_if:payload.kind,ice', 'array:candidate,sdpMid,sdpMLineIndex,usernameFragment'],
            'payload.candidate.candidate' => ['required_if:payload.kind,ice', 'string'],
            'payload.candidate.sdpMid' => ['nullable', 'string'],
            'payload.candidate.sdpMLineIndex' => ['nullable', 'integer'],
            'payload.candidate.usernameFragment' => ['nullable', 'string'],
            'to_type' => ['required_if:payload.kind,offer,answer,ice,decline,busy,cancel', 'required_with:to_id', 'nullable', 'string'],
            'to_id' => ['required_if:payload.kind,offer,answer,ice,decline,busy,cancel', 'required_with:to_type', 'nullable', 'integer'],
        ]);

        $chatable = $request->user();

        $recipients = $conversation->activeParticipants()
            ->get()
            ->reject(fn ($participant) => $participant->chatable_type === $chatable->getMorphClass()
                && (string) $participant->chatable_id === (string) $chatable->getKey());

        // Narrows recipients to one target instead of broadcasting to the whole conversation —
        // a call's SDP offer/answer/ICE signaling is peer-to-peer, so broadcasting it would make
        // every other member try to answer a negotiation meant for someone else.
        if (! empty($data['to_type']) && isset($data['to_id'])) {
            $recipients = $recipients->filter(fn ($participant) => $participant->chatable_type === $data['to_type']
                && (string) $participant->chatable_id === (string) $data['to_id']);

            abort_if($recipients->isEmpty(), 404);
        }

        $recipientChannels = $recipients
            ->map(fn ($participant) => "chatable.{$participant->chatable_type}.{$participant->chatable_id}")
            ->values()
            ->all();

        broadcast(new CallSignal($conversation->id, $chatable, $data['payload'], $recipientChannels))->toOthers();

        return response()->noContent();
    }
}
