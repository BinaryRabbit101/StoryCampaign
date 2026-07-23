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
        // The player may have chosen where the tale opens; otherwise the
        // world's first zone stands.
        $zone = Zone::find($campaign->starting_zone_id) ?? Zone::orderBy('id')->firstOrFail();

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
            ->limit(3)
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
        $scene = $scene->fresh();
        $cards = $this->composer->compose($character, $scene);
        $health = $character->meters['health'];

        // Ground every card the player will see: name who and what is
        // actually present, so no option arrives narratively unannounced.
        $parts = ["You stand at the edge of {$zone->name}.", $zone->description];
        $present = $scene->activeActors()->pluck('name');
        if ($present->isNotEmpty()) {
            $parts[] = 'Here with you: '.$present->join(', ').'.';
        }
        $features = $scene->allFeatures()->pluck('name')->take(6);
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
}
