<?php

namespace Tests\Feature;

use App\Game\Engine\BeatOutcome;
use App\Game\Engine\CardComposer;
use App\Game\Engine\Companions;
use App\Game\Engine\Dice;
use App\Game\Engine\SituationBoard;
use App\Game\Engine\Threads;
use App\Game\Meters;
use App\Models\Actor;
use App\Models\Campaign;
use App\Models\Character;
use App\Models\Rumor;
use App\Models\Scene;
use App\Models\SceneFeature;
use App\Models\Thread;
use App\Models\Turn;
use App\Models\User;
use App\Models\Zone;
use App\Services\Claude\ClaudeCli;
use App\Services\Claude\Narrator;
use App\Services\TurnStarter;
use Database\Seeders\WorldSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Side threads: someone else's small story.
 *
 * The load-bearing claims these hold down: a want attaches only to a
 * non-hostile soul the ground can actually support one on, one at a time; it is
 * DORMANT until the player discovers it, and nothing about it — board, card,
 * prompt — exists before that; help is the kind's own advance class and nothing
 * else; every payoff routes through machinery that already existed, and the
 * companionship one respects the party cap and the consensual pair; and
 * neglect ends it badly for THEM at no cost whatsoever to the player.
 */
class ThreadTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<string> */
    private array $prompts = [];

    private function createCampaign(): Campaign
    {
        $this->seed(WorldSeeder::class);
        Notification::fake();

        $this->prompts = [];
        $this->mock(ClaudeCli::class, function ($mock) {
            $mock->shouldReceive('promptForJson')->andReturnUsing(function (string $prompt) {
                $this->prompts[] = $prompt;

                return [
                    'chapter' => 'They walked on.',
                    'intent_line' => null,
                    'synopsis_line' => 'Someone was passed on the road.',
                ];
            })->byDefault();
            $mock->shouldReceive('prompt')->andReturn('A tale begins.')->byDefault();
        });

        $campaign = Campaign::create([
            'user_id' => User::factory()->create()->id,
            'name' => 'Someone Else’s Tale',
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

        // In play the question is whether the coin came up; in a test it is
        // always whether the GROUND and the SOUL qualify.
        config()->set('game.threads.offer_chance', 1.0);

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
        Thread::query()->delete();

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

    private function soul(Scene $scene, string $name = 'Aldan', string $kind = 'npc', array $tags = ['talkable' => true]): Actor
    {
        return Actor::create([
            'scene_id' => $scene->id,
            'zone_id' => $scene->zone_id,
            'name' => $name,
            'kind' => $kind,
            'tier' => 'regular',
            'stats' => ['health' => ['current' => 4, 'max' => 4], 'attack' => 1],
            'tags' => $tags,
            'status' => 'active',
            'source' => 'seed',
        ]);
    }

    /** New ground for the tale to cross onto. */
    private function nextGround(Campaign $campaign, string $title = 'The Far Lane'): Scene
    {
        return Scene::create([
            'campaign_id' => $campaign->id,
            'zone_id' => $campaign->activeScene->zone_id,
            'title' => $title,
            'description' => 'Ground further along.',
            'status' => 'active',
            'state' => ['dressed' => true],
        ]);
    }

    /** A want the test hangs on somebody directly, at whatever stage it needs. */
    private function want(Campaign $campaign, Actor $actor, string $kind, array $overrides = []): Thread
    {
        return Thread::create(array_merge([
            'campaign_id' => $campaign->id,
            'actor_id' => $actor->id,
            'actor_name' => $actor->name,
            'kind' => $kind,
            'segments' => 2,
            'filled' => 0,
            'age' => 0,
            'revealed' => false,
            'subject' => null,
            'status' => 'open',
            'history' => [],
        ], $overrides));
    }

    /** One resolved beat, as the resolver would have handed it along. */
    private function beat(string $verb, ?array $target = null, string $degree = BeatOutcome::SUCCESS, bool $skipped = false): BeatOutcome
    {
        return $skipped
            ? BeatOutcome::skipped('main', $verb, $target, 'It never happened.')
            : new BeatOutcome('main', $verb, $target, $degree, 14, 14, 10, ['It happened.'],
                note: 'I ask them what they are doing out here');
    }

    private function actorTarget(Actor $actor): array
    {
        return ['type' => 'actor', 'id' => $actor->id, 'name' => $actor->name];
    }

    private function featureTarget(SceneFeature $feature): array
    {
        return ['type' => 'feature', 'id' => $feature->id, 'name' => $feature->name];
    }

    /** The cards the engine would offer on this ground right now. */
    private function cards(Campaign $campaign, ?Scene $scene = null): array
    {
        return app(CardComposer::class)->compose(
            $campaign->character->fresh(),
            ($scene ?? $campaign->activeScene)->fresh(),
            new Dice(11),
        );
    }

    /** Run one turn's worth of thread detection over beats the test fixes itself. */
    private function tick(Campaign $campaign, array $outcomes, ?Scene $scene = null, ?array $before = null): array
    {
        $scene = ($scene ?? $campaign->activeScene)->fresh();

        return Threads::resolveTurn(
            $scene,
            $campaign->fresh()->currentTurn,
            $outcomes,
            $before ?? Threads::snapshot($scene),
        );
    }

    // ---- Attaching ----

    public function test_a_want_attaches_only_to_a_soul_and_a_ground_that_can_carry_one()
    {
        $campaign = $this->createCampaign();
        $this->openBareTurn($campaign);
        $scene = $campaign->activeScene;

        // Bare ground affords no want at all: nothing hidden, nothing broken,
        // and no road out of the country yet.
        $this->assertNull(Threads::attach($this->soul($scene, 'a lamplighter'), $scene->fresh()));
        $this->assertSame(0, Thread::count());

        $this->ground($scene, 'a smuggler’s hatch', [], ['hidden' => true]);

        // Never a hostile, and never someone already walking beside them.
        $this->assertNull(Threads::attach($this->soul($scene, 'a dockside tough', 'enemy', []), $scene->fresh()));
        $this->assertNull(Threads::attach($this->soul($scene, 'the old hand', 'companion', []), $scene->fresh()));

        // Never somebody the world is already using for something else.
        $stray = $this->soul($scene, 'a quiet one', 'npc', ['following' => true]);
        $this->assertNull(Threads::attach($stray, $scene->fresh()));

        $this->assertSame(0, Thread::count());

        $aldan = $this->soul($scene, 'Aldan');
        $thread = Threads::attach($aldan, $scene->fresh());

        $this->assertNotNull($thread, 'a plain bystander on hidden-rich ground carried no want');
        $this->assertSame(Threads::SEEKING, $thread->kind);
        $this->assertSame($aldan->id, $thread->actor_id);
        $this->assertSame('Aldan', $thread->actor_name);
        $this->assertFalse($thread->revealed);
        $this->assertSame(0, (int) $thread->filled);
        $this->assertGreaterThanOrEqual(2, (int) $thread->segments);
        $this->assertLessThanOrEqual(3, (int) $thread->segments);
    }

    public function test_the_ground_decides_which_want_and_the_offer_is_silent_while_one_is_running()
    {
        $campaign = $this->createCampaign();
        $this->openBareTurn($campaign);
        $scene = $campaign->activeScene;

        // Something standing and breakable is a mending, and it names the thing.
        $gate = $this->ground($scene, 'the barred gate', ['breakable' => true]);
        $mender = Threads::attach($this->soul($scene, 'Sera'), $scene->fresh());

        $this->assertSame(Threads::MENDING, $mender->kind);
        $this->assertSame($gate->id, $mender->subject['id']);
        $this->assertGreaterThanOrEqual(3, (int) $mender->segments);
        $this->assertLessThanOrEqual(4, (int) $mender->segments);

        // One at a time: while that one is open, the next soul carries nothing.
        $this->assertNull(Threads::attach($this->soul($scene, 'Kell'), $scene->fresh()));
        $this->assertSame(1, Thread::where('campaign_id', $campaign->id)->count());

        // With it closed out, the road opens once the world has forged one.
        $mender->update(['status' => 'expired']);
        $gate->update(['state' => ['destroyed' => true]]);

        $frontier = Zone::create([
            'campaign_id' => $campaign->id,
            'slug' => 'the-far-shelf-'.$campaign->id,
            'name' => 'The Far Shelf',
            'description' => 'Country nobody has walked yet.',
            'source' => 'forge',
        ]);
        $campaign->update(['next_zone_id' => $frontier->id]);

        $walker = Threads::attach($this->soul($scene, 'Roan'), $scene->fresh());
        $this->assertSame(Threads::ROAD, $walker->kind);
    }

    public function test_the_attach_roll_is_seeded_off_the_soul_and_replays_identically()
    {
        $campaign = $this->createCampaign();
        $this->openBareTurn($campaign);
        $scene = $campaign->activeScene;
        $this->ground($scene, 'the barred gate', ['breakable' => true]);

        $aldan = $this->soul($scene, 'Aldan');
        $first = Threads::attach($aldan, $scene->fresh());
        $segments = (int) $first->segments;

        // The same soul, the same ground, the same want — the row is thrown
        // away and re-rolled, and it comes back the same.
        $first->forceDelete();
        $second = Threads::attach($aldan->fresh(), $scene->fresh());

        $this->assertSame(Threads::MENDING, $second->kind);
        $this->assertSame($segments, (int) $second->segments);

        // And a coin that never comes up writes nothing at all.
        $second->forceDelete();
        config()->set('game.threads.offer_chance', 0.0);
        $this->assertNull(Threads::attach($aldan->fresh(), $scene->fresh()));
    }

    // ---- Dormancy ----

    public function test_an_undiscovered_want_reaches_no_board_no_card_and_no_prompt()
    {
        $campaign = $this->createCampaign();
        $turn = $this->openBareTurn($campaign);
        $scene = $campaign->activeScene;
        $this->ground($scene, 'a smuggler’s hatch', [], ['hidden' => true]);

        $aldan = $this->soul($scene, 'Aldan');
        $thread = Threads::attach($aldan, $scene->fresh());
        $this->assertNotNull($thread);
        $this->assertFalse($thread->revealed);

        // Nothing player-facing knows about it.
        $this->assertNull(Threads::forecast($scene->fresh()));
        $this->assertNull(Threads::boardLine($scene->fresh()));
        $this->assertNull(Threads::shownOn($scene->fresh()));

        $board = SituationBoard::for($campaign->character->fresh(), $scene->fresh());
        $this->assertNotContains('thread', collect($board)->pluck('key')->all());
        $this->assertStringNotContainsString('Aldan’s search', SituationBoard::prose($board));

        foreach (['pre', 'main', 'post'] as $slot) {
            foreach ($this->cards($campaign)[$slot] as $card) {
                $this->assertNull($card['forecast']['thread'],
                    "a dormant want was quoted on a {$card['verb']} card");
            }
        }

        // And the narrator is never told a word of it.
        $turn->update(['resolution' => ['beats' => [], 'thread' => null]]);
        $this->assertSame('', Threads::narratorBlock($turn->fresh()));
    }

    public function test_the_discovery_beat_fires_once_and_opens_everything_that_was_shut()
    {
        $campaign = $this->createCampaign();
        $this->openBareTurn($campaign);
        $scene = $campaign->activeScene;
        $this->ground($scene, 'a smuggler’s hatch', [], ['hidden' => true]);

        $aldan = $this->soul($scene, 'Aldan');
        $thread = Threads::attach($aldan, $scene->fresh());

        // A beat aimed at the ground rather than the person tells them nothing.
        $this->assertSame([], $this->tick($campaign, [$this->beat('examine')]));
        $this->assertFalse($thread->fresh()->revealed);

        // Nor does a conversation that went badly.
        $this->assertSame([], $this->tick($campaign,
            [$this->beat('speak', $this->actorTarget($aldan), BeatOutcome::FAILURE)]));
        $this->assertFalse($thread->fresh()->revealed);

        $facts = $this->tick($campaign, [$this->beat('speak', $this->actorTarget($aldan), BeatOutcome::PARTIAL)]);

        $this->assertCount(1, $facts);
        $this->assertStringContainsString('Aldan', $facts[0]);
        $this->assertTrue($thread->fresh()->revealed);
        // Hearing what somebody needs is not the same as helping with it.
        $this->assertSame(0, (int) $thread->fresh()->filled);

        // It never fires twice.
        $again = $this->tick($campaign, [$this->beat('speak', $this->actorTarget($aldan))]);
        $this->assertNotContains($facts[0], $again);

        // And now it is on the board, and on the cards that would help.
        $this->assertSame('Aldan’s search — 0 of '.$thread->fresh()->segments, Threads::boardLine($scene->fresh()));
        $board = SituationBoard::for($campaign->character->fresh(), $scene->fresh());
        $this->assertContains('thread', collect($board)->pluck('key')->all());
        // A count is the board's business and never the chapter's.
        $this->assertStringNotContainsString('Aldan’s search', SituationBoard::prose($board));
    }

    // ---- Helping ----

    public function test_only_the_kind_s_own_advance_class_moves_a_want()
    {
        $campaign = $this->createCampaign();
        $this->openBareTurn($campaign);
        $scene = $campaign->activeScene;
        $kept = $this->ground($scene, 'a smuggler’s hatch', [], ['hidden' => true]);

        $aldan = $this->soul($scene, 'Aldan');
        $thread = $this->want($campaign, $aldan, Threads::SEEKING, ['revealed' => true, 'segments' => 3]);

        $before = Threads::snapshot($scene->fresh());

        // A verb outside the class turns nothing up and moves nothing.
        $this->tick($campaign, [$this->beat('strike')], before: $before);
        $this->assertSame(0, (int) $thread->fresh()->filled);

        // The right verb that failed moves nothing either.
        $this->tick($campaign, [$this->beat('scout', null, BeatOutcome::FAILURE)], before: $before);
        $this->assertSame(0, (int) $thread->fresh()->filled);

        // The right verb, skipped, moves nothing.
        $this->tick($campaign, [$this->beat('scout', null, skipped: true)], before: $before);
        $this->assertSame(0, (int) $thread->fresh()->filled);

        // The right verb that turned nothing up moves nothing: the gate on a
        // search is the exposure, not the die.
        $this->tick($campaign, [$this->beat('examine')], before: $before);
        $this->assertSame(0, (int) $thread->fresh()->filled);

        // The right verb, and something genuinely dragged into the light.
        $kept->update(['state' => ['hidden' => false]]);
        $this->tick($campaign, [$this->beat('examine')], before: $before);
        $this->assertSame(1, (int) $thread->fresh()->filled);
    }

    public function test_a_mending_only_counts_work_done_on_the_thing_it_named()
    {
        $campaign = $this->createCampaign();
        $this->openBareTurn($campaign);
        $scene = $campaign->activeScene;

        $gate = $this->ground($scene, 'the barred gate', ['breakable' => true]);
        $other = $this->ground($scene, 'a crate', ['breakable' => true]);

        $sera = $this->soul($scene, 'Sera');
        $thread = $this->want($campaign, $sera, Threads::MENDING, [
            'revealed' => true,
            'segments' => 3,
            'subject' => ['type' => 'feature', 'id' => $gate->id, 'name' => $gate->name],
        ]);

        // The same verb, aimed somewhere else, is not their work.
        $this->tick($campaign, [$this->beat('break', $this->featureTarget($other))]);
        $this->assertSame(0, (int) $thread->fresh()->filled);

        // The named thing, and a beat that did not simply fail.
        $this->tick($campaign, [$this->beat('break', $this->featureTarget($gate), BeatOutcome::PARTIAL)]);
        $this->assertSame(1, (int) $thread->fresh()->filled);

        // At most one step a chapter, however many qualifying beats there were.
        $this->tick($campaign, [
            $this->beat('break', $this->featureTarget($gate)),
            $this->beat('lift', $this->featureTarget($gate)),
        ]);
        $this->assertSame(2, (int) $thread->fresh()->filled);
    }

    public function test_the_cards_quote_the_want_they_would_help_and_only_those_cards()
    {
        $campaign = $this->createCampaign();
        $this->openBareTurn($campaign);
        $scene = $campaign->activeScene;

        $gate = $this->ground($scene, 'the barred gate', ['breakable' => true]);
        $sera = $this->soul($scene, 'Sera');
        $this->want($campaign, $sera, Threads::MENDING, [
            'revealed' => true,
            'segments' => 3,
            'subject' => ['type' => 'feature', 'id' => $gate->id, 'name' => $gate->name],
        ]);

        $forecast = Threads::forecast($scene->fresh());
        $this->assertSame('Sera’s mending', $forecast['name']);
        $this->assertSame($gate->id, $forecast['target_id']);

        $quoted = [];
        foreach (['pre', 'main', 'post'] as $slot) {
            foreach ($this->cards($campaign)[$slot] as $card) {
                if ($card['forecast']['thread'] !== null) {
                    $this->assertSame('Sera’s mending', $card['forecast']['thread']);
                    $this->assertSame($gate->id, $card['target']['id'],
                        'a card promised the mending while aimed at something else');
                    $quoted[] = $card['verb'];
                }
            }
        }

        $this->assertContains('break', $quoted, 'the card that would do the work promised nothing');

        // The promise is a promise and nothing else: it never becomes an odds
        // part, on either side of the ledger.
        foreach ($this->cards($campaign)['main'] as $card) {
            foreach (array_merge($card['forecast']['parts'], $card['forecast']['bonus_parts']) as $part) {
                $this->assertStringNotContainsString('Sera', $part['label']);
                $this->assertStringNotContainsString('mending', $part['label']);
            }
        }
    }

    // ---- Payoffs ----

    public function test_a_search_seen_through_gives_up_what_the_place_was_keeping_back()
    {
        $campaign = $this->createCampaign();
        $this->openBareTurn($campaign);
        $scene = $campaign->activeScene;

        $first = $this->ground($scene, 'a loose board', [], ['hidden' => true]);
        $kept = $this->ground($scene, 'a smuggler’s hatch', [], ['hidden' => true]);

        $aldan = $this->soul($scene, 'Aldan');
        $thread = $this->want($campaign, $aldan, Threads::SEEKING, [
            'revealed' => true, 'segments' => 1, 'filled' => 0,
        ]);

        $before = Threads::snapshot($scene->fresh());
        $first->update(['state' => ['hidden' => false]]);

        $facts = $this->tick($campaign, [$this->beat('scout')], before: $before);

        $this->assertSame('filled', $thread->fresh()->status);
        $this->assertCount(1, $facts);
        $this->assertStringContainsString('Aldan', $facts[0]);
        // The sanctioned reveal, applied by the engine and nobody else.
        $this->assertFalse($kept->fresh()->state['hidden']);
    }

    public function test_a_search_over_ground_with_nothing_left_pays_the_way_out_instead()
    {
        $campaign = $this->createCampaign();
        $this->openBareTurn($campaign);
        $scene = $campaign->activeScene;
        $kept = $this->ground($scene, 'a loose board', [], ['hidden' => true]);

        $aldan = $this->soul($scene, 'Aldan');
        $this->want($campaign, $aldan, Threads::SEEKING, ['revealed' => true, 'segments' => 1]);

        $before = Threads::snapshot($scene->fresh());
        $kept->update(['state' => ['hidden' => false]]);

        $this->tick($campaign, [$this->beat('scout')], before: $before);

        $this->assertTrue($scene->fresh()->state['exit_scouted']);
        $this->assertNotNull(
            collect($this->cards($campaign)['main'])->firstWhere('verb', 'flee'),
            'the scouted way out never reached the cards',
        );
    }

    public function test_a_mending_seen_through_puts_one_traceable_tale_on_the_queue()
    {
        $campaign = $this->createCampaign();
        $this->openBareTurn($campaign);
        $scene = $campaign->activeScene;
        $gate = $this->ground($scene, 'the barred gate', ['breakable' => true]);

        $sera = $this->soul($scene, 'Sera');
        $thread = $this->want($campaign, $sera, Threads::MENDING, [
            'revealed' => true,
            'segments' => 1,
            'subject' => ['type' => 'feature', 'id' => $gate->id, 'name' => $gate->name],
        ]);

        $facts = $this->tick($campaign, [$this->beat('break', $this->featureTarget($gate))]);

        $this->assertSame('filled', $thread->fresh()->status);
        $this->assertCount(1, $facts);

        $rumor = Rumor::where('campaign_id', $campaign->id)->latest('id')->first();
        $this->assertNotNull($rumor, 'the tale never reached the queue');
        $this->assertSame(Rumor::THREAD, $rumor->source);
        $this->assertSame('Sera', $rumor->subject);
        $this->assertStringContainsString('Sera', $rumor->line);
        // It is news waiting on a moment, exactly like every other source.
        $this->assertNull($rumor->heard_turn_id);
    }

    public function test_the_road_walks_with_them_and_ends_in_the_consensual_pair()
    {
        $campaign = $this->createCampaign();
        $this->openBareTurn($campaign);
        $scene = $campaign->activeScene;

        $roan = $this->soul($scene, 'Roan');
        $thread = $this->want($campaign, $roan, Threads::ROAD, ['revealed' => true, 'segments' => 2]);

        $next = $this->nextGround($campaign);
        Threads::onSceneExit($scene->fresh(), $next);

        // They came along, and the crossing IS the progress.
        $this->assertSame($next->id, $roan->fresh()->scene_id);
        $this->assertSame(1, (int) $thread->fresh()->filled);
        $this->assertSame([], $this->tick($campaign, [], $next));

        $scene->update(['status' => 'past']);
        $further = $this->nextGround($campaign, 'The Further Lane');
        Threads::onSceneExit($next->fresh(), $further);
        $next->update(['status' => 'past']);

        $facts = $this->tick($campaign, [], $further);

        $this->assertSame('filled', $thread->fresh()->status);
        $this->assertCount(1, $facts);
        $this->assertStringContainsString('Roan', $facts[0]);
        $this->assertSame(Companions::THREAD, $roan->fresh()->tags['offering']);

        // The promised third road ends where the other two do: an ordinary,
        // refusable pair of main-slot cards, and nothing else about them.
        $verbs = collect($this->cards($campaign, $further)['main'])
            ->filter(fn (array $c) => ($c['target']['id'] ?? null) === $roan->id)
            ->pluck('verb');

        $this->assertContains('companion_welcome', $verbs->all());
        $this->assertContains('companion_dismiss', $verbs->all(),
            'saying no was never offered — joining has to be consensual on both sides');
        $this->assertNotContains('recruit', $verbs->all());
        $this->assertSame(0, Companions::present($further->fresh())->count(),
            'the payoff joined them without anybody being asked');
    }

    public function test_the_road_pays_nothing_while_the_party_is_already_full()
    {
        $campaign = $this->createCampaign();
        $this->openBareTurn($campaign);
        $scene = $campaign->activeScene;

        foreach (['the old hand', 'the runner'] as $name) {
            Companions::join($this->soul($scene, $name, 'npc', []), Companions::ASKED);
        }
        $this->assertTrue(Companions::atCap($scene->fresh()));

        $roan = $this->soul($scene, 'Roan');
        $thread = $this->want($campaign, $roan, Threads::ROAD, [
            'revealed' => true, 'segments' => 1, 'filled' => 1,
        ]);

        $facts = $this->tick($campaign, []);

        // Their story still finished — it simply finished without room for them.
        $this->assertSame('filled', $thread->fresh()->status);
        $this->assertCount(1, $facts);
        $this->assertArrayNotHasKey('offering', $roan->fresh()->tags ?? []);
        $this->assertSame(2, Companions::beside($scene->fresh())->count());
    }

    // ---- Neglect ----

    public function test_a_rooted_want_dies_at_the_border_and_costs_the_player_nothing()
    {
        $campaign = $this->createCampaign();
        $this->openBareTurn($campaign);
        $scene = $campaign->activeScene;
        $this->ground($scene, 'a smuggler’s hatch', [], ['hidden' => true]);

        $aldan = $this->soul($scene, 'Aldan');
        $thread = $this->want($campaign, $aldan, Threads::SEEKING, ['revealed' => true, 'segments' => 3]);

        $healthBefore = $campaign->character->fresh()->meters['health']['current'];
        $next = $this->nextGround($campaign);

        $facts = Threads::onSceneExit($scene->fresh(), $next);

        $this->assertSame('expired', $thread->fresh()->status);
        $this->assertCount(1, $facts);
        $this->assertStringContainsString('Aldan', $facts[0]);
        // The want ended badly for THEM. Nothing moved on the player's sheet,
        // and they stayed where they were rather than following.
        $this->assertSame($healthBefore, $campaign->character->fresh()->meters['health']['current']);
        $this->assertSame([], $campaign->character->fresh()->constraints()->pluck('constraint')->all());
        $this->assertSame($scene->id, $aldan->fresh()->scene_id);

        // And the ground the tale walked onto carries nothing of it.
        $this->assertNull(Threads::boardLine($next->fresh()));
        $this->assertNull(Threads::forecast($next->fresh()));
    }

    public function test_a_walking_want_runs_out_of_chapters_and_a_dormant_one_goes_quietly()
    {
        config()->set('game.threads.expiry_chapters', 3);

        $campaign = $this->createCampaign();
        $this->openBareTurn($campaign);
        $scene = $campaign->activeScene;

        $roan = $this->soul($scene, 'Roan');
        $thread = $this->want($campaign, $roan, Threads::ROAD, ['revealed' => true, 'segments' => 3, 'age' => 2]);

        $facts = $this->tick($campaign, []);

        $this->assertSame('expired', $thread->fresh()->status);
        $this->assertCount(1, $facts);
        $this->assertStringContainsString('Roan', $facts[0]);

        // A want nobody ever discovered ends in silence: saying how it went
        // would be telling the narrator about a story the tale never met.
        $kell = $this->soul($scene, 'Kell');
        $quiet = $this->want($campaign, $kell, Threads::ROAD, ['segments' => 3, 'age' => 2]);

        $this->assertSame([], $this->tick($campaign, []));
        $this->assertSame('expired', $quiet->fresh()->status);
    }

    public function test_a_soul_who_is_gone_takes_their_want_with_them()
    {
        $campaign = $this->createCampaign();
        $this->openBareTurn($campaign);
        $scene = $campaign->activeScene;

        $aldan = $this->soul($scene, 'Aldan');
        $thread = $this->want($campaign, $aldan, Threads::SEEKING, ['revealed' => true, 'segments' => 3]);

        $aldan->update(['status' => 'departed']);
        $facts = $this->tick($campaign, []);

        $this->assertSame('failed', $thread->fresh()->status);
        $this->assertCount(1, $facts);
    }

    // ---- What the narrator is handed ----

    public function test_the_narrator_gets_plain_words_and_never_a_count()
    {
        $campaign = $this->createCampaign();
        $turn = $this->openBareTurn($campaign);
        $scene = $campaign->activeScene;
        $this->ground($scene, 'a smuggler’s hatch', [], ['hidden' => true]);

        $aldan = $this->soul($scene, 'Aldan');
        $this->want($campaign, $aldan, Threads::SEEKING, [
            'revealed' => true, 'segments' => 3, 'filled' => 2,
        ]);

        $turn->update([
            'status' => Turn::STATUS_COMPLETE,
            'resolution' => [
                'beats' => [],
                'scene_reaction' => [],
                'thread' => ['Aldan said what they were doing here.'],
            ],
            'branch_trigger' => 'soft_timeout',
            'resolved_at' => now(),
        ]);

        $block = Threads::narratorBlock($turn->fresh());

        $this->assertStringContainsString('Aldan', $block);
        $this->assertStringContainsString('is close to it now', $block);
        $this->assertStringNotContainsString('2 of 3', $block);
        $this->assertStringNotContainsString('segment', $block);

        $this->prompts = [];
        app(Narrator::class)->narrate($turn->fresh());
        $prompt = implode("\n", $this->prompts);

        $this->assertStringContainsString('Somebody else’s small story', $prompt);
        $this->assertStringContainsString('Aldan said what they were doing here.', $prompt);
        $this->assertStringNotContainsString('2 of 3', $prompt);
    }

    public function test_a_want_never_reaches_the_narrator_before_it_is_discovered()
    {
        $campaign = $this->createCampaign();
        $turn = $this->openBareTurn($campaign);
        $scene = $campaign->activeScene;
        $this->ground($scene, 'a smuggler’s hatch', [], ['hidden' => true]);

        $aldan = $this->soul($scene, 'Aldan');
        Threads::attach($aldan, $scene->fresh());

        $turn->update([
            'status' => Turn::STATUS_COMPLETE,
            'resolution' => ['beats' => [], 'scene_reaction' => [], 'thread' => null],
            'branch_trigger' => 'soft_timeout',
            'resolved_at' => now(),
        ]);

        $this->prompts = [];
        app(Narrator::class)->narrate($turn->fresh());
        $prompt = implode("\n", $this->prompts);

        $this->assertStringNotContainsString('Somebody else’s small story', $prompt);
        $this->assertStringNotContainsString('looking for something this place', $prompt);
    }
}
