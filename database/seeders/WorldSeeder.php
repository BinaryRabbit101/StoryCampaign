<?php

namespace Database\Seeders;

use App\Models\Actor;
use App\Models\Item;
use App\Models\SceneFeature;
use App\Models\Zone;
use Illuminate\Database\Seeder;

/**
 * The seed world: three zones, each with enough affordance variety to
 * exercise the capability vocabulary and enough templates that any two
 * scenes dressed from the same zone play differently. Features tagged
 * `hidden` are discovery content — invisible until examine or scout finds
 * them. Zone `locales` name the ground new scenes open onto. Evolution
 * grows the world from here.
 */
class WorldSeeder extends Seeder
{
    public function run(): void
    {
        $this->oldDistrict();
        $this->rookeryHeights();
        $this->drownedMarket();

        Item::firstOrCreate(['slug' => 'weighted-cord'], [
            'name' => 'a weighted cord',
            'description' => 'A sailor\'s throwing line with a lead knot — extends what a grip can reach.',
            'grants' => [['capability' => 'reach', 'magnitude' => 8]],
            'power' => 1,
            'source' => 'seed',
        ]);
    }

    private function oldDistrict(): void
    {
        $zone = Zone::updateOrCreate(['slug' => 'old-district'], [
            'name' => 'The Old District',
            'description' => 'Sagging warehouses and lantern-lit lanes stacked against the harbor wall, '
                .'where the rooftops make a second city above the first.',
            'tags' => ['locales' => [
                ['title' => 'Ropewalk Lane', 'description' => 'A long straight lane of tar-barrels and coiled hemp, narrow enough that everything in it happens at close quarters.'],
                ['title' => "The Fishmongers' Row", 'description' => 'Wet cobbles, gutted stalls, and the day\'s catch swinging on hooks overhead.'],
                ['title' => 'Under the Harbor Wall', 'description' => 'The district\'s oldest stones, where the wall\'s shadow keeps the lanes dim at noon.'],
                ['title' => 'The Lantern Yard', 'description' => 'A cramped court where the lamplighters store their oil and ladders, ringed by shuttered workshops.'],
                ['title' => 'The Salt Warehouse', 'description' => 'A cavernous floor of white-crusted crates, catwalks above and brine-rot below.'],
                ['title' => 'The Tide Cellars', 'description' => 'Half-flooded vaults beneath the wharf, where the harbor breathes in and out through the grates.'],
            ]],
            'source' => 'seed',
        ]);

        $this->features($zone, [
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
            ['name' => 'the loading crane', 'feature_type' => 'building',
                'affordances' => ['reachable_via' => ['climb', 'swing'], 'height' => 14]],
            ['name' => 'a wall of stacked crates', 'feature_type' => 'cover',
                'affordances' => ['hideable' => true, 'max_size' => 'large', 'breakable' => true]],
            ['name' => "the smuggler's door", 'feature_type' => 'flee_route',
                'affordances' => ['flee_destination' => true, 'squeeze_required' => 'medium', 'hidden' => true]],
            ['name' => 'the tide-cellar grate', 'feature_type' => 'flee_route',
                'affordances' => ['flee_destination' => true, 'squeeze_required' => 'medium', 'breakable' => true, 'hidden' => true]],
        ]);

        $this->actors($zone, [
            ['name' => 'a dockside tough', 'kind' => 'enemy', 'tier' => 'regular',
                'stats' => ['health' => ['current' => 6, 'max' => 6], 'attack' => 2],
                'tags' => ['intimidatable' => true, 'type' => 'regular', 'restrainable' => true]],
            ['name' => 'a wiry cutpurse', 'kind' => 'enemy', 'tier' => 'regular',
                'stats' => ['health' => ['current' => 4, 'max' => 4], 'attack' => 1],
                'tags' => ['intimidatable' => true, 'type' => 'regular', 'restrainable' => true]],
            ['name' => 'the lantern watchman', 'kind' => 'npc', 'tier' => 'regular',
                'stats' => ['health' => ['current' => 5, 'max' => 5], 'attack' => 1],
                'tags' => ['talkable' => true, 'persuadeable' => true, 'calmable' => true, 'companionable' => true]],
            ['name' => 'a harbor enforcer', 'kind' => 'enemy', 'tier' => 'elite',
                'stats' => ['health' => ['current' => 9, 'max' => 9], 'attack' => 3],
                'tags' => ['restrainable' => true, 'type' => 'elite']],
            ['name' => "a smuggler's lookout", 'kind' => 'enemy', 'tier' => 'regular',
                'stats' => ['health' => ['current' => 4, 'max' => 4], 'attack' => 1],
                'tags' => ['intimidatable' => true, 'deceiveable' => true, 'restrainable' => true, 'type' => 'regular']],
            ['name' => 'a mangy dock-dog', 'kind' => 'enemy', 'tier' => 'regular',
                'stats' => ['health' => ['current' => 3, 'max' => 3], 'attack' => 1],
                'tags' => ['intimidatable' => true, 'calmable' => true, 'type' => 'regular']],
            ['name' => 'a stray dockhand', 'kind' => 'npc', 'tier' => 'regular',
                'stats' => ['health' => ['current' => 5, 'max' => 5], 'attack' => 1],
                'tags' => ['talkable' => true, 'companionable' => true]],
        ]);
    }

    private function rookeryHeights(): void
    {
        $zone = Zone::updateOrCreate(['slug' => 'rookery-heights'], [
            'name' => 'The Rookery Heights',
            'description' => 'The city above the city: a tangle of gables, chimneys, and strung washing '
                .'where the roof-folk keep their own laws and the streets below are only a rumor.',
            'tags' => ['locales' => [
                ['title' => "The Bellfounder's Roof", 'description' => 'A wide leaded roof around a cracked and silent bell, the highest open ground in the Heights.'],
                ['title' => 'The Pigeon Court', 'description' => 'A hollow between four gables, floored with coops and feathers, loud with wings.'],
                ['title' => 'Above the Printworks', 'description' => 'Warm slates over thumping presses below, ink-smell rising through every gap.'],
                ['title' => 'The Broken Gable', 'description' => 'A collapsed roofline bridged by planks, where one wrong step is a long story ended short.'],
                ['title' => 'The Rain Gutters', 'description' => 'Deep lead channels between rooftops, running with last night\'s water.'],
            ]],
            'source' => 'seed',
        ]);

        $this->features($zone, [
            ['name' => 'the bell tower', 'feature_type' => 'building',
                'affordances' => ['reachable_via' => ['climb'], 'height' => 18]],
            ['name' => 'the washing lines', 'feature_type' => 'crossing',
                'affordances' => ['crossable_via' => ['swing'], 'gap' => 'medium']],
            ['name' => 'the slate ridge', 'feature_type' => 'crossing',
                'affordances' => ['crossable_via' => ['leap'], 'gap' => 'short']],
            ['name' => 'the chimney forest', 'feature_type' => 'cover',
                'affordances' => ['hideable' => true, 'max_size' => 'large']],
            ['name' => 'the rookery ladders', 'feature_type' => 'building',
                'affordances' => ['reachable_via' => ['climb'], 'height' => 8, 'breakable' => true]],
            ['name' => 'the gull nets', 'feature_type' => 'crossing',
                'affordances' => ['crossable_via' => ['swing', 'glide'], 'gap' => 'far']],
            ['name' => 'a loose stone gargoyle', 'feature_type' => 'obstacle',
                'affordances' => ['lift_weight' => 120]],
            ['name' => "the widow's skylight", 'feature_type' => 'flee_route',
                'affordances' => ['flee_destination' => true, 'squeeze_required' => 'medium', 'hidden' => true]],
        ]);

        $this->actors($zone, [
            ['name' => 'a roof-runner', 'kind' => 'enemy', 'tier' => 'regular',
                'stats' => ['health' => ['current' => 4, 'max' => 4], 'attack' => 2],
                'tags' => ['intimidatable' => true, 'restrainable' => true, 'type' => 'regular']],
            ['name' => 'a rooftop footpad', 'kind' => 'enemy', 'tier' => 'regular',
                'stats' => ['health' => ['current' => 5, 'max' => 5], 'attack' => 2],
                'tags' => ['intimidatable' => true, 'deceiveable' => true, 'restrainable' => true, 'type' => 'regular']],
            ['name' => 'the pigeon keeper', 'kind' => 'npc', 'tier' => 'regular',
                'stats' => ['health' => ['current' => 4, 'max' => 4], 'attack' => 1],
                'tags' => ['talkable' => true, 'persuadeable' => true, 'companionable' => true]],
            ['name' => 'a molting messenger-crow', 'kind' => 'npc', 'tier' => 'regular',
                'stats' => ['health' => ['current' => 2, 'max' => 2], 'attack' => 1],
                'tags' => ['calmable' => true, 'companionable' => true]],
        ]);
    }

    private function drownedMarket(): void
    {
        $zone = Zone::updateOrCreate(['slug' => 'drowned-market'], [
            'name' => 'The Drowned Market',
            'description' => 'The old market arcades, half-taken by the tide: stalls trade from rafts and '
                .'mooring beams, and what sank is not always gone.',
            'tags' => ['locales' => [
                ['title' => 'The Flooded Arcade', 'description' => 'Columns rising out of slack green water, the old shopfronts drowned to their lintels.'],
                ['title' => 'The Eel Market', 'description' => 'Raft-stalls lashed together over deep water, writhing baskets and quick knives.'],
                ['title' => 'The Half-Drowned Chapel', 'description' => 'A nave knee-deep in tide, candles burning on the ledges that stayed dry.'],
                ['title' => 'The Silt Stairs', 'description' => 'A grand staircase descending into brown water, each step slicker than the last.'],
                ['title' => 'The Ferry Landing', 'description' => 'The one busy shore of the Market, where lantern-ferries put in and everyone watches everyone.'],
            ]],
            'source' => 'seed',
        ]);

        $this->features($zone, [
            ['name' => 'the sunken colonnade', 'feature_type' => 'crossing',
                'affordances' => ['crossable_via' => ['swim'], 'gap' => 'medium']],
            ['name' => 'the drowned stalls', 'feature_type' => 'cover',
                'affordances' => ['hideable' => true, 'max_size' => 'medium']],
            ['name' => 'the mooring beam', 'feature_type' => 'building',
                'affordances' => ['reachable_via' => ['climb', 'swing'], 'height' => 9]],
            ['name' => 'the flood grate', 'feature_type' => 'flee_route',
                'affordances' => ['flee_destination' => true, 'squeeze_required' => 'medium', 'breakable' => true]],
            ['name' => 'the capsized barge', 'feature_type' => 'crossing',
                'affordances' => ['crossable_via' => ['leap', 'swim'], 'gap' => 'short']],
            ['name' => "the eel-seller's cache", 'feature_type' => 'obstacle',
                'affordances' => ['lift_weight' => 60, 'hidden' => true]],
        ]);

        $this->actors($zone, [
            ['name' => 'a silt-wader', 'kind' => 'enemy', 'tier' => 'regular',
                'stats' => ['health' => ['current' => 5, 'max' => 5], 'attack' => 2],
                'tags' => ['intimidatable' => true, 'restrainable' => true, 'type' => 'regular']],
            ['name' => 'a market scavenger', 'kind' => 'enemy', 'tier' => 'regular',
                'stats' => ['health' => ['current' => 4, 'max' => 4], 'attack' => 1],
                'tags' => ['intimidatable' => true, 'deceiveable' => true, 'restrainable' => true, 'type' => 'regular']],
            ['name' => 'the eel-seller', 'kind' => 'npc', 'tier' => 'regular',
                'stats' => ['health' => ['current' => 5, 'max' => 5], 'attack' => 1],
                'tags' => ['talkable' => true, 'persuadeable' => true, 'companionable' => true]],
            ['name' => 'a ferry lantern-boy', 'kind' => 'npc', 'tier' => 'regular',
                'stats' => ['health' => ['current' => 3, 'max' => 3], 'attack' => 1],
                'tags' => ['talkable' => true, 'calmable' => true, 'companionable' => true]],
        ]);
    }

    /** @param list<array> $features */
    private function features(Zone $zone, array $features): void
    {
        foreach ($features as $feature) {
            SceneFeature::updateOrCreate(
                ['zone_id' => $zone->id, 'scene_id' => null, 'name' => $feature['name']],
                $feature + ['source' => 'seed'],
            );
        }
    }

    /** @param list<array> $actors */
    private function actors(Zone $zone, array $actors): void
    {
        foreach ($actors as $actor) {
            Actor::updateOrCreate(
                ['zone_id' => $zone->id, 'scene_id' => null, 'name' => $actor['name']],
                $actor + ['status' => 'active', 'source' => 'seed'],
            );
        }
    }
}
