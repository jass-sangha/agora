<?php

namespace Chat\Policies;

use Chat\Contracts\ParticipantRepositoryInterface;
use Chat\Enums\ConversationType;
use Chat\Enums\MessageType;
use Chat\Enums\ParticipantRole;
use Chat\Models\Message;
use Illuminate\Database\Eloquent\Model;

class MessagePolicy
{
    public function __construct(
        protected ParticipantRepositoryInterface $participants,
    ) {}

    public function view(Model $user, Message $message): bool
    {
        return $this->isActiveParticipant($message, $user) && $this->isAccessible($message, $user);
    }

    public function react(Model $user, Message $message): bool
    {
        return $this->isActiveParticipant($message, $user) && $this->isAccessible($message, $user);
    }

    public function vote(Model $user, Message $message): bool
    {
        // Without this, a vote on a non-poll message 422s only by accident: PollVoteService
        // reads $message->metadata['options'] ?? [], and an empty array happens to fail every
        // option-index bounds check. Explicit here so that behavior isn't load-bearing.
        return $message->type === MessageType::Poll
            && $this->isActiveParticipant($message, $user)
            && $this->isAccessible($message, $user);
    }

    public function rsvp(Model $user, Message $message): bool
    {
        // EventRsvpService::respond() never reads message metadata before writing, so without
        // this an RSVP on a non-event message would silently succeed and create a real EventRsvp
        // row against an arbitrary message — not a security hole (still scoped to the caller's
        // own RSVP on a message they can already see), but a data-integrity gap.
        return $message->type === MessageType::Event
            && $this->isActiveParticipant($message, $user)
            && $this->isAccessible($message, $user);
    }

    public function star(Model $user, Message $message): bool
    {
        return $this->isActiveParticipant($message, $user) && $this->isAccessible($message, $user);
    }

    public function pin(Model $user, Message $message): bool
    {
        return $this->isActiveParticipant($message, $user) && $this->isAccessible($message, $user);
    }

    public function forward(Model $user, Message $message): bool
    {
        // Without this, "delete for me" is cosmetic rather than a real access boundary: a user
        // could delete a message for themselves (hiding it from their own timeline) and then
        // still forward its full content into another conversation via the message id alone —
        // re-introducing content they'd just asked to have removed from their view. Same for a
        // disappearing message past its expires_at: forwarding it would re-introduce content
        // that's supposed to already be gone into a conversation with no TTL on it at all.
        return $this->isActiveParticipant($message, $user) && $this->isAccessible($message, $user);
    }

    public function deleteForMe(Model $user, Message $message): bool
    {
        return $this->isActiveParticipant($message, $user);
    }

    public function update(Model $user, Message $message): bool
    {
        if (! $this->isSender($message, $user)) {
            return false;
        }

        // Editing broadcasts MessageUpdated to every other participant — a sender who's deleted
        // this message for themselves shouldn't still be able to change what everyone else sees
        // via an id they merely remember, the same as they can no longer react to, star, pin, or
        // forward it.
        if (! $this->isAccessible($message, $user)) {
            return false;
        }

        if ($message->type !== MessageType::Text) {
            return false;
        }

        return ! $this->pastWindow($message, config('chat.message.edit_window_minutes'));
    }

    public function delete(Model $user, Message $message): bool
    {
        if ($this->isSender($message, $user)) {
            $window = config('chat.message.delete_for_everyone_window_minutes');

            if (! $this->pastWindow($message, $window)) {
                return true;
            }
        }

        return $this->isAdmin($message, $user);
    }

    protected function isSender(Message $message, Model $user): bool
    {
        return $message->chatable_type === $user->getMorphClass()
            && $message->chatable_id === $user->getKey();
    }

    protected function isActiveParticipant(Message $message, Model $user): bool
    {
        return $this->participants->isActiveParticipant($message->conversation_id, $user);
    }

    // Two independent reasons a message that's technically still a row in the database should
    // be treated as gone: the viewer deleted it for themselves ("delete for me" only means
    // something as a real access boundary if it also blocks direct access to the message by id,
    // not just the default timeline query's whereDoesntHave('deletions', ...) filter), or its
    // disappearing-messages TTL has passed (chat:prune-expired-messages only physically deletes
    // the row on whatever schedule the host configures — see Message::isExpired() — so the row
    // can outlive its expires_at by however long that schedule allows, or indefinitely if a host
    // never wires the command up at all).
    protected function isAccessible(Message $message, Model $user): bool
    {
        return ! $message->isExpired() && ! $this->isDeletedForUser($message, $user);
    }

    protected function isDeletedForUser(Message $message, Model $user): bool
    {
        return $message->deletions()
            ->where('chatable_type', $user->getMorphClass())
            ->where('chatable_id', $user->getKey())
            ->exists();
    }

    protected function isAdmin(Message $message, Model $user): bool
    {
        // "Admin" only means something for a group — a private conversation's creator gets the
        // same role value as a data-model artifact of how participant rows are seeded, not
        // because a 1:1 chat has an admin. Without this guard, the sender of a private message
        // would be able to delete it for everyone past the window purely by having started the
        // conversation.
        if ($message->conversation->type !== ConversationType::Group) {
            return false;
        }

        $participant = $this->participants->findFor($message->conversation_id, $user);

        return $participant !== null
            && $participant->left_at === null
            && $participant->role === ParticipantRole::Admin;
    }

    protected function pastWindow(Message $message, ?int $windowMinutes): bool
    {
        if ($windowMinutes === null) {
            return false;
        }

        return $message->created_at->addMinutes($windowMinutes)->isPast();
    }
}
