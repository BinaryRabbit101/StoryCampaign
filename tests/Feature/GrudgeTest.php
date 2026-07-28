<?php

namespace Tests\Feature;

use App\Game\Engine\CardComposer;
use App\Game\Engine\Dice;
use App\Game\Engine\Grudges;
use App\Game\Engine\SituationBoard;
use App\Game\Engine\TurnResolver;
use App\Game\Meters;
use App\Models\Actor;
use App\Models\Campaign;
use App\Models\Chapter;
use App\Models\Character;
use App\Models\Grudge;
use App\Models\Turn;
use App\Models\User;
use App\Services\Claude\ClaudeCli;
use App\Services\Claude\WorldEvolver;
use App\Services\TurnStarter;
use Database\Seeders\WorldSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Grudges: the tale's enemies remember. An enemy who flees becomes a
 * campaign-scoped grudge the engine alone can bring back — the evolver only
 * proposes how they changed within clamps, and Claude only narrates.
 */
class GrudgeTest extends TestCase
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
            'name' => 'Grudge Tale',
            'world_flavor' => 'harbor-city',
            'status' => 'active',
            'started_at' => now(),
        ]);

        Character::create([
            'campaign_id' => $campaign->id,
            'name' => 'The Cat',
            'description' => 'A striking black cat.',
            'meters' => Meters::default(),
            'status' => 'alive',
            'meters_regenerated_at' => now(),
        ]);

        return $campaign;
    }

    private function placeEnemy(Campaign $campaign, string $name, array $overrides = []): Actor
    {
        $scene = $campaign->activeScene;

        return Actor::create(array_merge([
            'scene_id' => $scene->id,
            'zone_id' => $scene->zone_id,
            'name' => $name,
            'kind' => 'enemy',
            'tier' => 'regular',
            'stats' => ['health' => ['current' => 5, 'max' => 5], 'attack' => 1],
            'tags' => [],
            'status' => 'active',
            'source' => 'seed',
        ], $overrides));
    }

    private function makeGrudge(Campaign $campaign, string $name, array $overrides = []): Grudge
    {
        return Grudge::create(array_merge([
            'campaign_id' => $campaign->id,
            'actor_name' => $name,
            'stats' => ['health' => ['current' => 5, 'max' => 5], 'attack' => 1],
            'tags' => [],
            'tier' => 'regular',
            'history' => [['turn_id' => null, 'chapter_id' => null, 'event' => 'fled', 'detail' => 'Fled at the docks.', 'place' => 'the docks']],
            'heat' => 3,
            'disposition' => 'vengeful',
            'status' => 'simmering',
        ], $overrides));
    }

    private function passChapterFloor(Campaign $campaign, int $chapters = 2): void
    {
        for ($i = 1; $i <= $chapters; $i++) {
            Chapter::create([
                'campaign_id' => $campaign->id,
                'number' => $i,
                'kind' => 'chapter',
                'body' => 'Pages turned.',
            ]);
        }
    }

    public function test_a_fleeing_enemy_becomes_a_grudge_and_a_reflee_heats_it()
    {
        $campaign = $this->createCampaign();
        $turn = app(TurnStarter::class)->openFirstTurn($campaign);
        $scene = $campaign->activeScene;

        $enemy = $this->placeEnemy($campaign, 'the scarred wharfinger');
        $enemy->update(['status' => 'fled']);

        Grudges::recordFlights($scene, $turn, []);

        $grudge = Grudge::where('campaign_id', $campaign->id)->where('actor_name', 'the scarred wharfinger')->first();
        $this->assertNotNull($grudge);
        $this->assertSame(1, $grudge->heat);
        // Untouched and untalked: they left on their own terms.
        $this->assertSame('scheming', $grudge->disposition);
        $this->assertSame('fled', $grudge->history[0]['event']);

        // A re-flee heats the same grudge — never a second row.
        Grudges::recordFlights($scene, $turn, []);

        $this->assertSame(1, Grudge::where('campaign_id', $campaign->id)->count());
        $this->assertSame(2, $grudge->fresh()->heat);
        $this->assertCount(2, $grudge->fresh()->history);
    }

    public function test_flee_circumstances_pick_the_disposition()
    {
        $campaign = $this->createCampaign();
        $turn = app(TurnStarter::class)->openFirstTurn($campaign);
        $scene = $campaign->activeScene;

        $wounded = $this->placeEnemy($campaign, 'the limping reaver', [
            'stats' => ['health' => ['current' => 2, 'max' => 5], 'attack' => 1],
        ]);
        $wounded->update(['status' => 'fled']);

        $cowed = $this->placeEnemy($campaign, 'the pale tallyman');
        $cowed->update(['status' => 'fled', 'tags' => ['fled_how' => 'intimidated']]);

        Grudges::recordFlights($scene, $turn, []);

        $this->assertSame('vengeful', Grudge::where('actor_name', 'the limping reaver')->first()->disposition);
        $this->assertSame('wary', Grudge::where('actor_name', 'the pale tallyman')->first()->disposition);
    }

    public function test_a_nameless_template_dupe_earns_no_grudge()
    {
        $campaign = $this->createCampaign();
        $turn = app(TurnStarter::class)->openFirstTurn($campaign);
        $scene = $campaign->activeScene;

        // Two of the same name in one scene: faceless, so no identity to hold.
        $this->placeEnemy($campaign, 'a dock rat');
        $second = $this->placeEnemy($campaign, 'a dock rat');
        $second->update(['status' => 'fled']);

        Grudges::recordFlights($scene, $turn, []);

        $this->assertSame(0, Grudge::count());
    }

    public function test_the_return_respects_the_chapter_floor_and_one_per_scene()
    {
        $campaign = $this->createCampaign();
        $turn = app(TurnStarter::class)->openFirstTurn($campaign);
        $scene = $campaign->activeScene;

        $this->makeGrudge($campaign, 'the scarred wharfinger');
        $this->makeGrudge($campaign, 'the pale tallyman');

        // No chapters have passed since the flee: no seed may bring them back.
        for ($seed = 1; $seed <= 40; $seed++) {
            $this->assertNull(Grudges::maybeReturn($scene, $campaign, new Dice($seed), $turn));
        }

        // Two chapters on, heat 3 gives a real chance — and when it lands,
        // at most ONE grudge enters the scene.
        $this->passChapterFloor($campaign);

        $returned = null;
        for ($seed = 1; $seed <= 40 && $returned === null; $seed++) {
            $returned = Grudges::maybeReturn($scene, $campaign, new Dice($seed), $turn);
        }

        $this->assertNotNull($returned);
        $this->assertSame(1, $scene->actors()->where('source', 'grudge')->count());
        $this->assertSame(1, Grudge::where('status', 'returning')->count());
        $this->assertSame($returned->tags['grudge_id'], Grudge::where('status', 'returning')->first()->id);
    }

    public function test_disposition_picks_the_entry_mode()
    {
        $campaign = $this->createCampaign();
        $turn = app(TurnStarter::class)->openFirstTurn($campaign);
        $this->passChapterFloor($campaign);

        foreach ([
            'vengeful' => 'the limping reaver',
            'wary' => 'the pale tallyman',
            'scheming' => 'the scarred wharfinger',
        ] as $disposition => $name) {
            $grudge = $this->makeGrudge($campaign, $name, ['disposition' => $disposition]);
            $scene = $campaign->activeScene;

            $actor = null;
            for ($seed = 1; $seed <= 60 && $actor === null; $seed++) {
                $actor = Grudges::maybeReturn($scene, $campaign, new Dice($seed), $turn);
            }
            $this->assertNotNull($actor, "{$disposition} grudge never returned");

            match ($disposition) {
                'vengeful' => $this->assertSame('press', $actor->tags['intent'] ?? null),
                'wary' => $this->assertTrue($actor->tags['lurking'] ?? false),
                'scheming' => $this->assertTrue(($actor->tags['truce'] ?? false)
                    && in_array($actor->tags['deal'] ?? null, Grudges::DEALS, true)),
            };

            // Clear the stage for the next disposition.
            $actor->delete();
            $grudge->delete();
        }
    }

    public function test_a_wary_return_stays_hidden_from_cards_board_and_narrator()
    {
        $campaign = $this->createCampaign();
        $turn = app(TurnStarter::class)->openFirstTurn($campaign);
        $this->passChapterFloor($campaign);
        $scene = $campaign->activeScene;

        $this->makeGrudge($campaign, 'the pale tallyman', ['disposition' => 'wary']);
        $lurker = null;
        for ($seed = 1; $seed <= 60 && $lurker === null; $seed++) {
            $lurker = Grudges::maybeReturn($scene, $campaign, new Dice($seed), $turn);
        }
        $this->assertNotNull($lurker);

        $cards = app(CardComposer::class)->compose($campaign->character->fresh(), $scene->fresh());
        $targeted = collect($cards)->only(['pre', 'main', 'post'])->flatMap(fn ($c) => $c)
            ->contains(fn ($c) => ($c['target']['id'] ?? null) === $lurker->id);
        $this->assertFalse($targeted);

        $board = json_encode(SituationBoard::for($campaign->character, $scene->fresh()));
        $this->assertStringNotContainsString('the pale tallyman', $board);
        $this->assertSame('', Grudges::returningFigures($turn->fresh()));

        // Once exposed, the old score stands in the open everywhere at once.
        $tags = $lurker->tags;
        unset($tags['lurking'], $tags['lurking_since']);
        $lurker->update(['tags' => $tags]);

        $board = json_encode(SituationBoard::for($campaign->character, $scene->fresh()));
        $this->assertStringContainsString('An old score', $board);
        $this->assertStringContainsString('you have met before', $board);
        $this->assertStringContainsString('Returning figure', Grudges::returningFigures($turn->fresh()));
    }

    public function test_killing_or_keeping_a_returned_grudge_settles_the_score_for_good()
    {
        $campaign = $this->createCampaign();
        $turn = app(TurnStarter::class)->openFirstTurn($campaign);
        $scene = $campaign->activeScene;

        $grudge = $this->makeGrudge($campaign, 'the limping reaver', ['status' => 'returning']);
        $actor = $this->placeEnemy($campaign, 'the limping reaver', ['tags' => ['grudge_id' => $grudge->id]]);
        $actor->update(['status' => 'defeated']);

        Grudges::settle($scene, $scene, false, $turn);

        $grudge->refresh();
        $this->assertSame('resolved', $grudge->status);
        $this->assertSame('resolved', collect($grudge->history)->last()['event']);

        // A settled score never reopens, whatever the dice say.
        $this->passChapterFloor($campaign);
        for ($seed = 1; $seed <= 40; $seed++) {
            $this->assertNull(Grudges::maybeReturn($scene, $campaign, new Dice($seed), $turn));
        }
    }

    public function test_walking_away_leaves_the_grudge_simmering_for_another_day()
    {
        $campaign = $this->createCampaign();
        $turn = app(TurnStarter::class)->openFirstTurn($campaign);
        $scene = $campaign->activeScene;

        $grudge = $this->makeGrudge($campaign, 'the limping reaver', ['status' => 'returning']);
        $this->placeEnemy($campaign, 'the limping reaver', ['tags' => ['grudge_id' => $grudge->id]]);

        // The player moved on; the grudge actor stayed behind, still standing.
        $next = $campaign->scenes()->create([
            'zone_id' => $scene->zone_id,
            'title' => 'New ground',
            'description' => 'Elsewhere.',
            'status' => 'active',
            'state' => ['dressed' => true],
        ]);

        Grudges::settle($scene, $next, true, $turn);

        $grudge->refresh();
        $this->assertSame('simmering', $grudge->status);
        $this->assertSame('escaped_again', collect($grudge->history)->last()['event']);
    }

    public function test_a_scheming_return_offers_a_bargain_card_that_settles_the_score()
    {
        $campaign = $this->createCampaign();
        $turn = app(TurnStarter::class)->openFirstTurn($campaign);
        $scene = $campaign->activeScene;
        $scene->actors()->delete();

        $grudge = $this->makeGrudge($campaign, 'the scarred wharfinger', [
            'disposition' => 'scheming', 'status' => 'returning',
        ]);
        $this->placeEnemy($campaign, 'the scarred wharfinger', [
            'tags' => ['grudge_id' => $grudge->id, 'truce' => true, 'deal' => 'depart', 'truce_health' => 5],
        ]);

        $turn->update(['cards' => app(CardComposer::class)->compose($campaign->character->fresh(), $scene->fresh())]);
        $turn = $turn->fresh();

        $bargain = collect($turn->cards['main'])->first(fn ($c) => $c['verb'] === 'bargain');
        $this->assertNotNull($bargain);
        // The bargain never rolls: the deal was theirs to offer.
        $this->assertFalse($bargain['forecast']['rolls']);

        $turn->update([
            'status' => Turn::STATUS_LOCKED,
            'submission' => ['main' => ['card_id' => $bargain['id'], 'modifiers' => []]],
            'submitted_at' => now(),
        ]);
        app(TurnResolver::class)->resolve($turn->fresh());
        $turn->refresh();

        $grudge->refresh();
        $this->assertSame('resolved', $grudge->status);
        $this->assertStringContainsString('took the terms', implode(' ', $turn->resolution['beats'][0]['facts']));
        // They walked by agreement — that departure builds no new grudge.
        $this->assertSame(1, Grudge::count());
    }

    public function test_a_grudge_under_truce_does_not_swing()
    {
        $campaign = $this->createCampaign();
        $turn = app(TurnStarter::class)->openFirstTurn($campaign);
        $scene = $campaign->activeScene;
        $scene->actors()->delete();

        $grudge = $this->makeGrudge($campaign, 'the scarred wharfinger', ['status' => 'returning']);
        $this->placeEnemy($campaign, 'the scarred wharfinger', [
            'tags' => ['grudge_id' => $grudge->id, 'truce' => true, 'deal' => 'depart', 'truce_health' => 5],
        ]);

        $turn = $turn->fresh();
        $wait = collect($turn->cards['main'])->first(fn ($c) => $c['verb'] === 'wait');
        $turn->update([
            'status' => Turn::STATUS_LOCKED,
            'submission' => ['main' => ['card_id' => $wait['id'], 'modifiers' => []]],
            'submitted_at' => now(),
        ]);
        app(TurnResolver::class)->resolve($turn->fresh());
        $turn->refresh();

        $this->assertStringContainsString('held to the truce', implode(' ', $turn->resolution['scene_reaction']));
        $this->assertSame([], $turn->resolution['reaction_rolls']);
    }

    public function test_the_evolver_tends_grudges_within_clamps_and_budget()
    {
        $campaign = $this->createCampaign();
        app(TurnStarter::class)->openFirstTurn($campaign);

        $first = $this->makeGrudge($campaign, 'the scarred wharfinger', ['heat' => 1]);
        $second = $this->makeGrudge($campaign, 'the pale tallyman', ['heat' => 3]);
        $third = $this->makeGrudge($campaign, 'the limping reaver', ['heat' => 1]);

        $this->mock(ClaudeCli::class, function ($mock) {
            $mock->shouldReceive('promptForJson')->andReturn([
                'chronicle' => 'The city shifted.',
                'rationale' => 'test',
                'features' => [], 'actors' => [], 'items' => [],
                'grudges' => [
                    ['name' => 'the scarred wharfinger', 'development' => 'They bought a sharper knife.',
                        'stats' => ['health' => ['max' => 99], 'attack' => 9],
                        'tags' => ['vicious' => true, 'truce' => true, 'lurking' => true]],
                    ['name' => 'the pale tallyman', 'development' => 'They hired muscle.'],
                    ['name' => 'the limping reaver', 'development' => 'One past the budget.'],
                ],
            ]);
        });

        app(WorldEvolver::class)->evolveCampaign($campaign);

        $first->refresh();
        // Deltas clamp to the actor bounds; heat climbs by exactly one.
        $this->assertSame(12, $first->stats['health']['max']);
        $this->assertSame(4, $first->stats['attack']);
        $this->assertSame(2, $first->heat);
        // The development joins the history; the return machinery is untouchable.
        $this->assertSame('developed', collect($first->history)->last()['event']);
        $this->assertTrue($first->tags['vicious'] ?? false);
        $this->assertArrayNotHasKey('truce', $first->tags);
        $this->assertArrayNotHasKey('lurking', $first->tags);

        // Heat caps at 3 even when tended again.
        $this->assertSame(3, $second->fresh()->heat);

        // The budget held: the third grudge went untended.
        $this->assertSame(1, $third->fresh()->heat);
        $this->assertCount(1, $third->fresh()->history);
    }

    public function test_a_grudge_never_crosses_into_another_campaign()
    {
        $campaignA = $this->createCampaign();
        $turnA = app(TurnStarter::class)->openFirstTurn($campaignA);
        $this->makeGrudge($campaignA, 'the scarred wharfinger');
        $this->passChapterFloor($campaignA);

        $campaignB = Campaign::create([
            'user_id' => $campaignA->user_id,
            'name' => 'Second Tale',
            'world_flavor' => 'harbor-city',
            'status' => 'active',
            'started_at' => now(),
        ]);
        Character::create([
            'campaign_id' => $campaignB->id,
            'name' => 'The Other',
            'description' => 'Someone else entirely.',
            'meters' => Meters::default(),
            'status' => 'alive',
            'meters_regenerated_at' => now(),
        ]);
        $turnB = app(TurnStarter::class)->openFirstTurn($campaignB);
        $this->passChapterFloor($campaignB);

        for ($seed = 1; $seed <= 40; $seed++) {
            $this->assertNull(Grudges::maybeReturn($campaignB->activeScene, $campaignB, new Dice($seed), $turnB));
        }
        $this->assertSame(0, $campaignB->activeScene->actors()->where('source', 'grudge')->count());
    }
}
