<?php

namespace Tests\Feature;

use App\Game\Engine\Ambient;
use App\Game\Engine\CardComposer;
use App\Game\Meters;
use App\Models\Actor;
use App\Models\Campaign;
use App\Models\Character;
use App\Models\SceneFeature;
use App\Models\Turn;
use App\Models\User;
use App\Services\Claude\ClaudeCli;
use App\Services\TurnStarter;
use Database\Seeders\WorldSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Every gameplay verb is available; the sheet moves the ODDS, not the menu.
 *
 * The 2026-07-29 batch floored the physical verbs (hide, break, a light
 * lift). This extends the same principle to the rest of the vocabulary: the
 * social verbs, the awareness verbs, and the body-plausible traversals all
 * have untrained floors — degraded, bonusless, never better than the bought
 * gift, and never offered as a strictly worse twin beside one. What stays
 * bought is the POWERS: swing, glide, burrow, the metered tempo verbs, and
 * every composed card, because nerve does not grow wings.
 */
class UntrainedVerbFloorsTest extends TestCase
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
            'name' => 'The Unpracticed',
            'world_flavor' => 'harbor-city',
            'status' => 'active',
            'started_at' => now(),
        ]);

        // Deliberately giftless: the whole point of the floors is what a
        // body with no bought capabilities can still do.
        Character::create([
            'campaign_id' => $campaign->id,
            'name' => 'The Second Stray',
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

    private function cardsWhere(Turn $turn, string $slot, callable $test): array
    {
        return collect($turn->cards[$slot])->filter($test)->values()->all();
    }

    private function makeEnemy(Campaign $campaign, array $tags = [], string $tier = 'regular', string $status = 'active'): Actor
    {
        $scene = $campaign->activeScene;

        return Actor::create([
            'scene_id' => $scene->id, 'zone_id' => $scene->zone_id,
            'name' => 'a wharf tough', 'kind' => 'enemy', 'tier' => $tier,
            'stats' => ['health' => ['current' => 5, 'max' => 5], 'attack' => 1],
            'tags' => $tags, 'status' => $status, 'source' => 'seed',
        ]);
    }

    // ------------------------------------------------------------------
    // The social floors
    // ------------------------------------------------------------------

    public function test_a_hostile_can_be_talked_down_without_a_bought_tongue()
    {
        $campaign = $this->createCampaign();
        $turn = $this->openBareTurn($campaign);
        $enemy = $this->makeEnemy($campaign, ['talkable' => true]);

        $turn = $this->refreshCards($turn);

        $parley = $this->cardWhere($turn, 'main',
            fn ($c) => $c['verb'] === 'calm' && ($c['target']['id'] ?? null) === $enemy->id);
        $this->assertNotNull($parley, 'a talkable hostile must offer the untrained parley');
        $this->assertNull($parley['capability'], 'the parley claims no craft');
        $this->assertSame('degraded', $parley['risk'], 'untrained words cost the harder DC');
        $this->assertStringContainsString('Talk', $parley['label']);
    }

    public function test_a_trained_tongue_replaces_the_parley_and_outclasses_it()
    {
        $campaign = $this->createCampaign();
        $campaign->character->capabilities()->create(['capability' => 'calm', 'source' => 'creation']);
        $turn = $this->openBareTurn($campaign);
        $enemy = $this->makeEnemy($campaign, ['talkable' => true]);

        $turn = $this->refreshCards($turn);

        $calms = $this->cardsWhere($turn, 'main',
            fn ($c) => $c['verb'] === 'calm' && ($c['target']['id'] ?? null) === $enemy->id);
        $this->assertCount(1, $calms, 'the floor must never stand beside the bought gift');
        $this->assertSame('calm', $calms[0]['capability']);
        // Mid-fight the bought tongue is a gamble too — but a cheaper one than
        // bare words, which is what "outclasses" has to keep meaning.
        $this->assertSame('risky', $calms[0]['risk']);
    }

    public function test_a_truce_is_a_conversation_already_and_gets_no_parley()
    {
        $campaign = $this->createCampaign();
        $turn = $this->openBareTurn($campaign);
        $enemy = $this->makeEnemy($campaign, [
            'talkable' => true, 'truce' => true, 'deal' => 'leave_zone',
        ]);

        $turn = $this->refreshCards($turn);

        $this->assertNull(
            $this->cardWhere($turn, 'main',
                fn ($c) => $c['verb'] === 'calm' && ($c['target']['id'] ?? null) === $enemy->id),
            'the terms on the table are the conversation — no parley beside a truce',
        );
    }

    public function test_untrained_presence_reaches_the_regular_tier_and_no_further()
    {
        $campaign = $this->createCampaign();
        $turn = $this->openBareTurn($campaign);
        $regular = $this->makeEnemy($campaign, ['intimidatable' => true]);

        $turn = $this->refreshCards($turn);

        $loom = $this->cardWhere($turn, 'main',
            fn ($c) => $c['verb'] === 'intimidate' && ($c['target']['id'] ?? null) === $regular->id);
        $this->assertNotNull($loom, 'bare nerve should reach a regular enemy');
        $this->assertNull($loom['capability']);
        $this->assertSame('degraded', $loom['risk']);

        $regular->delete();
        $elite = $this->makeEnemy($campaign, ['intimidatable' => true], tier: 'elite');
        $turn = $this->refreshCards($turn);

        $this->assertNull(
            $this->cardWhere($turn, 'main',
                fn ($c) => $c['verb'] === 'intimidate' && ($c['target']['id'] ?? null) === $elite->id),
            'an elite does not blink at bare nerve — that ground stays bought',
        );
    }

    public function test_an_untrained_grapple_is_offered_and_costs_more_than_the_trained_hold()
    {
        $campaign = $this->createCampaign();
        $turn = $this->openBareTurn($campaign);
        $enemy = $this->makeEnemy($campaign);

        $turn = $this->refreshCards($turn);

        $grapple = $this->cardWhere($turn, 'main',
            fn ($c) => $c['verb'] === 'restrain' && ($c['target']['id'] ?? null) === $enemy->id);
        $this->assertNotNull($grapple, 'anyone can try to wrap a body up');
        $this->assertNull($grapple['capability']);
        $this->assertSame('degraded', $grapple['risk'], 'the untrained hold prices above the risky trained one');
    }

    // ------------------------------------------------------------------
    // The awareness floors
    // ------------------------------------------------------------------

    public function test_the_awareness_verbs_floor_without_the_bought_gift()
    {
        $campaign = $this->createCampaign();
        $turn = $this->openBareTurn($campaign);
        $scene = $campaign->activeScene;

        $this->makeEnemy($campaign, ['lurking' => true]);
        Actor::create([
            'scene_id' => $scene->id, 'zone_id' => $scene->zone_id,
            'name' => 'a spent cutpurse', 'kind' => 'enemy', 'tier' => 'regular',
            'stats' => ['health' => ['current' => 2, 'max' => 5], 'attack' => 1],
            'tags' => [], 'status' => 'fled', 'source' => 'seed',
        ]);
        Actor::create([
            'scene_id' => $scene->id, 'zone_id' => $scene->zone_id,
            'name' => 'a quiet ferryman', 'kind' => 'companion', 'tier' => 'regular',
            'stats' => ['health' => ['current' => 5, 'max' => 5], 'attack' => 1],
            'tags' => [], 'status' => 'active', 'source' => 'seed',
        ]);

        $turn = $this->refreshCards($turn);

        foreach ([['pre', 'scout'], ['pre', 'detect'], ['main', 'track'], ['pre', 'command']] as [$slot, $verb]) {
            $card = $this->cardWhere($turn, $slot, fn ($c) => $c['verb'] === $verb);
            $this->assertNotNull($card, "no untrained {$verb} was offered");
            $this->assertNull($card['capability'], "the untrained {$verb} must not claim a capability");
            $this->assertSame('degraded', $card['risk'], "the untrained {$verb} has to cost the harder DC");
        }
    }

    public function test_trained_eyes_still_outclass_the_floor()
    {
        $campaign = $this->createCampaign();
        $campaign->character->capabilities()->create(['capability' => 'scout', 'source' => 'creation']);
        $turn = $this->openBareTurn($campaign);

        $turn = $this->refreshCards($turn);

        $scout = $this->cardWhere($turn, 'pre', fn ($c) => $c['verb'] === 'scout');
        $this->assertNotNull($scout);
        $this->assertSame('scout', $scout['capability']);
        $this->assertSame('safe', $scout['risk']);
    }

    // ------------------------------------------------------------------
    // The traversal floors
    // ------------------------------------------------------------------

    public function test_a_low_way_up_can_be_scrambled_and_a_tall_one_cannot()
    {
        $campaign = $this->createCampaign();
        $turn = $this->openBareTurn($campaign);
        $scene = $campaign->activeScene;

        SceneFeature::create([
            'scene_id' => $scene->id, 'zone_id' => $scene->zone_id,
            'name' => 'a stack of crab pots', 'feature_type' => 'vantage',
            'affordances' => ['reachable_via' => ['climb'], 'height' => 8],
            'state' => [], 'source' => 'seed',
        ]);
        SceneFeature::create([
            'scene_id' => $scene->id, 'zone_id' => $scene->zone_id,
            'name' => 'a harbor crane', 'feature_type' => 'vantage',
            'affordances' => ['reachable_via' => ['climb'], 'height' => 30],
            'state' => [], 'source' => 'seed',
        ]);

        $turn = $this->refreshCards($turn);

        $scramble = $this->cardWhere($turn, 'pre',
            fn ($c) => $c['verb'] === 'ascend' && ($c['target']['name'] ?? null) === 'a stack of crab pots');
        $this->assertNotNull($scramble, 'a low climb must be scrambleable without the gift');
        $this->assertNull($scramble['capability']);
        $this->assertSame('degraded', $scramble['risk']);

        $this->assertNull(
            $this->cardWhere($turn, 'pre',
                fn ($c) => $c['verb'] === 'ascend' && ($c['target']['name'] ?? null) === 'a harbor crane'),
            'the tall wall stays behind the bought climb',
        );
    }

    public function test_swing_and_kin_never_floor()
    {
        $campaign = $this->createCampaign();
        $turn = $this->openBareTurn($campaign);
        $scene = $campaign->activeScene;

        SceneFeature::create([
            'scene_id' => $scene->id, 'zone_id' => $scene->zone_id,
            'name' => 'a loading gantry', 'feature_type' => 'vantage',
            'affordances' => ['reachable_via' => ['swing'], 'height' => 8],
            'state' => [], 'source' => 'seed',
        ]);

        $turn = $this->refreshCards($turn);

        $this->assertNull(
            $this->cardWhere($turn, 'pre', fn ($c) => $c['verb'] === 'ascend'),
            'nerve does not grow wings — swing stays a bought power',
        );
    }

    public function test_the_scramble_never_stands_beside_a_trained_way_up()
    {
        $campaign = $this->createCampaign();
        $campaign->character->capabilities()->create(['capability' => 'climb', 'source' => 'creation']);
        $turn = $this->openBareTurn($campaign);
        $scene = $campaign->activeScene;

        SceneFeature::create([
            'scene_id' => $scene->id, 'zone_id' => $scene->zone_id,
            'name' => 'a stack of crab pots', 'feature_type' => 'vantage',
            'affordances' => ['reachable_via' => ['climb', 'leap'], 'height' => 5],
            'state' => [], 'source' => 'seed',
        ]);

        $turn = $this->refreshCards($turn);

        $ways = $this->cardsWhere($turn, 'pre', fn ($c) => $c['verb'] === 'ascend');
        $this->assertCount(1, $ways, 'the floor must never be a strictly worse twin beside a trained way');
        $this->assertSame('climb', $ways[0]['capability']);
    }

    public function test_an_untrained_cross_floors_on_the_body_plausible_ways_only()
    {
        $campaign = $this->createCampaign();
        $turn = $this->openBareTurn($campaign);
        $scene = $campaign->activeScene;

        SceneFeature::create([
            'scene_id' => $scene->id, 'zone_id' => $scene->zone_id,
            'name' => 'the harbor channel', 'feature_type' => 'obstacle',
            'affordances' => ['crossable_via' => ['swim']],
            'state' => [], 'source' => 'seed',
        ]);
        SceneFeature::create([
            'scene_id' => $scene->id, 'zone_id' => $scene->zone_id,
            'name' => 'a wide mooring gap', 'feature_type' => 'obstacle',
            'affordances' => ['crossable_via' => ['leap'], 'gap' => 'far'],
            'state' => [], 'source' => 'seed',
        ]);
        SceneFeature::create([
            'scene_id' => $scene->id, 'zone_id' => $scene->zone_id,
            'name' => 'a boarding plank', 'feature_type' => 'obstacle',
            'affordances' => ['crossable_via' => ['leap'], 'gap' => 'short'],
            'state' => [], 'source' => 'seed',
        ]);

        $turn = $this->refreshCards($turn);

        $swim = $this->cardWhere($turn, 'main',
            fn ($c) => $c['verb'] === 'cross' && ($c['target']['name'] ?? null) === 'the harbor channel');
        $this->assertNotNull($swim, 'anyone can paddle — badly');
        $this->assertNull($swim['capability']);
        $this->assertSame('degraded', $swim['risk']);

        $this->assertNull(
            $this->cardWhere($turn, 'main',
                fn ($c) => $c['verb'] === 'cross' && ($c['target']['name'] ?? null) === 'a wide mooring gap'),
            'a far gap is past an ordinary standing jump',
        );

        $hop = $this->cardWhere($turn, 'main',
            fn ($c) => $c['verb'] === 'cross' && ($c['target']['name'] ?? null) === 'a boarding plank');
        $this->assertNotNull($hop, 'a short gap is within an ordinary standing jump');
        $this->assertSame('degraded', $hop['risk']);
    }
}
