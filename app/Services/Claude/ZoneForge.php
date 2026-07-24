<?php

namespace App\Services\Claude;

use App\Game\Capability;
use App\Models\Actor;
use App\Models\Campaign;
use App\Models\Chapter;
use App\Models\SceneFeature;
use App\Models\Zone;
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
    }

    /** Claude-forged, engine-clamped; shared-world clone when the forge is cold. */
    public function forge(Campaign $campaign, ?Zone $leaving): Zone
    {
        try {
            $plan = $this->sanitize($this->claude->promptForJson($this->buildPrompt($campaign, $leaving)));

            return $this->materialize($campaign, $plan);
        } catch (Throwable $e) {
            report($e);

            return $this->cloneSharedZone($campaign, $leaving);
        }
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

    private function materialize(Campaign $campaign, array $plan): Zone
    {
        $zone = Zone::create([
            'campaign_id' => $campaign->id,
            'slug' => $this->uniqueSlug($plan['name'], $campaign),
            'name' => $plan['name'],
            'description' => $plan['description'],
            'tags' => ['locales' => $plan['locales']],
            'source' => 'forge',
        ]);

        foreach ($plan['features'] as $feature) {
            SceneFeature::create([
                'scene_id' => null,
                'zone_id' => $zone->id,
                'name' => $feature['name'],
                'feature_type' => $feature['feature_type'],
                'affordances' => $feature['affordances'],
                'source' => 'forge',
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
                'source' => 'forge',
            ]);
        }

        return $zone;
    }

    /**
     * The cold-forge fallback: clone a shared-world zone (templates and all)
     * as this campaign's own ground, so play continues on known archetypes
     * rather than stalling on a failed Claude call.
     */
    private function cloneSharedZone(Campaign $campaign, ?Zone $leaving): Zone
    {
        $donor = Zone::shared()
            ->when($leaving !== null, fn ($q) => $q->where('name', '!=', $leaving->name))
            ->inRandomOrder()
            ->firstOrFail();

        $zone = Zone::create([
            'campaign_id' => $campaign->id,
            'slug' => $this->uniqueSlug($donor->name, $campaign),
            'name' => $donor->name,
            'description' => $donor->description,
            'tags' => $donor->tags,
            'source' => 'seed',
        ]);

        foreach ($donor->features()->whereNull('scene_id')->get() as $feature) {
            SceneFeature::create([
                'scene_id' => null,
                'zone_id' => $zone->id,
                'name' => $feature->name,
                'feature_type' => $feature->feature_type,
                'affordances' => $feature->affordances,
                'source' => $feature->source,
            ]);
        }

        foreach ($donor->actors()->whereNull('scene_id')->where('status', 'active')->get() as $actor) {
            Actor::create([
                'scene_id' => null,
                'zone_id' => $zone->id,
                'name' => $actor->name,
                'kind' => $actor->kind,
                'tier' => $actor->tier,
                'stats' => $actor->stats,
                'tags' => $actor->tags,
                'status' => 'active',
                'source' => $actor->source,
            ]);
        }

        return $zone;
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

        $stage = $campaign->stageBrief() ?: '(none set — invent freely inside the bible\'s tone)';
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

        return <<<PROMPT
You are the world-forge of a living-world RPG. Forge ONE new zone — a whole region of this campaign's private world — colored by the player's stage and the story so far. {$context}

## Design bible (honor tone and themes absolutely)
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
Actors: kind enemy|npc|creature, tier regular|elite, health max ≤ 12, attack ≤ 4. Mix threats with people worth talking to. Give NPCs tags like {"talkable": true, "persuadeable": true, "companionable": true} and enemies {"intimidatable": true, "restrainable": true, "deceiveable": true} as fits.

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
