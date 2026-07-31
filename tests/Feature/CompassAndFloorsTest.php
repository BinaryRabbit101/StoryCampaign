<?php

namespace Tests\Feature;

use App\Game\Engine\Ambient;
use App\Game\Engine\Attempts;
use App\Game\Engine\BeatOutcome;
use App\Game\Engine\CardComposer;
use App\Game\Engine\Compass;
use App\Game\Engine\Dice;
use App\Game\Engine\Fortune;
use App\Game\Engine\Odds;
use App\Game\Engine\Standings;
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
use App\Services\TurnStarter;
use Database\Seeders\WorldSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The 2026-07-29 batch, from one playtest report:
 *
 *  - Untrained floors: hide, break, and a light lift are offered without the
 *    bought capability — degraded and bonusless, never better than training.
 *  - Anyone in reach can be fought: striking a bystander or a beast flips
 *    them to an enemy, and a struck PERSON costs standing.
 *  - The fortune die: quiet beats cast a d20 whose extremes are a lucky find
 *    or an unfortunate wrinkle, without touching the beat's own certainty.
 *  - The compass: dressed ground has ways out with headings, crossing one
 *    walks it onto the map, and the board says where you stand.
 */
class CompassAndFloorsTest extends TestCase
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
            'name' => 'The Unlettered',
            'world_flavor' => 'harbor-city',
            'status' => 'active',
            'started_at' => now(),
        ]);

        // Deliberately giftless: the whole point of the floors is what a
        // body with no bought capabilities can still do.
        Character::create([
            'campaign_id' => $campaign->id,
            'name' => 'The Stray',
            'description' => 'Nobody in particular, which is the test.',
            'meters' => Meters::default(),
            'status' => 'alive',
            'meters_regenerated_at' => now(),
        ]);

        return $campaign->fresh();
    }

    /** A turn on ground the test controls completely. */
    private function openBareTurn(Campaign $campaign): Turn
    {
        $turn = app(TurnStarter::class)->openFirstTurn($campaign);

        $scene = $campaign->activeScene;
        $scene->actors()->delete();
        $scene->features()->delete();
        Actor::whereNull('scene_id')->delete();
        $scene->update(['state' => array_merge($scene->state ?? [], ['ambient' => Ambient::CLEAR])]);

        return $turn->fresh();
    }

    private function refreshCards(Turn $turn): Turn
    {
        $turn->update(['cards' => app(CardComposer::class)->compose(
            $turn->campaign->character->fresh(), $turn->scene->fresh(),
        )]);

        return $turn->fresh();
    }

    private function cardWhere(Turn $turn, string $slot, callable $test): ?array
    {
        return collect($turn->cards[$slot])->first($test);
    }

    private function resolveCard(Turn $turn, string $slot, array $card): array
    {
        $turn->update([
            'status' => Turn::STATUS_LOCKED,
            'submission' => [$slot => ['card_id' => $card['id'], 'modifiers' => [], 'note' => null]],
            'submitted_at' => now(),
        ]);

        app(TurnResolver::class)->resolve($turn->fresh());

        $beat = collect($turn->fresh()->resolution['beats'])->firstWhere('verb', $card['verb']);
        $this->assertNotNull($beat, "the {$card['verb']} beat never resolved");

        return $beat;
    }

    /** A seeded stream whose FIRST d20 face satisfies the predicate. */
    private function diceWithFirstFace(callable $test): Dice
    {
        for ($seed = 1; $seed < 2000; $seed++) {
            if ($test((new Dice($seed))->d20())) {
                return new Dice($seed);
            }
        }

        $this->fail('no seed produced the wanted face — the d20 is broken');
    }

    // ------------------------------------------------------------------
    // Untrained floors
    // ------------------------------------------------------------------

    public function test_hide_break_and_a_light_lift_are_offered_without_the_bought_gift()
    {
        $campaign = $this->createCampaign();
        $turn = $this->openBareTurn($campaign);
        $scene = $campaign->activeScene;

        SceneFeature::create([
            'scene_id' => $scene->id, 'zone_id' => $scene->zone_id,
            'name' => 'a bank of steaming vents', 'feature_type' => 'cover',
            'affordances' => ['hideable' => true, 'max_size' => 'large'],
            'state' => [], 'source' => 'seed',
        ]);
        SceneFeature::create([
            'scene_id' => $scene->id, 'zone_id' => $scene->zone_id,
            'name' => 'a shuttered grille', 'feature_type' => 'obstacle',
            'affordances' => ['breakable' => true],
            'state' => [], 'source' => 'seed',
        ]);
        SceneFeature::create([
            'scene_id' => $scene->id, 'zone_id' => $scene->zone_id,
            'name' => 'a dented signal lamp', 'feature_type' => 'loose',
            'affordances' => ['lift_weight' => 30],
            'state' => [], 'source' => 'seed',
        ]);

        $turn = $this->refreshCards($turn);

        $hide = $this->cardWhere($turn, 'pre', fn ($c) => $c['verb'] === 'hide');
        $this->assertNotNull($hide, 'no untrained hide was offered on visible cover');
        $this->assertNull($hide['capability'], 'the untrained hide must not claim a capability');
        $this->assertSame('degraded', $hide['risk'], 'untrained cover has to cost the harder DC');

        $break = $this->cardWhere($turn, 'main', fn ($c) => $c['verb'] === 'break');
        $this->assertNotNull($break, 'no untrained break was offered on a breakable');
        $this->assertNull($break['capability']);
        $this->assertSame('degraded', $break['risk']);

        $lift = $this->cardWhere($turn, 'main', fn ($c) => $c['verb'] === 'lift');
        $this->assertNotNull($lift, 'no bare-hands lift was offered on a light thing');
        $this->assertNull($lift['capability']);
        $this->assertSame('degraded', $lift['risk']);
    }

    public function test_a_heavy_thing_still_needs_the_bought_strength()
    {
        $campaign = $this->createCampaign();
        $turn = $this->openBareTurn($campaign);
        $scene = $campaign->activeScene;

        SceneFeature::create([
            'scene_id' => $scene->id, 'zone_id' => $scene->zone_id,
            'name' => 'a harbor chain', 'feature_type' => 'obstacle',
            'affordances' => ['lift_weight' => 120],
            'state' => [], 'source' => 'seed',
        ]);

        $turn = $this->refreshCards($turn);

        $this->assertNull(
            $this->cardWhere($turn, 'main', fn ($c) => $c['verb'] === 'lift'),
            'a 120-weight lift must stay behind the bought capability',
        );
    }

    public function test_trained_hide_still_outclasses_the_floor()
    {
        $campaign = $this->createCampaign();
        $campaign->character->capabilities()->create(['capability' => 'conceal', 'source' => 'creation']);
        $turn = $this->openBareTurn($campaign);
        $scene = $campaign->activeScene;

        SceneFeature::create([
            'scene_id' => $scene->id, 'zone_id' => $scene->zone_id,
            'name' => 'a stand of reeds', 'feature_type' => 'cover',
            'affordances' => ['hideable' => true, 'max_size' => 'large'],
            'state' => [], 'source' => 'seed',
        ]);

        $turn = $this->refreshCards($turn);
        $hide = $this->cardWhere($turn, 'pre', fn ($c) => $c['verb'] === 'hide');

        $this->assertNotNull($hide);
        $this->assertSame('conceal', $hide['capability']);
        $this->assertSame('safe', $hide['risk']);
    }

    // ------------------------------------------------------------------
    // Fighting anyone in reach
    // ------------------------------------------------------------------

    public function test_turning_on_a_bystander_makes_an_enemy_and_the_town_remembers()
    {
        $campaign = $this->createCampaign();
        $turn = $this->openBareTurn($campaign);
        $scene = $campaign->activeScene;

        $bystander = Actor::create([
            'scene_id' => $scene->id, 'zone_id' => $scene->zone_id,
            'name' => 'a quay-side lampwright', 'kind' => 'npc', 'tier' => 'regular',
            'stats' => ['health' => ['current' => 4, 'max' => 4], 'attack' => 1],
            'tags' => ['talkable' => true], 'status' => 'active', 'source' => 'seed',
        ]);

        $turn = $this->refreshCards($turn);

        $strike = $this->cardWhere($turn, 'main',
            fn ($c) => $c['verb'] === 'strike' && ($c['target']['id'] ?? null) === $bystander->id);
        $this->assertNotNull($strike, 'a bystander in reach must be strikeable');
        $this->assertStringContainsString('Turn on', $strike['label']);

        $this->resolveCard($turn, 'main', $strike);

        $bystander->refresh();
        $this->assertSame('enemy', $bystander->kind, 'the first swing writes the quarrel');
        $this->assertSame('npc', $bystander->tags['was'] ?? null);

        // The town saw the swing: one point down, through the closed table.
        $this->assertSame(-1, Standings::of($campaign->activeScene->fresh()));
    }

    public function test_a_beast_can_be_fought_and_the_town_does_not_care()
    {
        $campaign = $this->createCampaign();
        $turn = $this->openBareTurn($campaign);
        $scene = $campaign->activeScene;

        $beast = Actor::create([
            'scene_id' => $scene->id, 'zone_id' => $scene->zone_id,
            'name' => 'a mangy dock-dog', 'kind' => 'creature', 'tier' => 'regular',
            'stats' => ['health' => ['current' => 6, 'max' => 6], 'attack' => 2],
            'tags' => [], 'status' => 'active', 'source' => 'seed',
        ]);

        $turn = $this->refreshCards($turn);

        $strike = $this->cardWhere($turn, 'main',
            fn ($c) => $c['verb'] === 'strike' && ($c['target']['id'] ?? null) === $beast->id);
        $this->assertNotNull($strike, 'a creature in reach must be strikeable');

        $this->resolveCard($turn, 'main', $strike);

        $this->assertSame('enemy', $beast->fresh()->kind);
        $this->assertSame(0, Standings::of($campaign->activeScene->fresh()),
            'the wild is between the player and the wild — no standing moves');
    }

    // ------------------------------------------------------------------
    // The fortune die
    // ------------------------------------------------------------------

    public function test_quiet_beats_cast_the_fortune_die_and_stay_certain()
    {
        $campaign = $this->createCampaign();
        $turn = $this->openBareTurn($campaign);
        $turn = $this->refreshCards($turn);

        $wait = $this->cardWhere($turn, 'main', fn ($c) => $c['verb'] === 'wait');
        $this->assertNotNull($wait);

        $beat = $this->resolveCard($turn, 'main', $wait);

        $this->assertSame('success', $beat['degree'], 'a quiet beat can never fail');
        $this->assertSame(0, $beat['roll'], 'the fortune die must never pose as the d20');
        $this->assertNotNull($beat['fortune'] ?? null, 'the quiet beat cast no fortune die');
        $this->assertGreaterThanOrEqual(1, $beat['fortune']['roll']);
        $this->assertLessThanOrEqual(20, $beat['fortune']['roll']);
        $this->assertContains($beat['fortune']['kind'], ['lucky', 'plain', 'unlucky']);
    }

    public function test_declarations_stay_outside_fortune()
    {
        $this->assertFalse(Fortune::eligible('undertake'));
        $this->assertFalse(Fortune::eligible('face'));
        $this->assertFalse(Fortune::eligible('companion_welcome'));
        $this->assertFalse(Fortune::eligible('bargain'));
        $this->assertTrue(Fortune::eligible('wait'));
        $this->assertTrue(Fortune::eligible('examine'));
    }

    public function test_a_lucky_face_pays_through_existing_machinery()
    {
        $campaign = $this->createCampaign();
        $this->openBareTurn($campaign);
        $scene = $campaign->activeScene;

        SceneFeature::create([
            'scene_id' => $scene->id, 'zone_id' => $scene->zone_id,
            'name' => 'a sealed crew locker', 'feature_type' => 'landmark',
            'affordances' => ['hidden' => true],
            'state' => ['hidden' => true], 'source' => 'seed',
        ]);

        $dice = $this->diceWithFirstFace(fn (int $f) => $f >= 18);
        $record = Fortune::roll($dice, 'wait', $campaign->character, $scene->fresh());

        $this->assertSame('lucky', $record['kind']);
        $this->assertNotNull($record['fact']);
        $this->assertFalse(
            (bool) ($scene->features()->first()->fresh()->state['hidden'] ?? false),
            'the lucky find should have surfaced the hidden feature',
        );
    }

    public function test_an_unlucky_face_costs_only_where_cost_exists()
    {
        $campaign = $this->createCampaign();
        $this->openBareTurn($campaign);
        $scene = $campaign->activeScene->fresh();

        // Nothing to pay with, nobody listening: the bad face passes silent.
        $dice = $this->diceWithFirstFace(fn (int $f) => $f <= 2);
        $record = Fortune::roll($dice, 'wait', $campaign->character, $scene);

        $this->assertSame('unlucky', $record['kind']);
        $this->assertNull($record['fact'], 'an unlucky face with nothing to cost must pass in silence');

        // With a fight on, the noise goes to the scene's own alarm counter.
        Actor::create([
            'scene_id' => $scene->id, 'zone_id' => $scene->zone_id,
            'name' => 'a wharf tough', 'kind' => 'enemy', 'tier' => 'regular',
            'stats' => ['health' => ['current' => 5, 'max' => 5], 'attack' => 1],
            'tags' => [], 'status' => 'active', 'source' => 'seed',
        ]);

        $dice = $this->diceWithFirstFace(fn (int $f) => $f <= 2);
        $record = Fortune::roll($dice, 'wait', $campaign->character, $scene->fresh());

        $this->assertNotNull($record['fact']);
        $this->assertSame(1, (int) ($scene->fresh()->state['alarm'] ?? 0));
    }

    // ------------------------------------------------------------------
    // The compass
    // ------------------------------------------------------------------

    public function test_dressed_ground_has_ways_out_with_headings()
    {
        $campaign = $this->createCampaign();
        $turn = $this->openBareTurn($campaign);
        $scene = $campaign->activeScene;

        $exits = $scene->exits()->get();
        $this->assertGreaterThanOrEqual(1, $exits->count(), 'every dressed scene needs at least one way out');
        $this->assertLessThanOrEqual(3, $exits->count());

        foreach ($exits as $exit) {
            $this->assertContains($exit->direction, Compass::DIRECTIONS);
            $this->assertNotSame('', $exit->label);
            $this->assertNull($exit->to_scene_id, 'a fresh way has not been walked');
        }
        $this->assertSame(
            $exits->pluck('direction')->unique()->count(), $exits->count(),
            'no two ways out share a heading',
        );

        // Each unwalked way is a card, and the board says where you stand.
        $turn = $this->refreshCards($turn);
        foreach ($exits as $exit) {
            $this->assertNotNull(
                $this->cardWhere($turn, 'main', fn ($c) => ($c['target']['type'] ?? null) === 'exit'
                    && ($c['target']['id'] ?? null) === $exit->id),
                "the {$exit->direction} way was not offered as a card",
            );
        }

        $board = collect($turn->fresh()->situation_board ?? []);
        $place = $board->firstWhere('key', 'place');
        $this->assertNotNull($place, 'the board must say where you stand');
        $this->assertStringContainsString($scene->title, $place['items'][0]);
    }

    public function test_crossing_a_named_way_walks_it_onto_the_map()
    {
        $campaign = $this->createCampaign();
        $turn = $this->openBareTurn($campaign);
        $scene = $campaign->activeScene;

        $way = $scene->exits()->firstOrFail();
        $turn = $this->refreshCards($turn);
        $cross = $this->cardWhere($turn, 'main',
            fn ($c) => ($c['target']['type'] ?? null) === 'exit' && ($c['target']['id'] ?? null) === $way->id);

        $beat = $this->resolveCard($turn, 'main', $cross);

        if ($beat['degree'] === 'failure') {
            // The die said no: the way stands unwalked and the refusal is on
            // the board through Attempts. Still the compass holding.
            $this->assertNull($way->fresh()->to_scene_id);

            return;
        }

        $next = $campaign->fresh()->activeScene;
        $this->assertNotSame($scene->id, $next->id, 'the tale should have moved');
        $this->assertSame($scene->id, $next->from_scene_id);
        $this->assertSame($way->direction, $next->from_direction);
        $this->assertSame($next->id, $way->fresh()->to_scene_id, 'the walked way must point at where it led');
        $this->assertSame($way->locale['title'], $next->title, 'the way leads where it said it led');

        [$dx, $dy] = Compass::offset($way->direction);
        $this->assertSame($scene->grid_x + $dx, $next->grid_x);
        $this->assertSame($scene->grid_y + $dy, $next->grid_y);

        // And the new ground has its own ways on, none pointing straight back.
        $this->assertGreaterThanOrEqual(1, $next->exits()->count());
        $this->assertNotContains(
            Compass::opposite($way->direction),
            $next->exits()->pluck('direction')->all(),
            'departure is irreversible — no way may point back where you came from',
        );
    }

    // ------------------------------------------------------------------
    // The open door (2026-07-30): an uncontested way out is a step, not a test
    // ------------------------------------------------------------------

    public function test_an_uncontested_way_out_is_certain_and_simply_happens()
    {
        $campaign = $this->createCampaign();
        $turn = $this->openBareTurn($campaign);
        $scene = $campaign->activeScene;

        $way = $scene->exits()->firstOrFail();
        $turn = $this->refreshCards($turn);
        $card = $this->cardWhere($turn, 'main',
            fn ($c) => ($c['target']['type'] ?? null) === 'exit' && ($c['target']['id'] ?? null) === $way->id);

        $this->assertNotNull($card);
        $this->assertFalse($card['forecast']['rolls'], 'an empty room does not test a doorway');
        $this->assertSame('Certain', $card['forecast']['band']);
        $this->assertSame(0, $card['forecast']['difficulty']);

        $beat = $this->resolveCard($turn, 'main', $card);

        $this->assertSame('success', $beat['degree'], 'a certain step cannot fail');
        $this->assertSame(0, $beat['roll'], 'a certain step casts no d20');
        $this->assertNotSame($scene->id, $campaign->fresh()->activeScene->id, 'and it still moves the tale');
        $this->assertSame($campaign->fresh()->activeScene->id, $way->fresh()->to_scene_id);
    }

    public function test_a_contested_way_out_rolls_exactly_as_before()
    {
        $campaign = $this->createCampaign();
        $turn = $this->openBareTurn($campaign);
        $scene = $campaign->activeScene;

        Actor::create([
            'scene_id' => $scene->id, 'zone_id' => $scene->zone_id,
            'name' => 'a dock-gang bruiser', 'kind' => 'enemy', 'tier' => 'regular',
            'stats' => ['health' => ['current' => 5, 'max' => 5], 'attack' => 2],
            'tags' => [], 'status' => 'active', 'source' => 'seed',
        ]);

        $way = $scene->exits()->firstOrFail();
        $turn = $this->refreshCards($turn->fresh());
        $card = $this->cardWhere($turn, 'main',
            fn ($c) => ($c['target']['type'] ?? null) === 'exit' && ($c['target']['id'] ?? null) === $way->id);

        $this->assertNotNull($card);
        $this->assertTrue($card['forecast']['rolls'], 'somebody in the open contests the door');
        $this->assertGreaterThan(0, $card['forecast']['difficulty']);
    }

    public function test_violent_air_contests_the_door_and_plain_dark_does_not()
    {
        $campaign = $this->createCampaign();
        $turn = $this->openBareTurn($campaign);
        $scene = $campaign->activeScene;

        $scene->update(['state' => array_merge($scene->state ?? [], ['ambient' => Ambient::GLOOM])]);
        $this->assertFalse(Odds::contestedGround($scene->fresh()), 'the dark is dark, not dangerous');

        $scene->update(['state' => array_merge($scene->state ?? [], ['ambient' => Ambient::SQUALL])]);
        $this->assertTrue(Odds::contestedGround($scene->fresh()));

        $way = $scene->exits()->firstOrFail();
        $turn = $this->refreshCards($turn->fresh());
        $card = $this->cardWhere($turn, 'main',
            fn ($c) => ($c['target']['type'] ?? null) === 'exit' && ($c['target']['id'] ?? null) === $way->id);

        $this->assertTrue($card['forecast']['rolls'], 'violent air makes the step a question again');
    }

    public function test_a_doorway_is_never_a_settled_question()
    {
        $this->assertNotContains('cross', Attempts::CLOSING,
            'a failed crossing must never seal that way for the rest of the scene');
    }

    /**
     * One bad look at the ground is a bad look. Only the second says the
     * reading is finished — and it is still keyed to this ground alone.
     */
    public function test_scout_takes_two_misses_to_close()
    {
        $campaign = $this->createCampaign();
        $this->openBareTurn($campaign);
        $scene = $campaign->activeScene->fresh();

        $missed = new BeatOutcome('main', 'scout', null, BeatOutcome::FAILURE, 3, 3, 14);

        Attempts::record($scene, $missed);
        $scene = $scene->fresh();
        $this->assertSame([], Attempts::spent($scene), 'one miss closes nothing');
        $this->assertNotSame([], Attempts::missed($scene), 'but it is remembered');

        Attempts::record($scene, $missed);
        $scene = $scene->fresh();
        $this->assertSame(['scout:scene:'.$scene->id], Attempts::spent($scene),
            'the second miss is the ground refusing to be read');
    }
}
