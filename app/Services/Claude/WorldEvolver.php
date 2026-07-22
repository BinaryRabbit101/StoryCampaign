<?php

namespace App\Services\Claude;

use App\Models\Actor;
use App\Models\Campaign;
use App\Models\Chapter;
use App\Models\EvolutionRun;
use App\Models\Item;
use App\Models\SceneFeature;
use App\Models\Zone;
use App\Notifications\ChronicleNotification;
use App\Services\CapabilityClamp;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Throwable;

/**
 * The scheduled world-evolution job: middle tier — data within fixed
 * systems, with flexibility to introduce new things per the core rules.
 * Claude proposes; the engine validates against the design bible's hard
 * bounds and a per-run budget, applies what survives, and narrates the
 * update in-world as the Chronicle. Live, no human gate.
 */
class WorldEvolver
{
    public function __construct(
        private readonly ClaudeCli $claude,
        private readonly CapabilityClamp $clamp,
    ) {}

    public function evolve(string $kind = 'daily'): EvolutionRun
    {
        $budget = config("game.bounds.evolution_budget.{$kind}", config('game.bounds.evolution_budget.daily'));

        $run = EvolutionRun::create([
            'kind' => $kind,
            'status' => 'running',
            'budget' => $budget,
            'started_at' => now(),
        ]);

        try {
            $plan = $this->claude->promptForJson($this->buildPrompt($kind, $budget));
            $applied = $this->apply($run, $plan, $budget);

            $chronicle = trim($plan['chronicle'] ?? '') ?: 'The world shifted in ways not yet visible.';

            $run->update([
                'status' => 'complete',
                'changes' => $applied,
                'chronicle' => $chronicle,
                'finished_at' => now(),
            ]);

            $this->publishChronicle($run, $chronicle);
        } catch (Throwable $e) {
            $run->update(['status' => 'failed', 'error' => $e->getMessage(), 'finished_at' => now()]);
            throw $e;
        }

        return $run;
    }

    private function buildPrompt(string $kind, array $budget): string
    {
        $biblePath = config('game.design_bible_path');
        $bible = File::exists($biblePath) ? File::get($biblePath) : 'No bible found — be conservative.';

        $zones = Zone::all()->map(fn (Zone $z) => "- {$z->slug}: {$z->name} — ".Str::limit($z->description, 120))->join("\n");
        $recentRuns = EvolutionRun::where('status', 'complete')->orderByDesc('id')->limit(5)->get()
            ->map(fn (EvolutionRun $r) => "- [{$r->kind} @ {$r->finished_at?->toDateString()}] ".Str::limit(json_encode($r->changes), 300))
            ->join("\n");
        $actorCount = Actor::whereNull('scene_id')->count();
        $itemCount = Item::count();
        $budgetJson = json_encode($budget);
        $maxPower = config('game.bounds.max_item_power');

        return <<<PROMPT
You are the world-evolution process of a living-world RPG, on a {$kind} run. Evolve the game world: new enemies, items, affordance-bearing features, and (budget permitting) zones. You may introduce NEW AFFORDANCE TYPES (e.g. wind currents rideable via glide, flooded passages requiring swim) — the capability grammar stays constant, its vocabulary of scene features grows. Do NOT rewrite core mechanics.

## Design bible (read-only; honor it absolutely)
{$bible}

## Current world
Zones:
{$zones}
Zone-level actor templates: {$actorCount}. Items: {$itemCount}.

## Recent evolution log (do not contradict or duplicate; do not spiral difficulty)
{$recentRuns}

## Your budget this run (hard caps, engine-enforced)
{$budgetJson}
Item power ≤ {$maxPower}. Actor tiers: regular or elite only.

Affordance JSON uses keys like: reachable_via (climb|swing|leap|glide + height), crossable_via (+ gap short|medium|far), flee_destination (+ squeeze_required small|medium|large), hideable (+ max_size), breakable, lift_weight, rideable_via.

Respond with ONLY a JSON object:
{
  "chronicle": "<in-world narration of tonight's changes, 100-250 words, a story beat not a patch note>",
  "rationale": "<one paragraph, out-of-world, for the evolution log>",
  "zones": [{"slug": "...", "name": "...", "description": "..."}],
  "features": [{"zone_slug": "...", "name": "...", "feature_type": "...", "affordances": {...}}],
  "actors": [{"zone_slug": "...", "name": "...", "kind": "enemy|npc|creature", "tier": "regular|elite", "stats": {"health": {"current": 6, "max": 6}, "attack": 2}, "tags": {"intimidatable": true, "type": "regular"}}],
  "items": [{"slug": "...", "name": "...", "description": "...", "power": 1, "grants": [{"capability": "...", "magnitude": null}]}]
}
Empty arrays are fine — a quiet night is a legitimate evolution.
PROMPT;
    }

    /** Validate against the budget and bounds, then apply. Returns the applied change set. */
    private function apply(EvolutionRun $run, array $plan, array $budget): array
    {
        $applied = ['rationale' => $plan['rationale'] ?? null];

        $zones = collect($plan['zones'] ?? [])->take($budget['zones'] ?? 0);
        foreach ($zones as $zone) {
            Zone::firstOrCreate(['slug' => Str::slug($zone['slug'] ?? $zone['name'])], [
                'name' => $zone['name'],
                'description' => $zone['description'] ?? '',
                'source' => 'evolution',
                'evolution_run_id' => $run->id,
            ]);
        }
        $applied['zones'] = $zones->values()->all();

        $zoneIds = Zone::pluck('id', 'slug');

        $features = collect($plan['features'] ?? [])->take($budget['features'] ?? 0)
            ->filter(fn ($f) => isset($zoneIds[$f['zone_slug'] ?? '']));
        foreach ($features as $feature) {
            SceneFeature::create([
                'scene_id' => null,
                'zone_id' => $zoneIds[$feature['zone_slug']],
                'name' => $feature['name'],
                'feature_type' => $feature['feature_type'] ?? 'landmark',
                'affordances' => $feature['affordances'] ?? [],
                'source' => 'evolution',
                'evolution_run_id' => $run->id,
            ]);
        }
        $applied['features'] = $features->values()->all();

        $actors = collect($plan['actors'] ?? [])->take($budget['actors'] ?? 0)
            ->filter(fn ($a) => isset($zoneIds[$a['zone_slug'] ?? '']));
        foreach ($actors as $actor) {
            $tier = in_array($actor['tier'] ?? 'regular', ['regular', 'elite'], true) ? $actor['tier'] : 'regular';
            $stats = $actor['stats'] ?? [];
            $max = min(12, max(1, (int) ($stats['health']['max'] ?? 6)));
            Actor::create([
                'scene_id' => null,
                'zone_id' => $zoneIds[$actor['zone_slug']],
                'name' => $actor['name'],
                'kind' => in_array($actor['kind'] ?? 'enemy', ['enemy', 'npc', 'creature'], true) ? $actor['kind'] : 'enemy',
                'tier' => $tier,
                'stats' => [
                    'health' => ['current' => $max, 'max' => $max],
                    'attack' => min(4, max(1, (int) ($stats['attack'] ?? 1))),
                ],
                'tags' => $actor['tags'] ?? [],
                'source' => 'evolution',
                'evolution_run_id' => $run->id,
            ]);
        }
        $applied['actors'] = $actors->values()->all();

        $items = collect($plan['items'] ?? [])->take($budget['items'] ?? 0);
        foreach ($items as $item) {
            $grants = $this->clamp->clamp($item['grants'] ?? [])['capabilities'];
            Item::firstOrCreate(['slug' => Str::slug($item['slug'] ?? $item['name'])], [
                'name' => $item['name'],
                'description' => $item['description'] ?? '',
                'grants' => $grants,
                'power' => min((int) config('game.bounds.max_item_power'), max(1, (int) ($item['power'] ?? 1))),
                'source' => 'evolution',
                'evolution_run_id' => $run->id,
            ]);
        }
        $applied['items'] = $items->values()->all();

        return $applied;
    }

    /**
     * The Chronicle: narrate the update in-world for every active campaign
     * (a durable chronicle chapter — book material) and push it as a story
     * beat, never a patch note.
     */
    private function publishChronicle(EvolutionRun $run, string $chronicle): void
    {
        Campaign::where('status', 'active')->with('user')->get()->each(function (Campaign $campaign) use ($chronicle) {
            Chapter::create([
                'campaign_id' => $campaign->id,
                'turn_id' => null,
                'number' => $campaign->nextChapterNumber(),
                'kind' => 'chronicle',
                'intent_line' => null,
                'body' => $chronicle,
            ]);

            $campaign->user->notify(new ChronicleNotification($campaign, $chronicle));
        });
    }
}
