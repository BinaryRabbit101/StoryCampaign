<?php

namespace Tests\Feature;

use App\Game\Engine\CardComposer;
use App\Game\Engine\Downtime;
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
 * Downtime: the idle wait becomes a choice.
 *
 * Optional by construction (no pick is today's game exactly), engine-priced
 * from real elapsed minutes with a floor and a cap, and one real tradeoff per
 * stance. The engine decides every payout; narration only ever gets a plain
 * sentence about how the wait passed.
 */
class DowntimeTest extends TestCase
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
            'name' => 'Downtime Tale',
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

    /**
     * Open a turn that already carries the downtime offer the resolver writes
     * onto every turn it opens. The room is emptied first: these tests are
     * about the wait, and an enemy swinging back would move the same health
     * pool the payout is measured in.
     */
    private function openTurnWithOffer(Campaign $campaign): Turn
    {
        $turn = app(TurnStarter::class)->openFirstTurn($campaign);
        $campaign->activeScene->actors()->delete();

        return $this->reoffer($turn);
    }

    /** Re-write the offer after a test has changed what the scene holds. */
    private function reoffer(Turn $turn): Turn
    {
        $turn->update(['downtime' => Downtime::offer($turn->campaign->activeScene)]);

        return $turn->fresh();
    }

    /** Take a stance, as though the pick had been made this many minutes ago. */
    private function spendWait(Turn $turn, string $stance, int $minutesAgo): Turn
    {
        Downtime::choose($turn, $stance, now()->subMinutes($minutesAgo));

        return $turn->fresh();
    }

    /** Submit the turn's quietest card, so the resolution turns on nothing but the wait. */
    private function resolveQuietly(Turn $turn): Turn
    {
        $wait = collect($turn->cards['main'])->first(fn ($c) => $c['verb'] === 'wait')
            ?? collect($turn->cards['main'])->first();

        $turn->update([
            'status' => Turn::STATUS_LOCKED,
            'submission' => ['main' => ['card_id' => $wait['id'], 'modifiers' => []]],
            'submitted_at' => now(),
        ]);

        return app(TurnResolver::class)->resolve($turn->fresh());
    }

    private function hideFeature(Scene $scene, string $name): SceneFeature
    {
        return SceneFeature::create([
            'scene_id' => $scene->id,
            'zone_id' => $scene->zone_id,
            'name' => $name,
            'feature_type' => 'cover',
            'affordances' => ['concealment' => true],
            'state' => ['hidden' => true],
            'source' => 'seed',
        ]);
    }

    public function test_no_pick_leaves_the_turn_exactly_as_it_was()
    {
        $campaign = $this->createCampaign();
        $turn = $this->openTurnWithOffer($campaign);

        $character = $campaign->character;
        Meters::damage($character, 4);
        $before = $character->fresh()->meters['health']['current'];

        $next = $this->resolveQuietly($turn);
        $turn->refresh();

        // Resolution ran normally, and nothing about the wait touched it.
        $this->assertSame(Turn::STATUS_COMPLETE, $turn->status);
        $this->assertNull($turn->resolution['downtime']);
        $this->assertSame($before, $campaign->character->fresh()->meters['health']['current']);
        $this->assertFalse($turn->resolution['conditions']['readied']);
        $this->assertFalse($turn->downtime['applied']);
        $this->assertNull($turn->downtime['payout']);

        // ...and the wait ahead is offered on the turn the resolver opened.
        $this->assertNotEmpty($next->downtime['offer']);
        $this->assertNull($next->downtime['stance']);
    }

    public function test_the_floor_pays_nothing_and_the_cap_stops_the_clock()
    {
        $campaign = $this->createCampaign();

        // Five minutes is not a wait: no payout, and the stance is spent all
        // the same so a rapid re-submit cannot farm it.
        $short = $this->openTurnWithOffer($campaign);
        Meters::damage($campaign->character, 9);
        $short = $this->spendWait($short, Downtime::REST, 5);
        $this->resolveQuietly($short);
        $short->refresh();

        $this->assertFalse($short->downtime['payout']['granted']);
        $this->assertTrue($short->downtime['applied']);
        $this->assertNull($short->resolution['downtime']);
        $this->assertSame(1, $campaign->character->fresh()->meters['health']['current']);

        // A full day away pays exactly what eight hours pays — an idle game
        // must never reward staying gone.
        $healed = [];
        foreach ([8 * 60, 24 * 60] as $minutes) {
            $campaign = $this->createCampaign();
            $turn = $this->openTurnWithOffer($campaign);
            Meters::damage($campaign->character, 9);
            $turn = $this->spendWait($turn, Downtime::REST, $minutes);
            $this->resolveQuietly($turn);
            $healed[] = $turn->fresh()->downtime['payout']['healed'];
        }

        $this->assertSame(8, $healed[0]);
        $this->assertSame($healed[0], $healed[1]);
    }

    public function test_rest_heals_through_the_meters_and_respects_the_ceiling()
    {
        $campaign = $this->createCampaign();
        $turn = $this->openTurnWithOffer($campaign);

        // Barely scratched: the heal is clamped by the pool, not by the hours.
        Meters::damage($campaign->character, 2);
        $turn = $this->spendWait($turn, Downtime::REST, 8 * 60);
        $this->resolveQuietly($turn);
        $turn->refresh();

        $health = $campaign->character->fresh()->meters['health'];
        $this->assertSame($health['max'], $health['current']);
        $this->assertSame(2, $turn->downtime['payout']['healed']);
        $this->assertStringContainsString('woke steadier', $turn->resolution['downtime']);
    }

    public function test_rest_never_lifts_a_downed_character_off_the_floor()
    {
        $campaign = $this->createCampaign();
        $turn = $this->openTurnWithOffer($campaign);

        Meters::damage($campaign->character, 10);
        $this->assertSame('downed', $campaign->character->fresh()->status);

        $turn = $this->spendWait($turn, Downtime::REST, 8 * 60);
        $this->resolveQuietly($turn);
        $turn->refresh();

        $character = $campaign->character->fresh();
        $this->assertSame(0, $character->meters['health']['current']);
        $this->assertSame('downed', $character->status);
        $this->assertSame(0, $turn->downtime['payout']['healed']);
    }

    public function test_keeping_watch_exposes_arrivals_but_not_an_ambush_already_laid()
    {
        $campaign = $this->createCampaign();
        $turn = $this->openTurnWithOffer($campaign);
        $scene = $campaign->activeScene;

        // Someone was already hiding here before the wait began.
        $standing = Actor::create([
            'scene_id' => $scene->id,
            'zone_id' => $scene->zone_id,
            'name' => 'the patient one',
            'kind' => 'enemy',
            'tier' => 'regular',
            'stats' => ['health' => ['current' => 5, 'max' => 5], 'attack' => 1],
            'tags' => ['lurking' => true, 'lurking_since' => 99],
            'status' => 'active',
            'source' => 'seed',
        ]);

        $turn = $this->spendWait($turn, Downtime::WATCH, 60);

        $lurkingBefore = Downtime::lurkingIds($scene);
        $this->assertSame([$standing->id], $lurkingBefore);

        // ...and someone else slips in during it.
        $arrival = Actor::create([
            'scene_id' => $scene->id,
            'zone_id' => $scene->zone_id,
            'name' => 'the late arrival',
            'kind' => 'enemy',
            'tier' => 'regular',
            'stats' => ['health' => ['current' => 5, 'max' => 5], 'attack' => 1],
            'tags' => ['lurking' => true, 'lurking_since' => $turn->number],
            'status' => 'active',
            'source' => 'seed',
        ]);

        Downtime::revealNewArrivals($lurkingBefore, $scene);

        $this->assertArrayNotHasKey('lurking', $arrival->fresh()->tags);
        $this->assertTrue($standing->fresh()->tags['lurking']);

        // The resolution stamps the wait as spent and colours it for the page.
        $this->resolveQuietly($turn);
        $turn->refresh();
        $this->assertTrue($turn->downtime['payout']['watching']);
        $this->assertStringContainsString('watching the dark', $turn->resolution['downtime']);
    }

    public function test_tending_gear_grants_exactly_the_readied_condition()
    {
        $campaign = $this->createCampaign();
        $turn = $this->openTurnWithOffer($campaign);
        $turn = $this->spendWait($turn, Downtime::TEND, 60);

        $this->resolveQuietly($turn);
        $turn->refresh();

        // The engine's own condition — the one `ready` grants and Odds prices
        // — never a second buff living outside the ledger.
        $this->assertTrue($turn->resolution['conditions']['readied']);
        $this->assertTrue($turn->downtime['payout']['readied']);
        $this->assertSame(0, $campaign->character->fresh()->meters['health']['max']
            - $campaign->character->fresh()->meters['health']['current']);
    }

    public function test_walking_the_ground_reveals_one_hidden_thing_and_only_one()
    {
        $campaign = $this->createCampaign();
        $turn = $this->openTurnWithOffer($campaign);

        $scene = $campaign->activeScene;
        $scene->features()->delete();
        $first = $this->hideFeature($scene, 'a loose flagstone');
        $second = $this->hideFeature($scene, 'a boarded hatch');

        $turn = $this->reoffer($turn);
        $this->assertContains(Downtime::WALK, Downtime::offeredStances($turn));

        $turn = $this->spendWait($turn, Downtime::WALK, 60);
        $this->resolveQuietly($turn);
        $turn->refresh();

        $revealed = collect([$first, $second])
            ->filter(fn (SceneFeature $f) => ! ($f->fresh()->state['hidden'] ?? false));

        $this->assertCount(1, $revealed);
        $this->assertSame($revealed->first()->name, $turn->downtime['payout']['revealed']);
        $this->assertStringContainsString($revealed->first()->name, $turn->resolution['downtime']);
    }

    public function test_walking_the_ground_is_not_offered_when_nothing_is_hidden()
    {
        $campaign = $this->createCampaign();
        $this->openTurnWithOffer($campaign);

        $scene = $campaign->activeScene;
        $scene->features()->delete();

        $offered = array_column(Downtime::offer($scene->fresh())['offer'], 'id');

        $this->assertNotContains(Downtime::WALK, $offered);
        // The other three are always real choices.
        $this->assertSame([Downtime::REST, Downtime::WATCH, Downtime::TEND], $offered);
    }

    public function test_the_narrator_hears_a_plain_sentence_and_no_mechanics()
    {
        $campaign = $this->createCampaign();
        $turn = $this->openTurnWithOffer($campaign);
        Meters::damage($campaign->character, 5);
        $turn = $this->spendWait($turn, Downtime::REST, 4 * 60);

        $this->resolveQuietly($turn);
        $line = $turn->fresh()->resolution['downtime'];

        $this->assertIsString($line);
        $this->assertDoesNotMatchRegularExpression('/\d/', $line);
        foreach (['rest', 'watch', 'stance', 'health', 'downtime'] as $word) {
            $this->assertStringNotContainsStringIgnoringCase($word, $line);
        }
    }

    public function test_a_pick_is_recorded_only_once_and_only_from_the_offered_set()
    {
        $campaign = $this->createCampaign();
        $turn = $this->openTurnWithOffer($campaign);
        $user = $campaign->user;

        // Never a stance the engine did not offer.
        $this->actingAs($user)
            ->post("/play/{$campaign->id}/downtime", ['turn_id' => $turn->id, 'stance' => 'carouse'])
            ->assertSessionHasErrors('stance');
        $this->assertNull($turn->fresh()->downtime['stance']);

        // The honest pick lands.
        $this->actingAs($user)
            ->post("/play/{$campaign->id}/downtime", ['turn_id' => $turn->id, 'stance' => Downtime::REST])
            ->assertRedirect();
        $this->assertSame(Downtime::REST, $turn->fresh()->downtime['stance']);
        $chosenAt = $turn->fresh()->downtime['chosen_at'];

        // ...and it cannot be re-armed, which is how the clock would be reset.
        $this->actingAs($user)
            ->post("/play/{$campaign->id}/downtime", ['turn_id' => $turn->id, 'stance' => Downtime::TEND])
            ->assertStatus(409);
        $this->assertSame(Downtime::REST, $turn->fresh()->downtime['stance']);
        $this->assertSame($chosenAt, $turn->fresh()->downtime['chosen_at']);

        // Another player's tale is not theirs to spend.
        $this->actingAs(User::factory()->create())
            ->post("/play/{$campaign->id}/downtime", ['turn_id' => $turn->id, 'stance' => Downtime::WATCH])
            ->assertForbidden();
    }

    public function test_a_resolved_turn_no_longer_takes_a_pick()
    {
        $campaign = $this->createCampaign();
        $turn = $this->openTurnWithOffer($campaign);

        $next = $this->resolveQuietly($turn);

        $this->actingAs($campaign->user)
            ->post("/play/{$campaign->id}/downtime", ['turn_id' => $turn->id, 'stance' => Downtime::REST])
            ->assertStatus(409);
        $this->assertNull($turn->fresh()->downtime['stance']);

        // The wait ahead is the open turn's, and that one takes the pick.
        $this->actingAs($campaign->user)
            ->post("/play/{$campaign->id}/downtime", ['turn_id' => $next->id, 'stance' => Downtime::REST])
            ->assertRedirect();
        $this->assertSame(Downtime::REST, $next->fresh()->downtime['stance']);
    }

    public function test_the_play_page_carries_the_offer_and_the_widget_carries_the_choice()
    {
        $campaign = $this->createCampaign();
        $turn = $this->openTurnWithOffer($campaign);
        $turn->update(['cards' => app(CardComposer::class)->compose(
            $campaign->character->fresh(), $campaign->activeScene,
        )]);

        $this->actingAs($campaign->user)
            ->get("/play/{$campaign->id}")
            ->assertInertia(fn ($page) => $page
                ->where('turn.downtime.stance', null)
                ->where('turn.downtime.offer.0.id', Downtime::REST)
                ->has('turn.downtime.offer.0.terms'));

        Downtime::choose($turn->fresh(), Downtime::WATCH);

        $token = $campaign->user->ensureWidgetToken();
        $this->getJson("/api/widget/status?token={$token}")
            ->assertOk()
            ->assertJsonPath('downtime', 'Keeping watch.');
    }
}
