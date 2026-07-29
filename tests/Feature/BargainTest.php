<?php

namespace Tests\Feature;

use App\Game\Engine\ActionCard;
use App\Game\Engine\Ambient;
use App\Game\Engine\Bargains;
use App\Game\Engine\CardComposer;
use App\Game\Engine\Dice;
use App\Game\Engine\Odds;
use App\Game\Engine\TurnResolver;
use App\Game\Hands;
use App\Game\Meters;
use App\Game\TurnSlot;
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
 * Bargain cards: a complication with a price tag.
 *
 * The same beat offered twice — the honest version, and one that trades a named
 * consequence in the world for a named edge on the arithmetic. Both halves are
 * printed before the commit, the edge comes off the one Odds ledger the dice
 * read, and the consequence is paid whether the roll lands or not.
 */
class BargainTest extends TestCase
{
    use RefreshDatabase;

    /**
     * The composer's own pass is off by default here. Most of these tests name
     * the key they are examining and stand it on the turn themselves; leaving
     * the seeded pass running underneath would mean every one of them was also
     * quietly betting that the dice had not already offered the same deal.
     * The three tests that are ABOUT the pass turn it back on.
     */
    protected function setUp(): void
    {
        parent::setUp();

        config(['game.bargains.chance' => 0.0]);
    }

    private function createCampaign(): Campaign
    {
        $this->seed(WorldSeeder::class);

        $this->mock(ClaudeCli::class, function ($mock) {
            $mock->shouldReceive('promptForJson')->andThrow(new \RuntimeException('offline'))->byDefault();
            $mock->shouldReceive('prompt')->andReturn('A tale begins.')->byDefault();
        });

        $campaign = Campaign::create([
            'user_id' => User::factory()->create()->id,
            'name' => 'Bargain Tale',
            'world_flavor' => 'harbor-city',
            'status' => 'active',
            'started_at' => now(),
        ]);

        $meters = Meters::default();
        $meters['tempo']['time_slow'] = ['current' => 2, 'max' => 3];

        $character = Character::create([
            'campaign_id' => $campaign->id,
            'name' => 'The Cat',
            'description' => 'A striking black cat.',
            'meters' => $meters,
            'status' => 'alive',
            'meters_regenerated_at' => now(),
        ]);

        // Enough of a sheet that every key in the table has some card it could
        // honestly attach to.
        foreach ([
            ['capability' => 'break'],
            ['capability' => 'lift', 'magnitude' => 200],
            ['capability' => 'restrain'],
            ['capability' => 'conceal'],
            ['capability' => 'climb'],
            ['capability' => 'swing'],
            ['capability' => 'reach', 'magnitude' => 12],
            ['capability' => 'squeeze', 'grade' => 'large'],
            ['capability' => 'time_slow'],
        ] as $capability) {
            $character->capabilities()->create($capability + ['source' => 'creation']);
        }

        return $campaign;
    }

    /** Ground the test owns outright: no strangers, no leftover props, clear air. */
    private function openBareTurn(Campaign $campaign): Turn
    {
        $turn = app(TurnStarter::class)->openFirstTurn($campaign);
        $scene = $campaign->activeScene;
        $scene->actors()->delete();
        $scene->features()->delete();
        $scene->update(['state' => array_merge($scene->state ?? [], ['ambient' => Ambient::CLEAR, 'alarm' => 0])]);

        return $turn->fresh();
    }

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

    private function placeActor(Scene $scene, string $name, array $tags = [], string $status = 'active'): Actor
    {
        $template = Actor::whereNull('scene_id')->where('name', $name)->firstOrFail();

        return Actor::create([
            'scene_id' => $scene->id,
            'zone_id' => $scene->zone_id,
            'name' => $template->name,
            'kind' => $template->kind,
            'tier' => $template->tier,
            'stats' => $template->stats,
            'tags' => array_merge($template->tags ?? [], $tags),
            'status' => $status,
            'source' => 'seed',
        ]);
    }

    private function refreshCards(Turn $turn, ?Dice $dice = null): Turn
    {
        $turn->update(['cards' => app(CardComposer::class)->compose(
            $turn->campaign->character->fresh(), $turn->scene->fresh(), $dice,
        )]);

        return $turn->fresh();
    }

    /** The conditions a card is priced against, exactly as the composer builds them. */
    private function conditions(Scene $scene): array
    {
        return [
            'elevated' => (bool) ($scene->state['elevated'] ?? false),
            'ambient' => Ambient::of($scene),
        ];
    }

    /**
     * Take the plain card the composer offered and stand its bargained twin
     * beside it on the turn. The composer's own pass is seeded and picks its
     * own key; the resolver tests need to name one, so they build it from the
     * same public table the composer builds from.
     */
    private function attachBargain(Turn $turn, string $slot, string $verb, string $key): array
    {
        $plain = collect($turn->cards[$slot])->firstWhere('verb', $verb);
        $this->assertNotNull($plain, "no {$verb} card was offered in the {$slot} slot");

        $card = Bargains::offer(ActionCard::fromArray($plain), $key)
            ->toArray($this->conditions($turn->scene->fresh()));

        $cards = $turn->cards;
        $cards[$slot][] = $card;
        $turn->update(['cards' => $cards]);

        return $card;
    }

    /** Submit one card and resolve, returning the beat the dice actually paid. */
    private function resolveCard(Turn $turn, string $slot, array $card, array $modifiers = []): array
    {
        $turn->update([
            'status' => Turn::STATUS_LOCKED,
            'submission' => [$slot => ['card_id' => $card['id'], 'modifiers' => $modifiers]],
            'submitted_at' => now(),
        ]);

        app(TurnResolver::class)->resolve($turn->fresh());

        $beat = collect($turn->fresh()->resolution['beats'])->firstWhere('verb', $card['verb']);
        $this->assertNotNull($beat, "the {$card['verb']} beat never resolved");

        return $beat;
    }

    /**
     * The deals on the table, counted as BEATS.
     *
     * Every one of the player's three picks offers the same list now, so one
     * deal reaches the player as three cards with three ids — one per position
     * it may be taken in. "One deal per turn" is a statement about how many
     * bargained beats the pass created, so the copies collapse here on the
     * identity that made them: the verb, the thing, and the deal itself.
     *
     * @return list<array>
     */
    private function bargainsIn(array $cards): array
    {
        return collect($cards)->only(['pre', 'main', 'post'])
            ->flatMap(fn (array $slot) => $slot)
            ->filter(fn (array $c) => ($c['bargain'] ?? null) !== null)
            ->unique(fn (array $c) => implode('|', [
                $c['verb'], $c['target']['id'] ?? '-', $c['bargain']['key'],
            ]))
            ->values()->all();
    }

    /** @return list<string> */
    private function labels(array $parts): array
    {
        return array_column($parts, 'label');
    }

    /**
     * A deal is never the whole offer. It stands directly after the honest
     * version of the same beat, so taking it is always a choice against the
     * plain card — and it carries its own id, or a submission could not say
     * which of the two the player committed to.
     */
    public function test_a_bargain_stands_beside_its_plain_sibling_and_carries_its_own_id()
    {
        config(['game.bargains.chance' => 1.0]);

        $campaign = $this->createCampaign();
        $turn = $this->openBareTurn($campaign);
        $scene = $campaign->activeScene;

        $this->placeFeature($scene, 'a wall of stacked crates');
        $this->placeActor($scene, 'a dockside tough');
        $turn = $this->refreshCards($turn, new Dice(11));

        $offers = $this->bargainsIn($turn->cards);
        $this->assertCount(1, $offers, 'exactly one deal per turn');

        $deal = $offers[0];
        $slot = $deal['slot'];
        $row = $turn->cards[$slot];
        $at = array_search($deal['id'], array_column($row, 'id'), true);

        // Immediately after its sibling: same verb, same target, same risk.
        $sibling = $row[$at - 1];
        $this->assertNull($sibling['bargain']);
        $this->assertSame($sibling['verb'], $deal['verb']);
        $this->assertSame($sibling['target'], $deal['target']);
        $this->assertSame($sibling['risk'], $deal['risk']);
        $this->assertNotSame($sibling['id'], $deal['id']);

        // Both halves are on the card, in words, before anything is committed.
        $this->assertContains($deal['bargain']['key'], Odds::bargainKeys());
        $this->assertNotSame('', $deal['bargain']['edge_label']);
        $this->assertNotSame('', $deal['bargain']['complication_label']);
        $this->assertStringContainsString($sibling['label'], $deal['label']);

        // And the invariant it must never touch.
        $this->assertGreaterThanOrEqual(2, count($turn->cards['main']));
    }

    /**
     * The load-bearing promise, inherited whole from Odds: the edge is one more
     * itemized line, and the number quoted on the card is the number the die is
     * measured against. Both sides of the ladder, because the table trades on
     * both.
     */
    public function test_the_edge_is_an_itemized_odds_part_on_the_card_and_on_the_die()
    {
        $campaign = $this->createCampaign();
        $turn = $this->openBareTurn($campaign);
        $scene = $campaign->activeScene;

        $this->placeFeature($scene, 'a wall of stacked crates');
        $this->placeActor($scene, 'a dockside tough');
        $turn = $this->refreshCards($turn);

        // Difficulty side: one band down, and the band the card prints moves
        // with it.
        $plain = collect($turn->cards['main'])->firstWhere('verb', 'break');
        $loud = $this->attachBargain($turn->fresh(), 'main', 'break', Bargains::LOUD);
        $edge = Odds::bargainLabel(Bargains::LOUD);

        $this->assertNotContains($edge, $this->labels($plain['forecast']['parts']));
        $this->assertContains($edge, $this->labels($loud['forecast']['parts']));
        $this->assertSame($plain['forecast']['difficulty'] - 4, $loud['forecast']['difficulty']);
        $this->assertSame(Odds::band($loud['forecast']['difficulty']), $loud['forecast']['band']);
        foreach (['balanced', 'cautious', 'bold'] as $stance) {
            $this->assertSame($plain['forecast']['stances'][$stance] - 4, $loud['forecast']['stances'][$stance]);
        }

        $beat = $this->resolveCard($turn->fresh(), 'main', $loud);
        $this->assertSame($loud['forecast']['difficulty'], $beat['difficulty']);
        $this->assertContains($edge, $this->labels($beat['difficulty_parts']));
        $this->assertSame(-4, collect($beat['difficulty_parts'])->firstWhere('label', $edge)['amount']);

        // Bonus side: it rides on the roll instead, and the roll pays it.
        $turn = $this->refreshCards($campaign->fresh()->currentTurn);
        $provoking = $this->attachBargain($turn, 'main', 'strike', Bargains::PROVOKING);
        $provokingEdge = Odds::bargainLabel(Bargains::PROVOKING);

        $this->assertContains($provokingEdge, $this->labels($provoking['forecast']['bonus_parts']));
        $this->assertSame(2, $provoking['forecast']['bonus']);

        $beat = $this->resolveCard($turn->fresh(), 'main', $provoking);
        $this->assertContains($provokingEdge, $this->labels($beat['bonus_parts']));
        $this->assertSame($beat['roll'] + 2, $beat['total']);
    }

    /**
     * Wrenching a gate open is loud even when it works. The price is what makes
     * the card priceable at choose-time, so it may never be quietly waived by a
     * good roll — nor become a second failure penalty, which is the `risky`
     * stance's ground and not this one's.
     */
    public function test_the_complication_is_paid_on_success_and_on_failure()
    {
        $campaign = $this->createCampaign();
        $this->openBareTurn($campaign);
        $scene = $campaign->activeScene;
        $this->placeActor($scene, 'a harbor enforcer');

        $degrees = [];

        for ($i = 0; $i < 20 && count(array_unique($degrees)) < 2; $i++) {
            $scene = $campaign->fresh()->activeScene;
            if ($scene->features()->count() === 0) {
                $this->placeFeature($scene, 'a wall of stacked crates');
            }

            $character = $campaign->character->fresh();
            $character->update(['carrying' => []]);
            Hands::take($character, 'a coil of dock rope', null, 1);

            $turn = $this->refreshCards($campaign->fresh()->currentTurn);
            $card = $this->attachBargain($turn, 'main', 'break', Bargains::TWO_HANDS);
            $beat = $this->resolveCard($turn->fresh(), 'main', $card);

            // Whichever way it went, both hands were on it.
            $this->assertSame([], Hands::held($campaign->character->fresh()),
                "the price went unpaid on a {$beat['degree']}");
            $this->assertStringContainsString(
                'a coil of dock rope',
                implode(' ', $beat['facts']),
            );

            $degrees[] = in_array($beat['degree'], ['strong', 'success'], true) ? 'won' : 'lost';

            // A broken thing stays broken; the loop re-places it above.
            $scene->features()->get()->each(fn (SceneFeature $f) => $f->update([
                'state' => array_merge($f->state ?? [], ['destroyed' => true]),
            ]));
            $scene->features()->delete();
        }

        $this->assertContains('won', $degrees, 'never saw the deal land');
        $this->assertContains('lost', $degrees, 'never saw the deal fail');
    }

    /** Loud: the district's clock, moved forward by one. */
    public function test_loud_turns_the_alarm_clock()
    {
        $campaign = $this->createCampaign();
        $this->openBareTurn($campaign);
        $scene = $campaign->activeScene;
        $this->placeFeature($scene, 'a wall of stacked crates');
        $this->placeActor($scene, 'a harbor enforcer');

        $turn = $this->refreshCards($campaign->fresh()->currentTurn);
        $plain = collect($turn->cards['main'])->firstWhere('verb', 'break');
        $this->resolveCard($turn, 'main', $plain);

        // One turn spent toe-to-toe raises the clock by one on its own.
        $this->assertSame(1, (int) $campaign->fresh()->activeScene->state['alarm']);

        $scene = $campaign->fresh()->activeScene;
        $scene->update(['state' => array_merge($scene->state, ['alarm' => 0])]);
        $scene->features()->delete();
        $this->placeFeature($scene, 'a wall of stacked crates');

        $turn = $this->refreshCards($campaign->fresh()->currentTurn);
        $loud = $this->attachBargain($turn, 'main', 'break', Bargains::LOUD);
        $beat = $this->resolveCard($turn->fresh(), 'main', $loud);

        // The same turn, taken loudly, costs the player a whole tick of it.
        $this->assertSame(2, (int) $campaign->fresh()->activeScene->state['alarm']);
        $this->assertStringContainsString('the noise carried', implode(' ', $beat['facts']));
    }

    /** Reckless: cover lost, and nothing waiting out of sight has a reason to wait. */
    public function test_reckless_costs_the_player_their_cover_and_springs_what_was_hiding()
    {
        $campaign = $this->createCampaign();
        $this->openBareTurn($campaign);
        $scene = $campaign->activeScene;
        $this->placeActor($scene, 'a harbor enforcer');
        $lurker = $this->placeActor($scene, 'a wiry cutpurse', ['lurking' => true, 'lurking_since' => 99]);

        $turn = $this->refreshCards($campaign->fresh()->currentTurn);
        $card = $this->attachBargain($turn, 'main', 'strike', Bargains::RECKLESS);
        $beat = $this->resolveCard($turn->fresh(), 'main', $card);

        $this->assertStringContainsString('were seen doing it', implode(' ', $beat['facts']));
        $this->assertFalse((bool) ($lurker->fresh()->tags['lurking'] ?? false));
        $this->assertStringContainsString(
            'burst from hiding',
            implode(' ', $turn->fresh()->resolution['scene_reaction']),
        );
        $this->assertFalse($turn->fresh()->resolution['conditions']['concealed']);
    }

    /** Provoking: the enemy's telegraph, rewritten to point straight at the player. */
    public function test_provoking_turns_the_target_on_the_player()
    {
        $campaign = $this->createCampaign();
        $this->openBareTurn($campaign);
        $scene = $campaign->activeScene;
        $enemy = $this->placeActor($scene, 'a harbor enforcer', ['intent' => 'guard']);

        // Left guarded, the enemy spends the turn behind their guard.
        $turn = $this->refreshCards($campaign->fresh()->currentTurn);
        $plain = collect($turn->cards['main'])->firstWhere('verb', 'strike');
        $this->resolveCard($turn, 'main', $plain);
        $this->assertStringContainsString(
            'stayed behind their guard',
            implode(' ', $turn->fresh()->resolution['scene_reaction']),
        );

        $enemy->update(['tags' => array_merge($enemy->fresh()->tags, ['intent' => 'guard'])]);
        $turn = $this->refreshCards($campaign->fresh()->currentTurn);
        $card = $this->attachBargain($turn, 'main', 'strike', Bargains::PROVOKING);
        $beat = $this->resolveCard($turn->fresh(), 'main', $card);

        // Provoked, they come out of it — the same turn, answered differently.
        $reaction = implode(' ', $turn->fresh()->resolution['scene_reaction']);
        $this->assertStringContainsString('coming straight at them', implode(' ', $beat['facts']));
        $this->assertStringNotContainsString('stayed behind their guard', $reaction);
    }

    /** Burning: a real charge off a real pool, spent through Meters and nowhere else. */
    public function test_burning_spends_a_charge_of_the_reserve()
    {
        $campaign = $this->createCampaign();
        $this->openBareTurn($campaign);
        $scene = $campaign->activeScene;
        $this->placeFeature($scene, 'a wall of stacked crates');
        $this->placeActor($scene, 'a harbor enforcer');

        $this->assertSame(2, Meters::charges($campaign->character->fresh(), 'time_slow'));

        $turn = $this->refreshCards($campaign->fresh()->currentTurn);
        $card = $this->attachBargain($turn, 'main', 'break', Bargains::BURNING);
        $beat = $this->resolveCard($turn->fresh(), 'main', $card);

        $this->assertSame(1, Meters::charges($campaign->character->fresh(), 'time_slow'));
        $this->assertStringContainsString('burned a charge', implode(' ', $beat['facts']));
    }

    /**
     * Two beats a deal may never touch: improvise, which must never be better
     * than an enumerated option, and the quiet verbs, which cast no die there
     * would be anything to sweeten.
     */
    public function test_no_deal_is_offered_on_improvise_or_on_a_quiet_verb()
    {
        config(['game.bargains.chance' => 1.0]);

        $campaign = $this->createCampaign();
        $turn = $this->openBareTurn($campaign);
        $scene = $campaign->activeScene;
        $this->placeFeature($scene, 'a wall of stacked crates');
        $this->placeActor($scene, 'a harbor enforcer');

        // Everything a state could gate on is true at once, so nothing is
        // excluded for any reason but the verb itself.
        $character = $campaign->character->fresh();
        Hands::take($character, 'a coil of dock rope', null, 1);

        $state = Bargains::sceneState($character->fresh(), $scene->fresh(), []);

        foreach (['improvise', 'examine', 'wait', 'inspect', 'reposition', 'catch_breath', 'drop', 'bargain'] as $verb) {
            $card = new ActionCard(
                slot: TurnSlot::Main,
                verb: $verb,
                label: "A {$verb}",
                description: 'Whatever it is.',
                target: ['type' => 'feature', 'id' => $scene->features()->value('id'), 'name' => 'a wall of stacked crates'],
                capability: 'break',
            );

            $this->assertSame([], Bargains::keysFor($card, $state), "{$verb} must never be bargained");
        }

        // And the composer, run hard against the same ground, never lands one
        // on either family however the dice fall.
        for ($seed = 1; $seed <= 40; $seed++) {
            $cards = app(CardComposer::class)->compose($character->fresh(), $scene->fresh(), new Dice($seed));
            foreach ($this->bargainsIn($cards) as $deal) {
                $this->assertNotSame('improvise', $deal['verb']);
                $this->assertTrue(Odds::rolls($deal['verb']), "{$deal['verb']} casts no die");
            }
        }
    }

    /**
     * A complication that could not cost anything is a free lunch wearing a
     * warning label — and one of those teaches the player the whole mechanic is
     * a strictly better button.
     */
    public function test_a_price_that_could_not_be_charged_is_never_offered()
    {
        $campaign = $this->createCampaign();
        $this->openBareTurn($campaign);
        $scene = $campaign->activeScene;
        $crates = $this->placeFeature($scene, 'a wall of stacked crates');

        $target = ['type' => 'feature', 'id' => $crates->id, 'name' => $crates->name];
        $breakCard = new ActionCard(
            slot: TurnSlot::Main, verb: 'break', label: 'Break the crates',
            description: 'Force it apart.', target: $target, capability: 'break', risk: 'risky',
        );

        // Empty ground: nobody to hear the noise, nothing in either hand.
        $bare = Bargains::sceneState($campaign->character->fresh(), $scene->fresh(), []);
        $this->assertNotContains(Bargains::LOUD, Bargains::keysFor($breakCard, $bare));
        $this->assertNotContains(Bargains::TWO_HANDS, Bargains::keysFor($breakCard, $bare));

        // Someone to hear it, and something to drop.
        $this->placeActor($scene, 'a harbor enforcer');
        $character = $campaign->character->fresh();
        Hands::take($character, 'a coil of dock rope', null, 1);
        $loaded = Bargains::sceneState($character->fresh(), $scene->fresh(), []);
        $this->assertContains(Bargains::LOUD, Bargains::keysFor($breakCard, $loaded));
        $this->assertContains(Bargains::TWO_HANDS, Bargains::keysFor($breakCard, $loaded));

        // Being seen costs nothing when nothing is watching and there is no
        // cover in play; a hide card in the set-up slot puts it back on.
        $strike = new ActionCard(
            slot: TurnSlot::Main, verb: 'strike', label: 'Strike',
            description: 'Attack.', risk: 'risky',
            target: ['type' => 'actor', 'id' => $scene->actors()->value('id'), 'name' => 'a harbor enforcer'],
        );
        $this->assertNotContains(Bargains::RECKLESS, Bargains::keysFor($strike, $loaded));

        $hide = new ActionCard(slot: TurnSlot::Pre, verb: 'hide', label: 'Take cover',
            description: 'Cover.', capability: 'conceal');
        $covered = Bargains::sceneState($character->fresh(), $scene->fresh(), [$hide]);
        $this->assertContains(Bargains::RECKLESS, Bargains::keysFor($strike, $covered));

        // Provoking an enemy already coming at you changes nothing about them.
        $this->assertContains(Bargains::PROVOKING, Bargains::keysFor($strike, $covered));
        $scene->actors()->update(['tags' => json_encode(['intent' => 'press'])]);
        $pressing = Bargains::sceneState($character->fresh(), $scene->fresh(), []);
        $this->assertNotContains(Bargains::PROVOKING, Bargains::keysFor($strike, $pressing));
    }

    /**
     * A tempo spend the pools cannot honor is a card that promises a cost it
     * would never charge — which is the same thing as giving the edge away.
     */
    public function test_burning_is_never_offered_against_a_pool_that_cannot_pay()
    {
        $campaign = $this->createCampaign();
        $this->openBareTurn($campaign);
        $scene = $campaign->activeScene;
        $crates = $this->placeFeature($scene, 'a wall of stacked crates');
        $this->placeActor($scene, 'a harbor enforcer');

        $card = new ActionCard(
            slot: TurnSlot::Main, verb: 'break', label: 'Break the crates',
            description: 'Force it apart.', capability: 'break', risk: 'risky',
            target: ['type' => 'feature', 'id' => $crates->id, 'name' => $crates->name],
        );

        $character = $campaign->character->fresh();
        $this->assertSame('time_slow', Bargains::reserve($character));
        $this->assertContains(Bargains::BURNING, Bargains::keysFor($card, Bargains::sceneState($character, $scene, [])));

        // Drained: no reserve, no deal.
        $meters = $character->meters;
        $meters['tempo']['time_slow']['current'] = 0;
        $character->update(['meters' => $meters]);
        $character = $character->fresh();

        $this->assertNull(Bargains::reserve($character));
        $this->assertNotContains(Bargains::BURNING, Bargains::keysFor($card, Bargains::sceneState($character, $scene, [])));

        // Nor against a card no gift is powering.
        $bare = new ActionCard(slot: TurnSlot::Main, verb: 'strike', label: 'Strike',
            description: 'Attack.', risk: 'risky',
            target: ['type' => 'actor', 'id' => $scene->actors()->value('id'), 'name' => 'a harbor enforcer']);
        $meters['tempo']['time_slow']['current'] = 2;
        $campaign->character->update(['meters' => $meters]);
        $this->assertNotContains(
            Bargains::BURNING,
            Bargains::keysFor($bare, Bargains::sceneState($campaign->character->fresh(), $scene, [])),
        );
    }

    /** One deal per turn, the same one every time the same stream is read. */
    public function test_the_pass_is_capped_at_one_and_seeded_deterministic()
    {
        config(['game.bargains.chance' => 1.0]);

        $campaign = $this->createCampaign();
        $this->openBareTurn($campaign);
        $scene = $campaign->activeScene;

        // Ground thick with candidates: something to break, something to lift,
        // a way up, cover, and company to notice any of it.
        $this->placeFeature($scene, 'a wall of stacked crates');
        $this->placeFeature($scene, 'the harbor chain');
        $this->placeFeature($scene, 'the warehouse roof');
        $this->placeActor($scene, 'a harbor enforcer');
        $character = $campaign->character->fresh();
        Hands::take($character, 'a coil of dock rope', null, 1);

        $first = app(CardComposer::class)->compose($character->fresh(), $scene->fresh(), new Dice(7));
        $second = app(CardComposer::class)->compose($character->fresh(), $scene->fresh(), new Dice(7));

        $this->assertCount(1, $this->bargainsIn($first));
        $this->assertSame(
            array_column($this->bargainsIn($first), 'id'),
            array_column($this->bargainsIn($second), 'id'),
        );

        // A different stream is allowed a different deal, but never two.
        for ($seed = 1; $seed <= 30; $seed++) {
            $cards = app(CardComposer::class)->compose($character->fresh(), $scene->fresh(), new Dice($seed));
            $this->assertLessThanOrEqual(1, count($this->bargainsIn($cards)));
        }

        // Turned off, the whole pass is absent — this is seasoning, and a world
        // that never reaches for it is still a legal world.
        config(['game.bargains.chance' => 0.0]);
        $this->assertSame([], $this->bargainsIn(
            app(CardComposer::class)->compose($character->fresh(), $scene->fresh(), new Dice(7)),
        ));

        config(['game.bargains.chance' => 1.0, 'game.bargains.per_turn' => 0]);
        $this->assertSame([], $this->bargainsIn(
            app(CardComposer::class)->compose($character->fresh(), $scene->fresh(), new Dice(7)),
        ));
    }

    /**
     * A bargain is a card like any other: it is resolvable only because the
     * engine stored the offer, and both gates that enforce that must see it.
     */
    public function test_a_bargain_is_only_resolvable_when_the_engine_offered_it()
    {
        $campaign = $this->createCampaign();
        $this->openBareTurn($campaign);
        $scene = $campaign->activeScene;
        $this->placeFeature($scene, 'a wall of stacked crates');
        $this->placeActor($scene, 'a harbor enforcer');

        $this->mock(Narrator::class)->shouldReceive('narrate');
        $this->actingAs($campaign->user);

        $turn = $this->refreshCards($campaign->fresh()->currentTurn);

        // Built but never offered: the form refuses it, exactly as it refuses
        // any forged id.
        $unoffered = Bargains::offer(
            ActionCard::fromArray(collect($turn->cards['main'])->firstWhere('verb', 'break')),
            Bargains::LOUD,
        )->toArray($this->conditions($scene->fresh()));

        $this->from("/play/{$campaign->id}")
            ->post("/play/{$campaign->id}", ['main' => ['card_id' => $unoffered['id']]])
            ->assertSessionHasErrors('main');

        // The resolver keeps its own copy of the same rule: a submission
        // smuggled straight onto the turn resolves nothing.
        $turn->update([
            'status' => Turn::STATUS_LOCKED,
            'submission' => ['main' => ['card_id' => $unoffered['id'], 'modifiers' => []]],
            'submitted_at' => now(),
        ]);
        app(TurnResolver::class)->resolve($turn->fresh());
        $this->assertSame([], $turn->fresh()->resolution['beats']);

        // Stored, it plays: same id, same deal, and the price is paid.
        $turn = $this->refreshCards($campaign->fresh()->currentTurn);
        $offered = $this->attachBargain($turn, 'main', 'break', Bargains::LOUD);

        $this->post("/play/{$campaign->id}", ['main' => ['card_id' => $offered['id']]])
            ->assertRedirect("/play/{$campaign->id}");

        $beat = collect($turn->fresh()->resolution['beats'])->firstWhere('verb', 'break');
        $this->assertNotNull($beat);
        $this->assertStringContainsString('the noise carried', implode(' ', $beat['facts']));
    }
}
