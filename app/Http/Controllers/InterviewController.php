<?php

namespace App\Http\Controllers;

use App\Game\NameForge;
use App\Game\TraitCatalog;
use App\Models\Campaign;
use App\Models\InterviewMessage;
use App\Services\Claude\Interviewer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class InterviewController extends Controller
{
    public function show(Request $request, Campaign $campaign, Interviewer $interviewer): Response|RedirectResponse
    {
        abort_unless($campaign->user_id === $request->user()->id, 403);

        if ($campaign->status !== 'interview') {
            // Straight to the story, not to the campaign index. Landing here
            // on an already-started tale means the interview finished without
            // the player seeing it — sending them one more click away from
            // the thing they were waiting for is how they end up believing
            // nothing happened.
            return $campaign->status === 'active'
                ? redirect()->route('play.show', $campaign)
                : redirect()->route('campaigns.show', $campaign);
        }

        return Inertia::render('Interview', [
            'campaign' => $campaign->only(['id', 'name', 'status']),
            // Suggested hero names: the first pre-fills the name field, the
            // rest feed its reroll. Seeded by campaign so a reload offers
            // the same names it did the first time.
            'names' => NameForge::pool($campaign->id),
            // The running sheet, priced. Visible from the narrator's first
            // reply onward, so the bargain is never a surprise at the end.
            'draft' => $interviewer->draftLedger($campaign),
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
     * Begin: the player is finished tuning and steps into the world with the
     * sheet currently on the table. `owing` is the named choice to step
     * through an overspent one and carry the shortfall as a debt.
     */
    public function begin(Request $request, Campaign $campaign, Interviewer $interviewer): RedirectResponse
    {
        abort_unless($campaign->user_id === $request->user()->id, 403);
        abort_unless($campaign->status === 'interview', 400);
        abort_unless($campaign->pending_sheet !== null, 409, 'There is no character yet to step into the world.');

        $validated = $request->validate([
            'owing' => ['nullable', 'boolean'],
            'name' => ['nullable', 'string', 'max:40'],
        ]);

        $interviewer->begin($campaign, (bool) ($validated['owing'] ?? false), $validated['name'] ?? null);

        // A refused bargain leaves the campaign in interview and the reason
        // in the transcript; the player carries on tuning.
        return $campaign->fresh()->status === 'active'
            ? redirect()->route('play.show', $campaign)
            : redirect()->route('interview.show', $campaign);
    }

    /**
     * A cheap, Inertia-free heartbeat for the interview page.
     *
     * The page polls this WHILE a slow request is in flight, which is exactly
     * why it cannot be an Inertia visit: a visit would cancel the very
     * request it is watching. Plain JSON, no side effects — it exists so a
     * player whose connection dropped mid-birth still gets told the world
     * opened without them.
     */
    public function status(Request $request, Campaign $campaign): JsonResponse
    {
        abort_unless($campaign->user_id === $request->user()->id, 403);

        return response()->json([
            'status' => $campaign->status,
            'messages' => $campaign->interviewMessages()->where('kind', 'creation')->count(),
            'play_url' => route('play.show', $campaign),
        ]);
    }

    public function message(Request $request, Campaign $campaign, Interviewer $interviewer): RedirectResponse
    {
        abort_unless($campaign->user_id === $request->user()->id, 403);
        abort_unless($campaign->status === 'interview', 400);

        $validated = $request->validate(['body' => ['required', 'string', 'max:2000']]);

        // A narrator who cannot answer (CLI down, timed out, or malformed
        // twice over) sends the player back to their own words rather than a
        // dead end: nothing was written, so speaking again simply retries.
        try {
            $interviewer->converse($campaign, $validated['body']);
        } catch (\RuntimeException $e) {
            report($e);

            throw ValidationException::withMessages([
                'body' => 'The words did not carry — the world did not hear you. Speak them again.',
            ]);
        }

        // Speaking never starts the tale any more — only Begin does — so the
        // conversation always continues here.
        return redirect()->route('interview.show', $campaign);
    }

    /** Growth request: same narrated-request mechanic, from the play screen. */
    public function grow(Request $request, Campaign $campaign, Interviewer $interviewer): RedirectResponse
    {
        abort_unless($campaign->user_id === $request->user()->id, 403);
        abort_unless($campaign->status === 'active', 400);

        $validated = $request->validate(['body' => ['required', 'string', 'max:2000']]);

        try {
            $interviewer->grow($campaign, $validated['body']);
        } catch (\RuntimeException $e) {
            report($e);

            throw ValidationException::withMessages([
                'body' => 'The world did not answer. Ask again.',
            ]);
        }

        return redirect()->route('play.show', $campaign);
    }
}
