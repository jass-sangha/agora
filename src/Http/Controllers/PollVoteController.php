<?php

namespace Chat\Http\Controllers;

use Chat\Contracts\PollVoteServiceInterface;
use Chat\Http\Requests\VoteOnPollRequest;
use Chat\Models\Message;
use Illuminate\Support\Facades\Gate;

class PollVoteController extends Controller
{
    public function __construct(
        protected PollVoteServiceInterface $votes,
    ) {}

    public function store(VoteOnPollRequest $request, Message $message)
    {
        Gate::authorize('vote', $message);

        $tally = $this->votes->toggle(
            $message,
            $request->user(),
            $request->validated()['option_index'],
        );

        return response()->json(['data' => $tally]);
    }
}
