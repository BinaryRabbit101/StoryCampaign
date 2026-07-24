<?php

namespace App\Game\Engine;

use App\Models\Actor;
use App\Models\Scene;
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

    /** Spawn a small draw of the zone's actor templates into the scene. */
    public function spawnActors(Scene $scene, Dice $dice, int $min, int $max): void
    {
        $templates = Actor::whereNull('scene_id')
            ->where('zone_id', $scene->zone_id)
            ->where('status', 'active')
            ->get();

        foreach ($this->draw($templates->all(), $dice, $min, $max) as $template) {
            Actor::create([
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
