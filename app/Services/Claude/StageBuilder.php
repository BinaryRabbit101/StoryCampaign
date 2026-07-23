<?php

namespace App\Services\Claude;

use App\Models\Campaign;
use App\Models\Zone;
use Illuminate\Support\Facades\File;
use Throwable;

/**
 * Builds a campaign's opening scene outward from the player's stage
 * (premise, tone) and the character stepping into it, instead of every
 * tale opening on the same predetermined seed content. Claude proposes;
 * the engine keeps only what survives the stage budget and stat clamps.
 *
 * Everything generated here is SCENE-scoped (features and actors bound to
 * the opening scene, source 'stage'). The shared world — zones, zone-level
 * templates, items — still grows only through evolution, so the stage can
 * color one campaign's opening without leaking into anyone else's world.
 */
class StageBuilder
{
    public function __construct(private readonly ClaudeCli $claude) {}

    /**
     * Propose an opening for the campaign's starting zone, or null when
     * Claude is unavailable — the caller falls back to zone templates.
     *
     * @return array{scene_title:?string,scene_description:?string,features:list<array>,actors:list<array>}|null
     */
    public function plan(Campaign $campaign, string $characterDescription): ?array
    {
        try {
            $zone = Zone::find($campaign->starting_zone_id) ?? Zone::orderBy('id')->first();
            if ($zone === null) {
                return null;
            }

            return $this->sanitize($this->claude->promptForJson($this->buildPrompt($campaign, $zone, $characterDescription)));
        } catch (Throwable $e) {
            report($e);

            return null;
        }
    }

    /**
     * Enforce the stage budget and the same stat clamps the evolver lives
     * under, no matter what the LLM proposed.
     */
    public function sanitize(array $plan): array
    {
        $budget = config('game.bounds.stage_budget');

        $features = collect($plan['features'] ?? [])
            ->filter(fn ($f) => is_array($f) && ($f['name'] ?? '') !== '')
            ->take($budget['features'])
            ->map(fn ($f) => [
                'name' => (string) $f['name'],
                'feature_type' => (string) ($f['feature_type'] ?? 'landmark'),
                'affordances' => is_array($f['affordances'] ?? null) ? $f['affordances'] : [],
            ])
            ->values()->all();

        $actors = collect($plan['actors'] ?? [])
            ->filter(fn ($a) => is_array($a) && ($a['name'] ?? '') !== '')
            ->take($budget['actors'])
            ->map(function ($actor) {
                $stats = $actor['stats'] ?? [];
                $max = min(12, max(1, (int) ($stats['health']['max'] ?? 6)));

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
            'scene_title' => isset($plan['scene_title']) && $plan['scene_title'] !== '' ? (string) $plan['scene_title'] : null,
            'scene_description' => isset($plan['scene_description']) && $plan['scene_description'] !== '' ? (string) $plan['scene_description'] : null,
            'features' => $features,
            'actors' => $actors,
        ];
    }

    private function buildPrompt(Campaign $campaign, Zone $zone, string $characterDescription): string
    {
        $biblePath = config('game.design_bible_path');
        $bible = File::exists($biblePath) ? mb_substr(File::get($biblePath), 0, 4000) : 'No bible found — be conservative.';

        $zoneFeatures = $zone->features()->whereNull('scene_id')->get()
            ->map(fn ($f) => "- {$f->name}: ".json_encode($f->affordances))
            ->join("\n") ?: '(none)';
        $stage = $campaign->stageBrief() ?: '(none set — build from the zone and the character alone)';
        $budget = config('game.bounds.stage_budget');

        return <<<PROMPT
You are dressing the opening scene of a new campaign in a living-world RPG. Every tale must open differently: build this one outward from the player's stage and the character below, not from stock content. Propose a handful of scene features (affordance-bearing set pieces) and actors present as the tale opens.

## Design bible (honor tone and themes absolutely)
{$bible}

## Where the tale opens (fixed — do not invent a different place)
{$zone->name}: {$zone->description}
Zone features already present in every scene here (do not duplicate them):
{$zoneFeatures}

## The stage the player set
{$stage}

## The character stepping in
{$characterDescription}

## Hard budget (engine-enforced): at most {$budget['features']} features, {$budget['actors']} actors.
Actors: kind enemy|npc|creature, tier regular|elite, health max ≤ 12, attack ≤ 4. Open gently — at most one enemy, and only if the stage calls for early danger. Give NPCs tags like {"talkable": true, "persuadeable": true, "companionable": true} and enemies {"intimidatable": true, "restrainable": true}.
Feature affordances use keys like: reachable_via (climb|swing|leap + height), crossable_via (+ gap short|medium|far), flee_destination (+ squeeze_required small|medium|large), hideable (+ max_size), breakable, lift_weight.

Respond with ONLY a JSON object:
{
  "scene_title": "<evocative name for this opening scene, rooted in the zone>",
  "scene_description": "<2-3 sentences of where the character stands as the tale opens, colored by the premise>",
  "features": [{"name": "...", "feature_type": "...", "affordances": {...}}],
  "actors": [{"name": "...", "kind": "npc", "tier": "regular", "stats": {"health": {"current": 5, "max": 5}, "attack": 1}, "tags": {...}}]
}
PROMPT;
    }
}
