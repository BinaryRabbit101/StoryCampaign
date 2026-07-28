<?php

namespace Tests\Feature;

use App\Game\Engine\Ambient;
use App\Game\Engine\CardComposer;
use App\Game\Engine\Dice;
use App\Game\Engine\Odds;
use App\Game\Engine\SceneDresser;
use App\Game\Engine\SituationBoard;
use App\Game\Engine\TurnResolver;
use App\Game\Meters;
use App\Models\Actor;
use App\Models\Campaign;
use App\Models\Character;
use App\Models\Scene;
use App\Models\SceneFeature;
use App\Models\Turn;
use App\Models\User;
use App\Services\Claude\ClaudeCli;
use App\Services\Claude\Narrator;
use App\Services\TurnStarter;
use Database\Seeders\WorldSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Ambient conditions: the air a scene stands in.
 *
 * One key rolled per dressed scene, fixed for that scene's life, priced as
 * itemized parts on the one ladder both the card's forecast and the die read.
 * The engine's vocabulary is abstract — gloom, haze, squall, clear — because it
 * has to work on an ash steppe and aboard a derelict station alike; the
 * narrator is the only thing that turns a key into weather.
 */
class AmbientTest extends TestCase
{
    use RefreshDatabase;

    private function createCampaign(): Campaign
    {
        $this->seed(WorldSeeder::class);

        $this->mock(ClaudeCli::class, function ($mock) {
            $mock->shouldReceive('promptForJson')->andThrow(new \RuntimeException('offline'))->byDefault();
            $mock->shouldReceive('prompt')->andReturn('A tale begins.')->byDefault();
        });

        $campaign = Campaign::create([
            'user_id' => User::factory()->create()->id,
            'name' => 'Weather Tale',
            'world_flavor' => 'harbor-city',
            'status' => 'active',
            'started_at' => now(),
        ]);

        $character = Character::create([
            'campaign_id' => $campaign->id,
            'name' => 'The Cat',
            'description' => 'A striking black cat.',
            'meters' => Meters::default(),
            'status' => 'alive',
            'meters_regenerated_at' => now(),
        ]);

        // Everything the ambient table touches: cover to take, ground to read,
        // a trail to follow, and a way up.
        foreach ([
            ['capability' => 'conceal'],
            ['capability' => 'scout'],
            ['capability' => 'track'],
            ['capability' => 'climb'],
        ] as $capability) {
            $character->capabilities()->create($capability + ['source' => 'creation']);
        }

        return $campaign;
    }

    /** A turn on ground the test controls: no strangers, no leftover props. */
    private function openBareTurn(Campaign $campaign): Turn
    {
        $turn = app(TurnStarter::class)->openFirstTurn($campaign);
        $scene = $campaign->activeScene;
        $scene->actors()->delete();
        $scene->features()->delete();

        return $turn->fresh();
    }

    /** Copy a seeded zone template into the scene, so the ground is exactly what the test wants. */
    private function placeFeature(Scene $scene, string $name, array $state = []): SceneFeature
    {
        $template = SceneFeature::whereNull('scene_id')->where('name', $name)->firstOrFail();

        return SceneFeature::create([
            'scene_id' => $scene->id,
            'zone_id' => $scene->zone_id,
            'name' => $template->name,
            'feature_type' => $template->feature_type,
            'affordances' => $template->affordances,
            'state' => $state,
            'source' => 'seed',
        ]);
    }

    /** Force the air, the way the dresser's roll would have left it. */
    private function setAmbient(Scene $scene, string $ambient): Scene
    {
        $scene->update(['state' => array_merge($scene->state ?? [], ['ambient' => $ambient])]);

        return $scene->fresh();
    }

    private function refreshCards(Turn $turn): Turn
    {
        $turn->update(['cards' => app(CardComposer::class)->compose(
            $turn->campaign->character->fresh(), $turn->scene->fresh(),
        )]);

        return $turn->fresh();
    }

    private function cardFor(Turn $turn, string $slot, string $verb): array
    {
        $card = collect($turn->cards[$slot])->firstWhere('verb', $verb);
        $this->assertNotNull($card, "no {$verb} card was offered in the {$slot} slot");

        return $card;
    }

    /** Submit one card and resolve, returning the beat the dice actually paid. */
    private function resolveCard(Turn $turn, string $slot, array $card): array
    {
        $turn->update([
            'status' => Turn::STATUS_LOCKED,
            'submission' => [$slot => ['card_id' => $card['id'], 'modifiers' => []]],
            'submitted_at' => now(),
        ]);

        app(TurnResolver::class)->resolve($turn->fresh());

        $beats = collect($turn->fresh()->resolution['beats'])
            ->firstWhere('verb', $card['verb']);
        $this->assertNotNull($beats, "the {$card['verb']} beat never resolved");

        return $beats;
    }

    /** @return list<string> */
    private function labels(array $parts): array
    {
        return array_column($parts, 'label');
    }

    public function test_a_dressed_scene_rolls_exactly_one_ambient_and_keeps_it()
    {
        $campaign = $this->createCampaign();
        app(TurnStarter::class)->openFirstTurn($campaign);

        $scene = $campaign->activeScene;
        $this->assertTrue($scene->state['dressed']);
        $this->assertContains($scene->state['ambient'], Ambient::KEYS);

        // Fixed for the scene's life: a second pass over the same ground never
        // re-rolls it, whatever dice it is handed. Weather that turns under a
        // card already priced is a card that lied.
        $first = $scene->state['ambient'];
        foreach ([7, 999, 12345] as $seed) {
            app(SceneDresser::class)->rollAmbient($scene, new Dice($seed));
        }
        $this->assertSame($first, $campaign->activeScene->state['ambient']);
    }

    public function test_the_roll_is_seeded_deterministic_and_legacy_scenes_read_as_clear()
    {
        $campaign = $this->createCampaign();
        $this->openBareTurn($campaign);

        // Same seed, same air — resolution stays auditable and retryable.
        $this->assertSame(
            Ambient::roll(new Dice(4242)),
            Ambient::roll(new Dice(4242)),
        );

        // A scene from before ambient existed (or one never dressed) carries
        // no key, and every campaign that predates this keeps playing exactly
        // the numbers it played yesterday.
        $legacy = Scene::create([
            'campaign_id' => $campaign->id,
            'zone_id' => $campaign->activeScene->zone_id,
            'title' => 'Old ground',
            'description' => 'Ground dressed before the air was ever rolled.',
            'status' => 'active',
            'state' => [],
        ]);

        $this->assertSame(Ambient::CLEAR, Ambient::of($legacy));
        $this->assertSame(Ambient::CLEAR, Ambient::of(null));
        $this->assertSame([], Odds::ambientParts(null, 'hide', 'conceal'));
    }

    /**
     * The load-bearing promise: what the card quoted is what the dice charged.
     * Gloom hides the character and blinds them — helping the cover they take
     * and costing them the reading of the ground, both on the card.
     */
    public function test_gloom_prices_cover_and_perception_on_the_card_and_on_the_die()
    {
        $campaign = $this->createCampaign();
        $turn = $this->openBareTurn($campaign);
        $scene = $campaign->activeScene;

        $this->placeFeature($scene, 'a wall of stacked crates');
        $this->placeFeature($scene, "the smuggler's door", ['hidden' => true]);
        $this->setAmbient($scene, Ambient::GLOOM);
        $turn = $this->refreshCards($turn);

        // Cover is cheaper in the dark...
        $hide = $this->cardFor($turn, 'pre', 'hide');
        $this->assertContains('Little light to be seen by', $this->labels($hide['forecast']['parts']));
        $this->assertSame(Odds::BASE - 2, $hide['forecast']['difficulty']);

        // ...and reading the ground is dearer, which is what makes it a choice
        // rather than a gift. A single-sided ambient is difficulty creep.
        $scout = $this->cardFor($turn, 'pre', 'scout');
        $this->assertContains('Too little light to read the ground by', $this->labels($scout['forecast']['parts']));
        $this->assertSame(Odds::BASE + 2, $scout['forecast']['difficulty']);

        // The quoted DC is the paid DC.
        $beat = $this->resolveCard($turn, 'pre', $hide);
        $this->assertSame($hide['forecast']['difficulty'], $beat['difficulty']);
        $this->assertContains('Little light to be seen by', $this->labels($beat['difficulty_parts']));
        $this->assertSame(-2, collect($beat['difficulty_parts'])
            ->firstWhere('label', 'Little light to be seen by')['amount']);
    }

    /** Haze covers a retreat and swallows a search — the same air, both ways. */
    public function test_haze_prices_a_break_away_and_a_search()
    {
        $campaign = $this->createCampaign();
        $turn = $this->openBareTurn($campaign);
        $scene = $campaign->activeScene;

        $this->placeFeature($scene, 'the narrow alley');
        $this->placeFeature($scene, 'the tide-cellar grate', ['hidden' => true]);
        $this->setAmbient($scene, Ambient::HAZE);
        $turn = $this->refreshCards($turn);

        $flee = $this->cardFor($turn, 'main', 'flee');
        $this->assertContains('Thick air to break away into', $this->labels($flee['forecast']['parts']));
        $this->assertSame(Odds::BASE - 1, $flee['forecast']['difficulty']);

        $scout = $this->cardFor($turn, 'pre', 'scout');
        $this->assertContains('Nothing carries far through this air', $this->labels($scout['forecast']['parts']));
        $this->assertSame(Odds::BASE + 2, $scout['forecast']['difficulty']);

        $beat = $this->resolveCard($turn, 'main', $flee);
        $this->assertSame($flee['forecast']['difficulty'], $beat['difficulty']);
        $this->assertContains('Thick air to break away into', $this->labels($beat['difficulty_parts']));
    }

    /**
     * Squall holds a trail and closes the high ground. The climb is charged
     * once, whether the card names the verb or the body it is spent through.
     */
    public function test_squall_prices_a_trail_and_the_high_ground_exactly_once()
    {
        $campaign = $this->createCampaign();
        $turn = $this->openBareTurn($campaign);
        $scene = $campaign->activeScene;

        $this->placeFeature($scene, 'the warehouse roof');
        Actor::create([
            'scene_id' => $scene->id,
            'zone_id' => $scene->zone_id,
            'name' => 'a wiry cutpurse',
            'kind' => 'enemy',
            'tier' => 'regular',
            'stats' => ['health' => ['current' => 4, 'max' => 4], 'attack' => 1],
            'tags' => [],
            'status' => 'fled',
            'source' => 'seed',
        ]);

        $this->setAmbient($scene, Ambient::SQUALL);
        $turn = $this->refreshCards($turn);

        $track = $this->cardFor($turn, 'main', 'track');
        $this->assertContains('A trail holds in ground like this', $this->labels($track['forecast']['parts']));
        $this->assertSame(Odds::BASE - 2, $track['forecast']['difficulty']);

        // The climb is `ascend` spent through `climb` — both named in the
        // table, and it must still cost two, not four.
        $ascend = $this->cardFor($turn, 'pre', 'ascend');
        $unsteady = array_filter($this->labels($ascend['forecast']['parts']),
            fn (string $l) => $l === 'Nothing off the ground is steady');
        $this->assertCount(1, $unsteady);
        $this->assertSame(Odds::BASE + 2, $ascend['forecast']['difficulty']);

        $beat = $this->resolveCard($turn, 'main', $track);
        $this->assertSame($track['forecast']['difficulty'], $beat['difficulty']);
        $this->assertContains('A trail holds in ground like this', $this->labels($beat['difficulty_parts']));
    }

    /** Clear is the baseline: no parts, no line, nothing anywhere. */
    public function test_clear_air_says_nothing_and_costs_nothing()
    {
        $campaign = $this->createCampaign();
        $turn = $this->openBareTurn($campaign);
        $scene = $campaign->activeScene;

        $this->placeFeature($scene, 'a wall of stacked crates');
        $this->placeFeature($scene, 'the warehouse roof');
        $scene = $this->setAmbient($scene, Ambient::CLEAR);
        $turn = $this->refreshCards($turn);

        foreach ([['pre', 'hide'], ['pre', 'ascend'], ['pre', 'scout']] as [$slot, $verb]) {
            $card = $this->cardFor($turn, $slot, $verb);
            $this->assertSame(['Base difficulty'], $this->labels($card['forecast']['parts']));
            $this->assertSame(Odds::BASE, $card['forecast']['difficulty']);
        }

        $this->assertNull(Ambient::line(Ambient::CLEAR));
        $this->assertNull(Ambient::fact(Ambient::CLEAR));
        $this->assertSame([], Odds::ambientParts(Ambient::CLEAR, 'hide', 'conceal'));

        // Empty-group-absent: clear air is not a bullet saying nothing.
        $board = SituationBoard::for($campaign->character->fresh(), $scene, null);
        $this->assertNotContains('sky', array_column($board, 'key'));

        $beat = $this->resolveCard($turn, 'pre', $this->cardFor($turn, 'pre', 'hide'));
        $this->assertSame(['Base difficulty'], $this->labels($beat['difficulty_parts']));
    }

    /** The board names the air in words that fit any land, and never a weather word. */
    public function test_the_board_carries_one_abstract_line_for_the_air()
    {
        $campaign = $this->createCampaign();
        $this->openBareTurn($campaign);
        $character = $campaign->character->fresh();

        foreach ([Ambient::GLOOM, Ambient::HAZE, Ambient::SQUALL] as $key) {
            $scene = $this->setAmbient($campaign->activeScene, $key);
            $board = SituationBoard::for($character, $scene, null);
            $sky = collect($board)->firstWhere('key', 'sky');

            $this->assertNotNull($sky, "no sky group under {$key}");
            // One group carries the air and the light both; in plain day the
            // light adds no item, so the air still stands alone here.
            $this->assertSame('The air and the light', $sky['title']);
            $this->assertCount(1, $sky['items']);
            $this->assertSame(Ambient::line($key), $sky['items'][0]);

            // The engine never says rain. The land decides what this looks
            // like, and the land is not decided here.
            foreach (['rain', 'fog', 'wind', 'snow', 'storm', 'night', 'sun', 'dust'] as $weather) {
                $this->assertStringNotContainsStringIgnoringCase($weather, $sky['items'][0]);
            }

            // The narrator reads the same board as prose, as a plain sentence.
            $this->assertStringContainsString(Ambient::line($key), SituationBoard::prose($board));
        }
    }

    /** The narrator is handed the key as a fact, to be rendered once in this land's own idiom. */
    public function test_the_narration_prompt_carries_the_air_once_and_only_when_there_is_air()
    {
        $campaign = $this->createCampaign();
        $turn = $this->openBareTurn($campaign);
        $this->setAmbient($campaign->activeScene, Ambient::GLOOM);

        $turn->update([
            'status' => Turn::STATUS_COMPLETE,
            'branch_trigger' => 'decision_point',
            'resolution' => [
                'beats' => [[
                    'slot' => 'main', 'verb' => 'examine', 'target' => null,
                    'degree' => 'success', 'roll' => 14, 'total' => 14, 'difficulty' => 10,
                    'facts' => ['The crates held nothing.'], 'skipped' => false, 'crit' => null,
                ]],
                'scene_reaction' => [], 'reaction_rolls' => [], 'new_threat' => null, 'downtime' => null,
            ],
        ]);

        $prompt = (new \ReflectionMethod(Narrator::class, 'buildPrompt'))
            ->invoke(app(Narrator::class), $turn->fresh());

        $this->assertStringContainsString('The air this scene stands in', $prompt);
        $this->assertStringContainsString(Ambient::fact(Ambient::GLOOM), $prompt);
        $this->assertStringContainsString('Render it exactly ONCE', $prompt);
        // No mechanics reach narration, ever — not even the key itself.
        $this->assertStringNotContainsString('gloom', $prompt);

        // Clear air carries no instructions about air at all.
        $this->setAmbient($campaign->activeScene, Ambient::CLEAR);
        $plain = (new \ReflectionMethod(Narrator::class, 'buildPrompt'))
            ->invoke(app(Narrator::class), $turn->fresh());
        $this->assertStringNotContainsString('The air this scene stands in', $plain);
    }

    /**
     * Ambient moves the odds of finding things; it never finds them. A hidden
     * feature and a lurking ambusher are exactly as hidden under every sky.
     */
    public function test_the_air_never_reveals_or_conceals_anything_by_itself()
    {
        $campaign = $this->createCampaign();
        $turn = $this->openBareTurn($campaign);
        $scene = $campaign->activeScene;

        $this->placeFeature($scene, 'a wall of stacked crates');
        $hidden = $this->placeFeature($scene, "the smuggler's door", ['hidden' => true]);
        $lurker = Actor::create([
            'scene_id' => $scene->id,
            'zone_id' => $scene->zone_id,
            'name' => 'a smuggler\'s lookout',
            'kind' => 'enemy',
            'tier' => 'regular',
            'stats' => ['health' => ['current' => 4, 'max' => 4], 'attack' => 1],
            'tags' => ['lurking' => true, 'lurking_since' => 1],
            'status' => 'active',
            'source' => 'seed',
        ]);

        $seen = [];
        foreach (Ambient::KEYS as $key) {
            $scene = $this->setAmbient($scene, $key);
            $character = $campaign->character->fresh();

            $this->assertTrue($hidden->fresh()->state['hidden']);
            $this->assertTrue($lurker->fresh()->tags['lurking']);
            $this->assertNotContains($hidden->name, $scene->visibleFeatures()->pluck('name')->all());
            $this->assertNotContains($lurker->name, $scene->visibleActors()->pluck('name')->all());

            $prose = SituationBoard::prose(SituationBoard::for($character, $scene, null));
            $this->assertStringNotContainsString($hidden->name, $prose);
            $this->assertStringNotContainsString($lurker->name, $prose);

            $cards = app(CardComposer::class)->compose($character, $scene);
            $seen[$key] = collect($cards)->only(['pre', 'main', 'post'])
                ->flatMap(fn ($slot) => array_column($slot, 'label'))->sort()->values()->all();
        }

        // Same offers under every sky: only the prices moved.
        foreach (Ambient::KEYS as $key) {
            $this->assertSame($seen[Ambient::CLEAR], $seen[$key]);
        }
    }

    /** The world is mostly ordinary, and squall is the rarest thing in it. */
    public function test_the_distribution_honors_the_clear_weighted_config()
    {
        $counts = array_fill_keys(Ambient::KEYS, 0);

        for ($seed = 1; $seed <= 1200; $seed++) {
            $key = Ambient::roll(new Dice($seed));
            $this->assertContains($key, Ambient::KEYS);
            $counts[$key]++;
        }

        // Seasoning, not the meal: about half of all ground is ordinary.
        $this->assertGreaterThan(0.38, $counts[Ambient::CLEAR] / 1200);
        $this->assertLessThan(0.62, $counts[Ambient::CLEAR] / 1200);

        $this->assertGreaterThan($counts[Ambient::GLOOM], $counts[Ambient::CLEAR]);
        $this->assertGreaterThan($counts[Ambient::HAZE], $counts[Ambient::GLOOM]);
        $this->assertGreaterThan($counts[Ambient::SQUALL], $counts[Ambient::HAZE]);

        // A world tuned to nothing but fair weather is still a legal world.
        config(['game.ambient.weights' => ['clear' => 100]]);
        $this->assertSame(Ambient::CLEAR, Ambient::roll(new Dice(3)));
    }
}
