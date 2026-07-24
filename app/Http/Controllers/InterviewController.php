<?php

namespace App\Http\Controllers;

use App\Game\TraitCatalog;
use App\Models\Campaign;
use App\Models\InterviewMessage;
use App\Services\Claude\Interviewer;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
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
            'canInsist' => $campaign->pending_sheet !== null,
            'messages' => $campaign->interviewMessages()->where('kind', 'creation')->orderBy('id')->get()
                ->map(fn (InterviewMessage $m) => $m->only(['id', 'role', 'body', 'suggestions'])),
            'catalog' => [
                'points' => TraitCatalog::startingPoints(),
                'positives' => collect(TraitCatalog::positives())
                    ->map(fn ($t, $key) => ['key' => $key, 'label' => $t['label'], 'description' => $t['description'], 'cost' => $t['cost'], 'group' => $t['group'] ?? null])
                    ->values(),
                'negatives' => collect(TraitCatalog::negatives())
                    ->map(fn ($t, $key) => ['key' => $key, 'label' => $t['label'], 'description' => $t['description'], 'refund' => $t['refund'], 'group' => $t['group'] ?? null])
                    ->values(),
            ],
        ]);
    }

    /**
     * The point-buy path: the engine prices and validates the build (the
     * balance must at least break even); Claude only writes prose around
     * the finished sheet.
     */
    public function build(Request $request, Campaign $campaign, Interviewer $interviewer): RedirectResponse
    {
        abort_unless($campaign->user_id === $request->user()->id, 403);
        abort_unless($campaign->status === 'interview', 400);

        $validated = $request->validate([
            'name' => ['nullable', 'string', 'max:40'],
            'traits' => ['required', 'array', 'max:20'],
            'traits.*' => ['string'],
            'override' => ['nullable', 'boolean'],
        ]);

        // The override softens ONLY the balance; unknown traits, group
        // conflicts, and giftless builds stay refused.
        $reason = TraitCatalog::rejectionReason($validated['traits'], allowOverspend: (bool) ($validated['override'] ?? false));
        if ($reason !== null) {
            throw ValidationException::withMessages(['traits' => $reason]);
        }

        $interviewer->buildFromTraits($campaign, $validated['traits'], $validated['name'] ?? null);

        return redirect()->route('play.show', $campaign);
    }

    /**
     * The interview-side override: finalize the sheet the world refused,
     * unbalanced and owing.
     */
    public function insist(Request $request, Campaign $campaign, Interviewer $interviewer): RedirectResponse
    {
        abort_unless($campaign->user_id === $request->user()->id, 403);
        abort_unless($campaign->status === 'interview', 400);
        abort_unless($campaign->pending_sheet !== null, 409, 'There is no refused sheet to insist on.');

        $interviewer->insist($campaign);

        return redirect()->route('play.show', $campaign);
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
