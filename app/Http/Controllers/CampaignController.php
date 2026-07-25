<?php

namespace App\Http\Controllers;

use App\Game\StoryAspects;
use App\Game\WorldFlavor;
use App\Models\Actor;
use App\Models\Campaign;
use App\Models\Character;
use App\Models\SceneFeature;
use App\Services\BookCompiler;
use App\Services\Claude\Interviewer;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
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

        return Inertia::render('Campaigns/Index', [
            'campaigns' => $campaigns,
            'characters' => $characters,
            // The story axes, each pickable or typed. Leaving them alone is
            // the normal path: the engine rolls the world and it is a
            // surprise. None of them touch a single mechanic.
            'genres' => StoryAspects::options(StoryAspects::genres()),
            'drives' => StoryAspects::options(StoryAspects::drives()),
            'techLevels' => StoryAspects::options(StoryAspects::techLevels()),
        ]);
    }

    public function store(Request $request, Interviewer $interviewer): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:80'],
            'character_id' => ['nullable', 'integer'],
            'premise' => ['nullable', 'string', 'max:500'],
            'tone' => ['nullable', 'string', 'max:120'],
            'world_flavor' => ['nullable', 'string', Rule::in(WorldFlavor::keys())],
            // The story axes take a catalog key OR the player's own words —
            // the catalog is a menu, not a fence. Bounded, not enumerated.
            'genre' => ['nullable', 'string', 'max:'.StoryAspects::MAX_LENGTH],
            'drive' => ['nullable', 'string', 'max:'.StoryAspects::MAX_LENGTH],
            'tech_level' => ['nullable', 'string', 'max:'.StoryAspects::MAX_LENGTH],
        ]);

        $original = ($validated['character_id'] ?? null) === null ? null
            : Character::whereHas('campaign', fn ($q) => $q->where('user_id', $request->user()->id))
                ->findOrFail($validated['character_id']);

        // Every tale opens in a world forged for it — there is no zone to
        // choose; the aspects the player set shape what the forge builds. The
        // LAND it builds on is the engine's roll, drawn only from lands that
        // can wear the chosen genre and never repeating one the player just
        // played, so a run of new tales cannot keep opening in the same
        // country — and a starfaring tale cannot open on chalk downs.
        $recent = $request->user()->campaigns()->latest('id')->limit(3)->pluck('world_flavor')->all();
        $genre = StoryAspects::resolve(StoryAspects::genres(), $validated['genre'] ?? null);

        $campaign = $request->user()->campaigns()->create([
            'name' => $validated['name'],
            'premise' => $validated['premise'] ?? null,
            'tone' => $validated['tone'] ?? null,
            'genre' => $validated['genre'] ?? null,
            'drive' => $validated['drive'] ?? null,
            'tech_level' => $validated['tech_level'] ?? null,
            'world_flavor' => $validated['world_flavor'] ?? WorldFlavor::roll(
                avoid: $recent,
                pool: WorldFlavor::keysForGenre($genre),
            ),
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

    /**
     * Delete a campaign and everything that was ever written into it —
     * chapters, turns, scenes (with their scene-scoped actors/features),
     * its forged world (campaign-scoped zones and their templates),
     * character, interview transcript. The shared world is untouched:
     * seed zones, their templates, and items belong to every tale.
     */
    public function destroy(Request $request, Campaign $campaign): RedirectResponse
    {
        $this->authorizeCampaign($request, $campaign);

        // Order matters: chapters reference turns and turns reference
        // scenes without cascade. Scenes cascade their own actors/features
        // at the DB level; the forged world's zone-level templates are
        // removed before their zones; the campaign cascades character +
        // transcript.
        DB::transaction(function () use ($campaign) {
            $campaign->chapters()->delete();
            $campaign->turns()->delete();
            $campaign->scenes()->delete();

            $zoneIds = $campaign->zones()->pluck('id');
            SceneFeature::whereIn('zone_id', $zoneIds)->delete();
            Actor::whereIn('zone_id', $zoneIds)->delete();
            $campaign->update(['starting_zone_id' => null, 'next_zone_id' => null]);
            $campaign->zones()->delete();

            $campaign->delete();
        });

        return redirect()->route('campaigns.index');
    }

    private function authorizeCampaign(Request $request, Campaign $campaign): void
    {
        abort_unless($campaign->user_id === $request->user()->id, 403);
    }
}
