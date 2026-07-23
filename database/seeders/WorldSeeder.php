<?php

namespace Database\Seeders;

use App\Models\Actor;
use App\Models\Item;
use App\Models\SceneFeature;
use App\Models\Zone;
use Illuminate\Database\Seeder;

/**
 * The seed world: one starter zone with enough affordance variety to
 * exercise the whole capability vocabulary. Evolution grows it from here.
 */
class WorldSeeder extends Seeder
{
    public function run(): void
    {
        $district = Zone::firstOrCreate(['slug' => 'old-district'], [
            'name' => 'The Old District',
            'description' => 'Sagging warehouses and lantern-lit lanes stacked against the harbor wall, '
                .'where the rooftops make a second city above the first.',
            'source' => 'seed',
        ]);

        $features = [
            ['name' => 'the warehouse roof', 'feature_type' => 'building',
                'affordances' => ['reachable_via' => ['climb', 'swing', 'leap'], 'height' => 11]],
            ['name' => 'the narrow alley', 'feature_type' => 'alley',
                'affordances' => ['flee_destination' => true, 'squeeze_required' => 'medium']],
            ['name' => 'the market stalls', 'feature_type' => 'cover',
                'affordances' => ['hideable' => true, 'max_size' => 'medium']],
            ['name' => 'the rope bridge', 'feature_type' => 'crossing',
                'affordances' => ['crossable_via' => ['swing', 'leap'], 'gap' => 'medium', 'breakable' => true]],
            ['name' => 'the harbor chain', 'feature_type' => 'obstacle',
                'affordances' => ['lift_weight' => 180]],
            ['name' => 'the collapsed archway', 'feature_type' => 'flee_route',
                'affordances' => ['flee_destination' => true, 'squeeze_required' => 'large']],
        ];

        foreach ($features as $feature) {
            SceneFeature::firstOrCreate(
                ['zone_id' => $district->id, 'scene_id' => null, 'name' => $feature['name']],
                $feature + ['source' => 'seed'],
            );
        }

        $actors = [
            ['name' => 'a dockside tough', 'kind' => 'enemy', 'tier' => 'regular',
                'stats' => ['health' => ['current' => 6, 'max' => 6], 'attack' => 2],
                'tags' => ['intimidatable' => true, 'type' => 'regular', 'restrainable' => true]],
            ['name' => 'a wiry cutpurse', 'kind' => 'enemy', 'tier' => 'regular',
                'stats' => ['health' => ['current' => 4, 'max' => 4], 'attack' => 1],
                'tags' => ['intimidatable' => true, 'type' => 'regular', 'restrainable' => true]],
            ['name' => 'the lantern watchman', 'kind' => 'npc', 'tier' => 'regular',
                'stats' => ['health' => ['current' => 5, 'max' => 5], 'attack' => 1],
                'tags' => ['talkable' => true, 'persuadeable' => true, 'calmable' => true, 'companionable' => true]],
        ];

        foreach ($actors as $actor) {
            Actor::firstOrCreate(
                ['zone_id' => $district->id, 'scene_id' => null, 'name' => $actor['name']],
                $actor + ['status' => 'active', 'source' => 'seed'],
            );
        }

        Item::firstOrCreate(['slug' => 'weighted-cord'], [
            'name' => 'a weighted cord',
            'description' => 'A sailor\'s throwing line with a lead knot — extends what a grip can reach.',
            'grants' => [['capability' => 'reach', 'magnitude' => 8]],
            'power' => 1,
            'source' => 'seed',
        ]);
    }
}
