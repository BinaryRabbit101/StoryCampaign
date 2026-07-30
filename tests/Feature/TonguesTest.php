<?php

namespace Tests\Feature;

use App\Game\Engine\Ambient;
use App\Game\Engine\CardComposer;
use App\Game\Engine\TurnResolver;
use App\Game\Meters;
use App\Models\Actor;
use App\Models\Campaign;
use App\Models\Character;
use App\Models\Turn;
use App\Models\User;
use App\Services\Claude\ClaudeCli;
use App\Services\TurnStarter;
use Database\Seeders\WorldSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Words carry the same weight as steel — no more, and no less.
 *
 * Talking somebody out of a fight used to be a strictly better strike: it
 * composed with no risk at all, deleted an enemy of any tier on a plain
 * success, and cost nothing when it missed. Three rules put it back beside the
 * swing. Inside a fight the trained tongues are `risky`, priced by the one
 * ladder like everything else. Who is standing there decides what a success
 * BUYS — only a regular can be talked off the field on a plain success, and
 * anything above one needs the perfect word. And a failure brings them on,
 * through the same intent tag the provoking bargain writes.
 *
 * Outside a fight none of this applies: talking to somebody who is not
 * fighting you was never a gamble. The untrained parley stays degraded,
 * bonusless, and consequence-free — a floor that also bites is a trap.
 */
class TonguesTest extends TestCase
{
    use RefreshDatabase;

    private function createCampaign(bool $trained = true): Campaign
    {
        $this->seed(WorldSeeder::class);

        $this->mock(ClaudeCli::class, function ($mock) {
            $mock->shouldReceive('promptForJson')->andThrow(new \RuntimeException('offline'))->byDefault();
            $mock->shouldReceive('prompt')->andReturn('A tale begins.')->byDefault();
        });

        $campaign = Campaign::create([
            'user_id' => User::factory()->create()->id,
            'name' => 'The Silver Tongue',
            'world_flavor' => 'harbor-city',
            'status' => 'active',
            'started_at' => now(),
        ]);

        $character = Character::create([
            'campaign_id' => $campaign->id,
            'name' => 'The Talker',
            'description' => 'Nobody in particular, which is the test.',
            'meters' => Meters::default(),
            'status' => 'alive',
            'meters_regenerated_at' => now(),
        ]);

        if ($trained) {
            $character->capabilities()->create(['capability' => 'persuade', 'source' => 'creation']);
        }

        return $campaign->fresh();
    }

    /** A turn on ground the test controls completely. */
    private function openBareTurn(Campaign $campaign): Turn
    {
        $turn = app(TurnStarter::class)->openFirstTurn($campaign);

        $this->clearGround($campaign);
        Actor::whereNull('scene_id')->delete();

        return $turn->fresh();
    }

    /** Nothing in the scene but what the test put there, and clear air over it. */
    private function clearGround(Campaign $campaign): void
    {
        $scene = $campaign->fresh()->activeScene;
        $scene->actors()->delete();
        $scene->features()->delete();
        $scene->update(['state' => array_merge($scene->state ?? [], ['ambient' => Ambient::CLEAR])]);
    }

    private function refreshCards(Turn $turn): Turn
    {
        $turn->update(['cards' => app(CardComposer::class)->compose(
            $turn->campaign->character->fresh(), $turn->scene->fresh(),
        )]);

        return $turn->fresh();
    }

    /**
     * @param  array<string,mixed>  $tags
     */
    private function makeActor(Campaign $campaign, string $kind, array $tags, string $tier = 'regular'): Actor
    {
        $scene = $campaign->fresh()->activeScene;

        return Actor::create([
            'scene_id' => $scene->id, 'zone_id' => $scene->zone_id,
            'name' => $kind === 'enemy' ? 'a wharf tough' : 'the lamp-oil seller',
            'kind' => $kind, 'tier' => $tier,
            'stats' => ['health' => ['current' => 6, 'max' => 6], 'attack' => 1],
            'tags' => $tags, 'status' => 'active', 'source' => 'seed',
        ]);
    }

    private function tongue(Turn $turn, string $verb, int $actorId): ?array
    {
        return collect($turn->cards['main'])->first(
            fn ($c) => $c['verb'] === $verb && ($c['target']['id'] ?? null) === $actorId,
        );
    }

    /** Submit one main beat and hand back the whole resolution. */
    private function resolve(Turn $turn, array $card): array
    {
        $turn->update([
            'status' => Turn::STATUS_LOCKED,
            'submission' => ['main' => [
                'card_id' => $card['id'], 'modifiers' => ['approach' => 'balanced'], 'note' => null,
            ]],
            'submitted_at' => now(),
        ]);

        app(TurnResolver::class)->resolve($turn->fresh());

        return $turn->fresh()->resolution;
    }

    /** Did this enemy actually swing this turn? */
    private function swungAt(array $resolution, string $name): bool
    {
        return collect($resolution['reaction_rolls'] ?? [])
            ->contains(fn (array $roll) => ($roll['actor'] ?? null) === $name && ($roll['verb'] ?? null) === 'attack');
    }

    // ------------------------------------------------------------------
    // The price of the words
    // ------------------------------------------------------------------

    /**
     * Mid-fight the tongue is a gamble the size of a swing, and the number the
     * card quotes is the number the dice measure it against — one ladder, read
     * twice, never copied.
     */
    public function test_a_trained_tongue_against_a_hostile_is_risky_and_the_dice_honour_the_forecast()
    {
        $campaign = $this->createCampaign();
        $turn = $this->openBareTurn($campaign);
        $enemy = $this->makeActor($campaign, 'enemy', ['talkable' => true, 'intent' => 'press']);

        $turn = $this->refreshCards($turn);
        $card = $this->tongue($turn, 'persuade', $enemy->id);

        $this->assertNotNull($card, 'a talkable hostile must still be worth talking to');
        $this->assertSame('persuade', $card['capability']);
        $this->assertSame('risky', $card['risk'], 'talking a fight down is a gamble, not a formality');

        // The card warns of the price before the turn commits.
        $this->assertStringContainsString('come at you', $card['description']);

        $resolution = $this->resolve($turn, $card);
        $beat = collect($resolution['beats'])->firstWhere('verb', 'persuade');

        $this->assertNotNull($beat);
        $this->assertSame($card['forecast']['difficulty'], $beat['difficulty'],
            'the card quoted a DC the dice did not honor');
    }

    /** Talking to somebody who is not fighting you was never a gamble. */
    public function test_the_same_verb_against_a_non_hostile_stays_safe()
    {
        $campaign = $this->createCampaign();
        $turn = $this->openBareTurn($campaign);
        $seller = $this->makeActor($campaign, 'npc', ['talkable' => true]);

        $turn = $this->refreshCards($turn);
        $card = $this->tongue($turn, 'persuade', $seller->id);

        $this->assertNotNull($card);
        $this->assertSame('safe', $card['risk']);
        $this->assertStringNotContainsString('come at you', $card['description']);
    }

    /** The card says the tier scope too, because a surprise is a surprise. */
    public function test_the_card_says_what_it_takes_to_move_somebody_tougher()
    {
        $campaign = $this->createCampaign();
        $turn = $this->openBareTurn($campaign);
        $elite = $this->makeActor($campaign, 'enemy', ['talkable' => true, 'intent' => 'press'], tier: 'elite');

        $turn = $this->refreshCards($turn);
        $card = $this->tongue($turn, 'persuade', $elite->id);

        $this->assertNotNull($card);
        $this->assertStringContainsString('perfect word', $card['description']);
    }

    // ------------------------------------------------------------------
    // What a success buys
    // ------------------------------------------------------------------

    /**
     * A regular can be talked off the field. An elite only leaves on the
     * perfect word — a plain success stays their hand for the beat and no
     * longer, and they are still standing there afterward.
     *
     * A long run of turns, because the die alone decides which rung comes up.
     */
    public function test_who_is_standing_there_decides_what_the_words_buy()
    {
        $campaign = $this->createCampaign();
        $this->openBareTurn($campaign);

        $seen = [];

        for ($i = 0; $i < 120; $i++) {
            $campaign = $campaign->fresh();
            $this->clearGround($campaign);
            $campaign->character->update(['meters' => Meters::default(), 'status' => 'alive']);

            $tier = $i % 2 === 0 ? 'regular' : 'elite';
            $enemy = $this->makeActor($campaign, 'enemy', ['talkable' => true, 'intent' => 'press'], tier: $tier);

            $turn = $this->refreshCards($campaign->fresh()->currentTurn);
            $card = $this->tongue($turn, 'persuade', $enemy->id);
            $this->assertNotNull($card);

            $resolution = $this->resolve($turn, $card);
            $beat = collect($resolution['beats'])->firstWhere('verb', 'persuade');
            $degree = $beat['degree'];
            $after = $enemy->fresh();

            if (in_array($degree, ['strong', 'success'], true)) {
                $this->assertSame('swayed', $after->tags['disposition'] ?? null,
                    'a landed word always leaves them differently disposed');
            }

            if ($tier === 'regular' && in_array($degree, ['strong', 'success'], true)) {
                $this->assertSame('fled', $after->status, 'a regular can be talked off the field');
                $this->assertSame('talked', $after->tags['fled_how'] ?? null,
                    'how they were moved has to travel with them to the grudge');
            } elseif ($tier === 'elite' && $degree === 'strong') {
                $this->assertSame('fled', $after->status, 'the perfect word still ends a fight');
                $this->assertSame('talked', $after->tags['fled_how'] ?? null);
            } elseif ($tier === 'elite' && $degree === 'success') {
                $this->assertSame('active', $after->status, 'the words landed; the will did not break');
                $this->assertArrayNotHasKey('fled_how', $after->tags ?? []);
                $this->assertFalse($this->swungAt($resolution, $enemy->name),
                    'a stayed hand is a hand that does not swing this turn');
                $this->assertContains(
                    "The words reached {$enemy->name}; the blade did not fall — but they did not leave.",
                    $beat['facts'],
                );
            } else {
                $this->assertNotSame('fled', $after->status, 'nothing short of a success moves anybody');
            }

            $seen["{$tier}:{$degree}"] = true;
        }

        foreach (['regular:success', 'elite:success', 'elite:strong'] as $rung) {
            $this->assertArrayHasKey($rung, $seen, "no {$rung} beat in 120 throws");
        }
    }

    // ------------------------------------------------------------------
    // What a failure costs
    // ------------------------------------------------------------------

    /**
     * You talked, they closed the distance. The failed tongue writes `press`
     * through the same tag the provoking bargain uses, so an enemy who was
     * behind their guard comes out from behind it in the same turn. A partial
     * buys nothing and costs nothing — they stay where they were.
     */
    public function test_a_failed_tongue_brings_a_hostile_on()
    {
        $campaign = $this->createCampaign();
        $this->openBareTurn($campaign);

        $seen = [];

        for ($i = 0; $i < 60 && count($seen) < 2; $i++) {
            $campaign = $campaign->fresh();
            $this->clearGround($campaign);
            $campaign->character->update(['meters' => Meters::default(), 'status' => 'alive']);

            // Behind a guard: left alone they spend the turn giving nothing
            // away, which is what makes the swing readable as the price.
            $enemy = $this->makeActor($campaign, 'enemy', ['talkable' => true, 'intent' => 'guard']);

            $turn = $this->refreshCards($campaign->fresh()->currentTurn);
            $card = $this->tongue($turn, 'persuade', $enemy->id);
            $this->assertNotNull($card);

            $resolution = $this->resolve($turn, $card);
            $beat = collect($resolution['beats'])->firstWhere('verb', 'persuade');

            if ($beat['degree'] === 'failure') {
                $this->assertContains(
                    "The words found no purchase on {$enemy->name} — and gave them the distance. {$enemy->name} comes on.",
                    $beat['facts'],
                );
                $this->assertTrue($this->swungAt($resolution, $enemy->name),
                    'the guard should have come off — that is the whole price');
                $seen['failure'] = true;
            } elseif ($beat['degree'] === 'partial') {
                $this->assertContains("The words found no purchase on {$enemy->name}.", $beat['facts']);
                $this->assertFalse($this->swungAt($resolution, $enemy->name),
                    'a partial buys nothing and costs nothing');
                $seen['partial'] = true;
            }
        }

        $this->assertArrayHasKey('failure', $seen, 'no failed tongue in 60 throws');
        $this->assertArrayHasKey('partial', $seen, 'no partial tongue in 60 throws');
    }

    /** Nobody who was not fighting starts fighting because a sentence landed badly. */
    public function test_a_failed_tongue_on_a_non_hostile_writes_nothing()
    {
        $campaign = $this->createCampaign();
        $this->openBareTurn($campaign);

        $failed = false;

        for ($i = 0; $i < 60 && ! $failed; $i++) {
            $campaign = $campaign->fresh();
            $this->clearGround($campaign);

            $seller = $this->makeActor($campaign, 'npc', ['talkable' => true]);

            $turn = $this->refreshCards($campaign->fresh()->currentTurn);
            $card = $this->tongue($turn, 'persuade', $seller->id);
            $this->assertNotNull($card);

            $resolution = $this->resolve($turn, $card);
            $beat = collect($resolution['beats'])->firstWhere('verb', 'persuade');

            if ($beat['degree'] !== 'failure') {
                continue;
            }

            $failed = true;
            $after = $seller->fresh();
            $this->assertArrayNotHasKey('intent', $after->tags ?? [],
                'a shopkeeper does not acquire a combat intent because a pitch fell flat');
            $this->assertSame('npc', $after->kind);
            $this->assertContains("The words found no purchase on {$seller->name}.", $beat['facts']);
        }

        $this->assertTrue($failed, 'no failed conversation in 60 throws');
    }

    /**
     * The floor stays a floor. Bare words are already degraded and bonusless;
     * hanging the price on them as well would make the only option a giftless
     * character has in a fight a strictly bad one.
     */
    public function test_the_untrained_parley_still_pays_no_price_on_a_failure()
    {
        $campaign = $this->createCampaign(trained: false);
        $this->openBareTurn($campaign);

        $failed = false;

        for ($i = 0; $i < 60 && ! $failed; $i++) {
            $campaign = $campaign->fresh();
            $this->clearGround($campaign);
            $campaign->character->update(['meters' => Meters::default(), 'status' => 'alive']);

            $enemy = $this->makeActor($campaign, 'enemy', ['talkable' => true, 'intent' => 'guard']);

            $turn = $this->refreshCards($campaign->fresh()->currentTurn);
            $card = $this->tongue($turn, 'calm', $enemy->id);
            $this->assertNotNull($card, 'a giftless character still gets one parley');
            $this->assertNull($card['capability']);
            $this->assertSame('degraded', $card['risk']);

            $resolution = $this->resolve($turn, $card);
            $beat = collect($resolution['beats'])->firstWhere('verb', 'calm');

            if ($beat['degree'] !== 'failure') {
                continue;
            }

            $failed = true;
            $this->assertContains("The words found no purchase on {$enemy->name}.", $beat['facts']);
            $this->assertFalse($this->swungAt($resolution, $enemy->name),
                'the floor must not also bite — the guard stays up');
        }

        $this->assertTrue($failed, 'no failed parley in 60 throws');
    }
}
