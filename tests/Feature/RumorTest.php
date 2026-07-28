<?php

namespace Tests\Feature;

use App\Game\Engine\CardComposer;
use App\Game\Engine\Dice;
use App\Game\Engine\Downtime;
use App\Game\Engine\SituationBoard;
use App\Game\Engine\TurnResolver;
use App\Game\Meters;
use App\Models\Actor;
use App\Models\Campaign;
use App\Models\Chapter;
use App\Models\Character;
use App\Models\EvolutionRun;
use App\Models\Rumor;
use App\Models\Scene;
use App\Models\SceneFeature;
use App\Models\Turn;
use App\Models\User;
use App\Models\Zone;
use App\Services\Claude\ClaudeCli;
use App\Services\Claude\Narrator;
use App\Services\Claude\WorldEvolver;
use App\Services\Rumors;
use App\Services\TurnStarter;
use Database\Seeders\WorldSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Rumors: the world's news, reaching the character at last.
 *
 * The load-bearing claims: every line traces back to a fact the engine really
 * logged; delivery rides three moments the turn already produced and no others;
 * combat silences all of them; one per chapter, oldest first, and news about
 * ground already walked is skipped rather than repeated; an empty queue is
 * silence and never an invitation to invent; and a rumor is colour in every
 * direction — never a card, never an odds part, never a board group.
 */
class RumorTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<string> */
    private array $prompts = [];

    /** What the mocked Claude proposes back for the rumor, if anything. */
    private mixed $reworded = null;

    private function createCampaign(string $name = 'News Tale'): Campaign
    {
        $this->seed(WorldSeeder::class);
        Notification::fake();

        $this->prompts = [];
        $this->mock(ClaudeCli::class, function ($mock) {
            $mock->shouldReceive('promptForJson')->andReturnUsing(function (string $prompt) {
                $this->prompts[] = $prompt;

                return array_filter([
                    'chapter' => 'They walked on, carrying what they had been told.',
                    'intent_line' => null,
                    'synopsis_line' => 'Something was heard.',
                    'rumor' => $this->reworded,
                ], fn ($value) => $value !== null);
            })->byDefault();
            $mock->shouldReceive('prompt')->andReturn('A tale begins.')->byDefault();
        });

        $campaign = Campaign::create([
            'user_id' => User::factory()->create()->id,
            'name' => $name,
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

    private function ground(Scene $scene, string $name, array $affordances = []): SceneFeature
    {
        return SceneFeature::create([
            'scene_id' => $scene->id,
            'zone_id' => $scene->zone_id,
            'name' => $name,
            'feature_type' => 'landmark',
            'affordances' => $affordances,
            'state' => [],
            'source' => 'seed',
        ]);
    }

    private function bystander(Scene $scene, string $name = 'a lamplighter'): Actor
    {
        return Actor::create([
            'scene_id' => $scene->id,
            'zone_id' => $scene->zone_id,
            'name' => $name,
            'kind' => 'npc',
            'tier' => 'regular',
            'stats' => ['health' => ['current' => 4, 'max' => 4], 'attack' => 1],
            'tags' => ['talkable' => true],
            'status' => 'active',
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
            'tags' => ['intent' => 'guard'],
            'status' => 'active',
            'source' => 'seed',
        ]);
    }

    /** Re-offer the open turn from the ground as it stands, then resolve one verb off it. */
    private function play(Campaign $campaign, string $verb): Turn
    {
        $turn = $campaign->fresh()->currentTurn;
        $turn->update(['cards' => app(CardComposer::class)->compose(
            $campaign->character->fresh(), $campaign->activeScene->fresh(), new Dice($turn->id + 5),
        )]);

        $card = collect($turn->cards['main'])->firstWhere('verb', $verb);
        $this->assertNotNull($card, "No {$verb} card was offered.");

        $turn->update([
            'status' => Turn::STATUS_LOCKED,
            'submission' => ['main' => ['card_id' => $card['id'], 'modifiers' => []]],
            'submitted_at' => now(),
        ]);

        app(TurnResolver::class)->resolve($turn->fresh());

        return $turn->fresh();
    }

    /** A wait already spoken for, and already long enough to have paid out. */
    private function spendTheWaitOn(Campaign $campaign, string $stance): void
    {
        $turn = $campaign->fresh()->currentTurn;
        $turn->update(['downtime' => [
            'offer' => [['id' => $stance, 'label' => 'x', 'terms' => 'x']],
            'stance' => $stance,
            'chosen_at' => now()->subHours(3)->toIso8601String(),
            'applied' => false,
            'payout' => null,
        ]]);
    }

    /** One piece of news waiting in the queue. */
    private function queue(Campaign $campaign, string $subject, string $line, ?Zone $zone = null): Rumor
    {
        return Rumors::offer($campaign, Rumor::EVOLUTION, $subject, $line, $zone);
    }

    private function frontier(Campaign $campaign, string $name = 'The Far Shelf'): Zone
    {
        return Zone::create([
            'campaign_id' => $campaign->id,
            'slug' => 'the-far-shelf-'.$campaign->id,
            'name' => $name,
            'description' => 'Country nobody in this tale has walked yet.',
            'tags' => [],
            'source' => 'forge',
        ]);
    }

    // ---- Producers ----

    public function test_an_evolution_run_yields_capped_candidates_each_traceable_to_a_logged_change()
    {
        config(['game.rumors.per_run' => 2]);

        $campaign = $this->createCampaign();
        $zone = Zone::create([
            'campaign_id' => $campaign->id,
            'slug' => 'tended-ground',
            'name' => 'Tended Ground',
            'description' => 'Where the world worked last night.',
            'source' => 'forge',
        ]);

        // A synthetic run, exactly as a real night would have logged it.
        $run = EvolutionRun::create([
            'kind' => 'daily',
            'status' => 'complete',
            'budget' => [],
            'started_at' => now(),
            'finished_at' => now(),
        ]);

        $applied = [
            'grudges' => [['name' => 'the harbormaster', 'development' => 'Asking around.']],
            'actors' => [['zone_slug' => 'tended-ground', 'name' => 'a wire-thin runner']],
            'features' => [['zone_slug' => 'tended-ground', 'name' => 'a collapsed stair']],
            'items' => [['name' => 'a bone whistle']],
        ];
        $run->update(['changes' => $applied]);

        $written = Rumors::fromEvolution($campaign, $run, $applied);

        // Capped, and the most personal news first.
        $this->assertCount(2, $written);
        $this->assertSame(Rumor::GRUDGE, $written[0]->source);
        $this->assertSame('the harbormaster', $written[0]->subject);
        $this->assertSame('a wire-thin runner', $written[1]->subject);
        $this->assertSame($zone->id, $written[1]->subject_zone_id);

        // Every line traces back to something the run really logged.
        $logged = json_encode($run->fresh()->changes);
        foreach ($written as $rumor) {
            $this->assertSame($run->id, $rumor->evolution_run_id);
            $this->assertStringContainsString($rumor->subject, $logged);
            $this->assertStringContainsString($rumor->subject, $rumor->line);
        }
    }

    public function test_the_evolver_writes_the_night_s_news_on_its_own()
    {
        $campaign = $this->createCampaign();
        $zone = Zone::create([
            'campaign_id' => $campaign->id,
            'slug' => 'tended-ground',
            'name' => 'Tended Ground',
            'description' => 'Where the world works.',
            'source' => 'forge',
        ]);

        $this->mock(ClaudeCli::class, function ($mock) {
            $mock->shouldReceive('promptForJson')->andReturn([
                'chronicle' => 'Something stirred in the night.',
                'rationale' => 'A quiet run.',
                'actors' => [[
                    'zone_slug' => 'tended-ground',
                    'name' => 'a wire-thin runner',
                    'kind' => 'enemy',
                    'tier' => 'regular',
                    'stats' => ['health' => ['max' => 5], 'attack' => 1],
                    'tags' => [],
                ]],
            ]);
            $mock->shouldReceive('prompt')->andReturn('x');
        });

        $run = app(WorldEvolver::class)->evolveCampaign($campaign);

        $this->assertSame('complete', $run->status);

        $rumor = Rumor::where('campaign_id', $campaign->id)->first();
        $this->assertNotNull($rumor, 'a night of tending produced no news at all');
        $this->assertSame('a wire-thin runner', $rumor->subject);
        $this->assertSame($zone->id, $rumor->subject_zone_id);
        $this->assertSame($run->id, $rumor->evolution_run_id);

        // The Chronicle is untouched by any of it: the reader still gets the
        // omniscient digest, and hearing an echo of it later is the point.
        $this->assertSame(
            'Something stirred in the night.',
            $campaign->fresh()->chapters()->where('kind', 'chronicle')->value('body'),
        );
    }

    public function test_the_frontier_forge_gives_the_road_ahead_a_voice()
    {
        $campaign = $this->createCampaign();
        $zone = $this->frontier($campaign);

        $rumor = Rumors::fromForge($campaign, $zone);

        $this->assertNotNull($rumor);
        $this->assertSame(Rumor::FORGE, $rumor->source);
        $this->assertSame($zone->id, $rumor->subject_zone_id);
        $this->assertStringContainsString('The Far Shelf', $rumor->line);
    }

    public function test_the_queue_drops_the_oldest_news_past_its_cap()
    {
        config(['game.rumors.queue' => 3]);

        $campaign = $this->createCampaign();

        foreach (range(1, 5) as $n) {
            $this->queue($campaign, "thing {$n}", "Word is that thing {$n} happened.");
        }

        $waiting = Rumor::where('campaign_id', $campaign->id)->orderBy('id')->pluck('subject');

        $this->assertSame(['thing 3', 'thing 4', 'thing 5'], $waiting->all());
        $this->assertSame(3, Rumors::pending($campaign));
    }

    // ---- The channels ----

    public function test_a_crossing_carries_the_news()
    {
        $campaign = $this->createCampaign();
        $this->openBareTurn($campaign);
        $scene = $campaign->activeScene;

        $this->ground($scene, 'a gap in the boards', ['flee_destination' => true, 'squeeze_required' => 'small']);
        $this->queue($campaign, 'a bone whistle', 'There is talk of a bone whistle turning up out east.');

        $turn = null;
        for ($i = 0; $i < 12 && Rumors::pending($campaign) > 0; $i++) {
            Meters::heal($campaign->character->fresh(), 20);
            $turn = $this->play($campaign, 'flee');
        }

        $this->assertSame(Rumors::CROSSING, $turn->resolution['rumor']['channel']);
        $this->assertStringContainsString('bone whistle', $turn->resolution['rumor']['line']);
        $this->assertSame($turn->id, Rumor::first()->heard_turn_id);
    }

    public function test_talking_to_somebody_willing_carries_the_news()
    {
        $campaign = $this->createCampaign();
        $this->openBareTurn($campaign);
        $this->bystander($campaign->activeScene);

        $this->queue($campaign, 'a bone whistle', 'There is talk of a bone whistle turning up out east.');

        $turn = null;
        for ($i = 0; $i < 12 && Rumors::pending($campaign) > 0; $i++) {
            Meters::heal($campaign->character->fresh(), 20);
            $turn = $this->play($campaign, 'speak');
        }

        $this->assertSame(Rumors::TALK, $turn->resolution['rumor']['channel']);
        $this->assertStringContainsString('bone whistle', $turn->resolution['rumor']['line']);
    }

    public function test_a_wait_spent_out_in_it_carries_the_news()
    {
        $campaign = $this->createCampaign();
        $this->openBareTurn($campaign);

        $this->queue($campaign, 'a bone whistle', 'There is talk of a bone whistle turning up out east.');
        $this->spendTheWaitOn($campaign, Downtime::WATCH);

        $turn = $this->play($campaign, 'wait');

        $this->assertSame(Rumors::FIRESIDE, $turn->resolution['rumor']['channel']);
        $this->assertStringContainsString('bone whistle', $turn->resolution['rumor']['line']);
    }

    public function test_a_turn_that_produced_no_moment_hears_nothing()
    {
        $campaign = $this->createCampaign();
        $this->openBareTurn($campaign);

        $this->queue($campaign, 'a bone whistle', 'There is talk of a bone whistle turning up out east.');

        // Standing still, saying nothing, going nowhere, and having spent the
        // wait on none of the stances that put them out in the world.
        $turn = $this->play($campaign, 'examine');

        $this->assertNull($turn->resolution['rumor']);
        $this->assertSame(1, Rumors::pending($campaign));
    }

    public function test_a_wait_spent_asleep_or_over_the_gear_carries_nothing()
    {
        foreach ([Downtime::REST, Downtime::TEND] as $stance) {
            $campaign = $this->createCampaign("Tale of {$stance}");
            $this->openBareTurn($campaign);

            $this->queue($campaign, 'a bone whistle', 'There is talk of a bone whistle out east.');
            $this->spendTheWaitOn($campaign, $stance);

            $turn = $this->play($campaign, 'wait');

            $this->assertNull($turn->resolution['rumor'], "the {$stance} stance carried news it should not have");
        }
    }

    public function test_a_fight_still_standing_silences_every_channel()
    {
        foreach ([
            ['flee', fn () => null],
            ['speak', fn () => null],
            ['wait', fn () => null],
        ] as [$verb, $ignored]) {
            $campaign = $this->createCampaign("Tale of {$verb}");
            $this->openBareTurn($campaign);
            $scene = $campaign->activeScene;

            $this->ground($scene, 'a gap in the boards', ['flee_destination' => true, 'squeeze_required' => 'small']);
            $this->bystander($scene);
            $this->enemy($scene);

            $this->queue($campaign, 'a bone whistle', 'There is talk of a bone whistle out east.');
            $this->spendTheWaitOn($campaign, Downtime::WATCH);

            $turn = $this->play($campaign, $verb);

            $this->assertNull($turn->resolution['rumor'], "news changed hands mid-fight on a {$verb}");
            $this->assertSame(1, Rumors::pending($campaign));
        }
    }

    // ---- The queue's own discipline ----

    public function test_one_per_chapter_and_oldest_first()
    {
        $campaign = $this->createCampaign();
        $this->openBareTurn($campaign);

        $older = $this->queue($campaign, 'the first thing', 'Word is that the first thing happened.');
        $this->queue($campaign, 'the second thing', 'Word is that the second thing happened.');

        $this->spendTheWaitOn($campaign, Downtime::WATCH);
        $turn = $this->play($campaign, 'wait');

        $this->assertSame($older->line, $turn->resolution['rumor']['line']);
        // The second is still waiting: a chapter is one turn's telling, and a
        // chapter hears one thing.
        $this->assertSame(1, Rumors::pending($campaign));
        $this->assertNull(Rumors::deliver($turn, Rumors::FIRESIDE));
    }

    public function test_news_about_ground_already_walked_is_skipped_and_marked()
    {
        $campaign = $this->createCampaign();
        $this->openBareTurn($campaign);

        $here = $campaign->activeScene->zone;
        $stale = $this->queue($campaign, $here->name, "Travellers speak of {$here->name}, out past the edge.", $here);
        $fresh = $this->queue($campaign, 'a bone whistle', 'There is talk of a bone whistle out east.');

        $this->spendTheWaitOn($campaign, Downtime::WATCH);
        $turn = $this->play($campaign, 'wait');

        // They are standing in it: silence about a place you can see is good
        // manners, and the row goes out of the queue rather than round it.
        $this->assertSame($fresh->line, $turn->resolution['rumor']['line']);
        $this->assertSame($turn->id, $stale->fresh()->heard_turn_id);
        $this->assertSame(0, Rumors::pending($campaign));
    }

    public function test_a_crossing_puts_word_of_the_road_ahead_first()
    {
        $campaign = $this->createCampaign();
        $this->openBareTurn($campaign);

        $this->queue($campaign, 'a bone whistle', 'There is talk of a bone whistle out east.');
        $ahead = $this->frontier($campaign);
        $road = Rumors::fromForge($campaign, $ahead);
        $campaign->update(['next_zone_id' => $ahead->id]);

        $turn = $campaign->fresh()->currentTurn;

        $delivered = Rumors::deliver($turn, Rumors::CROSSING, $ahead->id);

        // Oldest-first everywhere else; on the road, where the road goes wins.
        $this->assertSame($road->line, $delivered['line']);
        $this->assertSame(1, Rumors::pending($campaign->fresh()));
    }

    public function test_an_empty_queue_is_silence_and_never_an_invention()
    {
        $campaign = $this->createCampaign();
        $this->openBareTurn($campaign);

        $this->spendTheWaitOn($campaign, Downtime::WATCH);
        $turn = $this->play($campaign, 'wait');

        // The moment qualified. There was simply nothing to say.
        $this->assertNull($turn->resolution['rumor']);
        $this->assertSame(0, Rumor::count());

        app(Narrator::class)->narrate($turn->fresh());

        $prompt = collect($this->prompts)->first(fn (string $p) => str_contains($p, 'You are the narrator'));
        $this->assertStringNotContainsString('Something they heard about elsewhere', $prompt);
        $this->assertStringNotContainsString('"rumor"', $prompt);
    }

    public function test_news_from_one_tale_never_reaches_another()
    {
        $campaign = $this->createCampaign();
        $this->openBareTurn($campaign);
        $this->queue($campaign, 'a bone whistle', 'There is talk of a bone whistle out east.');

        $other = $this->createCampaign('Another Tale');
        $turn = $this->openBareTurn($other);

        $this->assertNull(Rumors::deliver($turn, Rumors::CROSSING));
        $this->assertSame(1, Rumors::pending($campaign->fresh()));
    }

    // ---- What the narrator may and may not do with it ----

    public function test_the_narrator_is_handed_the_news_as_a_fixed_fact()
    {
        $campaign = $this->createCampaign();
        $this->openBareTurn($campaign);

        $rumor = $this->queue($campaign, 'a bone whistle', 'There is talk of a bone whistle turning up out east.');
        $this->spendTheWaitOn($campaign, Downtime::WATCH);
        $turn = $this->play($campaign, 'wait');

        $this->reworded = 'Somebody out east has been blowing a bone whistle nobody recognises.';

        app(Narrator::class)->narrate($turn->fresh());

        $prompt = collect($this->prompts)->first(fn (string $p) => str_contains($p, 'You are the narrator'));
        $this->assertStringContainsString('Something they heard about elsewhere', $prompt);
        $this->assertStringContainsString($rumor->line, $prompt);
        $this->assertStringContainsString('It changes nothing here', $prompt);

        $after = $rumor->fresh();
        $this->assertSame($this->reworded, $after->line);
        $this->assertNotNull($after->heard_chapter_id);
        $this->assertSame('a bone whistle', $after->subject);
    }

    public function test_a_rewording_that_strays_leaves_the_engine_s_words_standing()
    {
        $campaign = $this->createCampaign();
        $this->openBareTurn($campaign);

        $chapter = Chapter::create([
            'campaign_id' => $campaign->id,
            'turn_id' => null,
            'number' => 1,
            'kind' => 'chapter',
            'body' => 'It happened, and then it was over.',
        ]);

        foreach ([
            'nothing at all' => null,
            'a line that runs on' => 'Somebody somewhere has been going on and on and on and on and on and on about a bone whistle that nobody has actually seen anywhere.',
            'a different subject' => 'They say the harbormaster has taken up with the excise men again.',
            'the machinery underneath' => 'A bone whistle turned up out east, on a lucky roll of the dice.',
            'blank words' => '   ',
            'not even a sentence' => ['line' => 'a bone whistle out east'],
        ] as $label => $proposal) {
            $turn = Turn::create([
                'campaign_id' => $campaign->id,
                'scene_id' => $campaign->fresh()->activeScene->id,
                'number' => 400 + Turn::where('campaign_id', $campaign->id)->count(),
                'status' => Turn::STATUS_COMPLETE,
                'situation' => 'Ground already walked.',
                'cards' => ['main' => []],
            ]);

            $rumor = $this->queue($campaign, 'a bone whistle', 'There is talk of a bone whistle turning up out east.');
            $rumor->update(['heard_turn_id' => $turn->id]);

            Rumors::reword($turn, $chapter, $proposal);

            $after = $rumor->fresh();
            $this->assertSame($rumor->line, $after->line, "the clamp let through: {$label}");
            // The chapter stamp still lands — the citation is the engine's,
            // not something Claude has to earn.
            $this->assertSame($chapter->id, $after->heard_chapter_id);
        }
    }

    // ---- Colour, and nothing else ----

    public function test_news_never_becomes_a_card_an_odds_part_or_a_board_group()
    {
        $campaign = $this->createCampaign();
        $this->openBareTurn($campaign);
        $scene = $campaign->activeScene;

        // Something the place is genuinely keeping back, plus news about
        // elsewhere: the news may never touch the first of those.
        $kept = SceneFeature::create([
            'scene_id' => $scene->id,
            'zone_id' => $scene->zone_id,
            'name' => 'a smuggler’s hatch',
            'feature_type' => 'landmark',
            'affordances' => [],
            'state' => ['hidden' => true],
            'source' => 'seed',
        ]);
        $lurker = $this->enemy($scene, 'the patient one');
        $lurker->update(['tags' => ['lurking' => true, 'lurking_since' => 1]]);

        $rumor = $this->queue($campaign, 'a bone whistle', 'There is talk of a bone whistle turning up out east.');
        $this->spendTheWaitOn($campaign, Downtime::WATCH);

        // A lurker is not a fight anybody knows about, so the wait still pays.
        $turn = $this->play($campaign, 'wait');
        $this->assertSame($rumor->line, $turn->resolution['rumor']['line']);

        // It revealed nothing standing here.
        $this->assertTrue($kept->fresh()->state['hidden']);

        $next = $campaign->fresh()->currentTurn;
        $cards = collect($next->cards['pre'])
            ->concat($next->cards['main'])
            ->concat($next->cards['post']);

        foreach ($cards as $card) {
            $printed = "{$card['label']} {$card['description']}";
            $this->assertStringNotContainsString('bone whistle', $printed);
            foreach ($card['forecast']['parts'] as $part) {
                $this->assertStringNotContainsString('bone whistle', $part['label']);
            }
            foreach ($card['forecast']['bonus_parts'] as $part) {
                $this->assertStringNotContainsString('bone whistle', $part['label']);
            }
        }

        $board = SituationBoard::for($campaign->character->fresh(), $campaign->fresh()->activeScene);
        $this->assertNotContains('rumor', collect($board)->pluck('key')->all());
        $this->assertStringNotContainsString('bone whistle', SituationBoard::prose($board));
        $this->assertStringNotContainsString('bone whistle', $next->situation);
    }
}
