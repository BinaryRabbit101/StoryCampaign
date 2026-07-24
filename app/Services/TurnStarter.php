<?php

namespace App\Services;

use App\Game\Engine\CardComposer;
use App\Game\Engine\Dice;
use App\Game\Engine\SceneDresser;
use App\Models\Actor;
use App\Models\Campaign;
use App\Models\Scene;
use App\Models\SceneFeature;
use App\Models\Turn;
use App\Models\Zone;

/**
 * Opens a campaign's first scene and turn once the creation interview
 * completes. Given a stage-built opening plan (already sanitized by the
 * StageBuilder), the scene is dressed with that campaign's own content plus
 * a light draw of the zone's geography; without one, the dresser rolls the
 * whole opening from the zone's templates.
 */
class TurnStarter
{
    public function __construct(
        private readonly CardComposer $composer,
        private readonly SceneDresser $dresser,
    ) {}

    public function openFirstTurn(Campaign $campaign, ?array $opening = null): Turn
    {
        // The player may have chosen where the tale opens; otherwise the
        // world's first zone stands.
        $zone = Zone::find($campaign->starting_zone_id) ?? Zone::orderBy('id')->firstOrFail();
        $dice = new Dice($campaign->id * 2654435761 % PHP_INT_MAX);

        $scene = Scene::create([
            'campaign_id' => $campaign->id,
            'zone_id' => $zone->id,
            'title' => $opening['scene_title'] ?? $zone->name,
            'description' => $opening['scene_description'] ?? $zone->description,
            'status' => 'active',
            'state' => ['dressed' => true],
        ]);

        if ($opening !== null) {
            // The stage sets the cast; the zone still lends some ground.
            $this->dressScene($scene, $opening);
            $this->dresser->instantiateFeatures($scene, $dice, 2, 3);
        } else {
            $this->dresser->instantiateFeatures($scene, $dice, 4, 5);
            $this->dresser->spawnActors($scene, $dice, 2, 3);
        }

        $character = $campaign->character;
        $scene = $scene->fresh();
        $cards = $this->composer->compose($character, $scene);
        $health = $character->meters['health'];

        // Ground every card the player will see: name who and what is
        // actually present, so no option arrives narratively unannounced.
        $parts = ["You stand at the edge of {$zone->name}.", $scene->description];
        $present = $scene->visibleActors()->pluck('name');
        if ($present->isNotEmpty()) {
            $parts[] = 'Here with you: '.$present->join(', ').'.';
        }
        $features = $scene->visibleFeatures()->pluck('name')->take(6);
        if ($features->isNotEmpty()) {
            $parts[] = 'Around you: '.$features->join(', ').'.';
        }
        $parts[] = "Health {$health['current']}/{$health['max']}. The world is waiting for your first move.";

        return Turn::create([
            'campaign_id' => $campaign->id,
            'scene_id' => $scene->id,
            'number' => 1,
            'status' => Turn::STATUS_AWAITING,
            'situation' => implode(' ', $parts),
            'cards' => $cards,
        ]);
    }

    /** Scene-scoped stage content: this campaign's opening, no one else's. */
    private function dressScene(Scene $scene, array $opening): void
    {
        foreach ($opening['features'] ?? [] as $feature) {
            SceneFeature::create([
                'scene_id' => $scene->id,
                'zone_id' => $scene->zone_id,
                'name' => $feature['name'],
                'feature_type' => $feature['feature_type'],
                'affordances' => $feature['affordances'],
                'source' => 'stage',
            ]);
        }

        foreach ($opening['actors'] ?? [] as $actor) {
            Actor::create([
                'scene_id' => $scene->id,
                'zone_id' => $scene->zone_id,
                'name' => $actor['name'],
                'kind' => $actor['kind'],
                'tier' => $actor['tier'],
                'stats' => $actor['stats'],
                'tags' => $actor['tags'],
                'status' => 'active',
                'source' => 'stage',
            ]);
        }
    }
}
