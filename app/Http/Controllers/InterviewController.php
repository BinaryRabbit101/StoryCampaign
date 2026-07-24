<?php

namespace App\Http\Controllers;

use App\Models\Campaign;
use App\Models\InterviewMessage;
use App\Services\Claude\Interviewer;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class InterviewController extends Controller
{
    public function show(Request $request, Campaign $campaign): Response|RedirectResponse
    {
        abort_unless($campaign->user_id === $request->user()->id, 403);

        if ($campaign->status !== 'interview') {
            return redirect()->route('campaigns.show', $campaign);
        }

        return Inertia::render('Interview', [
            'campaign' => $campaign->only(['id', 'name', 'status']),
            'messages' => $campaign->interviewMessages()->where('kind', 'creation')->orderBy('id')->get()
                ->map(fn (InterviewMessage $m) => $m->only(['id', 'role', 'body', 'suggestions'])),
        ]);
    }

    public function message(Request $request, Campaign $campaign, Interviewer $interviewer): RedirectResponse
    {
        abort_unless($campaign->user_id === $request->user()->id, 403);
        abort_unless($campaign->status === 'interview', 400);

        $validated = $request->validate(['body' => ['required', 'string', 'max:2000']]);

        $interviewer->converse($campaign, $validated['body']);

        $campaign->refresh();

        return $campaign->status === 'active'
            ? redirect()->route('play.show', $campaign)
            : redirect()->route('interview.show', $campaign);
    }

    /** Growth request: same narrated-request mechanic, from the play screen. */
    public function grow(Request $request, Campaign $campaign, Interviewer $interviewer): RedirectResponse
    {
        abort_unless($campaign->user_id === $request->user()->id, 403);
        abort_unless($campaign->status === 'active', 400);

        $validated = $request->validate(['body' => ['required', 'string', 'max:2000']]);

        $interviewer->grow($campaign, $validated['body']);

        return redirect()->route('play.show', $campaign);
    }
}
