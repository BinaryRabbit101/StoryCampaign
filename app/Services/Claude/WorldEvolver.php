<?php

namespace App\Services\Claude;

use App\Models\Actor;
use App\Models\Campaign;
use App\Models\Chapter;
use App\Models\EvolutionRun;
use App\Models\Grudge;
use App\Models\Item;
use App\Models\SceneFeature;
use App\Models\Zone;
use App\Notifications\ChronicleNotification;
use App\Services\CapabilityClamp;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Throwable;

/**
 * The scheduled world-evolution job: middle tier — data within fixed
 * systems, with flexibility to introduce new things per the core rules.
 * Since worlds became campaign-scoped, evolution tends each ACTIVE
 * campaign's own world: Claude proposes new inhabitants and features for
 * the zones that tale actually walks; the engine validates against the
 * design bible's hard bounds and a per-run budget, applies what survives,
 * and narrates the update to that campaign as its Chronicle. Live, no
 * human gate.
 */
class WorldEvolver
{
    public function __construct(
        private readonly ClaudeCli $claude,
        private readonly CapabilityClamp $clamp,
    ) {}

    /**
     * Evolve every recently-played campaign's world. Returns the runs
     * (possibly empty when no world has been walked in the window).
     *
     * @return list<EvolutionRun>
     */
    public function evolve(string $kind = 'daily'): array
    {
        $window = $kind === 'weekly' ? now()->subWeek() : now()->subDay();

        return Campaign::where('status', 'active')
            ->whereHas('turns', fn ($q) => $q->where('resolved_at', '>=', $window))
            ->get()
            ->map(fn (Campaign $campaign) => $this->evolveCampaign($campaign, $kind))
            ->all();
    }

    public function evolveCampaign(Campaign $campaign, string $kind = 'daily'): EvolutionRun
    {
        $budget = config("game.bounds.evolution_budget.{$kind}", config('game.bounds.evolution_budget.daily'));

        $run = EvolutionRun::create([
            'kind' => $kind,
            'status' => 'running',
            'budget' => $budget,
            'started_at' => now(),
        ]);

        try {
            $zones = $this->campaignZones($campaign);
            $plan = $this->claude->promptForJson($this->buildPrompt($campaign, $zones, $kind, $budget));
            $applied = $this->apply($run, $plan, $budget, $zones, $campaign);
            $applied['campaign'] = ['id' => $campaign->id, 'name' => $campaign->name];

            $chronicle = trim($plan['chronicle'] ?? '') ?: 'The world shifted in ways not yet visible.';

            $run->update([
                'status' => 'complete',
                'changes' => $applied,
                'chronicle' => $chronicle,
                'finished_at' => now(),
            ]);

            $this->publishChronicle($campaign, $chronicle);
        } catch (Throwable $e) {
            // One world's failed night must not block the others: the run
            // records the failure and the loop moves on.
            report($e);
            $run->update(['status' => 'failed', 'error' => $e->getMessage(), 'finished_at' => now()]);
        }

        return $run;
    }

    /**
     * The zones this tale actually inhabits: its forged world, plus any
     * shared zone its scenes have walked (legacy campaigns).
     *
     * @return Collection<int, Zone>
     */
    private function campaignZones(Campaign $campaign)
    {
        $walked = $campaign->scenes()->distinct()->pluck('zone_id');

        return Zone::where('campaign_id', $campaign->id)
            ->orWhereIn('id', $walked)
            ->get();
    }

    private function buildPrompt(Campaign $campaign, $zones, string $kind, array $budget): string
    {
        $biblePath = config('game.design_bible_path');
        $bible = File::exists($biblePath) ? File::get($biblePath) : 'No bible found — be conservative.';

        $zoneList = $zones->map(fn (Zone $z) => "- {$z->slug}: {$z->name} — ".Str::limit($z->description, 120))->join("\n") ?: '(none)';
        $recentRuns = EvolutionRun::where('status', 'complete')->orderByDesc('id')->limit(5)->get()
            ->map(fn (EvolutionRun $r) => "- [{$r->kind} @ {$r->finished_at?->toDateString()}] ".Str::limit(json_encode($r->changes), 300))
            ->join("\n");
        $actorCount = Actor::whereNull('scene_id')->whereIn('zone_id', $zones->pluck('id'))->count();
        $itemCount = Item::count();
        $budgetJson = json_encode($budget);
        $maxPower = config('game.bounds.max_item_power');
        $land = $campaign->worldBrief();
        $stage = $campaign->stageBrief() ?: '(none set)';

        // The tale's open scores: enemies who fled and are still out there.
        // The evolver may develop them off-screen — never bring them back;
        // the engine alone decides whether and when a grudge returns.
        $grudgeBudget = (int) ($budget['grudges'] ?? 0);
        $grudges = Grudge::where('campaign_id', $campaign->id)->where('status', 'simmering')->get();
        $grudgeList = $grudges->map(fn (Grudge $g) => "- {$g->actor_name} ({$g->disposition}): "
            .collect($g->history)->pluck('detail')->take(-3)->join(' '))->join("\n") ?: '(none)';

        return <<<PROMPT
You are the world-evolution process of a living-world RPG, on a {$kind} run, tending ONE campaign's private world. Evolve it: new enemies, items, and affordance-bearing features in the zones this tale walks. You may introduce NEW AFFORDANCE TYPES (e.g. wind currents rideable via glide, flooded passages requiring swim) — the capability grammar stays constant, its vocabulary of scene features grows. Do NOT rewrite core mechanics, and do not invent new zones — the frontier does that.

## The land this world is made of (FIXED — everything you add belongs here)
{$land}
If the design bible below illustrates a different kind of place or genre, that is an example of voice only; this world overrides it. Everything you add must fit this world's genre and its stated level of magic and machinery.

## Design bible (read-only; honor its voice, guardrails, and bounds absolutely — but NOT its examples of place or genre)
{$bible}

## The stage this campaign's player set (color evolution toward it)
{$stage}

## This campaign's world
Zones:
{$zoneList}
Zone-level actor templates in them: {$actorCount}. Items (world-wide): {$itemCount}.

## Open grudges — enemies who fled this tale and are still out there (tend at most {$grudgeBudget})
{$grudgeList}
You may develop a grudge off-screen: one sentence of what they have been doing (chronicle material), and optionally a small stat or tag change within the same bounds as actors. Never decide that they return, and never place them anywhere — the tale itself chooses their moment.

## Recent evolution log (do not contradict or duplicate; do not spiral difficulty)
{$recentRuns}

## Your budget this run (hard caps, engine-enforced)
{$budgetJson}
Item power ≤ {$maxPower}. Actor tiers: regular or elite only.

Affordance JSON uses keys like: reachable_via (climb|swing|leap|glide + height), crossable_via (+ gap short|medium|far), flee_destination (+ squeeze_required small|medium|large), hideable (+ max_size), breakable, lift_weight, rideable_via, hidden.

Respond with ONLY a JSON object:
{
  "chronicle": "<in-world narration of tonight's changes, 100-250 words, a story beat not a patch note>",
  "rationale": "<one paragraph, out-of-world, for the evolution log>",
  "features": [{"zone_slug": "...", "name": "...", "feature_type": "...", "affordances": {...}}],
  "actors": [{"zone_slug": "...", "name": "...", "kind": "enemy|npc|creature", "tier": "regular|elite", "stats": {"health": {"current": 6, "max": 6}, "attack": 2}, "tags": {"intimidatable": true, "type": "regular"}}],
  "items": [{"slug": "...", "name": "...", "description": "...", "power": 1, "grants": [{"capability": "...", "magnitude": null}]}],
  "grudges": [{"name": "<an open grudge's exact name>", "development": "<one sentence, in-world>", "stats": {"health": {"max": 7}, "attack": 2}, "tags": {}}]
}
Empty arrays are fine — a quiet night is a legitimate evolution.
PROMPT;
    }

    /** Validate against the budget and bounds, then apply. Returns the applied change set. */
    private function apply(EvolutionRun $run, array $plan, array $budget, $zones, Campaign $campaign): array
    {
        $applied = ['rationale' => $plan['rationale'] ?? null];

        // Zone creation moved to the frontier forge: evolution only ever
        // tends ground the campaign already holds.
        $zoneIds = $zones->pluck('id', 'slug');

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

        $applied['grudges'] = $this->tendGrudges($plan, $budget, $campaign);

        return $applied;
    }

    /**
     * Grudge tending: the LLM proposes how a fled enemy changed off-screen;
     * the engine clamps every delta to the same actor bounds, bumps heat by
     * at most one per run, and never lets a proposal touch the return
     * machinery — a grudge is a story thread, not a difficulty spiral.
     *
     * @return list<array{name: string, development: ?string}>
     */
    private function tendGrudges(array $plan, array $budget, Campaign $campaign): array
    {
        // The engine's own markers and scene-transient combat state: a tag
        // delta may deepen who they are, never how or when they come back.
        $reserved = array_flip([
            'intent', 'angle', 'ambush', 'shaken', 'cornered', 'pinned',
            'lurking', 'lurking_since', 'fled_how', 'called_off',
            'grudge_id', 'truce', 'deal', 'truce_health',
        ]);

        $tended = [];

        foreach (collect($plan['grudges'] ?? [])->take($budget['grudges'] ?? 0) as $proposal) {
            $grudge = Grudge::where('campaign_id', $campaign->id)
                ->where('actor_name', $proposal['name'] ?? '')
                ->where('status', 'simmering')->first();
            if ($grudge === null) {
                continue;
            }

            $stats = $grudge->stats;
            if (isset($proposal['stats']['health']['max'])) {
                $max = min(12, max(1, (int) $proposal['stats']['health']['max']));
                $stats['health'] = ['current' => $max, 'max' => $max];
            }
            if (isset($proposal['stats']['attack'])) {
                $stats['attack'] = min(4, max(1, (int) $proposal['stats']['attack']));
            }

            $tags = array_merge($grudge->tags ?? [],
                array_diff_key((array) ($proposal['tags'] ?? []), $reserved));

            $development = trim((string) ($proposal['development'] ?? '')) ?: null;
            $history = $grudge->history;
            if ($development !== null) {
                $history[] = [
                    'turn_id' => null, 'chapter_id' => null,
                    'event' => 'developed', 'detail' => $development, 'place' => null,
                ];
            }

            $grudge->update([
                'stats' => $stats,
                'tags' => $tags,
                'heat' => min(3, $grudge->heat + 1),
                'history' => $history,
            ]);

            $tended[] = ['name' => $grudge->actor_name, 'development' => $development];
        }

        return $tended;
    }

    /**
     * The Chronicle: narrate the update in-world for the campaign whose
     * world just grew (a durable chronicle chapter — book material) and
     * push it as a story beat, never a patch note.
     */
    private function publishChronicle(Campaign $campaign, string $chronicle): void
    {
        Chapter::create([
            'campaign_id' => $campaign->id,
            'turn_id' => null,
            'number' => $campaign->nextChapterNumber(),
            'kind' => 'chronicle',
            'intent_line' => null,
            'body' => $chronicle,
        ]);

        $campaign->user->notify(new ChronicleNotification($campaign, $chronicle));
    }
}
