<?php

namespace App\Game\Engine;

use App\Models\Actor;
use App\Models\Scene;
use App\Models\SceneExit;
use App\Models\SceneFeature;
use App\Models\Zone;

/**
 * Dresses a scene from its zone's templates: a seeded random SUBSET of the
 * zone's features instantiated as scene-scoped copies, a small draw of
 * spawn-template actors, and a locale name for the ground itself. Copies —
 * never the shared rows — so per-scene state (hidden, destroyed) stays this
 * campaign's own, and two scenes in the same zone never play identically.
 *
 * Dressed scenes are marked in state; Scene::allFeatures() then reads only
 * scene-scoped rows. Undressed (legacy) scenes keep the old template-overlay
 * behavior, so campaigns that predate dressing continue unbroken.
 */
class SceneDresser
{
    /** Pick a locale name for new ground, avoiding the title just left. */
    public function locale(Zone $zone, Dice $dice, ?string $exclude = null): array
    {
        $locales = collect($zone->tags['locales'] ?? [])
            ->reject(fn (array $l) => $l['title'] === $exclude)
            ->values();

        if ($locales->isEmpty()) {
            return [
                'title' => 'Deeper into '.$zone->name,
                'description' => 'New ground, reached in motion. The old scene lies behind.',
            ];
        }

        return $locales[$dice->between(0, $locales->count() - 1)];
    }

    /**
     * Mint this ground's ways out: one to three exits, each with a heading
     * and the locale it leads toward. The heading walked to ARRIVE here never
     * reappears as a way out — departure is irreversible in this game, and an
     * exit pointing back the way you came would be a promise the engine does
     * not keep. Idempotent: ground that already has its ways keeps them.
     *
     * The destination locale is drawn when the exit is minted, not when it is
     * walked — which is what makes north and east genuinely different doors
     * rather than two buttons on the same random draw.
     */
    public function mintExits(Scene $scene, Zone $zone, Dice $dice): void
    {
        if ($scene->exits()->exists()) {
            return;
        }

        $pool = array_values(array_diff(
            Compass::DIRECTIONS,
            $scene->from_direction !== null ? [Compass::opposite($scene->from_direction)] : [],
        ));

        // Shuffle the headings with the same seeded stream everything else
        // dressing this scene uses, then keep 1-3 of them.
        for ($i = count($pool) - 1; $i > 0; $i--) {
            $j = $dice->between(0, $i);
            [$pool[$i], $pool[$j]] = [$pool[$j], $pool[$i]];
        }
        $count = min(count($pool), $dice->between(1, 3));

        // Each exit leads toward its own locale, no two the same, never the
        // ground it leaves. When the zone runs out of named places the way
        // still exists — it just leads deeper in.
        $locales = collect($zone->tags['locales'] ?? [])
            ->reject(fn (array $l) => $l['title'] === $scene->title)
            ->values()->all();
        for ($i = count($locales) - 1; $i > 0; $i--) {
            $j = $dice->between(0, $i);
            [$locales[$i], $locales[$j]] = [$locales[$j], $locales[$i]];
        }

        foreach (array_slice($pool, 0, $count) as $k => $direction) {
            $locale = $locales[$k] ?? [
                'title' => 'Deeper into '.$zone->name,
                'description' => 'New ground, reached in motion. The old scene lies behind.',
            ];

            SceneExit::create([
                'scene_id' => $scene->id,
                'direction' => $direction,
                'label' => $locale['title'],
                'locale' => $locale,
            ]);
        }
    }

    /**
     * Instantiate a subset of the zone's template features as scene-scoped
     * copies. Templates tagged `hidden` arrive concealed — discovery content
     * for examine and scout, invisible to the cards until revealed.
     */
    public function instantiateFeatures(Scene $scene, Dice $dice, int $min, int $max): void
    {
        $templates = SceneFeature::whereNull('scene_id')
            ->where('zone_id', $scene->zone_id)
            ->get();

        foreach ($this->draw($templates->all(), $dice, $min, $max) as $template) {
            SceneFeature::create([
                'scene_id' => $scene->id,
                'zone_id' => $scene->zone_id,
                'name' => $template->name,
                'feature_type' => $template->feature_type,
                'affordances' => $template->affordances,
                'state' => ($template->affordances['hidden'] ?? false) ? ['hidden' => true] : [],
                'source' => $template->source,
                'evolution_run_id' => $template->evolution_run_id,
            ]);
        }
    }

    /**
     * Roll the air this ground stands in, once, and remember it.
     *
     * Fixed for the scene's life on purpose: a place whose weather turns every
     * few beats is a place the player cannot plan against, and re-rolling would
     * mean a card priced last turn quietly costing something else this turn.
     * New ground gets new air; this ground keeps what it was given.
     *
     * Called at the END of dressing rather than the start, because the draw
     * above walks the same seeded stream — rolling first would shift every
     * feature and actor a scene has ever drawn.
     */
    public function rollAmbient(Scene $scene, Dice $dice): string
    {
        $state = $scene->state ?? [];

        if (isset($state['ambient'])) {
            return Ambient::of($scene);
        }

        $ambient = Ambient::roll($dice);
        $scene->update(['state' => array_merge($state, ['ambient' => $ambient])]);

        return $ambient;
    }

    /** Spawn a small draw of the zone's actor templates into the scene. */
    public function spawnActors(Scene $scene, Dice $dice, int $min, int $max): void
    {
        $templates = Actor::whereNull('scene_id')
            ->where('zone_id', $scene->zone_id)
            ->where('status', 'active')
            ->get();

        foreach ($this->draw($templates->all(), $dice, $min, $max) as $template) {
            $spawned = Actor::create([
                'scene_id' => $scene->id,
                'zone_id' => $scene->zone_id,
                'name' => $template->name,
                'kind' => $template->kind,
                'tier' => $template->tier,
                'stats' => $template->stats,
                'tags' => $template->tags,
                'status' => 'active',
                'source' => $template->source,
                'evolution_run_id' => $template->evolution_run_id,
            ]);

            // Rarely, one of them takes an interest. The roll is seeded off the
            // spawned actor's own id rather than off the stream above, on
            // purpose: that stream is the dressing draw, and spending a die per
            // spawn inside it would shift every feature, inhabitant and sky
            // every dressed scene in the game has ever produced.
            Companions::markStray($spawned, $scene);

            // And rarely, one of them turns out to be in the middle of
            // something of their own. Seeded off the actor's own id for the
            // same reason as the line above, and silent while the tale is
            // already carrying a want — one small story at a time.
            Threads::attach($spawned->fresh(), $scene);
        }
    }

    /**
     * A seeded draw of between $min and $max items, order shuffled by the
     * same dice — deterministic for a given seed, different between scenes.
     *
     * @template T
     *
     * @param  list<T>  $items
     * @return list<T>
     */
    private function draw(array $items, Dice $dice, int $min, int $max): array
    {
        for ($i = count($items) - 1; $i > 0; $i--) {
            $j = $dice->between(0, $i);
            [$items[$i], $items[$j]] = [$items[$j], $items[$i]];
        }

        return array_slice($items, 0, min(count($items), $dice->between($min, $max)));
    }
}
