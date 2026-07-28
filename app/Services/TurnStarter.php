<?php

namespace App\Services;

use App\Game\Engine\CardComposer;
use App\Game\Engine\Dice;
use App\Game\Engine\SceneDresser;
use App\Game\Engine\SituationBoard;
use App\Models\Actor;
use App\Models\Campaign;
use App\Models\Scene;
use App\Models\SceneFeature;
use App\Models\Turn;
use App\Models\Zone;
use App\Services\Claude\ZoneForge;

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
        private readonly ZoneForge $forge,
    ) {}

    public function openFirstTurn(Campaign $campaign, ?array $opening = null): Turn
    {
        // Every tale opens in its own forged world. The interviewer forges
        // it ahead of the transaction; this is the defensive fallback.
        $zone = Zone::find($campaign->starting_zone_id) ?? $this->forge->ensureStartingZone($campaign);
        $dice = new Dice($campaign->id * 2654435761 % PHP_INT_MAX);

        $scene = Scene::create([
            'campaign_id' => $campaign->id,
            'zone_id' => $zone->id,
            'title' => $opening['scene_title'] ?? $zone->name,
            'description' => $opening['scene_description'] ?? $zone->description,
            'status' => 'active',
            'state' => ['dressed' => true],
        ]);

        // Sparse on purpose. An opening that hands the player five props and
        // three strangers at once has spent the whole world in one breath —
        // and it reads as clutter, because nothing in it has had a chance to
        // mean anything yet. The world adds as the tale goes.
        if ($opening !== null) {
            // The stage sets the cast; the zone still lends some ground.
            $this->dressScene($scene, $opening);
            $this->dresser->instantiateFeatures($scene, $dice, 1, 2);
        } else {
            $this->dresser->instantiateFeatures($scene, $dice, 2, 3);
            $this->dresser->spawnActors($scene, $dice, 0, 2);
        }

        // The air the tale opens in, rolled once and kept. It has to land
        // before the cards are composed: every one of them prices itself
        // against it, and a forecast written under the wrong sky is a lie.
        $this->dresser->rollAmbient($scene, $dice);

        $character = $campaign->character()->firstOrFail();
        $scene = $scene->fresh();
        $cards = $this->composer->compose($character, $scene);

        // Ground every card the player will see: name who and what is
        // actually present, so no option arrives narratively unannounced.
        // The same board every later turn gets, with the ground itself
        // standing in for the trigger there is not one of yet.
        $board = array_merge(
            [[
                'key' => 'moment',
                'title' => 'Where you are',
                'tone' => 'neutral',
                'items' => array_values(array_filter([
                    "You stand at the edge of {$zone->name}.",
                    trim((string) $scene->description) ?: null,
                ])),
            ]],
            SituationBoard::for($character, $scene),
        );

        return Turn::create([
            'campaign_id' => $campaign->id,
            'scene_id' => $scene->id,
            'number' => 1,
            'status' => Turn::STATUS_AWAITING,
            'situation' => SituationBoard::prose($board),
            'situation_board' => $board,
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
