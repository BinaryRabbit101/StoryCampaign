<?php

namespace Tests\Feature;

use App\Game\Engine\Attempts;
use App\Game\Engine\BeatOutcome;
use App\Game\Engine\CardComposer;
use App\Game\Engine\Dice;
use App\Game\Engine\TurnResolver;
use App\Game\Hands;
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
 * Three picks over one list, and a route that has already been refused.
 *
 * Two changes to the shape of a turn live here. The first: position no longer
 * decides what may stand in it, so all three of the player's picks offer the
 * same beats and the player decides what belongs where. The second: a failure on
 * the short closed list of route-and-search verbs takes THAT card off the table
 * for the rest of the scene, because offering it again is the engine asking a
 * question the ground has already answered.
 */
class BeatSlotsTest extends TestCase
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
            'name' => 'Slot Tale',
            'world_flavor' => 'harbor-city',
            'status' => 'active',
            'started_at' => now(),
        ]);

        $character = Character::create([
            'campaign_id' => $campaign->id,
            'name' => 'The Cat',
            'description' => 'A 200 lb striking black cat with a 12-foot prehensile tail.',
            'meters' => Meters::default(),
            'status' => 'alive',
            'meters_regenerated_at' => now(),
        ]);

        foreach ([
            ['capability' => 'swing'],
            ['capability' => 'reach', 'magnitude' => 12],
            ['capability' => 'squeeze', 'grade' => 'large'],
            ['capability' => 'ready'],
        ] as $cap) {
            $character->capabilities()->create($cap + ['source' => 'creation']);
        }

        return $campaign;
    }

    private function placeFeature(Scene $scene, string $name): SceneFeature
    {
        $template = SceneFeature::whereNull('scene_id')->where('name', $name)->firstOrFail();

        return SceneFeature::create([
            'scene_id' => $scene->id,
            'zone_id' => $scene->zone_id,
            'name' => $template->name,
            'feature_type' => $template->feature_type,
            'affordances' => $template->affordances,
            'state' => [],
            'source' => 'seed',
        ]);
    }

    private function placeActor(Scene $scene, string $name): Actor
    {
        $template = Actor::whereNull('scene_id')->where('name', $name)->firstOrFail();

        return Actor::create([
            'scene_id' => $scene->id,
            'zone_id' => $scene->zone_id,
            'name' => $template->name,
            'kind' => $template->kind,
            'tier' => $template->tier,
            'stats' => $template->stats,
            'tags' => $template->tags,
            'status' => 'active',
            'source' => 'seed',
        ]);
    }

    private function refreshCards(Turn $turn): Turn
    {
        $turn->update(['cards' => app(CardComposer::class)->compose(
            $turn->campaign->character->fresh(), $turn->scene->fresh(),
        )]);

        return $turn->fresh();
    }

    /** What a card IS, with the position it was offered in taken out. */
    private function beat(array $card): string
    {
        return implode('|', [
            $card['verb'],
            $card['capability'] ?? '-',
            $card['target']['id'] ?? ($card['target']['name'] ?? '-'),
            $card['risk'],
            $card['bargain']['key'] ?? '-',
        ]);
    }

    /**
     * Every pick offers the same beats.
     *
     * "First…" and "Afterward…" used to be two separate short piles of whatever
     * the composer happened to file there — bracing was pre-only, looting
     * post-only — so two of the player's three beats were leftovers they learned
     * to skip. The three lists are one list now, and the numbers on screen are
     * the order they resolve in.
     */
    public function test_all_three_picks_offer_the_same_beats()
    {
        $campaign = $this->createCampaign();
        $turn = app(TurnStarter::class)->openFirstTurn($campaign);
        $this->placeFeature($campaign->activeScene, 'the warehouse roof');
        $this->placeActor($campaign->activeScene, 'a dockside tough');
        $turn = $this->refreshCards($turn);

        $beats = [];
        foreach (['pre', 'main', 'post'] as $slot) {
            $beats[$slot] = array_map(fn (array $c) => $this->beat($c), $turn->cards[$slot]);
        }

        $this->assertSame($beats['pre'], $beats['main']);
        $this->assertSame($beats['main'], $beats['post']);
        $this->assertNotEmpty($beats['main']);

        // Beats that used to belong to one position only now stand in all three.
        foreach (['pre', 'main', 'post'] as $slot) {
            $verbs = collect($turn->cards[$slot])->pluck('verb');
            $this->assertTrue($verbs->contains('ready'), "no set-up beat in {$slot}");
            $this->assertTrue($verbs->contains('strike'), "no act in {$slot}");
            $this->assertTrue($verbs->contains('catch_breath'), "no follow-up beat in {$slot}");
        }

        // The copies are distinct, individually validated cards: a submission
        // still says which position it committed a beat to, and the id it names
        // has to have been offered for exactly that one.
        $ids = collect(['pre', 'main', 'post'])
            ->flatMap(fn (string $slot) => array_column($turn->cards[$slot], 'id'));
        $this->assertCount($ids->count(), $ids->unique());

        foreach (['pre', 'main', 'post'] as $slot) {
            foreach ($turn->cards[$slot] as $card) {
                $this->assertSame($slot, $card['slot']);
            }
        }
    }

    /** A beat's price is the same whichever of the three it is taken in. */
    public function test_a_beat_is_priced_the_same_in_every_position()
    {
        $campaign = $this->createCampaign();
        $turn = app(TurnStarter::class)->openFirstTurn($campaign);
        $this->placeActor($campaign->activeScene, 'a dockside tough');
        $turn = $this->refreshCards($turn);

        $forecasts = [];
        foreach (['pre', 'main', 'post'] as $slot) {
            $forecasts[$slot] = collect($turn->cards[$slot])->firstWhere('verb', 'strike')['forecast'];
        }

        $this->assertSame($forecasts['pre'], $forecasts['main']);
        $this->assertSame($forecasts['main'], $forecasts['post']);
    }

    /** Three beats out of one list, resolved in the order they were numbered. */
    public function test_the_same_kind_of_beat_can_be_taken_in_more_than_one_position()
    {
        $campaign = $this->createCampaign();
        $turn = app(TurnStarter::class)->openFirstTurn($campaign);
        $this->placeActor($campaign->activeScene, 'a dockside tough');
        $turn = $this->refreshCards($turn);

        $first = collect($turn->cards['pre'])->firstWhere('verb', 'ready');
        $act = collect($turn->cards['main'])->firstWhere('verb', 'strike');
        $then = collect($turn->cards['post'])->firstWhere('verb', 'strike');

        $this->actingAs($campaign->user)
            ->post("/play/{$campaign->id}", [
                'pre' => ['card_id' => $first['id'], 'modifiers' => [], 'note' => null],
                'main' => ['card_id' => $act['id'], 'modifiers' => ['approach' => 'balanced'], 'note' => null],
                'post' => ['card_id' => $then['id'], 'modifiers' => ['approach' => 'balanced'], 'note' => null],
                'companions' => [],
            ])
            ->assertRedirect("/play/{$campaign->id}");

        $slots = collect($turn->fresh()->resolution['beats'])->pluck('slot');
        $this->assertSame(['pre', 'main', 'post'], $slots->all());
    }

    /**
     * A way that has been tried and refused stops being offered.
     *
     * Fleeing into the toppled shelving and finding it will not take you does not
     * become truer on the second attempt. Only an outright failure closes it, it
     * closes only that thing, and it closes only for this scene.
     */
    public function test_a_failed_route_is_not_offered_again_in_the_same_scene()
    {
        $campaign = $this->createCampaign();
        $turn = app(TurnStarter::class)->openFirstTurn($campaign);
        $scene = $campaign->activeScene;
        $scene->features()->delete();
        $scene->actors()->delete();

        $roof = $this->placeFeature($scene, 'the warehouse roof');
        $this->placeFeature($scene, 'the harbor chain');

        // Stacked against them on purpose, so the die cannot rescue the beat and
        // the test is about the rule rather than about luck: hands full (a real
        // stretch, one-armed), an old wound that prices every climb, and the
        // boldest reading of it.
        $character = $campaign->character->fresh();
        Hands::take($character, 'a crate of salvage', null, 2);
        $character->constraints()->create([
            'name' => 'marked_limp',
            'params' => null,
            'coupled_capability' => null,
            'source' => 'scar',
        ]);

        $turn = $this->refreshCards($turn);

        $climb = collect($turn->cards['main'])->first(
            fn (array $c) => $c['verb'] === 'ascend' && ($c['target']['id'] ?? null) === $roof->id,
        );
        $this->assertNotNull($climb, 'the roof never offered a way up');

        $turn->update([
            'status' => Turn::STATUS_LOCKED,
            'submission' => ['main' => ['card_id' => $climb['id'], 'modifiers' => ['approach' => 'bold']]],
            'submitted_at' => now(),
        ]);
        app(TurnResolver::class)->resolve($turn->fresh());

        $beat = collect($turn->fresh()->resolution['beats'])->firstWhere('verb', 'ascend');
        $this->assertNotNull($beat);
        $this->assertSame('failure', $beat['degree'], 'the beat was meant to be unwinnable');

        $this->assertContains(
            Attempts::key('ascend', $climb['target'], $scene->fresh()),
            Attempts::spent($scene->fresh()),
        );

        // The card is gone from every one of the three picks, and the ground says
        // why rather than leaving the player to notice an absence.
        $cards = app(CardComposer::class)->compose($campaign->character->fresh(), $scene->fresh());
        foreach (['pre', 'main', 'post'] as $slot) {
            $this->assertNull(collect($cards[$slot])->first(
                fn (array $c) => $c['verb'] === 'ascend' && ($c['target']['id'] ?? null) === $roof->id,
            ), "the refused way up is still offered in {$slot}");
        }

        $this->assertGreaterThanOrEqual(2, count($cards['main']));
        $this->assertNotEmpty(Attempts::boardLines($scene->fresh()));

        // ...and only that one. Everything else about the roof still stands:
        // closing a route is not destroying the ground it ran over.
        $this->assertNotNull(collect($cards['main'])->first(
            fn (array $c) => ($c['target']['id'] ?? null) === $roof->id && $c['verb'] !== 'ascend',
        ));
    }

    /** A missed swing settles nothing. The list is closed, and short. */
    public function test_a_missed_strike_is_still_offered()
    {
        $campaign = $this->createCampaign();
        $scene = app(TurnStarter::class)->openFirstTurn($campaign)->scene;
        $tough = $this->placeActor($scene, 'a dockside tough');

        $outcome = new BeatOutcome(
            'main', 'strike', ['type' => 'actor', 'id' => $tough->id, 'name' => $tough->name],
            BeatOutcome::FAILURE, 2, 2, 14, ['They missed.'],
        );

        Attempts::record($scene, $outcome);

        $this->assertSame([], Attempts::spent($scene->fresh()));
        $this->assertFalse(Attempts::closes('strike'));
        $this->assertFalse(Attempts::closes('persuade'));
        $this->assertTrue(Attempts::closes('flee'));
    }

    /** A route closed in one place is open again in the next. */
    public function test_a_closed_route_belongs_to_the_scene_it_was_refused_in()
    {
        $campaign = $this->createCampaign();
        $first = app(TurnStarter::class)->openFirstTurn($campaign)->scene;
        $feature = $this->placeFeature($first, 'the narrow alley');

        Attempts::record($first, new BeatOutcome(
            'main', 'flee', ['type' => 'feature', 'id' => $feature->id, 'name' => $feature->name],
            BeatOutcome::FAILURE, 1, 1, 12, ['It would not take them.'],
        ));

        $this->assertNotEmpty(Attempts::spent($first->fresh()));

        $next = Scene::create([
            'campaign_id' => $campaign->id,
            'zone_id' => $first->zone_id,
            'title' => 'Further in',
            'description' => 'New ground.',
            'status' => 'active',
            'state' => ['dressed' => true],
        ]);

        $this->assertSame([], Attempts::spent($next));
    }

    /** The bargain pass still puts exactly one deal on the table. */
    public function test_one_deal_reaches_the_table_as_one_beat_in_three_positions()
    {
        config(['game.bargains.chance' => 1.0]);

        $campaign = $this->createCampaign();
        $scene = app(TurnStarter::class)->openFirstTurn($campaign)->scene;
        $this->placeFeature($scene, 'a wall of stacked crates');
        $this->placeActor($scene, 'a dockside tough');

        $cards = app(CardComposer::class)->compose(
            $campaign->character->fresh(), $scene->fresh(), new Dice(11),
        );

        $deals = [];
        foreach (['pre', 'main', 'post'] as $slot) {
            $deals[$slot] = collect($cards[$slot])
                ->filter(fn (array $c) => $c['bargain'] !== null)
                ->map(fn (array $c) => $this->beat($c))->values()->all();
        }

        $this->assertCount(1, $deals['main'], 'exactly one deal per turn');
        $this->assertSame($deals['pre'], $deals['main']);
        $this->assertSame($deals['main'], $deals['post']);
    }
}
