<?php

namespace App\Game;

/**
 * The land a tale is set in — rolled by the ENGINE at campaign creation and
 * fixed for that campaign's whole life.
 *
 * Before this existed, the design bible's one worked example ("a lantern-lit
 * harbor city") was the only setting any prompt ever saw, so every forged
 * world drifted back to docks and tide-water, and every cold forge cloned the
 * shared harbor seed. The bible now carries tone and bounds only; the LAND
 * comes from here, one roll per campaign, so two tales started on the same
 * evening open on unrelated ground.
 *
 * Each flavor carries two things: a prompt brief (what Claude is told the
 * world is made of) and a cold-forge kit — names for a mechanically complete
 * zone the engine can build with no LLM at all. The kit's mechanics are
 * IDENTICAL across flavors (same affordance skeleton, same stat blocks); only
 * the names and prose change. Variety is a matter of fiction, never of
 * balance.
 */
class WorldFlavor
{
    /** The one flavor used when a campaign predates the roll or names an unknown land. */
    public const DEFAULT = 'harbor-city';

    /**
     * The cold-forge feature skeleton: one feature per affordance role, so a
     * cold zone always offers climbing, crossing, cover, an escape, something
     * breakable, and one thing that must be discovered.
     */
    private const FEATURE_KIT = [
        'high' => ['feature_type' => 'building', 'affordances' => ['reachable_via' => ['climb'], 'height' => 12]],
        'crossing' => ['feature_type' => 'crossing', 'affordances' => ['crossable_via' => ['leap', 'swing'], 'gap' => 'medium']],
        'cover' => ['feature_type' => 'cover', 'affordances' => ['hideable' => true, 'max_size' => 'large']],
        'bolt_hole' => ['feature_type' => 'flee_route', 'affordances' => ['flee_destination' => true, 'squeeze_required' => 'medium', 'hideable' => true, 'max_size' => 'medium']],
        'breakable' => ['feature_type' => 'obstacle', 'affordances' => ['breakable' => true, 'lift_weight' => 120]],
        'secret' => ['feature_type' => 'landmark', 'affordances' => ['hidden' => true, 'hideable' => true, 'max_size' => 'medium']],
    ];

    /** The cold-forge actor skeleton: one threat with weight, one quick one, one beast, one person worth talking to. */
    private const ACTOR_KIT = [
        'brute' => ['kind' => 'enemy', 'tier' => 'elite', 'health' => 9, 'attack' => 3,
            'tags' => ['intimidatable' => true, 'restrainable' => true]],
        'skirmisher' => ['kind' => 'enemy', 'tier' => 'regular', 'health' => 5, 'attack' => 2,
            'tags' => ['deceiveable' => true, 'intimidatable' => true]],
        'beast' => ['kind' => 'creature', 'tier' => 'regular', 'health' => 6, 'attack' => 2,
            'tags' => ['restrainable' => true]],
        'local' => ['kind' => 'npc', 'tier' => 'regular', 'health' => 4, 'attack' => 1,
            'tags' => ['talkable' => true, 'persuadeable' => true, 'companionable' => true]],
    ];

    /** Used only when a campaign has outlived its flavor's named grounds. */
    private const FAR_SUFFIXES = ['Far Side', 'Lower Reach', 'Far Verge'];

    /**
     * Every land carries the genres it can honestly wear (see
     * App\Game\StoryAspects), so a tale set in space never rolls chalk downs.
     *
     * @return array<string, array{
     *     title: string, genres: list<string>, land: string, edges: string,
     *     grounds: list<array{name: string, description: string}>,
     *     locales: list<array{title: string, description: string}>,
     *     features: array<string, string>, actors: array<string, string>}>
     */
    public static function all(): array
    {
        return [
            'harbor-city' => [
                'title' => 'a lantern-lit harbor city',
                'genres' => ['grounded-fantasy', 'pirates', 'horror', 'medieval'],
                'land' => 'A salt-rotted port stacked on its own older selves, where everything moves on tide, rumor, and the price of passage. The wilds press in past the last quay and nobody pretends otherwise.',
                'edges' => 'flood-taken outskirts, rope-strung rooftops, the wet country beyond the seawall',
                'grounds' => [
                    ['name' => 'The Old District', 'description' => 'Sagging warehouses and lantern-lit lanes stacked against the harbor wall, where the day\'s cargo becomes the night\'s argument.'],
                    ['name' => 'The Rookery Heights', 'description' => 'The roofline city above the streets: slate ridges, chimney stacks, and washing lines strung between other people\'s lives.'],
                    ['name' => 'The Drowned Market', 'description' => 'Arcades half-taken by the tide, where the stalls trade from rafts and every bargain is struck above deep water.'],
                ],
                'locales' => [
                    ['title' => 'Under the Harbor Wall', 'description' => 'The oldest stones in the district, where the wall keeps the lanes dim at noon.'],
                    ['title' => 'The Lamplighter\'s Round', 'description' => 'A circuit of posts lit in the same order every dusk, by the same tired man.'],
                    ['title' => 'The Cargo Yard', 'description' => 'Crates stacked into corridors that change shape between one night and the next.'],
                    ['title' => 'The Gull Steps', 'description' => 'A stair worn to a slope, climbing from the quay into the lanes above.'],
                    ['title' => 'The Chandler\'s Row', 'description' => 'Shopfronts of rope, tar, and lamp oil, open later than anything honest needs.'],
                    ['title' => 'The Tide Cellars', 'description' => 'Half-flooded vaults where the harbor breathes in and out through the grates.'],
                ],
                'features' => [
                    'high' => 'the loading crane', 'crossing' => 'the rope bridge', 'cover' => 'a wall of stacked crates',
                    'bolt_hole' => 'the smuggler\'s door', 'breakable' => 'the harbor chain', 'secret' => 'the tide-cellar grate',
                ],
                'actors' => [
                    'brute' => 'a harbor enforcer', 'skirmisher' => 'a wiry cutpurse',
                    'beast' => 'a mangy dock-dog', 'local' => 'a stray dockhand',
                ],
            ],

            'ashen-steppe' => [
                'title' => 'an ash-fallen steppe',
                'genres' => ['grounded-fantasy', 'western', 'post-apocalypse', 'high-fantasy'],
                'land' => 'Grass plains under a sky that has been shedding ash since before anyone\'s grandmother, so the horizon is always a shade of grey away. Herds, raiders, and standing stones; shelter is something you build, buy, or take.',
                'edges' => 'salt pans, burned-out waystations, the herd roads running out of sight',
                'grounds' => [
                    ['name' => 'The Long Burn', 'description' => 'Miles of grass gone silver under ash-fall, where a fire from any year still shows in the ground.'],
                    ['name' => 'The Standing Stones', 'description' => 'A field of leaning markers older than the herds, set by people who left no other argument.'],
                    ['name' => 'The Herd Road', 'description' => 'The wide trampled way the drovers keep, and everyone else uses when the drovers are not looking.'],
                ],
                'locales' => [
                    ['title' => 'The Ash Wallows', 'description' => 'Hollows where the fall lies deep enough to take a horse to the knee.'],
                    ['title' => 'The Broken Waystation', 'description' => 'Four walls, no roof, and a well somebody still keeps clear.'],
                    ['title' => 'The Wind Line', 'description' => 'A ridge where the grass all leans one way and has for a century.'],
                    ['title' => 'The Drover\'s Camp', 'description' => 'Fires, hobbled beasts, and a night watch that counts strangers twice.'],
                    ['title' => 'The Salt Pan', 'description' => 'A white flat that rings underfoot and holds every track laid on it.'],
                    ['title' => 'The Burn Scar', 'description' => 'Black ground still refusing to green, three summers on.'],
                ],
                'features' => [
                    'high' => 'a leaning standing stone', 'crossing' => 'a wind-cut gully', 'cover' => 'a drift of grey ash',
                    'bolt_hole' => 'a burrow mouth beneath the roots', 'breakable' => 'a rack of bleached bones', 'secret' => 'a hollow cairn',
                ],
                'actors' => [
                    'brute' => 'a steppe raider', 'skirmisher' => 'an outrider with a sling',
                    'beast' => 'an ash-jackal', 'local' => 'a herd-drover',
                ],
            ],

            'canopy-reach' => [
                'title' => 'a rainforest lived in from above',
                'genres' => ['grounded-fantasy', 'high-fantasy', 'anime'],
                'land' => 'Forest so old the people gave up on the floor and built their lives sixty feet up, on platforms, ladders, and long grudges. Everything below the canopy is rumor, root, and whatever feeds there.',
                'edges' => 'root-flooded lowland, rope-town platforms, the unlit forest floor',
                'grounds' => [
                    ['name' => 'The Ladder Towns', 'description' => 'Whole streets of platform and rope lashed around trunks, stacked three storeys into the green.'],
                    ['name' => 'The Green Loft', 'description' => 'The high canopy where the light lives, crossed on branches that bend but do not break.'],
                    ['name' => 'The Root Dark', 'description' => 'The forest floor, where the ladders stop and people go only in company.'],
                ],
                'locales' => [
                    ['title' => 'The Rope Market', 'description' => 'Stalls swaying on a platform that everyone insists is rated for the weight.'],
                    ['title' => 'The Long Walk', 'description' => 'A hundred feet of plank bridge with one handrail and a story about the other.'],
                    ['title' => 'The Cistern Bromeliads', 'description' => 'Leaf-cups the size of baths, holding last month\'s rain and things that swim in it.'],
                    ['title' => 'The Fig Cathedral', 'description' => 'A strangler fig grown hollow around whatever it killed, open to the sky.'],
                    ['title' => 'The Sap Works', 'description' => 'Buckets, tapped trunks, and the sweetest air in the reach.'],
                    ['title' => 'The Fallen Giant', 'description' => 'A toppled trunk making a bridge across the dark, forty feet above the roots.'],
                ],
                'features' => [
                    'high' => 'a strangler-fig ladder', 'crossing' => 'a swaying rope-walk', 'cover' => 'a curtain of hanging moss',
                    'bolt_hole' => 'a hollow trunk', 'breakable' => 'a rotten platform rail', 'secret' => 'a cache lashed under the boards',
                ],
                'actors' => [
                    'brute' => 'a canopy toll-taker', 'skirmisher' => 'a barefoot branch-runner',
                    'beast' => 'a heavy-jawed tree-cat', 'local' => 'a platform weaver',
                ],
            ],

            'sunken-caldera' => [
                'title' => 'the bowl of a sleeping volcano',
                'genres' => ['grounded-fantasy', 'high-fantasy', 'post-apocalypse'],
                'land' => 'A caldera floored with black glass and steaming pools, warm enough that people winter here in a place that has no winter. The mountain is not as dead as the maps insist.',
                'edges' => 'steam terraces, glass fields, the ash rim above',
                'grounds' => [
                    ['name' => 'The Glass Floor', 'description' => 'Acres of cooled lava that ring like crockery and cut like a razor where they break.'],
                    ['name' => 'The Steaming Terraces', 'description' => 'Stepped pools of hot water, each owned by somebody who will explain it to you.'],
                    ['name' => 'The Rim Road', 'description' => 'The track around the caldera lip, in wind, above everything.'],
                ],
                'locales' => [
                    ['title' => 'The Bathhouse Steps', 'description' => 'Wet stone, steam, and the whole town\'s business discussed at half volume.'],
                    ['title' => 'The Vent Field', 'description' => 'Ground that breathes, marked with stakes where the crust is thin.'],
                    ['title' => 'The Cutter\'s Yard', 'description' => 'Black glass stacked and sorted, every edge wrapped in rag.'],
                    ['title' => 'The Ash Terraces', 'description' => 'Warm ground where things grow out of season and everyone farms it.'],
                    ['title' => 'The Cinder Chapel', 'description' => 'A shrine built into the slag, kept by someone who counts the tremors.'],
                    ['title' => 'The Boiling Shallows', 'description' => 'A pool nobody crosses twice, ringed with warning stones.'],
                ],
                'features' => [
                    'high' => 'a spire of cooled lava', 'crossing' => 'a crust bridge over a steam vent', 'cover' => 'a curtain of sulfur steam',
                    'bolt_hole' => 'a lava-tube mouth', 'breakable' => 'a sheet of black glass', 'secret' => 'a sealed vent shrine',
                ],
                'actors' => [
                    'brute' => 'a glasscutter\'s enforcer', 'skirmisher' => 'a scavenger in a rag mask',
                    'beast' => 'a heat-loving rock lizard', 'local' => 'a bathhouse keeper',
                ],
            ],

            'winter-fells' => [
                'title' => 'a frozen upland',
                'genres' => ['grounded-fantasy', 'medieval', 'horror', 'modern-realistic'],
                'land' => 'White fells and black rock, with frozen tarns that ring like struck bells when you cross them. Cold is the local authority here and it takes its cut from everyone.',
                'edges' => 'avalanche country, frozen tarns, the shepherds\' huts below the snowline',
                'grounds' => [
                    ['name' => 'The White Fells', 'description' => 'Open snow country where the wind writes the map fresh every night.'],
                    ['name' => 'The Frozen Tarns', 'description' => 'A chain of iced-over lakes, each a road or a trapdoor depending on the week.'],
                    ['name' => 'The Snowline Huts', 'description' => 'The last inhabited band of the mountain, where fires are shared and doors are barred.'],
                ],
                'locales' => [
                    ['title' => 'The Cairn Line', 'description' => 'Stone markers set a shout apart, the only honest path in a whiteout.'],
                    ['title' => 'The Wind Scoop', 'description' => 'A hollow the gales have carved out and keep polishing.'],
                    ['title' => 'The Black Scree', 'description' => 'Bare rock where the snow will not hold and neither will your feet.'],
                    ['title' => 'The Byre', 'description' => 'A low stone shelter smelling of beasts, warm enough to argue in.'],
                    ['title' => 'The Ice Fall', 'description' => 'A frozen cascade hanging off the crag, blue the whole way down.'],
                    ['title' => 'The Avalanche Run', 'description' => 'A slope stripped bare, still creaking, that nobody names out loud.'],
                ],
                'features' => [
                    'high' => 'an ice-sheathed crag', 'crossing' => 'a frozen tarn', 'cover' => 'a bank of driven snow',
                    'bolt_hole' => 'a snow-hollow under a boulder', 'breakable' => 'a curtain of icicles', 'secret' => 'a buried shepherds\' cache',
                ],
                'actors' => [
                    'brute' => 'a fur-wrapped reaver', 'skirmisher' => 'a snow-blind scout',
                    'beast' => 'a lean winter wolf', 'local' => 'a hut-keeping shepherd',
                ],
            ],

            'dune-road' => [
                'title' => 'a caravan road between dunes',
                'genres' => ['grounded-fantasy', 'western', 'post-apocalypse', 'medieval'],
                'land' => 'A trade road threaded between sand hills, marked by wells worth more than the towns they feed. Wind erases yesterday, and every few years it uncovers something that should have stayed buried.',
                'edges' => 'wells and waystations, buried quarters, the open sand',
                'grounds' => [
                    ['name' => 'The Well Road', 'description' => 'A chain of water and shade, each stop a day apart and none of them free.'],
                    ['name' => 'The Buried Quarter', 'description' => 'A city the dunes took, showing three roofs and a minaret above the sand.'],
                    ['name' => 'The Long Dune', 'description' => 'A single ridge of sand walking slowly east, taking the road with it.'],
                ],
                'locales' => [
                    ['title' => 'The Well Head', 'description' => 'Rope, shade, and a queue that everyone respects until they don\'t.'],
                    ['title' => 'The Caravan Ring', 'description' => 'Beasts couched in a circle, packs stacked inside, watch set outside.'],
                    ['title' => 'The Glass Scars', 'description' => 'Streaks where lightning fused the sand, sharp and singing underfoot.'],
                    ['title' => 'The Roof Line', 'description' => 'The tops of buried houses, a street you walk at chimney height.'],
                    ['title' => 'The Shade Market', 'description' => 'Awnings strung wall to wall, trading only between noon and dusk.'],
                    ['title' => 'The Slip Face', 'description' => 'The dune\'s steep side, which forgives nothing and remembers no tracks.'],
                ],
                'features' => [
                    'high' => 'a half-buried watchtower', 'crossing' => 'a dune slip-face', 'cover' => 'a stand of dead palms',
                    'bolt_hole' => 'a drift-choked doorway', 'breakable' => 'a rotted caravan cart', 'secret' => 'a sand-covered cistern hatch',
                ],
                'actors' => [
                    'brute' => 'a road-toll raider', 'skirmisher' => 'a masked sand-runner',
                    'beast' => 'a dune jackal', 'local' => 'a well-keeper',
                ],
            ],

            'undercity' => [
                'title' => 'the cisterns beneath a city',
                'genres' => ['grounded-fantasy', 'horror', 'cyberpunk', 'medieval'],
                'land' => 'Catacombs, water galleries, and drowned foundations under a city that has forgotten it has a basement. Light is rationed down here, and everyone is down here for a reason.',
                'edges' => 'cistern galleries, bone stacks, the street grates above',
                'grounds' => [
                    ['name' => 'The Cistern Galleries', 'description' => 'Pillared halls of standing water, echoing every footstep back twice.'],
                    ['name' => 'The Bone Stacks', 'description' => 'Catacomb corridors racked to the ceiling, tidy as a library and just as quiet.'],
                    ['name' => 'The Grate Line', 'description' => 'The shallow level where daylight falls in bars and the city can be heard walking overhead.'],
                ],
                'locales' => [
                    ['title' => 'The Candle Market', 'description' => 'A dozen stalls selling light, and one selling the dark.'],
                    ['title' => 'The Sump', 'description' => 'Where everything the city loses eventually settles.'],
                    ['title' => 'The Sluice House', 'description' => 'Gates and wheels that decide which galleries are floor and which are lake.'],
                    ['title' => 'The Founders\' Vault', 'description' => 'Stonework older than the city above, still holding up its mistakes.'],
                    ['title' => 'The Drip Hall', 'description' => 'A room measured in falling water, where speaking softly carries furthest.'],
                    ['title' => 'The Ladder Shaft', 'description' => 'Ninety rungs up to a grate that may or may not be barred this month.'],
                ],
                'features' => [
                    'high' => 'a broken stair to the grates', 'crossing' => 'a collapsed span over the channel', 'cover' => 'a stack of ossuary crates',
                    'bolt_hole' => 'a drain mouth', 'breakable' => 'a rotten sluice gate', 'secret' => 'a bricked-over doorway',
                ],
                'actors' => [
                    'brute' => 'a tunnel-toll bruiser', 'skirmisher' => 'a lamp-thief',
                    'beast' => 'a pale cistern hound', 'local' => 'a candle-seller',
                ],
            ],

            'chalk-downs' => [
                'title' => 'open chalk hill country',
                'genres' => ['grounded-fantasy', 'medieval', 'horror', 'modern-realistic'],
                'land' => 'Rolling downs cut by sunken lanes, hillforts gone to grass, and villages keeping older agreements than the crown\'s. Wide sky, long sightlines, and nowhere to hide but the hollow ways.',
                'edges' => 'hollow ways, grass-grown hillforts, the wooded combes',
                'grounds' => [
                    ['name' => 'The Hollow Ways', 'description' => 'Lanes worn twelve feet below the fields by two thousand years of feet, roofed over with hedge.'],
                    ['name' => 'The Grass Fort', 'description' => 'A ring of ditch and bank on the high ground, holding nothing but sheep and old opinion.'],
                    ['name' => 'The Long Combe', 'description' => 'A wooded fold in the downs where the wind stops and the village keeps its back turned.'],
                ],
                'locales' => [
                    ['title' => 'The Ridge Way', 'description' => 'The oldest road on the downs, running the crest so you see trouble coming.'],
                    ['title' => 'The Dew Pond', 'description' => 'A clay-lined dish of water on dry hilltop, tended by someone every year.'],
                    ['title' => 'The Chalk Cut', 'description' => 'A white scar in the turf, visible from three parishes.'],
                    ['title' => 'The Tithe Barn', 'description' => 'Bigger than the church and better maintained, for reasons everyone understands.'],
                    ['title' => 'The Sheepfold', 'description' => 'Hurdles, dogs, and the only argument that matters this month.'],
                    ['title' => 'The Beacon Stone', 'description' => 'Where the fire is lit when the county needs telling something.'],
                ],
                'features' => [
                    'high' => 'the old hillfort rampart', 'crossing' => 'a chalk-cut gully', 'cover' => 'a hedgerow gone wild',
                    'bolt_hole' => 'a badger-worked bank', 'breakable' => 'a drystone wall', 'secret' => 'a barrow mouth in the turf',
                ],
                'actors' => [
                    'brute' => 'a hedge-toll bully', 'skirmisher' => 'a poacher with a sling',
                    'beast' => 'a hunting dog gone feral', 'local' => 'a shepherd on the ridge',
                ],
            ],

            'iron-warren' => [
                'title' => 'a mountainside cut for ore',
                'genres' => ['grounded-fantasy', 'western', 'medieval', 'post-apocalypse'],
                'land' => 'Three generations of digging terraced into a mountain: tramways, slag hills, and shafts that breathe warm air in winter. The company owns the ground; the warren owns everything under it.',
                'edges' => 'slag terraces, tramway cuttings, the deep shafts',
                'grounds' => [
                    ['name' => 'The Slag Terraces', 'description' => 'Grey steps of spoil marching down the mountain, with houses built on them anyway.'],
                    ['name' => 'The Tramway Cut', 'description' => 'A rail line notched into the slope, its wagons running whether anyone is on the track or not.'],
                    ['name' => 'The Breathing Shafts', 'description' => 'The deep workings, where the air moves on its own and the props talk at night.'],
                ],
                'locales' => [
                    ['title' => 'The Pit Head', 'description' => 'Winding gear, a queue of lamps, and the shift bell nobody argues with.'],
                    ['title' => 'The Sorting Floor', 'description' => 'Noise, dust, and a hundred pairs of fast hands picking ore from stone.'],
                    ['title' => 'The Company Row', 'description' => 'Identical houses, identical rent, one door painted a different colour.'],
                    ['title' => 'The Drowned Level', 'description' => 'A gallery given back to the water, still on the map, still worth something.'],
                    ['title' => 'The Powder Store', 'description' => 'Thick walls, thin roof, kept well away from everything that matters.'],
                    ['title' => 'The Spoil Slide', 'description' => 'Loose grey ground that moves under weight and takes fences with it.'],
                ],
                'features' => [
                    'high' => 'a headframe over the shaft', 'crossing' => 'a tramway trestle', 'cover' => 'a slag heap',
                    'bolt_hole' => 'an abandoned adit', 'breakable' => 'a rotted pit prop', 'secret' => 'a boarded-over drift',
                ],
                'actors' => [
                    'brute' => 'a company enforcer', 'skirmisher' => 'a scrap-runner',
                    'beast' => 'a pit dog', 'local' => 'an off-shift digger',
                ],
            ],

            'cloud-pass' => [
                'title' => 'a trade pass above the clouds',
                'genres' => ['grounded-fantasy', 'high-fantasy', 'anime', 'medieval'],
                'land' => 'A high road strung with rope bridges and hostel-monasteries that ring bells at strangers. The air is thin, tempers are patient, and the drop is always one step to your left.',
                'edges' => 'rope-bridge spans, prayer terraces, the cloud sea below',
                'grounds' => [
                    ['name' => 'The Bell Terraces', 'description' => 'Stepped monastery courts where every hour is announced whether you asked or not.'],
                    ['name' => 'The Rope Spans', 'description' => 'The linked bridges that make the pass a road instead of a wall.'],
                    ['name' => 'The Cloud Sea', 'description' => 'The level below the ridgeline, white and moving, that swallows anything dropped into it.'],
                ],
                'locales' => [
                    ['title' => 'The Pilgrim Hostel', 'description' => 'Bunks, butter tea, and a register that names everyone who came through.'],
                    ['title' => 'The Prayer Wall', 'description' => 'Stacked stones carved by passers-by for four hundred years.'],
                    ['title' => 'The Windward Ledge', 'description' => 'Six feet of path, a rope, and a gale with opinions.'],
                    ['title' => 'The Ice Cistern', 'description' => 'Meltwater held behind a wall, rationed by the bell.'],
                    ['title' => 'The Cairn Junction', 'description' => 'Where the pass forks and the wrong choice takes a day to notice.'],
                    ['title' => 'The Eagle Steps', 'description' => 'A stair cut into the cliff, wide enough for one and steep enough for none.'],
                ],
                'features' => [
                    'high' => 'a prayer-flag mast', 'crossing' => 'a rope bridge over the drop', 'cover' => 'a wall of stacked prayer stones',
                    'bolt_hole' => 'a pilgrims\' crawlway', 'breakable' => 'an ice-rimed handrail', 'secret' => 'a cliff-hollow reliquary',
                ],
                'actors' => [
                    'brute' => 'a pass-toll bravo', 'skirmisher' => 'a thin-air bandit',
                    'beast' => 'a cliff-leaping ram', 'local' => 'a bell-ringing hosteler',
                ],
            ],

            'broken-orchards' => [
                'title' => 'farm country the sickness emptied',
                'genres' => ['grounded-fantasy', 'horror', 'post-apocalypse', 'modern-realistic'],
                'land' => 'A decade of nobody: orchards gone to thicket, deer in the lanes, houses still standing with the tables set. Something has learned to use the roads at dusk and the last few families know its hours.',
                'edges' => 'abandoned steadings, second-growth woodland, the mill road',
                'grounds' => [
                    ['name' => 'The Quiet Steadings', 'description' => 'Farmhouses left mid-sentence, doors swinging, roofs holding for now.'],
                    ['name' => 'The Second Growth', 'description' => 'Ten years of saplings taking the fields back one hedge line at a time.'],
                    ['name' => 'The Mill Road', 'description' => 'The one route still walked, because the mill is the one thing still working.'],
                ],
                'locales' => [
                    ['title' => 'The Set Table', 'description' => 'A kitchen laid for six, dusted over, chairs pushed back.'],
                    ['title' => 'The Cider Yard', 'description' => 'A press seized with rust and a smell that never entirely left.'],
                    ['title' => 'The Deer Lawn', 'description' => 'A field cropped short by animals that no longer look up.'],
                    ['title' => 'The Boundary Hedge', 'description' => 'Laid by hand two generations back, now twelve feet of thorn.'],
                    ['title' => 'The Plague Stone', 'description' => 'A hollowed rock where coins were left in vinegar, still holding water.'],
                    ['title' => 'The Mill Pool', 'description' => 'Slack green water and a wheel that turns when the sluice is opened.'],
                ],
                'features' => [
                    'high' => 'a leaning hay-barn loft', 'crossing' => 'a broken millrace', 'cover' => 'a thicket of unpruned apple trees',
                    'bolt_hole' => 'a root cellar', 'breakable' => 'a rotten orchard gate', 'secret' => 'a well with a lid on it',
                ],
                'actors' => [
                    'brute' => 'a squatter with a billhook', 'skirmisher' => 'a lookout in the orchard rows',
                    'beast' => 'a boar gone huge in the fallow', 'local' => 'the last miller',
                ],
            ],

            'deep-wood' => [
                'title' => 'an old forest under charcoal smoke',
                'genres' => ['grounded-fantasy', 'high-fantasy', 'horror', 'medieval'],
                'land' => 'Woodland older than the law, with tracks that change their minds and boundary stones nobody has moved for good reason. The trees are the oldest local government and they hold court.',
                'edges' => 'charcoal clearings, deer paths, the boundary stones',
                'grounds' => [
                    ['name' => 'The Charcoal Clearings', 'description' => 'Smoking earth mounds and the burners who sleep beside them all season.'],
                    ['name' => 'The Deer Paths', 'description' => 'Narrow runs through the understorey, made by animals and used by everyone.'],
                    ['name' => 'The Boundary Stones', 'description' => 'The old markers deep in the wood, where two claims meet and neither presses it.'],
                ],
                'locales' => [
                    ['title' => 'The Coppice Rows', 'description' => 'Hazel cut on a seven-year cycle, straight as a colonnade.'],
                    ['title' => 'The Burner\'s Camp', 'description' => 'A turf hut, a water butt, and a fire that must not be left.'],
                    ['title' => 'The Wind-Thrown Acre', 'description' => 'A patch flattened by one storm, root-plates standing like doors.'],
                    ['title' => 'The Green Ride', 'description' => 'A grassy avenue cut through the trees for hunting, straight for half a mile.'],
                    ['title' => 'The Hollow Yew', 'description' => 'A tree old enough to stand inside, with room to spare.'],
                    ['title' => 'The Ford', 'description' => 'Where the stream runs shallow over gravel and every track converges.'],
                ],
                'features' => [
                    'high' => 'a lightning-split oak', 'crossing' => 'a deadfall over the stream', 'cover' => 'a stand of hazel scrub',
                    'bolt_hole' => 'a fox earth under the roots', 'breakable' => 'a charcoal-burner\'s stack', 'secret' => 'a stone with a hollow behind it',
                ],
                'actors' => [
                    'brute' => 'a forest-law bailiff', 'skirmisher' => 'a poacher in green',
                    'beast' => 'a silent hunting lynx', 'local' => 'a charcoal burner',
                ],
            ],

            // ---- Starfaring ground -------------------------------------------------
            // The affordance skeleton is genre-blind: a conduit climbs like a
            // crag, a cargo net hides like a hedgerow. Only the fiction moves.

            'derelict-station' => [
                'title' => 'a half-dead orbital station',
                'genres' => ['space', 'horror', 'cyberpunk'],
                'land' => 'A ring station running on a third of its power, where whole decks are cold and the people left aboard have divided what still works. Nothing outside the hull is coming to help.',
                'edges' => 'dark decks, the docking spine, the long fall outside the viewports',
                'grounds' => [
                    ['name' => 'The Lit Decks', 'description' => 'The powered third of the ring, crowded because it is warm, and policed by whoever holds the breakers.'],
                    ['name' => 'The Cold Ring', 'description' => 'Sealed sections in the dark, walked in suits, where the frost keeps a record of everyone who came through.'],
                    ['name' => 'The Docking Spine', 'description' => 'The long axis where ships still put in, and where every rumor arrives before its cargo does.'],
                ],
                'locales' => [
                    ['title' => 'The Hydroponics Tier', 'description' => 'The only green aboard, humid and loud with pumps.'],
                    ['title' => 'The Breaker Room', 'description' => 'Where somebody decides which decks get to be warm today.'],
                    ['title' => 'The Long Window', 'description' => 'A gallery of viewports where the station\'s own bulk turns slowly past the stars.'],
                    ['title' => 'The Recycler Deck', 'description' => 'Everything the station has ever thrown away, sorted by machines nobody maintains.'],
                    ['title' => 'The Quarantine Lock', 'description' => 'A door with a hand-written sign, sealed from the wrong side.'],
                    ['title' => 'The Spine Market', 'description' => 'Trade stalls bolted to the corridor walls, thinning out toward the dark end.'],
                ],
                'features' => [
                    'high' => 'an open conduit shaft', 'crossing' => 'a gap where the deck plating ends', 'cover' => 'a bank of cargo netting',
                    'bolt_hole' => 'a maintenance crawlway', 'breakable' => 'a buckled pressure panel', 'secret' => 'a sealed crew locker',
                ],
                'actors' => [
                    'brute' => 'a deck-boss in a hardsuit', 'skirmisher' => 'a scavenger off the cold ring',
                    'beast' => 'a hull-rat grown bold', 'local' => 'a station machinist',
                ],
            ],

            'colony-frontier' => [
                'title' => 'a half-terraformed colony world',
                'genres' => ['space', 'western', 'post-apocalypse'],
                'land' => 'Thin new air over old red ground, seeded with grass that has not decided to live yet. The company that started the work stopped answering, and the settlements have been sorting it out themselves ever since.',
                'edges' => 'seed-fields, dust flats, the silent relay towers',
                'grounds' => [
                    ['name' => 'The Seed Fields', 'description' => 'Kilometres of engineered grass holding down soil that wants to be dust again.'],
                    ['name' => 'The Dust Flats', 'description' => 'Unreclaimed ground where the old world shows through and the wind carries grit that eats machines.'],
                    ['name' => 'The Relay Line', 'description' => 'A chain of towers built to talk to a sky that stopped talking back.'],
                ],
                'locales' => [
                    ['title' => 'The Pump Station', 'description' => 'Water, shade, and the settlement\'s only real argument.'],
                    ['title' => 'The Landing Apron', 'description' => 'Scorched concrete that has not taken a ship in two years.'],
                    ['title' => 'The Greenhouse Row', 'description' => 'Sealed tunnels of real soil, guarded better than the houses.'],
                    ['title' => 'The Rust Yard', 'description' => 'Cannibalized machinery stacked by whoever gets there first.'],
                    ['title' => 'The Old Crater', 'description' => 'A dish of red stone the terraformers never filled.'],
                    ['title' => 'The Silent Tower', 'description' => 'A relay with its lights still on, receiving nothing.'],
                ],
                'features' => [
                    'high' => 'a relay tower gantry', 'crossing' => 'a washed-out irrigation cut', 'cover' => 'a windbreak of dead seed-grass',
                    'bolt_hole' => 'a buried service trench', 'breakable' => 'a corroded pump housing', 'secret' => 'a cached supply drop',
                ],
                'actors' => [
                    'brute' => 'a claim-jumper in a work rig', 'skirmisher' => 'a dust-runner',
                    'beast' => 'a burrowing pack-lizard', 'local' => 'a settlement pump-keeper',
                ],
            ],

            'asteroid-drift' => [
                'title' => 'a mining drift among asteroids',
                'genres' => ['space', 'cyberpunk'],
                'land' => 'A cluster of hollowed rocks lashed together with docking tubes, spun just hard enough to have a down. Everything here is measured: air, water, and how much anyone is owed.',
                'edges' => 'tube junctions, worked-out rocks, the black between them',
                'grounds' => [
                    ['name' => 'The Tube Warren', 'description' => 'Pressurized corridors linking a dozen rocks, patched so often nobody knows the original route.'],
                    ['name' => 'The Worked-Out Rock', 'description' => 'A mined-hollow asteroid left to the people who could not pay to leave.'],
                    ['name' => 'The Refinery Cage', 'description' => 'The processing frame at the drift\'s heart, hot, loud, and never off.'],
                ],
                'locales' => [
                    ['title' => 'The Air Board', 'description' => 'Where the day\'s ration is posted and read very carefully.'],
                    ['title' => 'The Spin Floor', 'description' => 'The deepest deck, where down feels almost honest.'],
                    ['title' => 'The Slag Bay', 'description' => 'Waste rock stacked in nets, drifting gently against the mesh.'],
                    ['title' => 'The Tether Lock', 'description' => 'Suits, lines, and the log everyone signs before going outside.'],
                    ['title' => 'The Company Office', 'description' => 'Two rooms, one desk, and the ledger the whole drift lives inside.'],
                    ['title' => 'The Quiet Rock', 'description' => 'A hollow nobody claims, kept dark on purpose.'],
                ],
                'features' => [
                    'high' => 'a refinery gantry', 'crossing' => 'a gap between docking tubes', 'cover' => 'a stack of ore nets',
                    'bolt_hole' => 'an unpressurized side bore', 'breakable' => 'a spalled rock face', 'secret' => 'a smuggler\'s void cache',
                ],
                'actors' => [
                    'brute' => 'a company muscle in a mining rig', 'skirmisher' => 'a tube-runner',
                    'beast' => 'a drift-bred scavenger swarm', 'local' => 'an air-rationer',
                ],
            ],

            // ---- Neon ground -------------------------------------------------------

            'neon-sprawl' => [
                'title' => 'a rain-lit megacity',
                'genres' => ['cyberpunk', 'modern-realistic', 'horror'],
                'land' => 'Twenty million people stacked under permanent rain and permanent advertising, where the street level never sees the sky and nobody looks up anyway. Everything is owned; the only question is by whom.',
                'edges' => 'the elevated levels, the flooded underlevels, the corporate spires',
                'grounds' => [
                    ['name' => 'The Street Level', 'description' => 'Noodle steam, neon, and traffic that does not stop for anyone on foot.'],
                    ['name' => 'The Skyways', 'description' => 'Elevated walkways between towers, where the rain falls past you instead of on you.'],
                    ['name' => 'The Underlevels', 'description' => 'The city\'s older floors, built over and forgotten, still lived in.'],
                ],
                'locales' => [
                    ['title' => 'The Night Market', 'description' => 'Four hundred stalls under one leaking roof, open until it isn\'t.'],
                    ['title' => 'The Ad Wall', 'description' => 'Forty storeys of light that makes the whole block flicker.'],
                    ['title' => 'The Clinic Row', 'description' => 'Back-alley surgeries with their prices in the window.'],
                    ['title' => 'The Transit Spine', 'description' => 'Where the trains come through fast and everybody stands well back.'],
                    ['title' => 'The Dead Block', 'description' => 'A tower emptied by a fire nobody investigated.'],
                    ['title' => 'The Rooftop Gardens', 'description' => 'Someone\'s private green, forty floors up, fenced and watched.'],
                ],
                'features' => [
                    'high' => 'a fire escape up the ad wall', 'crossing' => 'a gap between skyway spans', 'cover' => 'a bank of steaming vents',
                    'bolt_hole' => 'a service duct behind the noodle stalls', 'breakable' => 'a shuttered security grille', 'secret' => 'a sealed transit door',
                ],
                'actors' => [
                    'brute' => 'a corporate security contractor', 'skirmisher' => 'a courier on a stolen bike',
                    'beast' => 'a stray dog with a scavenged collar', 'local' => 'a night-market cook',
                ],
            ],

            'stack-tenements' => [
                'title' => 'a vertical slum of stacked towers',
                'genres' => ['cyberpunk', 'modern-realistic', 'post-apocalypse'],
                'land' => 'Housing blocks grown into one another by forty years of unlicensed building, until the whole stack became one structure with its own weather inside. The lifts stopped working a decade ago and life reorganized around the stairs.',
                'edges' => 'the light shafts, the roof gardens, the flooded ground floors',
                'grounds' => [
                    ['name' => 'The Middle Floors', 'description' => 'The stack\'s crowded heart, where the corridors are streets and the rent is collected in person.'],
                    ['name' => 'The Roof Gardens', 'description' => 'Soil hauled up thirty floors, guarded like treasure, because it is.'],
                    ['name' => 'The Drowned Base', 'description' => 'Ground floors given over to standing water and whatever grows in the dark.'],
                ],
                'locales' => [
                    ['title' => 'The Stair Market', 'description' => 'Trade conducted on landings, everyone stepping around everyone.'],
                    ['title' => 'The Light Shaft', 'description' => 'A gap cut through twelve floors so somebody could see the sun.'],
                    ['title' => 'The Water Line', 'description' => 'Where the hand-run pipes end and the buckets begin.'],
                    ['title' => 'The Bridge Walk', 'description' => 'Planks and scaffold linking two towers at the ninth floor.'],
                    ['title' => 'The Quiet Landing', 'description' => 'A floor everyone agrees to leave alone.'],
                    ['title' => 'The Generator Room', 'description' => 'Noise, fumes, and the most important machine in the stack.'],
                ],
                'features' => [
                    'high' => 'a scaffold ladder up the light shaft', 'crossing' => 'a plank bridge between towers', 'cover' => 'a wall of hung laundry',
                    'bolt_hole' => 'a gap behind the stairwell', 'breakable' => 'a rotted balcony rail', 'secret' => 'a bricked-up flat',
                ],
                'actors' => [
                    'brute' => 'a floor-boss collecting rent', 'skirmisher' => 'a stairwell lookout',
                    'beast' => 'a landing dog nobody owns', 'local' => 'a water-carrier',
                ],
            ],

            // ---- Heightened ground -------------------------------------------------

            'academy-island' => [
                'title' => 'an island academy for the strangely gifted',
                'genres' => ['anime', 'high-fantasy', 'modern-realistic'],
                'land' => 'A school on its own island, where students with impossible talents are taught, ranked, and quietly watched. The ferry runs twice a week and the staff decide who is on it.',
                'edges' => 'the training grounds, the cliff paths, the shuttered old campus',
                'grounds' => [
                    ['name' => 'The Upper Campus', 'description' => 'Dormitories, halls, and a ranking board everyone pretends not to check.'],
                    ['name' => 'The Training Grounds', 'description' => 'Fields and rigs built to survive whatever the students turn out to be.'],
                    ['name' => 'The Old Campus', 'description' => 'The wing closed after the incident, fenced with a story instead of a fence.'],
                ],
                'locales' => [
                    ['title' => 'The Ranking Board', 'description' => 'Names in order, updated on Fridays, read like scripture.'],
                    ['title' => 'The Bell Court', 'description' => 'Where classes change and every rivalry gets its audience.'],
                    ['title' => 'The Practice Rings', 'description' => 'Chalked circles worn into the dirt by a decade of matches.'],
                    ['title' => 'The Cliff Stair', 'description' => 'Two hundred steps to the ferry, and the only way off.'],
                    ['title' => 'The Greenhouse', 'description' => 'Warm, quiet, and where people go to say things properly.'],
                    ['title' => 'The Sealed Wing', 'description' => 'Chained doors, an intact classroom visible through the glass.'],
                ],
                'features' => [
                    'high' => 'the bell tower scaffold', 'crossing' => 'a gap in the cliff path', 'cover' => 'a rack of training dummies',
                    'bolt_hole' => 'a gap under the dormitory floor', 'breakable' => 'a cracked practice pillar', 'secret' => 'a sealed classroom door',
                ],
                'actors' => [
                    'brute' => 'the ranked upperclassman', 'skirmisher' => 'a fast-talking rival',
                    'beast' => 'the groundskeeper\'s enormous dog', 'local' => 'a tired instructor',
                ],
            ],

            'festival-city' => [
                'title' => 'a lantern-festival city where spirits walk',
                'genres' => ['anime', 'high-fantasy', 'horror'],
                'land' => 'A city three days into its great festival, hung end to end with paper lanterns, where the crowds are thick enough that things not entirely people can walk in them unnoticed. The old rules of hospitality are the only ones that hold.',
                'edges' => 'the shrine steps, the paper-lantern lanes, the river of the parade',
                'grounds' => [
                    ['name' => 'The Lantern Lanes', 'description' => 'Streets roofed in paper light, packed shoulder to shoulder until dawn.'],
                    ['name' => 'The Shrine Steps', 'description' => 'A thousand stone stairs up out of the noise, where offerings pile against the gate.'],
                    ['name' => 'The Parade Route', 'description' => 'The cleared road the great floats come down, guarded by rope and custom.'],
                ],
                'locales' => [
                    ['title' => 'The Mask Stalls', 'description' => 'Rows of faces for sale, and a few nobody remembers making.'],
                    ['title' => 'The Offering Gate', 'description' => 'Rice, coins, and letters left for things that read them.'],
                    ['title' => 'The Float Yard', 'description' => 'Enormous painted structures resting between processions.'],
                    ['title' => 'The Quiet Bridge', 'description' => 'One span the crowd never quite crosses, for no reason anyone gives.'],
                    ['title' => 'The Fire Watch', 'description' => 'Buckets, sand, and men who have not slept for three nights.'],
                    ['title' => 'The Old Ward', 'description' => 'Where the lanterns thin out and the festival is only a sound.'],
                ],
                'features' => [
                    'high' => 'a festival float scaffold', 'crossing' => 'a rope-strung lantern line', 'cover' => 'a curtain of hanging paper lanterns',
                    'bolt_hole' => 'a gap behind the shrine screens', 'breakable' => 'a stacked offering rack', 'secret' => 'a shuttered spirit-gate',
                ],
                'actors' => [
                    'brute' => 'a festival ward captain', 'skirmisher' => 'a masked cutpurse',
                    'beast' => 'a fox that watches too closely', 'local' => 'a lantern-maker',
                ],
            ],

            // ---- Salt and powder ---------------------------------------------------

            'reef-isles' => [
                'title' => 'a chain of reef islands',
                'genres' => ['pirates', 'grounded-fantasy'],
                'land' => 'A scatter of green islands behind a reef that eats deep-draught ships, which is exactly why the people here chose it. Every anchorage is somebody\'s, and the somebody changes.',
                'edges' => 'hidden anchorages, the reef shallows, the high jungle spine',
                'grounds' => [
                    ['name' => 'The Careening Beach', 'description' => 'Where hulls are hauled over and scraped, and half the island\'s business gets settled.'],
                    ['name' => 'The Reef Shallows', 'description' => 'Clear water over teeth of coral, crossed only by people who grew up on it.'],
                    ['name' => 'The Island Spine', 'description' => 'Jungle ridge above the anchorages, with a view of every sail for ten miles.'],
                ],
                'locales' => [
                    ['title' => 'The Powder Store', 'description' => 'Dug into the hillside, well away from the drinking.'],
                    ['title' => 'The Long Hut', 'description' => 'Where shares are argued out loud and written down badly.'],
                    ['title' => 'The Lookout Palm', 'description' => 'The tallest tree on the ridge, with steps cut into it.'],
                    ['title' => 'The Wreck Bar', 'description' => 'A hull hauled up the sand and roofed over.'],
                    ['title' => 'The Fresh Spring', 'description' => 'The reason anyone settled here at all.'],
                    ['title' => 'The Careened Hulk', 'description' => 'A ship on its side, half stripped, still argued over.'],
                ],
                'features' => [
                    'high' => 'a lookout palm with cut steps', 'crossing' => 'a gap in the reef shelf', 'cover' => 'a stand of sea-grape',
                    'bolt_hole' => 'a smuggler\'s cave mouth', 'breakable' => 'a rotted careening spar', 'secret' => 'a buried powder cache',
                ],
                'actors' => [
                    'brute' => 'a quartermaster with a grudge', 'skirmisher' => 'a barefoot deckhand',
                    'beast' => 'a reef-hunting shark', 'local' => 'a shipwright',
                ],
            ],

            'salt-flotilla' => [
                'title' => 'a town of ships lashed together',
                'genres' => ['pirates', 'post-apocalypse', 'grounded-fantasy'],
                'land' => 'Forty hulls rafted into one floating town, roped and planked and rebuilt after every storm. Nobody owns the sea under it, which is the whole point.',
                'edges' => 'the outer hulls, the plank streets, the open water past the last line',
                'grounds' => [
                    ['name' => 'The Plank Streets', 'description' => 'Walkways lashed deck to deck, rising and falling with the swell.'],
                    ['name' => 'The Outer Hulls', 'description' => 'The newest ships, tied at the edge, first to take weather and boarders.'],
                    ['name' => 'The Old Keels', 'description' => 'The flotilla\'s core, four generations grown together into one hull.'],
                ],
                'locales' => [
                    ['title' => 'The Rope Court', 'description' => 'Where disputes are heard, on the widest deck, in public.'],
                    ['title' => 'The Rain Casks', 'description' => 'The flotilla\'s water, counted daily.'],
                    ['title' => 'The Net Walk', 'description' => 'A stretch crossed on nets alone, sagging over black water.'],
                    ['title' => 'The Gutting Deck', 'description' => 'Fish, knives, gulls, and the loudest gossip afloat.'],
                    ['title' => 'The Cut Line', 'description' => 'Where a hull was cast off in a storm and never retied.'],
                    ['title' => 'The Bell Mast', 'description' => 'The tallest spar, rung for weather, boarders, or a death.'],
                ],
                'features' => [
                    'high' => 'the bell mast rigging', 'crossing' => 'a gap between lashed hulls', 'cover' => 'a stack of drying nets',
                    'bolt_hole' => 'a hatch into the old keels', 'breakable' => 'a rotted plank walkway', 'secret' => 'a false-bottomed hold',
                ],
                'actors' => [
                    'brute' => 'a flotilla enforcer', 'skirmisher' => 'a rope-quick boarder',
                    'beast' => 'a gull-mobbed deck cat', 'local' => 'a net-mender',
                ],
            ],

            'boomtown' => [
                'title' => 'a frontier boomtown',
                'genres' => ['western', 'grounded-fantasy', 'modern-realistic'],
                'land' => 'A town that went from four buildings to four hundred in eighteen months because of what came out of the ground, and will go back to four when it stops. Law here is whoever is standing in the street at the time.',
                'edges' => 'the diggings, the stage road, the open range past the last fence',
                'grounds' => [
                    ['name' => 'The Main Drag', 'description' => 'One wide street of raw timber storefronts, mud, and a great deal of opinion.'],
                    ['name' => 'The Diggings', 'description' => 'The worked ground the whole town exists for, staked, guarded, and re-staked.'],
                    ['name' => 'The Stage Road', 'description' => 'The one route in and out, watched by everyone with a reason to watch it.'],
                ],
                'locales' => [
                    ['title' => 'The Assay Office', 'description' => 'Scales, a strongbox, and the man who decides what anyone is worth.'],
                    ['title' => 'The Water Trough', 'description' => 'Where the street\'s business gets conducted between horses.'],
                    ['title' => 'The Tent Line', 'description' => 'Canvas housing for everyone who arrived last week.'],
                    ['title' => 'The Livery', 'description' => 'Horses, hay, and the only ladder in town worth climbing.'],
                    ['title' => 'The Boot Hill', 'description' => 'Fresh markers, most of them recent, some of them honest.'],
                    ['title' => 'The Dry Wash', 'description' => 'A gully behind the buildings where things get carried away.'],
                ],
                'features' => [
                    'high' => 'the livery hayloft', 'crossing' => 'the dry wash gully', 'cover' => 'a stack of mine timbers',
                    'bolt_hole' => 'a crawlspace under the boardwalk', 'breakable' => 'a saloon hitching rail', 'secret' => 'a floorboard cache',
                ],
                'actors' => [
                    'brute' => 'a claim boss with a shotgun', 'skirmisher' => 'a quick-handed card sharp',
                    'beast' => 'a half-broke horse', 'local' => 'the assay clerk',
                ],
            ],

            // ---- Ground that watches back ------------------------------------------

            'fog-village' => [
                'title' => 'a village the fog does not leave',
                'genres' => ['horror', 'grounded-fantasy', 'modern-realistic'],
                'land' => 'A valley village where the fog came one autumn and stayed, thick enough that the church is a rumor from the green. The people still keep their routines, and the routines have started keeping them.',
                'edges' => 'the fogline, the drowned water meadow, the road that comes back',
                'grounds' => [
                    ['name' => 'The Green', 'description' => 'The village centre, where you can see four buildings and hear all of them.'],
                    ['name' => 'The Water Meadow', 'description' => 'Flooded pasture below the village, where sound carries wrong.'],
                    ['name' => 'The Fogline', 'description' => 'The valley edge where the fog thins and the road out should be.'],
                ],
                'locales' => [
                    ['title' => 'The Lychgate', 'description' => 'The churchyard entrance, where the village still leaves things.'],
                    ['title' => 'The Bell Rope', 'description' => 'Rung at set hours by someone who has never missed one.'],
                    ['title' => 'The Empty Pub', 'description' => 'Fire lit, glasses poured, nobody in it.'],
                    ['title' => 'The Sunken Lane', 'description' => 'A road cut below the fields, where the fog pools deepest.'],
                    ['title' => 'The Old Sluice', 'description' => 'Gates that decide how much of the meadow is water this week.'],
                    ['title' => 'The Turning Post', 'description' => 'A signpost on the fogline whose arms do not always agree.'],
                ],
                'features' => [
                    'high' => 'the church tower stair', 'crossing' => 'a broken footbridge over the sluice', 'cover' => 'a bank of standing fog',
                    'bolt_hole' => 'a coal chute under the pub', 'breakable' => 'a rotted lychgate', 'secret' => 'a boarded cellar door',
                ],
                'actors' => [
                    'brute' => 'a parish warden who does not blink', 'skirmisher' => 'a figure keeping pace in the fog',
                    'beast' => 'a sheepdog with no flock', 'local' => 'the last publican',
                ],
            ],

            'ruin-freeway' => [
                'title' => 'a dead freeway through a fallen city',
                'genres' => ['post-apocalypse', 'modern-realistic', 'horror'],
                'land' => 'Eight lanes elevated above a city that stopped, jammed end to end with the cars everyone left in. The road is the safest way through and everyone knows it, which is the problem.',
                'edges' => 'the collapsed spans, the towers either side, the street level below',
                'grounds' => [
                    ['name' => 'The Jam', 'description' => 'Miles of stopped traffic gone to rust, walked like a corridor of steel.'],
                    ['name' => 'The Broken Span', 'description' => 'Where the freeway fell into the street and the route becomes a climb.'],
                    ['name' => 'The Off-Ramp Camps', 'description' => 'Settlements built where the road touches ground, fortified both ways.'],
                ],
                'locales' => [
                    ['title' => 'The Sign Gantry', 'description' => 'Green boards still naming exits that do not exist.'],
                    ['title' => 'The Bus Wall', 'description' => 'Coaches dragged across the lanes as a gate.'],
                    ['title' => 'The Fuel Tanker', 'description' => 'Toppled, empty, and still the best landmark for a mile.'],
                    ['title' => 'The Barrier Gap', 'description' => 'A break in the central divider everybody uses and nobody guards.'],
                    ['title' => 'The Toll Camp', 'description' => 'A checkpoint run by whoever holds it this season.'],
                    ['title' => 'The Quiet Mile', 'description' => 'A stretch with no cars at all, which nobody can explain.'],
                ],
                'features' => [
                    'high' => 'a sign gantry over the lanes', 'crossing' => 'the gap where the span fell', 'cover' => 'a wall of stacked wrecks',
                    'bolt_hole' => 'a drainage culvert under the road', 'breakable' => 'a rusted crash barrier', 'secret' => 'a sealed cargo trailer',
                ],
                'actors' => [
                    'brute' => 'a toll-camp heavy', 'skirmisher' => 'a scavenger working the wrecks',
                    'beast' => 'a lean pack dog', 'local' => 'a ramp-camp trader',
                ],
            ],

            // ---- Older and stranger ------------------------------------------------

            'sky-shards' => [
                'title' => 'islands hanging in open sky',
                'genres' => ['high-fantasy', 'anime'],
                'land' => 'Broken land floating at every height, trailing waterfalls into cloud, linked by whatever people have managed to build between them. Down is a rumor; nobody has come back from checking.',
                'edges' => 'chain bridges, drifting lesser shards, the cloud floor below',
                'grounds' => [
                    ['name' => 'The Chain Isles', 'description' => 'A cluster of shards linked by iron chain bridges, inhabited and quarrelsome.'],
                    ['name' => 'The Falling Gardens', 'description' => 'Terraces down a shard\'s underside, watered by the fall itself.'],
                    ['name' => 'The Drift Shoal', 'description' => 'Smaller stones adrift on the wind, boarded like ships when they pass close.'],
                ],
                'locales' => [
                    ['title' => 'The Anchor Post', 'description' => 'Where the chains are made fast and inspected by people who take it seriously.'],
                    ['title' => 'The Updraught', 'description' => 'A column of rising air used as a road by anything that can ride it.'],
                    ['title' => 'The Cistern Shard', 'description' => 'A stone that holds rain, and is therefore the richest rock in the sky.'],
                    ['title' => 'The Broken Chain', 'description' => 'A bridge that failed, its far half hanging in open air.'],
                    ['title' => 'The Root Underside', 'description' => 'The shard\'s bottom, hung with roots and things that nest in them.'],
                    ['title' => 'The Wind Shrine', 'description' => 'Bells and ribbons on the windward edge, read like a forecast.'],
                ],
                'features' => [
                    'high' => 'a hanging root ladder', 'crossing' => 'a chain bridge over open sky', 'cover' => 'a curtain of falling water',
                    'bolt_hole' => 'a hollow in the shard\'s underside', 'breakable' => 'a corroded chain anchor', 'secret' => 'a shrine niche in the rock',
                ],
                'actors' => [
                    'brute' => 'a bridge-toll skyman', 'skirmisher' => 'a chain-runner',
                    'beast' => 'a nesting cliff-raptor', 'local' => 'an anchor-keeper',
                ],
            ],

            'god-bones' => [
                'title' => 'the remains of something enormous',
                'genres' => ['high-fantasy', 'horror', 'grounded-fantasy'],
                'land' => 'A landscape that is a body: ribs the size of towers, a spine walked like a ridge road, ground that is not quite stone. It fell long enough ago that towns grew in its shelter and short enough ago that it is still warm in places.',
                'edges' => 'the rib country, the spine road, the deep hollows inside',
                'grounds' => [
                    ['name' => 'The Rib Country', 'description' => 'Curved white spans arching over farmland, roofed and built against.'],
                    ['name' => 'The Spine Road', 'description' => 'A ridge of vertebrae walked end to end, the safest high route for fifty miles.'],
                    ['name' => 'The Deep Hollows', 'description' => 'The chambers inside, warm, echoing, and claimed by things that like both.'],
                ],
                'locales' => [
                    ['title' => 'The Marrow Wells', 'description' => 'Shafts sunk into the bone for something the locals will not name.'],
                    ['title' => 'The Rib Town', 'description' => 'A whole settlement roofed by one arch of it.'],
                    ['title' => 'The Warm Chamber', 'description' => 'A hollow that never cools, and never has for a century.'],
                    ['title' => 'The Scavenger Cut', 'description' => 'Where bone is quarried, under guard, by the yard.'],
                    ['title' => 'The Eye Socket', 'description' => 'An opening the size of a cathedral, facing the sunset.'],
                    ['title' => 'The Quiet Joint', 'description' => 'A place the bone still shifts, very slightly, in bad weather.'],
                ],
                'features' => [
                    'high' => 'a rib arch with cut hand-holds', 'crossing' => 'a gap between vertebrae', 'cover' => 'a drift of bone dust',
                    'bolt_hole' => 'a marrow shaft', 'breakable' => 'a brittle bone spar', 'secret' => 'a sealed inner chamber',
                ],
                'actors' => [
                    'brute' => 'a bone-quarry foreman', 'skirmisher' => 'a relic scavenger',
                    'beast' => 'a hollow-dwelling carrion beast', 'local' => 'a marrow-well keeper',
                ],
            ],

            'walled-town' => [
                'title' => 'a walled town in a hard season',
                'genres' => ['medieval', 'grounded-fantasy', 'horror'],
                'land' => 'Stone walls, four gates, and eight hundred people inside them who all know each other\'s business. Something outside has made the gates matter again, and the town is discovering what it is willing to do about it.',
                'edges' => 'the gatehouses, the wall walk, the country outside',
                'grounds' => [
                    ['name' => 'The Market Square', 'description' => 'The town\'s one open space, where announcements are made and believed differently by everyone.'],
                    ['name' => 'The Wall Walk', 'description' => 'The circuit above the streets, watched in shifts by people who would rather not.'],
                    ['name' => 'The Shambles', 'description' => 'The oldest lanes, narrow enough to touch both walls, roofed by overhanging upper floors.'],
                ],
                'locales' => [
                    ['title' => 'The North Gate', 'description' => 'Barred since the trouble, argued about daily.'],
                    ['title' => 'The Well Court', 'description' => 'The town\'s water and the town\'s news, in that order.'],
                    ['title' => 'The Tithe Barn', 'description' => 'Where the winter stores sit, counted twice a week.'],
                    ['title' => 'The Undercroft', 'description' => 'Vaulted cellars beneath the merchant houses, linked door to door.'],
                    ['title' => 'The Bell Tower', 'description' => 'Rung for fire, flood, and the other thing.'],
                    ['title' => 'The Postern', 'description' => 'A small door in the wall that officially does not exist.'],
                ],
                'features' => [
                    'high' => 'the gatehouse stair', 'crossing' => 'a gap in the wall walk', 'cover' => 'a row of market awnings',
                    'bolt_hole' => 'the postern door', 'breakable' => 'a barred shutter', 'secret' => 'a bricked undercroft passage',
                ],
                'actors' => [
                    'brute' => 'a gate sergeant', 'skirmisher' => 'a cutpurse working the square',
                    'beast' => 'a butcher\'s mastiff', 'local' => 'the well-court gossip',
                ],
            ],

            'city-nightshift' => [
                'title' => 'a modern city on the night shift',
                'genres' => ['modern-realistic', 'horror', 'cyberpunk'],
                'land' => 'A working city between midnight and six, when the buildings are empty, the roads are yours, and everyone still out has a reason. Nothing here is stranger than people, which is enough.',
                'edges' => 'the loading docks, the transit tunnels, the towers with one light on',
                'grounds' => [
                    ['name' => 'The Empty Downtown', 'description' => 'Glass towers, lit lobbies, and nobody in any of them.'],
                    ['name' => 'The Service Level', 'description' => 'Loading bays, bin stores, and the doors the city actually runs on.'],
                    ['name' => 'The All-Night Strip', 'description' => 'The four blocks that never close, and everyone who ends up there.'],
                ],
                'locales' => [
                    ['title' => 'The Parking Structure', 'description' => 'Six floors, four cars, and very good sightlines.'],
                    ['title' => 'The Loading Dock', 'description' => 'Roller doors, a smoking security guard, and one propped exit.'],
                    ['title' => 'The Night Diner', 'description' => 'Fluorescent, half-full, and neutral ground by long habit.'],
                    ['title' => 'The Transit Tunnel', 'description' => 'Closed for works, still walkable if you know the gate.'],
                    ['title' => 'The Site Hoarding', 'description' => 'A construction lot behind plywood, floodlit and unwatched.'],
                    ['title' => 'The One Lit Floor', 'description' => 'Twenty-third storey, lights on, somebody working very late.'],
                ],
                'features' => [
                    'high' => 'a construction site scaffold', 'crossing' => 'a gap between parking decks', 'cover' => 'a row of bin stores',
                    'bolt_hole' => 'a propped fire exit', 'breakable' => 'a chained roller door', 'secret' => 'a locked utility room',
                ],
                'actors' => [
                    'brute' => 'a private security lead', 'skirmisher' => 'a bike courier who never stops',
                    'beast' => 'an urban fox working the bins', 'local' => 'the night-diner cook',
                ],
            ],

            'backcountry-park' => [
                'title' => 'deep backcountry, days from a road',
                'genres' => ['modern-realistic', 'horror', 'post-apocalypse'],
                'land' => 'Wilderness big enough to lose a search party in, with a ranger network that is thin in summer and gone by October. The nearest help is a two-day walk and knows it.',
                'edges' => 'the trailheads, the burn scar, the ridgeline above treeline',
                'grounds' => [
                    ['name' => 'The Trail System', 'description' => 'Marked routes, log bridges, and shelters spaced a hard day apart.'],
                    ['name' => 'The Burn Scar', 'description' => 'Ten thousand acres of standing dead timber, silent and open.'],
                    ['name' => 'The High Ridge', 'description' => 'Above the trees, where the weather arrives first and the radio finally works.'],
                ],
                'locales' => [
                    ['title' => 'The Backcountry Hut', 'description' => 'Bunks, a stove, and a logbook everyone signs.'],
                    ['title' => 'The Log Crossing', 'description' => 'One felled trunk over fast water, with a rope if you are lucky.'],
                    ['title' => 'The Bear Cache', 'description' => 'Food hung high, and the ground beneath it well trodden.'],
                    ['title' => 'The Blowdown', 'description' => 'Acres of storm-thrown timber crossed at knee height.'],
                    ['title' => 'The Old Fire Lookout', 'description' => 'A tower on the ridge, decommissioned, still standing.'],
                    ['title' => 'The Cut Trail', 'description' => 'A route not on the map, recently cleared by somebody.'],
                ],
                'features' => [
                    'high' => 'the fire lookout tower', 'crossing' => 'a log bridge over fast water', 'cover' => 'a tangle of storm blowdown',
                    'bolt_hole' => 'a hollow beneath a root plate', 'breakable' => 'a rotten trail bridge', 'secret' => 'a cached supply drum',
                ],
                'actors' => [
                    'brute' => 'a man who does not want to be found', 'skirmisher' => 'a fast-moving stranger on the trail',
                    'beast' => 'a bear working the cache', 'local' => 'a backcountry ranger',
                ],
            ],
        ];
    }

    /** @return list<string> */
    public static function keys(): array
    {
        return array_keys(self::all());
    }

    public static function has(string $key): bool
    {
        return isset(self::all()[$key]);
    }

    /**
     * The lands a player may ask for by name. Random is still the default —
     * this is the "I know what I want" path.
     *
     * @return list<array{key: string, label: string, land: string, genres: list<string>}>
     */
    public static function options(): array
    {
        return collect(self::all())
            ->map(fn (array $flavor, string $key) => [
                'key' => $key,
                'label' => ucfirst($flavor['title']),
                'land' => $flavor['land'],
                'genres' => $flavor['genres'],
            ])
            ->values()->all();
    }

    /**
     * The lands that can honestly wear a genre. An unknown genre (the player
     * typed their own) constrains nothing — the forge reconciles it, and the
     * cold forge draws from the whole catalog rather than refusing to build.
     *
     * @return list<string>
     */
    public static function keysForGenre(?string $genre): array
    {
        if ($genre === null || $genre === '') {
            return self::keys();
        }

        $matching = collect(self::all())
            ->filter(fn (array $flavor) => in_array($genre, $flavor['genres'], true))
            ->keys()->all();

        return $matching !== [] ? $matching : self::keys();
    }

    /**
     * Roll the land a new tale opens in, out of `$pool` (the genre's lands, or
     * everything). `$avoid` holds the player's recent lands — a fresh roll
     * never repeats one of those, so a run of new tales cannot keep handing
     * back the same country. If avoiding would leave too little to draw from,
     * the POOL comes back whole; the genre is never broken to satisfy it.
     *
     * @param  list<string>|string|null  $avoid
     * @param  list<string>|null  $pool
     */
    public static function roll(array|string|null $avoid = null, ?array $pool = null): string
    {
        $pool = $pool !== null && $pool !== [] ? array_values($pool) : self::keys();
        $narrowed = array_values(array_diff($pool, array_filter((array) $avoid)));

        if (count($narrowed) < 2) {
            $narrowed = $pool;
        }

        return $narrowed[array_rand($narrowed)];
    }

    /** @return array<string, mixed> */
    public static function get(string $key): array
    {
        return self::all()[$key] ?? self::all()[self::DEFAULT];
    }

    /**
     * The prompt block naming this campaign's land. Every Claude call that
     * invents or narrates ground gets this, and it OUTRANKS any setting the
     * design bible happens to use as an example.
     */
    public static function brief(string $key): string
    {
        $flavor = self::get($key);

        return "This campaign's world is {$flavor['title']}. {$flavor['land']}\n"
            ."What lies at its edges: {$flavor['edges']}.";
    }

    /**
     * A whole zone the engine can build with no LLM at all — same shape
     * ZoneForge::sanitize() returns, so it drops straight into materialize().
     * Used when the Claude forge is unavailable; the tale never stalls and
     * never falls back onto somebody else's harbor.
     *
     * @param  list<string>  $usedNames  zone names this campaign already holds
     */
    public static function coldPlan(string $key, array $usedNames = []): array
    {
        $flavor = self::get($key);
        $grounds = $flavor['grounds'];

        $ground = null;
        foreach ($grounds as $candidate) {
            if (! in_array($candidate['name'], $usedNames, true)) {
                $ground = $candidate;
                break;
            }
        }

        // Past the flavor's named grounds, keep walking the same land: reuse a
        // ground under a further-out name rather than repeat one exactly.
        if ($ground === null) {
            $lap = intdiv(count($usedNames), count($grounds));
            $ground = $grounds[count($usedNames) % count($grounds)];
            $ground['name'] .= ', '.self::FAR_SUFFIXES[($lap - 1) % count(self::FAR_SUFFIXES)];
        }

        // Second zone in a land draws the other half of its locales, so the
        // frontier does not open onto the same six named spots.
        $locales = array_slice($flavor['locales'], (count($usedNames) % 2) * 3, 3);

        return [
            'name' => $ground['name'],
            'description' => $ground['description'],
            'locales' => $locales,
            'features' => collect(self::FEATURE_KIT)
                ->map(fn ($spec, $role) => [
                    'name' => $flavor['features'][$role],
                    'feature_type' => $spec['feature_type'],
                    'affordances' => $spec['affordances'],
                ])
                ->values()->all(),
            'actors' => collect(self::ACTOR_KIT)
                ->map(fn ($spec, $role) => [
                    'name' => $flavor['actors'][$role],
                    'kind' => $spec['kind'],
                    'tier' => $spec['tier'],
                    'stats' => [
                        'health' => ['current' => $spec['health'], 'max' => $spec['health']],
                        'attack' => $spec['attack'],
                    ],
                    'tags' => $spec['tags'],
                ])
                ->values()->all(),
        ];
    }
}
