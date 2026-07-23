<?php

namespace App\Http\Controllers;

use App\Models\Campaign;
use App\Models\Character;
use App\Services\BookCompiler;
use App\Services\Claude\Interviewer;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class CampaignController extends Controller
{
    public function index(Request $request): Response
    {
        $rows = $request->user()->campaigns()
            ->with('character')
            ->latest()
            ->get();

        $campaigns = $rows->map(fn (Campaign $c) => [
            'id' => $c->id,
            'name' => $c->name,
            'status' => $c->status,
            'title' => $c->title,
            'character' => $c->character?->name,
            'started_at' => $c->started_at?->toFormattedDateString(),
            'ended_at' => $c->ended_at?->toFormattedDateString(),
        ]);

        // Heroes who can return for a new tale (each entry is that
        // campaign's own copy — lineage, not a shared record).
        $characters = $rows
            ->filter(fn (Campaign $c) => $c->character !== null)
            ->map(fn (Campaign $c) => [
                'id' => $c->character->id,
                'name' => $c->character->name,
                'from' => $c->title ?? $c->name,
            ])->values();

        return Inertia::render('Campaigns/Index', ['campaigns' => $campaigns, 'characters' => $characters]);
    }

    public function store(Request $request, Interviewer $interviewer): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:80'],
            'character_id' => ['nullable', 'integer'],
        ]);

        $original = ($validated['character_id'] ?? null) === null ? null
            : Character::whereHas('campaign', fn ($q) => $q->where('user_id', $request->user()->id))
                ->findOrFail($validated['character_id']);

        $campaign = $request->user()->campaigns()->create([
            'name' => $validated['name'],
            'status' => 'interview',
        ]);

        if ($original !== null) {
            $interviewer->returnCharacter($campaign, $original);
        } else {
            $interviewer->open($campaign);
        }

        return redirect()->route('campaigns.show', $campaign);
    }

    /** Route the player to wherever this campaign currently lives. */
    public function show(Request $request, Campaign $campaign): RedirectResponse
    {
        $this->authorizeCampaign($request, $campaign);

        return match ($campaign->status) {
            'interview' => redirect()->route('interview.show', $campaign),
            'active' => redirect()->route('play.show', $campaign),
            default => redirect()->route('book.show', $campaign),
        };
    }

    /** End a campaign early; the player chooses whether Claude writes a coda. */
    public function end(Request $request, Campaign $campaign, BookCompiler $compiler): RedirectResponse
    {
        $this->authorizeCampaign($request, $campaign);
        abort_unless($campaign->status === 'active', 400);

        $validated = $request->validate(['coda' => ['required', 'boolean']]);

        $compiler->close($campaign, early: true, withCoda: $validated['coda']);

        return redirect()->route('book.show', $campaign);
    }

    private function authorizeCampaign(Request $request, Campaign $campaign): void
    {
        abort_unless($campaign->user_id === $request->user()->id, 403);
    }
}
