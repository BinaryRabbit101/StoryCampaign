<?php

namespace Tests\Feature;

use App\Game\Engine\BeatOutcome;
use App\Game\Engine\CardComposer;
use App\Game\Engine\Clocks;
use App\Game\Engine\Dice;
use App\Game\Engine\SituationBoard;
use App\Game\Engine\TurnResolver;
use App\Game\Meters;
use App\Models\Actor;
use App\Models\Campaign;
use App\Models\Character;
use App\Models\Clock;
use App\Models\Memento;
use App\Models\Scene;
use App\Models\SceneFeature;
use App\Models\Turn;
use App\Models\User;
use App\Models\Zone;
use App\Services\Claude\ClaudeCli;
use App\Services\Claude\Narrator;
use App\Services\TurnStarter;
use Database\Seeders\WorldSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Endeavor clocks: the alarm clock, turned around.
 *
 * The load-bearing claims these hold down: the ENGINE authors the goal and
 * only offers one where the ground supports it; progress is mechanical (the
 * clock's own verb list, and a die that did not simply fail); the payoff is
 * the closed enum and nothing else; a goal about ground the tale has left dies
 * at the border; one endeavor at a time; and giving one up is free, lossy, and
 * never a dead choice.
 */
class ClockTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<string> */
    private array $prompts = [];

    private function createCampaign(): Campaign
    {
        $this->seed(WorldSeeder::class);

        $this->prompts = [];
        $this->mock(ClaudeCli::class, function ($mock) {
            $mock->shouldReceive('promptForJson')->andReturnUsing(function (string $prompt) {
                $this->prompts[] = $prompt;

                return [
                    'chapter' => 'They kept at it.',
                    'intent_line' => null,
                    'synopsis_line' => 'The work went on.',
                ];
            })->byDefault();
            $mock->shouldReceive('prompt')->andReturn('A tale begins.')->byDefault();
        });

        $campaign = Campaign::create([
            'user_id' => User::factory()->create()->id,
            'name' => 'Endeavor Tale',
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
        ])->capabilities()->createMany([
            ['capability' => 'scout', 'source' => 'creation'],
            ['capability' => 'break', 'source' => 'creation'],
        ]);

        // The offer is occasional in play; in a test the question is always
        // whether the GROUND qualifies, never whether the coin came up.
        config()->set('game.clocks.offer_chance', 1.0);

        return $campaign;
    }

    /** Ground the test owns outright: nothing on it but what the test puts there. */
    private function openBareTurn(Campaign $campaign): Turn
    {
        $turn = app(TurnStarter::class)->openFirstTurn($campaign);

        $scene = $campaign->activeScene;
        $scene->actors()->delete();
        $scene->features()->delete();
        Actor::whereNull('scene_id')->delete();

        return $turn->fresh();
    }

    private function ground(Scene $scene, string $name, array $affordances = [], array $state = []): SceneFeature
    {
        return SceneFeature::create([
            'scene_id' => $scene->id,
            'zone_id' => $scene->zone_id,
            'name' => $name,
            'feature_type' => 'landmark',
            'affordances' => $affordances,
            'state' => $state,
            'source' => 'seed',
        ]);
    }

    private function enemy(Scene $scene, string $name = 'a dockside tough'): Actor
    {
        return Actor::create([
            'scene_id' => $scene->id,
            'zone_id' => $scene->zone_id,
            'name' => $name,
            'kind' => 'enemy',
            'tier' => 'regular',
            'stats' => ['health' => ['current' => 6, 'max' => 6], 'attack' => 1],
            'tags' => ['intent' => 'press'],
            'status' => 'active',
            'source' => 'seed',
        ]);
    }

    /** The cards the engine would offer on this ground right now. */
    private function cards(Campaign $campaign, int $seed = 7): array
    {
        return app(CardComposer::class)->compose(
            $campaign->character->fresh(),
            $campaign->activeScene->fresh(),
            new Dice($seed),
        );
    }

    /** Re-offer the open turn from the ground as it stands, then resolve one verb off it. */
    private function play(Campaign $campaign, string $verb, string $slot = 'main'): Turn
    {
        $turn = $campaign->fresh()->currentTurn;
        $turn->update(['cards' => $this->cards($campaign, $turn->id + 3)]);

        $card = collect($turn->cards[$slot])->firstWhere('verb', $verb);
        $this->assertNotNull($card, "No {$verb} card was offered in the {$slot} slot.");

        $submission = [$slot => ['card_id' => $card['id'], 'modifiers' => []]];
        if ($slot !== 'main') {
            $main = collect($turn->cards['main'])->firstWhere('verb', 'wait');
            $submission['main'] = ['card_id' => $main['id'], 'modifiers' => []];
        }

        $turn->update([
            'status' => Turn::STATUS_LOCKED,
            'submission' => $submission,
            'submitted_at' => now(),
        ]);

        return app(TurnResolver::class)->resolve($turn->fresh());
    }

    private function clock(Campaign $campaign): ?Clock
    {
        return Clock::where('campaign_id', $campaign->id)->orderByDesc('id')->first();
    }

    /** One resolved beat, as the resolver would have handed it to the tick. */
    private function beat(string $verb, string $degree = BeatOutcome::SUCCESS, bool $skipped = false): BeatOutcome
    {
        return $skipped
            ? BeatOutcome::skipped('main', $verb, null, 'It never happened.')
            : new BeatOutcome('main', $verb, null, $degree, 14, 14, 10, ['It happened.'],
                note: 'I go at it with everything I have');
    }

    // ---- What the ground affords ----

    public function test_the_engine_offers_an_endeavor_only_where_the_ground_supports_one()
    {
        $campaign = $this->createCampaign();
        $this->openBareTurn($campaign);
        $scene = $campaign->activeScene;

        // Empty ground affords nothing to set yourself to.
        $this->assertNull(Clocks::propose($scene->fresh()));
        $this->assertNull(collect($this->cards($campaign)['main'])->firstWhere('verb', 'undertake'));

        // One hidden thing is a discovery, not an endeavor.
        $this->ground($scene, 'a loose board', [], ['hidden' => true]);
        $this->assertNull(Clocks::propose($scene->fresh()));

        // Two is a place worth going over end to end.
        $this->ground($scene, 'a smuggler’s hatch', [], ['hidden' => true]);
        $proposal = Clocks::propose($scene->fresh());
        $this->assertSame(Clocks::SEARCH, $proposal['kind']);
        $this->assertSame(Clocks::REVEAL_HIDDEN, $proposal['payoff']);
        $this->assertFalse($proposal['portable']);

        $card = collect($this->cards($campaign)['main'])->firstWhere('verb', 'undertake');
        $this->assertNotNull($card, 'hidden-rich ground offered no endeavor');
        $this->assertSame('do', $card['family']);
        $this->assertFalse($card['forecast']['rolls'], 'committing is a declaration, not a roll');
        $this->assertSame($proposal['name'], $card['target']['name']);
    }

    public function test_a_breakable_obstacle_and_a_fight_each_afford_their_own_endeavor()
    {
        $campaign = $this->createCampaign();
        $this->openBareTurn($campaign);
        $scene = $campaign->activeScene;

        $gate = $this->ground($scene, 'the barred gate', ['breakable' => true]);

        $proposal = Clocks::propose($scene->fresh());
        $this->assertSame(Clocks::DEMOLITION, $proposal['kind']);
        $this->assertSame(Clocks::DESTROY_OBSTACLE, $proposal['payoff']);
        $this->assertSame($gate->id, $proposal['subject']['id']);
        $this->assertContains('break', $proposal['advance_verbs']);

        // With the way already down, the fight is what is left to work at.
        $gate->update(['state' => ['destroyed' => true]]);
        $this->enemy($scene);

        $proposal = Clocks::propose($scene->fresh());
        $this->assertSame(Clocks::PREPARATION, $proposal['kind']);
        $this->assertSame(Clocks::GRANT_READIED, $proposal['payoff']);
        // What the body learned travels; ground-bound work does not.
        $this->assertTrue($proposal['portable']);
    }

    public function test_segment_counts_stay_inside_the_configured_band()
    {
        $campaign = $this->createCampaign();
        $this->openBareTurn($campaign);
        $scene = $campaign->activeScene;

        $this->ground($scene, 'a loose board', [], ['hidden' => true]);
        $this->ground($scene, 'a smuggler’s hatch', [], ['hidden' => true]);

        config()->set('game.clocks.min_segments', 6);
        $this->assertSame(6, Clocks::propose($scene->fresh())['segments']);

        config()->set('game.clocks.max_segments', 4);
        config()->set('game.clocks.min_segments', 4);
        $this->assertSame(4, Clocks::propose($scene->fresh())['segments']);
    }

    // ---- Committing ----

    public function test_committing_costs_a_beat_writes_the_clock_and_shuts_the_door_on_a_second()
    {
        $campaign = $this->createCampaign();
        $this->openBareTurn($campaign);
        $scene = $campaign->activeScene;

        $this->ground($scene, 'a loose board', [], ['hidden' => true]);
        $this->ground($scene, 'a smuggler’s hatch', [], ['hidden' => true]);

        $this->play($campaign, 'undertake');

        $clock = $this->clock($campaign);
        $this->assertNotNull($clock);
        $this->assertSame('open', $clock->status);
        $this->assertSame(0, $clock->filled);
        $this->assertSame($scene->id, $clock->scene_id);

        // One at a time: the ground can no longer offer a second, and neither
        // can any other ground while this one stands open.
        $this->assertFalse(Clocks::mayOffer($scene->fresh()));
        $this->assertNull(collect($this->cards($campaign)['main'])->firstWhere('verb', 'undertake'));
        $this->assertSame(
            1,
            Clock::where('campaign_id', $campaign->id)->count(),
        );

        // ...and the refusal holds even against a direct second commit.
        Clocks::commit($scene->fresh());
        $this->assertSame(1, Clock::where('campaign_id', $campaign->id)->count());
    }

    public function test_a_card_the_engine_never_offered_commits_nothing()
    {
        $campaign = $this->createCampaign();
        $turn = $this->openBareTurn($campaign);
        $scene = $campaign->activeScene;

        $this->ground($scene, 'a loose board', [], ['hidden' => true]);
        $this->ground($scene, 'a smuggler’s hatch', [], ['hidden' => true]);

        $turn->update([
            'status' => Turn::STATUS_LOCKED,
            'submission' => ['main' => ['card_id' => 'not-a-card-the-engine-wrote', 'modifiers' => []]],
            'submitted_at' => now(),
        ]);

        app(TurnResolver::class)->resolve($turn->fresh());

        $this->assertNull($this->clock($campaign));
    }

    public function test_a_scene_that_changed_under_the_offer_commits_nothing()
    {
        $campaign = $this->createCampaign();
        $this->openBareTurn($campaign);
        $scene = $campaign->activeScene;

        $kept = $this->ground($scene, 'a loose board', [], ['hidden' => true]);
        $this->ground($scene, 'a smuggler’s hatch', [], ['hidden' => true]);

        $turn = $campaign->fresh()->currentTurn;
        $turn->update(['cards' => $this->cards($campaign)]);
        $card = collect($turn->cards['main'])->firstWhere('verb', 'undertake');

        // The ground stops affording it between the offer and the commit.
        $kept->update(['state' => ['hidden' => false]]);

        $turn->update([
            'status' => Turn::STATUS_LOCKED,
            'submission' => ['main' => ['card_id' => $card['id'], 'modifiers' => []]],
            'submitted_at' => now(),
        ]);
        app(TurnResolver::class)->resolve($turn->fresh());

        $this->assertNull($this->clock($campaign));
    }

    // ---- Progress ----

    public function test_only_a_named_verb_that_did_not_fail_moves_it()
    {
        $campaign = $this->createCampaign();
        $this->openBareTurn($campaign);
        $scene = $campaign->activeScene;

        $this->ground($scene, 'a loose board', [], ['hidden' => true]);
        $this->ground($scene, 'a smuggler’s hatch', [], ['hidden' => true]);
        Clocks::commit($scene->fresh());

        $conditions = [];
        $fresh = fn () => $campaign->activeScene->fresh();

        // A verb the clock never named moves nothing, however well it went.
        Clocks::advance($fresh(), $this->beat('strike', BeatOutcome::STRONG), $conditions);
        $this->assertSame(0, $this->clock($campaign)->filled);

        // A named verb that simply failed moves nothing either.
        Clocks::advance($fresh(), $this->beat('scout', BeatOutcome::FAILURE), $conditions);
        $this->assertSame(0, $this->clock($campaign)->filled);

        // A beat that never happened moves nothing.
        Clocks::advance($fresh(), $this->beat('scout', skipped: true), $conditions);
        $this->assertSame(0, $this->clock($campaign)->filled);

        // A partial counts: this measures ground covered, not blows landed.
        Clocks::advance($fresh(), $this->beat('scout', BeatOutcome::PARTIAL), $conditions);
        $this->assertSame(1, $this->clock($campaign)->filled);

        Clocks::advance($fresh(), $this->beat('improvise', BeatOutcome::SUCCESS), $conditions);
        $this->assertSame(2, $this->clock($campaign)->filled);
    }

    public function test_a_beat_that_casts_no_die_never_buys_progress()
    {
        $campaign = $this->createCampaign();
        $this->openBareTurn($campaign);
        $scene = $campaign->activeScene;

        $this->ground($scene, 'a loose board', [], ['hidden' => true]);
        $this->ground($scene, 'a smuggler’s hatch', [], ['hidden' => true]);
        Clocks::commit($scene->fresh());

        // Force a quiet verb into the list the way a future table might, and
        // check the tick refuses it anyway: a quiet beat always "succeeds", so
        // counting one would make the whole endeavor free.
        $clock = $this->clock($campaign);
        $clock->update(['advance_verbs' => ['examine', 'scout']]);

        $conditions = [];
        Clocks::advance($campaign->activeScene->fresh(), $this->beat('examine'), $conditions);

        $this->assertSame(0, $clock->fresh()->filled);
    }

    public function test_the_card_quotes_the_endeavor_it_will_actually_move()
    {
        $campaign = $this->createCampaign();
        $this->openBareTurn($campaign);
        $scene = $campaign->activeScene;

        $this->ground($scene, 'a loose board', [], ['hidden' => true]);
        $this->ground($scene, 'a smuggler’s hatch', [], ['hidden' => true]);
        Clocks::commit($scene->fresh());

        $clock = $this->clock($campaign);
        $cards = $this->cards($campaign);
        $all = collect($cards['pre'])->concat($cards['main'])->concat($cards['post']);

        foreach ($all as $card) {
            $expected = ($card['forecast']['rolls'] && in_array($card['verb'], $clock->advance_verbs, true))
                ? $clock->name : null;

            $this->assertSame(
                $expected, $card['forecast']['endeavor'],
                "the '{$card['verb']}' card mis-quoted what it advances",
            );
        }

        // And at least one card is genuinely promising it, or the check above
        // would be asserting nothing at all.
        $this->assertTrue($all->contains(fn (array $c) => $c['forecast']['endeavor'] === $clock->name));
    }

    // ---- The payoff ----

    public function test_a_filled_search_turns_out_everything_the_place_was_keeping()
    {
        $campaign = $this->createCampaign();
        $this->openBareTurn($campaign);
        $scene = $campaign->activeScene;

        $first = $this->ground($scene, 'a loose board', [], ['hidden' => true]);
        $second = $this->ground($scene, 'a smuggler’s hatch', [], ['hidden' => true]);
        $gone = $this->ground($scene, 'a burnt crate', [], ['hidden' => true, 'destroyed' => true]);
        Clocks::commit($scene->fresh());

        $clock = $this->clock($campaign);
        $clock->update(['filled' => $clock->segments - 1]);

        $conditions = [];
        $result = Clocks::advance($campaign->activeScene->fresh(), $this->beat('scout'), $conditions);

        $this->assertSame($clock->name, $result['filled']);
        $this->assertSame('filled', $clock->fresh()->status);
        $this->assertFalse($first->fresh()->state['hidden']);
        $this->assertFalse($second->fresh()->state['hidden']);
        // Wreckage stays wreckage: the reveal is not a resurrection.
        $this->assertTrue($gone->fresh()->state['hidden']);
        // The one condition it may grant, it did not.
        $this->assertArrayNotHasKey('readied', $conditions);
    }

    public function test_a_filled_demolition_brings_down_exactly_what_it_named()
    {
        $campaign = $this->createCampaign();
        $this->openBareTurn($campaign);
        $scene = $campaign->activeScene;

        $gate = $this->ground($scene, 'the barred gate', ['breakable' => true]);
        $other = $this->ground($scene, 'a stack of crates', ['breakable' => true]);
        Clocks::commit($scene->fresh());

        $clock = $this->clock($campaign);
        $this->assertSame($gate->id, $clock->subject['id']);
        $clock->update(['filled' => $clock->segments - 1]);

        $conditions = [];
        Clocks::advance($campaign->activeScene->fresh(), $this->beat('break'), $conditions);

        $this->assertTrue($gate->fresh()->state['destroyed']);
        $this->assertFalse($other->fresh()->state['destroyed'] ?? false);
        $this->assertArrayNotHasKey('readied', $conditions);
    }

    public function test_a_filled_preparation_grants_the_condition_that_already_existed()
    {
        $campaign = $this->createCampaign();
        $this->openBareTurn($campaign);
        $scene = $campaign->activeScene;

        $this->enemy($scene);
        Clocks::commit($scene->fresh());

        $clock = $this->clock($campaign);
        $clock->update(['filled' => $clock->segments - 1]);

        $conditions = [];
        Clocks::advance($campaign->activeScene->fresh(), $this->beat('strike'), $conditions);

        // The existing Odds::CONDITIONS entry, and no parallel buff beside it.
        $this->assertSame(['readied' => true], $conditions);
        $this->assertSame('filled', $clock->fresh()->status);
    }

    public function test_a_full_clock_never_ticks_past_full()
    {
        $campaign = $this->createCampaign();
        $this->openBareTurn($campaign);
        $scene = $campaign->activeScene;

        $this->enemy($scene);
        Clocks::commit($scene->fresh());

        $clock = $this->clock($campaign);
        $clock->update(['filled' => $clock->segments - 1]);

        $conditions = [];
        Clocks::advance($campaign->activeScene->fresh(), $this->beat('strike'), $conditions);
        $second = Clocks::advance($campaign->activeScene->fresh(), $this->beat('strike'), $conditions);

        $this->assertNull($second['filled']);
        $this->assertSame([], $second['facts']);
        $this->assertSame($clock->segments, $clock->fresh()->filled);
    }

    // ---- Endings ----

    public function test_ground_bound_work_dies_at_the_border_and_what_the_body_learned_travels()
    {
        $campaign = $this->createCampaign();
        $this->openBareTurn($campaign);
        $scene = $campaign->activeScene;

        $next = Scene::create([
            'campaign_id' => $campaign->id,
            'zone_id' => $scene->zone_id,
            'title' => 'further along the quay',
            'description' => 'New ground.',
            'status' => 'active',
            'state' => ['dressed' => true],
        ]);

        $this->ground($scene, 'a loose board', [], ['hidden' => true]);
        $this->ground($scene, 'a smuggler’s hatch', [], ['hidden' => true]);
        Clocks::commit($scene->fresh());

        Clocks::onSceneExit($scene->fresh(), $next);
        $this->assertSame('expired', $this->clock($campaign)->status);
        $this->assertNull(Clocks::on($next->fresh()));
        // Expired frees the tale to take something else on.
        $this->assertNull(Clocks::openFor($campaign->fresh()));

        // The portable one comes along instead.
        $this->enemy($next);
        Clocks::commit($next->fresh());
        $carried = $this->clock($campaign);
        $this->assertTrue($carried->portable);

        $further = Scene::create([
            'campaign_id' => $campaign->id,
            'zone_id' => $scene->zone_id,
            'title' => 'the end of the quay',
            'description' => 'Newer ground.',
            'status' => 'active',
            'state' => ['dressed' => true],
        ]);
        Clocks::onSceneExit($next->fresh(), $further);

        $this->assertSame('open', $carried->fresh()->status);
        $this->assertSame($further->id, $carried->fresh()->scene_id);
    }

    public function test_a_transition_expires_the_endeavor_through_the_resolver_itself()
    {
        $campaign = $this->createCampaign();
        $this->openBareTurn($campaign);
        $scene = $campaign->activeScene;

        $this->ground($scene, 'a loose board', [], ['hidden' => true]);
        $this->ground($scene, 'a smuggler’s hatch', [], ['hidden' => true]);
        $this->ground($scene, 'a gap in the boards', ['flee_destination' => true, 'squeeze_required' => 'small']);

        $this->play($campaign, 'undertake');
        $this->assertSame('open', $this->clock($campaign)->status);

        // A way out that does not take on the first try is taken on the next:
        // this is about what the BORDER does, not about how the dice fell.
        for ($i = 0; $i < 12 && $campaign->fresh()->activeScene->id === $scene->id; $i++) {
            Meters::heal($campaign->character->fresh(), 20);
            $this->play($campaign, 'flee');
        }

        $this->assertNotSame($scene->id, $campaign->fresh()->activeScene->id, 'the tale never left the ground');
        $this->assertSame('expired', $this->clock($campaign)->status);
        $this->assertNull(Clocks::openFor($campaign->fresh()));
    }

    public function test_giving_it_up_is_free_lossy_and_opens_the_way_to_another()
    {
        $campaign = $this->createCampaign();
        $this->openBareTurn($campaign);
        $scene = $campaign->activeScene;

        $this->ground($scene, 'a loose board', [], ['hidden' => true]);
        $this->ground($scene, 'a smuggler’s hatch', [], ['hidden' => true]);
        Clocks::commit($scene->fresh());

        $conditions = [];
        Clocks::advance($scene->fresh(), $this->beat('scout'), $conditions);
        $this->assertSame(1, $this->clock($campaign)->filled);

        $this->play($campaign, 'abandon', slot: 'post');

        $clock = $this->clock($campaign);
        $this->assertSame('abandoned', $clock->status);
        // The progress is gone with it — that loss IS the commitment.
        $this->assertSame(1, $clock->filled);
        $this->assertNull(Clocks::openFor($campaign->fresh()));

        // ...and the way is open again for a different endeavor elsewhere.
        $elsewhere = Scene::create([
            'campaign_id' => $campaign->id,
            'zone_id' => $scene->zone_id,
            'title' => 'the far end',
            'description' => 'Other ground.',
            'status' => 'active',
            'state' => ['dressed' => true],
        ]);
        $this->enemy($elsewhere);
        $this->assertTrue(Clocks::mayOffer($elsewhere->fresh()));
    }

    // ---- What the player and the narrator are told ----

    public function test_the_board_carries_the_count_and_the_narrator_never_does()
    {
        $campaign = $this->createCampaign();
        $this->openBareTurn($campaign);
        $scene = $campaign->activeScene;

        $this->ground($scene, 'a loose board', [], ['hidden' => true]);
        $this->ground($scene, 'a smuggler’s hatch', [], ['hidden' => true]);
        Clocks::commit($scene->fresh());

        $conditions = [];
        Clocks::advance($scene->fresh(), $this->beat('scout'), $conditions);

        $board = SituationBoard::for($campaign->character->fresh(), $scene->fresh());
        $group = collect($board)->firstWhere('key', 'endeavor');

        $this->assertNotNull($group, 'the board said nothing about what the player is set on');
        $this->assertSame('neutral', $group['tone']);
        $this->assertStringContainsString('1 of 5', $group['items'][0]);

        // The narrator's copy of the same board carries no tally at all.
        $prose = SituationBoard::prose($board);
        $this->assertStringNotContainsString('1 of 5', $prose);
        $this->assertStringNotContainsString('What you are set on', $prose);
    }

    public function test_the_narrator_is_handed_a_goal_and_never_a_number()
    {
        $campaign = $this->createCampaign();
        $this->openBareTurn($campaign);
        $scene = $campaign->activeScene;

        $this->ground($scene, 'a loose board', [], ['hidden' => true]);
        $this->ground($scene, 'a smuggler’s hatch', [], ['hidden' => true]);

        $turn = $campaign->fresh()->currentTurn;
        $this->play($campaign, 'undertake');

        app(Narrator::class)->narrate($turn->fresh());

        $prompt = collect($this->prompts)->first(fn (string $p) => str_contains($p, 'You are the narrator'));
        $this->assertNotNull($prompt);
        $this->assertStringContainsString('partway through the search of', $prompt);
        $this->assertStringContainsString('Never count it, never number it', $prompt);
        $this->assertStringNotContainsString('0 of 5', $prompt);
        $this->assertStringNotContainsString('segment', $prompt);
    }

    public function test_an_ordinary_chapter_carries_no_instructions_about_an_endeavor()
    {
        $campaign = $this->createCampaign();
        $turn = $this->openBareTurn($campaign);

        $this->assertSame('', Clocks::narratorBlock($turn));
        $this->assertNull(Clocks::page($campaign));
        $this->assertNull(Clocks::boardLine($campaign->activeScene->fresh()));
    }

    // ---- The shelf ----

    public function test_seeing_an_endeavor_all_the_way_through_leaves_a_keepsake()
    {
        $campaign = $this->createCampaign();
        $this->openBareTurn($campaign);
        $scene = $campaign->activeScene;

        $this->ground($scene, 'the barred gate', ['breakable' => true]);
        $this->play($campaign, 'undertake');

        $clock = $this->clock($campaign);
        $this->assertNotNull($clock);

        // Every beat but the last, handed over directly — this test is about
        // what the FILL leaves behind, not about how many times the dice have
        // to be asked to get there.
        $clock->update(['filled' => $clock->segments - 1]);

        // ...and the last one goes through the resolver, where the shelf is
        // reached by the ordinary detection path and nothing else.
        for ($i = 0; $i < 12 && $clock->fresh()->status === 'open'; $i++) {
            Meters::heal($campaign->character->fresh(), 20);
            $this->play($campaign, 'break');
        }

        $this->assertSame('filled', $clock->fresh()->status);

        $memento = Memento::where('campaign_id', $campaign->id)
            ->where('trigger', 'endeavor_filled')->first();

        $this->assertNotNull($memento, 'the endeavor was finished and the tale kept nothing');
        $this->assertSame($clock->name, $memento->subject);
        $this->assertStringContainsString('gate', "{$memento->name} {$memento->line}");
    }

    public function test_the_finish_reaches_the_resolution_as_plain_facts()
    {
        $campaign = $this->createCampaign();
        $this->openBareTurn($campaign);
        $scene = $campaign->activeScene;

        $this->ground($scene, 'the barred gate', ['breakable' => true]);
        $this->play($campaign, 'undertake');

        $clock = $this->clock($campaign);
        $clock->update(['filled' => $clock->segments - 1]);

        $turn = null;
        for ($i = 0; $i < 12 && $clock->fresh()->status === 'open'; $i++) {
            Meters::heal($campaign->character->fresh(), 20);
            $played = $campaign->fresh()->currentTurn;
            $this->play($campaign, 'break');
            $turn = $played->fresh();
        }

        $this->assertSame('filled', $clock->fresh()->status);
        $this->assertNotNull($turn->resolution['endeavor']);

        foreach ($turn->resolution['endeavor'] as $fact) {
            $this->assertDoesNotMatchRegularExpression('/\d/', $fact);
            foreach (['segment', 'clock', 'roll', 'card', 'difficulty', 'meter'] as $word) {
                $this->assertStringNotContainsStringIgnoringCase($word, $fact);
            }
        }
    }

    public function test_an_endeavor_from_one_tale_never_reaches_another()
    {
        $campaign = $this->createCampaign();
        $this->openBareTurn($campaign);
        $scene = $campaign->activeScene;

        $this->enemy($scene);
        Clocks::commit($scene->fresh());

        $other = Campaign::create([
            'user_id' => User::factory()->create()->id,
            'name' => 'Another Tale',
            'world_flavor' => 'harbor-city',
            'status' => 'active',
            'started_at' => now(),
        ]);
        $zone = Zone::create([
            'campaign_id' => $other->id,
            'slug' => 'other-ground',
            'name' => 'Other Ground',
            'description' => 'Somewhere else entirely.',
            'source' => 'forge',
        ]);
        $elsewhere = Scene::create([
            'campaign_id' => $other->id,
            'zone_id' => $zone->id,
            'title' => 'the other quay',
            'description' => 'Another tale’s ground.',
            'status' => 'active',
            'state' => ['dressed' => true],
        ]);

        $this->assertNull(Clocks::openFor($other));
        $this->assertNull(Clocks::on($elsewhere));
        $this->assertTrue(Clocks::mayOffer($elsewhere));
    }
}
