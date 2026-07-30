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
 * Binding wounds is an attempt, not a faucet.
 *
 * Two rules live here. The dressing holds as well as the roll went — three on a
 * strong beat down to nothing at all on a failure — and a turn tends the body
 * once, however many of its three positions the same card was taken in. ONE
 * LIST is what made the second rule necessary: the card stands in all three
 * picks, and without a guard a wounded character could bind the same wound
 * three times against a world that spends one or two a turn.
 */
class MendingTest extends TestCase
{
    use RefreshDatabase;

    /** How much a beat of this degree is worth to the body. */
    private const LADDER = ['strong' => 3, 'success' => 2, 'partial' => 1, 'failure' => 0];

    private function createCampaign(): Campaign
    {
        $this->seed(WorldSeeder::class);

        $this->mock(ClaudeCli::class, function ($mock) {
            $mock->shouldReceive('promptForJson')->andThrow(new \RuntimeException('offline'))->byDefault();
            $mock->shouldReceive('prompt')->andReturn('A tale begins.')->byDefault();
        });

        $campaign = Campaign::create([
            'user_id' => User::factory()->create()->id,
            'name' => 'The Bound Wound',
            'world_flavor' => 'harbor-city',
            'status' => 'active',
            'started_at' => now(),
        ]);

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

    /** Open the wound to a known depth, so every heal is readable as a delta. */
    private function wound(Campaign $campaign, int $health): void
    {
        $meters = Meters::default();
        $meters['health']['current'] = $health;

        $campaign->character->update(['meters' => $meters, 'status' => 'alive']);
    }

    private function bandage(Turn $turn, string $slot): ?array
    {
        return collect($turn->cards[$slot])->firstWhere('verb', 'bandage');
    }

    /**
     * @param  array<string, array{card_id: string}>  $submission
     */
    private function resolve(Turn $turn, array $submission): array
    {
        $turn->update([
            'status' => Turn::STATUS_LOCKED,
            'submission' => array_map(
                fn (array $card) => ['card_id' => $card['id'], 'modifiers' => ['approach' => 'balanced'], 'note' => null],
                $submission,
            ),
            'submitted_at' => now(),
        ]);

        app(TurnResolver::class)->resolve($turn->fresh());

        return collect($turn->fresh()->resolution['beats'])->where('verb', 'bandage')->values()->all();
    }

    /**
     * The heal rides the degree, and a failure finally means what it means
     * everywhere else in the game. A long run of turns, because the die is the
     * only thing that decides which rung comes up.
     */
    public function test_the_dressing_holds_as_well_as_the_roll_went()
    {
        $campaign = $this->createCampaign();
        $this->openBareTurn($campaign);

        $seen = [];

        for ($i = 0; $i < 90; $i++) {
            $campaign = $campaign->fresh();
            // Keep the run clean: nothing wanders in to reopen the wound
            // between the beat and the reading.
            $campaign->activeScene->actors()->delete();
            $this->wound($campaign, 4);

            $turn = $this->refreshCards($campaign->currentTurn);
            $card = $this->bandage($turn, 'main');
            $this->assertNotNull($card, 'a wounded body was offered no way to bind it');

            $beats = $this->resolve($turn, ['main' => $card]);
            $this->assertCount(1, $beats);
            $beat = $beats[0];

            $heal = self::LADDER[$beat['degree']];
            $this->assertSame(
                4 + $heal,
                $campaign->character->fresh()->meters['health']['current'],
                "a {$beat['degree']} dressing was worth the wrong amount",
            );

            $this->assertContains(
                $heal > 0
                    ? "They bound their wounds (+{$heal} health)."
                    : 'The dressing would not hold — the wound is as it was.',
                $beat['facts'],
            );

            $seen[$beat['degree']] = true;
        }

        foreach (array_keys(self::LADDER) as $degree) {
            $this->assertArrayHasKey($degree, $seen, "no {$degree} dressing in 90 throws");
        }
    }

    /**
     * Twice in one chain is a legal submission that resolves once and skips
     * once, with the reason said in plain words — the same shape as any other
     * legality re-check at resolution time.
     */
    public function test_a_turn_tends_the_body_once_however_many_positions_it_was_taken_in()
    {
        $campaign = $this->createCampaign();
        $turn = $this->openBareTurn($campaign);
        $this->wound($campaign, 4);
        $turn = $this->refreshCards($turn);

        $beats = $this->resolve($turn, [
            'pre' => $this->bandage($turn, 'pre'),
            'main' => $this->bandage($turn, 'main'),
        ]);

        $this->assertCount(2, $beats);

        $this->assertSame('pre', $beats[0]['slot']);
        $this->assertFalse($beats[0]['skipped'], 'the first tending should have gone through');

        $this->assertSame('main', $beats[1]['slot']);
        $this->assertTrue($beats[1]['skipped'], 'the second tending should not have been resolved');
        $this->assertContains('They have already seen to their wounds this turn.', $beats[1]['facts']);

        // And the body moved exactly once, by exactly what the first beat won.
        $this->assertSame(
            4 + self::LADDER[$beats[0]['degree']],
            $campaign->character->fresh()->meters['health']['current'],
        );
    }

    /**
     * The guard reads what the chain RESOLVED. A bandage that never reached the
     * dice — here because the card was priced against a pool that is dry —
     * leaves the turn's one tending unspent.
     */
    public function test_a_bandage_skipped_for_another_reason_does_not_burn_the_turns_one()
    {
        $campaign = $this->createCampaign();
        $turn = $this->openBareTurn($campaign);
        $this->wound($campaign, 4);
        $turn = $this->refreshCards($turn);

        // A price the character cannot pay, hung on the first of the two. The
        // composer would never write this; the skip path it forces is the same
        // one a spent pool takes in play.
        $cards = $turn->cards;
        foreach ($cards['pre'] as $index => $card) {
            if ($card['verb'] === 'bandage') {
                $cards['pre'][$index]['cost'] = [['meter' => 'time_slow', 'amount' => 1]];
            }
        }
        $turn->update(['cards' => $cards]);
        $turn = $turn->fresh();

        $beats = $this->resolve($turn, [
            'pre' => $this->bandage($turn, 'pre'),
            'main' => $this->bandage($turn, 'main'),
        ]);

        $this->assertCount(2, $beats);

        $this->assertTrue($beats[0]['skipped']);
        $this->assertContains('The time slow is spent dry.', $beats[0]['facts']);

        $this->assertFalse($beats[1]['skipped'], 'the turn had spent nothing on its wounds yet');
        $this->assertSame(
            4 + self::LADDER[$beats[1]['degree']],
            $campaign->character->fresh()->meters['health']['current'],
        );
    }

    /**
     * ONE LIST is untouched: position stays the player's information, and an
     * unwounded body is offered nothing to bind.
     */
    public function test_the_card_stands_in_every_position_while_wounded_and_nowhere_at_full_health()
    {
        $campaign = $this->createCampaign();
        $turn = $this->openBareTurn($campaign);

        $this->wound($campaign, 4);
        $turn = $this->refreshCards($turn);
        foreach (['pre', 'main', 'post'] as $slot) {
            $this->assertNotNull($this->bandage($turn, $slot), "no way to bind wounds in {$slot}");
        }

        $this->wound($campaign, 10);
        $turn = $this->refreshCards($turn);
        foreach (['pre', 'main', 'post'] as $slot) {
            $this->assertNull($this->bandage($turn, $slot), "an unhurt body was offered a dressing in {$slot}");
        }
    }
}
