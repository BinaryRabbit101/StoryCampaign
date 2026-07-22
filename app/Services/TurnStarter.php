<?php

namespace App\Services;

use App\Game\Engine\CardComposer;
use App\Models\Actor;
use App\Models\Campaign;
use App\Models\Scene;
use App\Models\Turn;
use App\Models\Zone;

/**
 * Opens a campaign's first scene and turn once the creation interview
 * completes: places the character in the starter zone, seeds the scene
 * with a couple of actor templates, and composes the opening cards.
 */
class TurnStarter
{
    public function __construct(private readonly CardComposer $composer) {}

    public function openFirstTurn(Campaign $campaign): Turn
    {
        $zone = Zone::orderBy('id')->firstOrFail();

        $scene = Scene::create([
            'campaign_id' => $campaign->id,
            'zone_id' => $zone->id,
            'title' => $zone->name,
            'description' => $zone->description,
            'status' => 'active',
            'state' => [],
        ]);

        Actor::whereNull('scene_id')
            ->where('zone_id', $zone->id)
            ->where('status', 'active')
            ->orderBy('id')
            ->limit(2)
            ->get()
            ->each(fn (Actor $template) => Actor::create([
                'scene_id' => $scene->id,
                'zone_id' => $zone->id,
                'name' => $template->name,
                'kind' => $template->kind,
                'tier' => $template->tier,
                'stats' => $template->stats,
                'tags' => $template->tags,
                'status' => 'active',
                'source' => $template->source,
                'evolution_run_id' => $template->evolution_run_id,
            ]));

        $character = $campaign->character;
        $cards = $this->composer->compose($character, $scene->fresh());
        $health = $character->meters['health'];

        return Turn::create([
            'campaign_id' => $campaign->id,
            'scene_id' => $scene->id,
            'number' => 1,
            'status' => Turn::STATUS_AWAITING,
            'situation' => "You stand at the edge of {$zone->name}. {$zone->description} "
                ."Health {$health['current']}/{$health['max']}. The world is waiting for your first move.",
            'cards' => $cards,
        ]);
    }
}
