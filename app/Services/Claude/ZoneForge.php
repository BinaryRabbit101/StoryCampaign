<?php

namespace App\Services\Claude;

use App\Game\Capability;
use App\Game\WorldFlavor;
use App\Models\Actor;
use App\Models\Campaign;
use App\Models\Chapter;
use App\Models\SceneFeature;
use App\Models\Zone;
use App\Services\Rumors;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Throwable;

/**
 * Forges campaign-scoped zones: the starting world at creation, and each
 * frontier zone as the tale ranges outward. Claude proposes a whole zone —
 * name, locales, feature and actor templates — colored by the premise,
 * tone, and the story so far; the engine keeps only what survives the
 * forge budget, the affordance grammar, and the same stat clamps the
 * evolver lives under. When Claude is unavailable, a shared-world zone is
 * cloned as the campaign's own ground instead — the tale never stalls.
 */
class ZoneForge
{
    private const SIZES = ['small', 'medium', 'large'];

    private const GAPS = ['short', 'medium', 'far'];

    public function __construct(private readonly ClaudeCli $claude) {}

    /** Forge (or fall back to) the zone a new campaign opens in. */
    public function ensureStartingZone(Campaign $campaign): Zone
    {
        $existing = Zone::find($campaign->starting_zone_id);
        if ($existing !== null) {
            return $existing;
        }

        $zone = $this->forge($campaign, leaving: null);
        $campaign->update(['starting_zone_id' => $zone->id]);

        return $zone;
    }

    /**
     * Frontier growth: once the tale has ranged across enough scenes of the
     * current zone, pre-forge the next one so the "press on" card can open
     * the way. Runs off the player's clock (from the narration job); a
     * failure here costs nothing but the wait for the next chapter.
     */
    public function ensureFrontierZone(Campaign $campaign): void
    {
        if ($campaign->next_zone_id !== null) {
            return;
        }

        $scene = $campaign->scenes()->where('status', 'active')->orderByDesc('id')->first();
        if ($scene === null) {
            return;
        }

        $ranged = $campaign->scenes()->where('zone_id', $scene->zone_id)->count();
        if ($ranged < (int) config('game.frontier_scenes')) {
            return;
        }

        $zone = $this->forge($campaign, leaving: $scene->zone);
        $campaign->update(['next_zone_id' => $zone->id]);

        // The road ahead gets a voice before anybody walks it, so the venture
        // card's destination is somewhere the character has heard of rather
        // than a name that appears out of nothing. A failure here costs the
        // hearsay and never the zone.
        try {
            Rumors::fromForge($campaign, $zone);
        } catch (Throwable $e) {
            report($e);
        }
    }

    /** Claude-forged, engine-clamped; engine-built from the campaign's land when the forge is cold. */
    public function forge(Campaign $campaign, ?Zone $leaving): Zone
    {
        $opening = $leaving === null;

        try {
            $plan = $this->sanitize($this->claude->promptForJson($this->buildPrompt($campaign, $leaving)));

            return $this->materialize($campaign, $this->stock($plan, $campaign, $opening));
        } catch (Throwable $e) {
            report($e);

            return $this->coldForge($campaign, $opening);
        }
    }

    /**
     * What every zone must hold, whatever the forge proposed.
     *
     * A playtest ran nine turns without meeting a single hostile, on ground
     * whose whole enemy roster was one template — the wandering-threat roll had
     * almost nothing to draw from, so the world could not bite even when it
     * rolled to. Both rules here are about giving that roll something to find;
     * neither touches a number anywhere.
     *
     *  - TWO threats, minimum. Topped up from this campaign's own land kit (the
     *    same kit the cold forge builds from), never from the shared world.
     *  - The PREMISE, given a body. A tale premised on banishing a demon should
     *    have something in it the engine can actually put in the player's way,
     *    and this is the cheapest honest way to do it: one more actor template,
     *    marked so nothing later mistakes it for surplus. Claude names it (the
     *    prompt asks, and the name is clamped like every other actor's); the
     *    cold path names one from the kit. It spawns through the machinery that
     *    already existed and carries no mechanics of its own.
     *
     * @param  array{name:string,description:string,locales:list<array>,features:list<array>,actors:list<array>}  $plan
     * @return array{name:string,description:string,locales:list<array>,features:list<array>,actors:list<array>}
     */
    private function stock(array $plan, Campaign $campaign, bool $opening): array
    {
        $budget = (int) config('game.bounds.zone_forge')['actors'];
        $kit = collect(WorldFlavor::coldPlan($campaign->worldFlavor())['actors']);
        $taken = collect($plan['actors'])->pluck('name')->map(fn ($n) => mb_strtolower((string) $n))->all();

        // By reference: each name this takes is a name it must not take again,
        // or a zone short of two threats would be topped up with the same
        // figure standing in it twice.
        $spare = function (string $kind) use ($kit, &$taken): ?array {
            return $kit->first(fn (array $a) => $a['kind'] === $kind
                && ! in_array(mb_strtolower($a['name']), $taken, true));
        };

        // The premise, embodied. Only the opening zone, only where the player
        // actually set one, and only when Claude did not already answer the
        // prompt's request for it.
        $premise = trim((string) $campaign->premise);
        $anchored = collect($plan['actors'])->contains(fn (array $a) => ($a['tags']['premise_anchor'] ?? false) === true);

        if ($opening && $premise !== '' && ! $anchored) {
            // Whatever this zone already holds that could stand in the way,
            // before inventing anything: a bespoke forged threat is a better
            // face for the premise than a kit name dropped into somebody
            // else's region. Only ground with nothing hostile on it at all
            // borrows from the kit.
            $index = collect($plan['actors'])
                ->search(fn (array $a) => in_array($a['kind'], ['enemy', 'creature'], true));

            if ($index === false) {
                $stand = $spare('enemy') ?? $spare('creature');

                if ($stand !== null) {
                    $plan['actors'][] = $stand;
                    $taken[] = mb_strtolower($stand['name']);
                    $index = array_key_last($plan['actors']);
                }
            }

            if ($index !== false) {
                $plan['actors'][$index]['tags'] = array_merge(
                    $plan['actors'][$index]['tags'] ?? [], ['premise_anchor' => true],
                );
            }
        }

        // And enough of a roster for the world to have something to send.
        while (collect($plan['actors'])->filter(fn (array $a) => $a['kind'] === 'enemy')->count() < 2) {
            $threat = $spare('enemy');

            if ($threat === null) {
                break;
            }

            $plan['actors'][] = $threat;
            $taken[] = mb_strtolower($threat['name']);
        }

        // The budget still binds — but it now sheds the people rather than the
        // threats, because an over-budget plan that dropped the last enemy
        // would undo the very thing above it.
        if (count($plan['actors']) > $budget) {
            $plan['actors'] = collect($plan['actors'])
                ->sortBy(fn (array $a) => match (true) {
                    ($a['tags']['premise_anchor'] ?? false) === true => 0,
                    $a['kind'] === 'enemy' => 1,
                    default => 2,
                })
                ->take($budget)->values()->all();
        }

        return $plan;
    }

    /**
     * Enforce the forge budget, the affordance grammar, and actor stat
     * clamps, no matter what the LLM proposed. Unknown affordance keys and
     * unknown capability names are dropped — the vocabulary only grows
     * through evolution, never through a forge run.
     */
    public function sanitize(array $plan): array
    {
        $budget = config('game.bounds.zone_forge');

        $locales = collect($plan['locales'] ?? [])
            ->filter(fn ($l) => is_array($l) && ($l['title'] ?? '') !== '')
            ->take($budget['locales'])
            ->map(fn ($l) => [
                'title' => (string) $l['title'],
                'description' => (string) ($l['description'] ?? ''),
            ])
            ->values()->all();

        $features = collect($plan['features'] ?? [])
            ->filter(fn ($f) => is_array($f) && ($f['name'] ?? '') !== '')
            ->take($budget['features'])
            ->map(fn ($f) => [
                'name' => (string) $f['name'],
                'feature_type' => (string) ($f['feature_type'] ?? 'landmark'),
                'affordances' => $this->sanitizeAffordances(is_array($f['affordances'] ?? null) ? $f['affordances'] : []),
            ])
            ->values()->all();

        $actors = collect($plan['actors'] ?? [])
            ->filter(fn ($a) => is_array($a) && ($a['name'] ?? '') !== '')
            ->take($budget['actors'])
            ->map(function ($actor) {
                $stats = $actor['stats'] ?? [];
                $max = min(12, max(1, (int) ($stats['health']['max'] ?? 5)));

                return [
                    'name' => (string) $actor['name'],
                    'kind' => in_array($actor['kind'] ?? 'npc', ['enemy', 'npc', 'creature'], true) ? $actor['kind'] : 'npc',
                    'tier' => in_array($actor['tier'] ?? 'regular', ['regular', 'elite'], true) ? $actor['tier'] : 'regular',
                    'stats' => [
                        'health' => ['current' => $max, 'max' => $max],
                        'attack' => min(4, max(1, (int) ($stats['attack'] ?? 1))),
                    ],
                    'tags' => is_array($actor['tags'] ?? null) ? $actor['tags'] : [],
                ];
            })
            ->values()->all();

        return [
            'name' => trim((string) ($plan['name'] ?? '')) ?: 'The Unnamed Reach',
            'description' => trim((string) ($plan['description'] ?? '')),
            'locales' => $locales,
            'features' => $features,
            'actors' => $actors,
        ];
    }

    /**
     * Only the known affordance grammar survives: whitelisted keys, valid
     * capability names in the *_via lists, magnitudes inside bounds.
     */
    private function sanitizeAffordances(array $raw): array
    {
        $clean = [];

        foreach (['reachable_via', 'crossable_via', 'rideable_via'] as $via) {
            $verbs = collect($raw[$via] ?? [])
                ->filter(fn ($v) => is_string($v) && Capability::tryFrom($v) !== null)
                ->values()->all();
            if ($verbs !== []) {
                $clean[$via] = $verbs;
            }
        }

        if (isset($clean['reachable_via'])) {
            $clean['height'] = min(30, max(1, (int) ($raw['height'] ?? 10)));
        }
        if (isset($clean['crossable_via'])) {
            $clean['gap'] = in_array($raw['gap'] ?? null, self::GAPS, true) ? $raw['gap'] : 'medium';
        }

        if (($raw['flee_destination'] ?? false) === true) {
            $clean['flee_destination'] = true;
            $clean['squeeze_required'] = in_array($raw['squeeze_required'] ?? null, self::SIZES, true)
                ? $raw['squeeze_required'] : 'large';
        }

        if (($raw['hideable'] ?? false) === true) {
            $clean['hideable'] = true;
            if (in_array($raw['max_size'] ?? null, self::SIZES, true)) {
                $clean['max_size'] = $raw['max_size'];
            }
        }

        if (($raw['breakable'] ?? false) === true) {
            $clean['breakable'] = true;
        }
        if (isset($raw['lift_weight'])) {
            $clean['lift_weight'] = min(400, max(10, (int) $raw['lift_weight']));
        }
        if (($raw['hidden'] ?? false) === true) {
            $clean['hidden'] = true;
        }

        return $clean;
    }

    private function materialize(Campaign $campaign, array $plan, string $source = 'forge'): Zone
    {
        $zone = Zone::create([
            'campaign_id' => $campaign->id,
            'slug' => $this->uniqueSlug($plan['name'], $campaign),
            'name' => $plan['name'],
            'description' => $plan['description'],
            'tags' => ['locales' => $plan['locales']],
            'source' => $source,
        ]);

        foreach ($plan['features'] as $feature) {
            SceneFeature::create([
                'scene_id' => null,
                'zone_id' => $zone->id,
                'name' => $feature['name'],
                'feature_type' => $feature['feature_type'],
                'affordances' => $feature['affordances'],
                'source' => $source,
            ]);
        }

        foreach ($plan['actors'] as $actor) {
            Actor::create([
                'scene_id' => null,
                'zone_id' => $zone->id,
                'name' => $actor['name'],
                'kind' => $actor['kind'],
                'tier' => $actor['tier'],
                'stats' => $actor['stats'],
                'tags' => $actor['tags'],
                'status' => 'active',
                'source' => $source,
            ]);
        }

        return $zone;
    }

    /**
     * The cold forge: when Claude is unavailable, the ENGINE builds the zone
     * from this campaign's own land (App\Game\WorldFlavor) — named ground,
     * locales, a full affordance skeleton, and four actors. It used to clone
     * a shared-world zone instead, which is why every offline campaign woke
     * up in the same harbor. Nothing here touches the shared world.
     */
    private function coldForge(Campaign $campaign, bool $opening = false): Zone
    {
        $used = $campaign->zones()->pluck('name')->all();

        return $this->materialize(
            $campaign,
            $this->stock(WorldFlavor::coldPlan($campaign->worldFlavor(), $used), $campaign, $opening),
            source: 'cold',
        );
    }

    private function uniqueSlug(string $name, Campaign $campaign): string
    {
        $base = Str::slug($name).'-c'.$campaign->id;
        $slug = $base;
        for ($i = 2; Zone::where('slug', $slug)->exists(); $i++) {
            $slug = "{$base}-{$i}";
        }

        return $slug;
    }

    private function buildPrompt(Campaign $campaign, ?Zone $leaving): string
    {
        $biblePath = config('game.design_bible_path');
        $bible = File::exists($biblePath) ? mb_substr(File::get($biblePath), 0, 4000) : 'No bible found — be conservative.';

        $land = $campaign->worldBrief();
        $stage = $campaign->stageBrief() ?: '(none set — invent freely inside this land and the bible\'s tone)';
        // Queried, not lazy-loaded: at creation time the character may not
        // exist yet, and caching a null relation here would poison callers.
        $character = $campaign->character()->first()?->description ?? '(character not yet known)';
        $budget = config('game.bounds.zone_forge');

        $story = $campaign->chapters()->reorder('number', 'desc')->limit(2)->get()
            ->reverse()
            ->map(fn (Chapter $c) => mb_substr($c->plainBody(), -800))
            ->join("\n\n") ?: '(the tale has not begun yet)';

        $context = $leaving === null
            ? 'This is the OPENING zone of a brand-new campaign — the ground where the whole tale begins.'
            : "The player is about to push past the frontier of \"{$leaving->name}\" ({$leaving->description}). Forge the region that lies beyond it — related ground, not a copy: a place \"{$leaving->name}\" could believably border.";

        $existing = $campaign->zones()->pluck('name')->join(', ') ?: '(none yet)';

        // The player's own words for the land outrank the genre's idea of what
        // is plausible. A tale set in "a forbidden section of a voyager
        // spaceship" under a modern-realistic genre came back as a SEA
        // passenger ship with wharf rats on it — the genre quietly substituted
        // a cousin it found more believable. It does not get to: the genre is a
        // register to write the named place IN, never a reason to move it.
        $typed = trim((string) $campaign->setting) !== '' ? <<<'TYPED'

            The land above is the player's OWN words, not a catalog entry, and it outranks everything — the genre, the design bible's examples, and any land the engine keeps in a kit. Build THAT place. Render the genre inside it (its tone, its people, its idea of trouble) and never swap it for a more ordinary cousin the genre finds easier to believe: if the words say a ship between stars, it is a ship between stars, whatever the genre would otherwise suggest.

            TYPED : '';

        // Two threats, minimum, and a face for the premise. The engine tops
        // both up if this comes back short (see stock()), but the forge's own
        // words for them are always better than the kit's.
        $anchor = ($leaving === null && trim((string) $campaign->premise) !== '') ? <<<'ANCHOR'
            EXACTLY ONE actor must be the thing the player's premise is about — the trouble at the heart of it, wearing this land's own materials. Give it `"premise_anchor": true` in its tags, a name and description drawn from the premise above, and make it a real presence in the world rather than an idea: kind enemy or creature.

            ANCHOR : '';

        return <<<PROMPT
You are the world-forge of a living-world RPG. Forge ONE new zone — a whole region of this campaign's private world — colored by the land below, the player's stage, and the story so far. {$context}

## The world this campaign is set in (FIXED — build inside it, and nowhere else)
{$land}
Every name, feature, and creature you invent must belong to this world — its land, its genre, and its stated level of magic and machinery. Do not import the setting or genre of any example you have been shown: anything the design bible below illustrates is an example of VOICE, not of place or genre, and this world overrides it.
{$typed}

## Design bible (honor its voice, guardrails, and bounds — but NOT its examples of place or genre)
{$bible}

## The stage the player set
{$stage}

## The character walking this world
{$character}

## The story so far (recent pages)
{$story}

## Zones this campaign's world already holds (do not duplicate their names or character)
{$existing}

## Hard budget (engine-enforced): at most {$budget['features']} feature templates, {$budget['actors']} actor templates, {$budget['locales']} locales.
Locales are the named grounds scenes open onto inside this zone — each a distinct spot with a 1-2 sentence description.
Feature affordances use ONLY these keys: reachable_via (climb|swing|leap|glide, + height 1-30), crossable_via (swim|swing|leap|glide, + gap short|medium|far), flee_destination (+ squeeze_required small|medium|large), hideable (+ max_size small|medium|large), breakable, lift_weight (10-400), hidden (true for 1-2 secret features found only by examining or scouting).
The fiction and the tags must agree: any feature a body could plausibly duck behind or slip inside — a vent, a locker, a stand of reeds, a doorway — MUST carry hideable, and at least ONE feature in the zone must be hideable without also being hidden. A breakable thing carries breakable; a portable thing carries lift_weight. A feature whose fiction promises something its tags don't deliver is a door painted on a wall.
Actors: kind enemy|npc|creature, tier regular|elite, health max ≤ 12, attack ≤ 4. Mix threats with people worth talking to. Give NPCs tags like {"talkable": true, "persuadeable": true, "companionable": true} and enemies {"intimidatable": true, "restrainable": true, "deceiveable": true} as fits.
At least TWO of them must be kind enemy. These are spawn templates, not a cast list — the world draws on them for as long as the tale stays here, and a region with one threat in it is a region nothing can ever happen in.
{$anchor}

Respond with ONLY a JSON object:
{
  "name": "<the zone's name>",
  "description": "<2-3 sentences of what this region is>",
  "locales": [{"title": "...", "description": "..."}],
  "features": [{"name": "...", "feature_type": "...", "affordances": {...}}],
  "actors": [{"name": "...", "kind": "npc", "tier": "regular", "stats": {"health": {"current": 5, "max": 5}, "attack": 1}, "tags": {...}}]
}
PROMPT;
    }
}
