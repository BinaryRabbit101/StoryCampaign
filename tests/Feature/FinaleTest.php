<?php

namespace Tests\Feature;

use App\Game\Engine\CardComposer;
use App\Game\Engine\Clocks;
use App\Game\Engine\Dice;
use App\Game\Engine\Finale;
use App\Game\Engine\Grudges;
use App\Game\Engine\SituationBoard;
use App\Game\Engine\TurnResolver;
use App\Game\Meters;
use App\Models\Actor;
use App\Models\Campaign;
use App\Models\Character;
use App\Models\Clock;
use App\Models\Grudge;
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
 * The finale: a tale that ends on a peak.
 *
 * The load-bearing claims these hold down: ripeness is closed engine facts and
 * config weights and nothing else (the story the player told moves none of it);
 * arming only ever OFFERS, and declining is free forever; the ending curates
 * rather than invents — the forced grudge return is reachable through the
 * finale's own parameter and nowhere else, and ordinary returns keep every
 * clamp; the engine's own last-stretch clock never costs the player their
 * endeavor slot and can never be set down; and the close is the existing close,
 * exactly once, with a mid-finale fall past the scar cap still ending the tale
 * through its own path with no special casing anywhere.
 */
class FinaleTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<string> */
    private array $prompts = [];

    private function createCampaign(string $name = 'Ending Tale'): Campaign
    {
        $this->seed(WorldSeeder::class);

        $this->prompts = [];
        $this->mock(ClaudeCli::class, function ($mock) {
            $mock->shouldReceive('promptForJson')->andReturnUsing(function (string $prompt) {
                $this->prompts[] = $prompt;

                return [
                    'chapter' => 'It came to a head, and then it was quiet.',
                    'intent_line' => null,
                    'synopsis_line' => 'The last of it.',
                    'title' => 'The Long Road Back',
                    'back_cover' => 'A tale that finished what it started.',
                ];
            })->byDefault();

            $mock->shouldReceive('prompt')->andReturnUsing(function (string $prompt) {
                $this->prompts[] = $prompt;

                return 'And so the road ran out, and they let it.';
            })->byDefault();
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
        ])->capabilities()->createMany([
            ['capability' => 'scout', 'source' => 'creation'],
            ['capability' => 'break', 'source' => 'creation'],
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
    private function play(Campaign $campaign, string $verb = 'wait', string $slot = 'main'): Turn
    {
        $turn = $campaign->fresh()->currentTurn;
        $turn->update(['cards' => $this->cards($campaign, $turn->id + 3)]);

        $card = collect($turn->cards[$slot])->firstWhere('verb', $verb);
        $this->assertNotNull($card, "No {$verb} card was offered in the {$slot} slot.");

        $turn->update([
            'status' => Turn::STATUS_LOCKED,
            'submission' => [$slot => ['card_id' => $card['id'], 'modifiers' => []]],
            'submitted_at' => now(),
        ]);

        app(TurnResolver::class)->resolve($turn->fresh());

        return $turn->fresh();
    }

    /** Chapters behind them: the floor is counted in pages actually written. */
    private function chapters(Campaign $campaign, int $count): void
    {
        for ($i = 1; $i <= $count; $i++) {
            $campaign->chapters()->create([
                'turn_id' => null,
                'number' => $i,
                'kind' => 'chapter',
                'body' => "Chapter {$i} happened.",
            ]);
        }
    }

    private function grudge(Campaign $campaign, string $name, int $heat = 3, string $status = 'simmering'): Grudge
    {
        return Grudge::create([
            'campaign_id' => $campaign->id,
            'actor_name' => $name,
            'stats' => ['health' => ['current' => 1, 'max' => 1], 'attack' => 0],
            'tags' => [],
            'tier' => 'regular',
            'history' => [],
            'heat' => $heat,
            'disposition' => 'wary',
            'status' => $status,
            // Seen only a moment ago: the ordinary return's chapter floor
            // refuses them, which is precisely what the finale must not.
            'last_seen_chapter_id' => $campaign->chapters()->max('id'),
        ]);
    }

    private function scar(Campaign $campaign, string $name = 'marked_limp'): void
    {
        $campaign->character->constraints()->create([
            'name' => $name, 'params' => ['scar' => $name], 'source' => 'scar',
        ]);
    }

    private function keepsakes(Campaign $campaign, int $count): void
    {
        for ($i = 1; $i <= $count; $i++) {
            Memento::create([
                'campaign_id' => $campaign->id,
                'turn_id' => null,
                'trigger' => 'first_ground',
                'subject' => "a thing {$i}",
                'name' => "keepsake {$i}",
                'line' => 'They kept it.',
            ]);
        }
    }

    private function filledClock(Campaign $campaign): void
    {
        Clock::create([
            'campaign_id' => $campaign->id,
            'scene_id' => null,
            'kind' => Clocks::SEARCH,
            'name' => 'the search of the long quay',
            'segments' => 5,
            'filled' => 5,
            'advance_verbs' => ['scout'],
            'payoff' => Clocks::REVEAL_HIDDEN,
            'subject' => null,
            'portable' => false,
            'status' => 'filled',
        ]);
    }

    /** A tale with enough behind it to be offered an ending: 5 signals, 8 chapters. */
    private function ripen(Campaign $campaign, bool $withGrudge = true): void
    {
        $this->chapters($campaign, 8);
        $this->filledClock($campaign);
        $this->scar($campaign);
        $this->keepsakes($campaign, 4);

        if ($withGrudge) {
            $this->grudge($campaign, 'the harbormaster');
        } else {
            // Without a nemesis the same tale still ripens; it just has nothing
            // with a face to end against.
            $this->keepsakes($campaign, 8);
        }
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

    // ---- Ripeness ----

    public function test_ripeness_is_the_chapter_floor_and_the_weighted_signals_and_nothing_else()
    {
        $campaign = $this->createCampaign();
        $this->openBareTurn($campaign);

        $this->assertSame(0, Finale::ripeness($campaign)['signals']);
        $this->assertFalse(Finale::ripeness($campaign)['ripe']);

        $this->chapters($campaign, 8);
        $this->grudge($campaign, 'the harbormaster', heat: Grudges::MAX_HEAT);
        $this->filledClock($campaign);
        $this->scar($campaign);
        $this->keepsakes($campaign, 4);

        $ripeness = Finale::ripeness($campaign->fresh());

        $this->assertSame(2, $ripeness['parts']['max_heat_grudge']);
        $this->assertSame(1, $ripeness['parts']['clock_filled']);
        $this->assertSame(0, $ripeness['parts']['zone_beyond_first']);
        $this->assertSame(1, $ripeness['parts']['scar']);
        $this->assertSame(1, $ripeness['parts']['mementos'], 'four keepsakes are worth one signal');
        $this->assertSame(5, $ripeness['signals']);
        $this->assertTrue($ripeness['ripe']);

        // Three more keepsakes buy nothing; the fourth buys the next signal.
        $this->keepsakes($campaign, 3);
        $this->assertSame(1, Finale::ripeness($campaign->fresh())['parts']['mementos']);
        $this->keepsakes($campaign, 1);
        $this->assertSame(2, Finale::ripeness($campaign->fresh())['parts']['mementos']);

        // Country crossed counts per zone BEYOND the first.
        $zone = Zone::create([
            'campaign_id' => $campaign->id,
            'slug' => 'far-ground', 'name' => 'Far Ground',
            'description' => 'Somewhere else.', 'source' => 'forge',
        ]);
        Scene::create([
            'campaign_id' => $campaign->id, 'zone_id' => $zone->id,
            'title' => 'the far end', 'description' => 'New country.',
            'status' => 'past', 'state' => ['dressed' => true],
        ]);
        $this->assertSame(1, Finale::ripeness($campaign->fresh())['parts']['zone_beyond_first']);

        // The engine's own last-stretch clock never counts toward the ripeness
        // that armed it — that would be an ending arguing for itself.
        Clock::create([
            'campaign_id' => $campaign->id, 'scene_id' => null,
            'kind' => Clocks::RECKONING, 'name' => 'the last of it',
            'segments' => 6, 'filled' => 6, 'advance_verbs' => ['improvise'],
            'payoff' => Clocks::NO_PAYOFF, 'subject' => null,
            'portable' => true, 'status' => 'filled',
        ]);
        $this->assertSame(1, Finale::ripeness($campaign->fresh())['parts']['clock_filled']);
    }

    public function test_the_chapter_floor_gates_a_tale_on_its_own()
    {
        $campaign = $this->createCampaign();
        $this->openBareTurn($campaign);

        $this->chapters($campaign, (int) config('game.finale.chapter_floor') - 1);
        $this->grudge($campaign, 'the harbormaster');
        $this->filledClock($campaign);
        $this->scar($campaign);
        $this->keepsakes($campaign, 4);

        $ripeness = Finale::ripeness($campaign->fresh());
        $this->assertGreaterThanOrEqual($ripeness['threshold'], $ripeness['signals']);
        $this->assertFalse($ripeness['ripe'], 'a short tale was offered an ending it had not earned');

        // A turn played under the floor arms nothing at all.
        $this->play($campaign);
        $this->assertNull($campaign->fresh()->finale);
        $this->assertNull(collect($this->cards($campaign)['main'])->firstWhere('verb', 'face'));

        // One more page, and the same debts are enough.
        $campaign->chapters()->create(['turn_id' => null, 'number' => 99, 'kind' => 'chapter', 'body' => 'One more.']);
        $this->assertTrue(Finale::ripeness($campaign->fresh())['ripe']);
    }

    public function test_the_story_the_player_told_never_moves_ripeness()
    {
        $campaign = $this->createCampaign();
        $this->openBareTurn($campaign);
        $this->ripen($campaign);

        $before = Finale::ripeness($campaign->fresh());

        $campaign->update([
            'genre' => 'a horror of the deep cold',
            'drive' => 'revenge, and nothing else',
            'tech_level' => 'engines and worse',
            'setting' => 'a station falling out of orbit',
            'premise' => 'They swore to see it finished.',
            'tone' => 'grim',
        ]);

        $this->assertSame($before, Finale::ripeness($campaign->fresh()));
    }

    // ---- Arming ----

    public function test_arming_puts_the_line_on_the_board_and_the_card_on_the_table()
    {
        $campaign = $this->createCampaign();
        $this->openBareTurn($campaign);
        $this->ripen($campaign);

        $this->play($campaign);

        $campaign = $campaign->fresh();
        $this->assertSame(Finale::ARMED, $campaign->finale['state']);
        $this->assertTrue(Finale::isArmed($campaign));
        $this->assertFalse(Finale::isUnderway($campaign));

        // The board says so in plain words, and carries no count.
        $board = SituationBoard::for($campaign->character->fresh(), $campaign->activeScene->fresh());
        $group = collect($board)->firstWhere('key', 'finale');
        $this->assertNotNull($group, 'the board said nothing about where the tale was going');
        $this->assertStringContainsString('gathering toward its end', $group['items'][0]);
        $this->assertDoesNotMatchRegularExpression('/\d/', $group['items'][0]);

        // And the card is on the table: main slot, roll-free, naming what has
        // been building and what taking it costs.
        $card = collect($this->cards($campaign)['main'])->firstWhere('verb', 'face');
        $this->assertNotNull($card, 'a ripe tale was offered no ending');
        $this->assertSame('main', $card['slot']);
        $this->assertSame('do', $card['family']);
        $this->assertFalse($card['forecast']['rolls'], 'the ending is a declaration, not a roll');
        $this->assertStringContainsString('the harbormaster', $card['label']);
        $this->assertStringContainsString('no stepping back', $card['description']);
    }

    public function test_declining_forever_is_free_and_nothing_escalates()
    {
        $campaign = $this->createCampaign();
        $this->openBareTurn($campaign);
        $this->ripen($campaign);

        $this->play($campaign);
        $armed = $campaign->fresh()->finale;

        for ($i = 0; $i < 4; $i++) {
            Meters::heal($campaign->character->fresh(), 20);
            $this->play($campaign);

            // Same state, same record, same offer — and nothing behind it moved.
            $this->assertSame($armed, $campaign->fresh()->finale);
            $this->assertNotNull(collect($this->cards($campaign)['main'])->firstWhere('verb', 'face'));
            $this->assertSame('simmering', Grudge::where('campaign_id', $campaign->id)->first()->status);
            $this->assertSame(0, Clock::where('campaign_id', $campaign->id)
                ->where('kind', Clocks::RECKONING)->count());
            $this->assertNull($campaign->fresh()->currentTurn->resolution['finale'] ?? null);
        }
    }

    public function test_the_ending_can_never_be_bought_at_a_discount()
    {
        $campaign = $this->createCampaign();
        $this->openBareTurn($campaign);
        $this->ripen($campaign);
        $this->ground($campaign->activeScene, 'the barred gate', ['breakable' => true]);

        $this->play($campaign);

        // Every deal the pass would ever consider, on every card of every turn.
        config()->set('game.bargains.chance', 1.0);
        config()->set('game.bargains.per_turn', 12);

        for ($seed = 1; $seed <= 12; $seed++) {
            $cards = collect($this->cards($campaign, $seed)['main']);
            $this->assertNotNull($cards->firstWhere('verb', 'face'));

            foreach ($cards->where('verb', 'face') as $card) {
                $this->assertNull($card['bargain'], 'the ending was offered at a discount');
            }
        }
    }

    // ---- The forced return ----

    public function test_the_forced_return_is_reachable_only_through_the_finale_and_arrives_telegraphing()
    {
        $campaign = $this->createCampaign();
        $turn = $this->openBareTurn($campaign);
        $this->chapters($campaign, 8);
        $grudge = $this->grudge($campaign, 'the harbormaster');
        $scene = $campaign->activeScene;

        // Ordinary play cannot reach them: the chapter floor holds, so they are
        // not even a candidate, and the roll never gets to happen.
        $this->assertCount(0, Grudges::candidates($campaign->fresh()));
        for ($seed = 1; $seed <= 25; $seed++) {
            $this->assertNull(Grudges::maybeReturn($scene->fresh(), $campaign->fresh(), new Dice($seed), $turn));
        }
        $this->assertSame('simmering', $grudge->fresh()->status);

        // The finale's own parameter waives it — and only for the name it asks
        // for, and only while that score is still open.
        $this->assertNull(Grudges::forceReturn($campaign, 'somebody else entirely', $scene->fresh(), new Dice(3), $turn));

        $actor = Grudges::forceReturn($campaign, 'the harbormaster', $scene->fresh(), new Dice(3), $turn);

        $this->assertNotNull($actor);
        $this->assertSame('the harbormaster', $actor->name);
        $this->assertSame('returning', $grudge->fresh()->status);

        // Telegraphing, whatever the stored disposition said: never lurking,
        // never under terms, and visible to the cards and the board alike.
        $this->assertSame('press', $actor->tags['intent']);
        $this->assertFalse($actor->tags['lurking'] ?? false);
        $this->assertFalse($actor->tags['truce'] ?? false);
        $this->assertTrue($scene->fresh()->visibleActors()->contains(fn ($a) => $a->id === $actor->id));

        // A settled score is never dragged back, by the finale or anything else.
        $grudge->update(['status' => 'resolved']);
        $this->assertNull(Grudges::forceReturn($campaign, 'the harbormaster', $scene->fresh(), new Dice(3), $turn));
    }

    public function test_taking_it_up_pins_the_hottest_score_and_brings_them_back()
    {
        $campaign = $this->createCampaign();
        $this->openBareTurn($campaign);
        $this->ripen($campaign);
        $this->grudge($campaign, 'a dockside tough', heat: 1);

        $this->play($campaign);
        $this->play($campaign, 'face');

        $campaign = $campaign->fresh();
        $this->assertSame(Finale::UNDERWAY, $campaign->finale['state']);
        $this->assertSame('the harbormaster', $campaign->finale['grudge_name'], 'the ending pinned the cooler score');
        $this->assertNull($campaign->finale['clock_id'], 'a tale with a nemesis needs no clock');

        // They are standing here, in the open, on ground that was empty — never
        // lurking and never under terms. (What they telegraph from one turn to
        // the next is the ordinary intent roll's business, and it keeps running
        // through the finale like everything else.)
        $arrived = $campaign->activeScene->actors()->where('name', 'the harbormaster')->first();
        $this->assertNotNull($arrived, 'the ending was taken up and nobody came');
        $this->assertFalse($arrived->tags['lurking'] ?? false);
        $this->assertFalse($arrived->tags['truce'] ?? false);
        $this->assertTrue($campaign->activeScene->fresh()->visibleActors()
            ->contains(fn ($a) => $a->id === $arrived->id));

        // The turn says so in plain words, and no mechanics reached the record.
        $record = $campaign->turns()->orderByDesc('number')->skip(1)->first()->resolution['finale'];
        $this->assertSame('begun', $record['event']);
        $this->assertFalse($record['complete']);
        foreach ($record['facts'] as $fact) {
            $this->assertDoesNotMatchRegularExpression('/\d/', $fact);
            foreach (['roll', 'card', 'clock', 'difficulty', 'meter'] as $word) {
                $this->assertStringNotContainsStringIgnoringCase($word, $fact);
            }
        }

        // And the offer is gone: it was never a card you take twice.
        $this->assertNull(collect($this->cards($campaign)['main'])->firstWhere('verb', 'face'));
    }

    public function test_the_way_on_closes_only_while_the_last_stretch_runs()
    {
        $campaign = $this->createCampaign();
        $this->openBareTurn($campaign);
        $this->ripen($campaign, withGrudge: false);

        $frontier = Zone::create([
            'campaign_id' => $campaign->id,
            'slug' => 'the-frontier', 'name' => 'The Frontier',
            'description' => 'Country nobody here has walked.', 'source' => 'forge',
        ]);
        $campaign->update(['next_zone_id' => $frontier->id]);

        // Armed changes nothing about the world: the way on is still open.
        $this->play($campaign);
        $this->assertSame(Finale::ARMED, $campaign->fresh()->finale['state']);
        $this->assertNotNull(collect($this->cards($campaign)['main'])->firstWhere('verb', 'venture'));

        $this->play($campaign, 'face');
        $this->assertSame(Finale::UNDERWAY, $campaign->fresh()->finale['state']);

        // Underway, and only underway, the frontier card is not offered.
        $this->assertNull(collect($this->cards($campaign)['main'])->firstWhere('verb', 'venture'));

        // And nothing else holds its breath: the stillness still fills, and the
        // wheel still turns under it.
        $before = $campaign->fresh();
        $this->play($campaign);
        $this->play($campaign);

        $this->assertGreaterThan(0, (int) ($campaign->fresh()->activeScene->state['stall'] ?? 0));
        $this->assertNotSame(
            [$before->hour_phase, $before->hour_progress],
            [$campaign->fresh()->hour_phase, $campaign->fresh()->hour_progress],
        );
    }

    // ---- The engine's own clock ----

    public function test_a_tale_with_no_old_score_gets_a_reckoning_that_costs_the_player_nothing()
    {
        $campaign = $this->createCampaign();
        $this->openBareTurn($campaign);
        $this->ripen($campaign, withGrudge: false);

        $this->play($campaign);
        $this->play($campaign, 'face');

        $campaign = $campaign->fresh();
        $clock = Finale::reckoning($campaign);

        $this->assertNotNull($clock, 'a tale with nothing to face got no last stretch');
        $this->assertSame($clock->id, $campaign->finale['clock_id']);
        $this->assertNull($campaign->finale['grudge_name']);
        $this->assertNull($clock->scene_id, 'the last stretch belongs to the tale, not the ground');
        $this->assertTrue($clock->portable);
        $this->assertContains('improvise', $clock->advance_verbs);

        // Exempt from the one-at-a-time rule, and it frees nothing: the player's
        // own slot is untouched, so they may still take an endeavor on.
        $scene = $campaign->activeScene;
        $this->assertNull(Clocks::openFor($campaign));
        $this->ground($scene, 'the barred gate', ['breakable' => true]);
        $this->assertTrue(Clocks::mayOffer($scene->fresh()));

        // ...and it can never be set down. Nothing offers an abandon against it.
        $this->assertNull(collect($this->cards($campaign)['post'])->firstWhere('verb', 'abandon'));

        config()->set('game.clocks.offer_chance', 1.0);
        $this->play($campaign, 'undertake');

        $own = Clocks::openFor($campaign->fresh());
        $this->assertNotNull($own, 'the reckoning ate the player’s own endeavor slot');
        $this->assertNotSame($clock->id, $own->id);
        $this->assertSame('open', Finale::reckoning($campaign->fresh())->status);

        // The abandon card that appears now is the player's, never the engine's.
        $abandon = collect($this->cards($campaign->fresh())['post'])->firstWhere('verb', 'abandon');
        $this->assertNotNull($abandon);
        $this->assertSame($own->id, $abandon['target']['id']);
    }

    public function test_the_reckoning_reads_how_this_tale_was_played_and_reads_it_the_same_way_twice()
    {
        $campaign = $this->createCampaign();
        $this->openBareTurn($campaign);
        $this->ripen($campaign, withGrudge: false);

        // A tale of breaking things is answered by a last stretch of breaking
        // things. The verbs come off the families the beats actually used.
        for ($i = 0; $i < 3; $i++) {
            $this->ground($campaign->activeScene, "the barred gate {$i}", ['breakable' => true]);
        }
        for ($i = 0; $i < 3; $i++) {
            Meters::heal($campaign->character->fresh(), 20);
            $this->play($campaign, 'break');
        }

        $first = Finale::advanceVerbs($campaign->fresh());
        $this->assertSame($first, Finale::advanceVerbs($campaign->fresh()), 'the same tale read two ways');
        $this->assertContains('break', $first);
        $this->assertContains('improvise', $first, 'the last stretch must be reachable on any ground');

        // Nothing that casts no die, and nothing anybody else does for them.
        foreach ($first as $verb) {
            $this->assertStringStartsNotWith('companion_', $verb);
            $this->assertNotContains($verb, ['wait', 'examine', 'inspect', 'ready', 'face']);
        }
    }

    // ---- Completion ----

    public function test_a_settled_score_closes_the_book_through_the_existing_coda_path_exactly_once()
    {
        $campaign = $this->createCampaign();
        $this->openBareTurn($campaign);
        $this->ripen($campaign);

        $this->play($campaign);
        $this->play($campaign, 'face');

        // The nemesis is standing here. Put them down, and the score closes.
        $ended = null;
        for ($i = 0; $i < 10 && ! (Finale::stateOf($campaign->fresh()) === Finale::COMPLETE); $i++) {
            Meters::heal($campaign->character->fresh(), 20);
            $ended = $this->play($campaign, 'strike');
        }

        $campaign = $campaign->fresh();
        $this->assertSame(Finale::COMPLETE, $campaign->finale['state']);
        $this->assertSame('resolved', Grudge::where('campaign_id', $campaign->id)
            ->where('actor_name', 'the harbormaster')->first()->status);

        // Nothing left to choose: the resolver opened no successor.
        $this->assertSame('intent_complete', $ended->branch_trigger);
        $this->assertTrue($ended->resolution['finale']['complete']);
        $this->assertSame(0, $campaign->turns()->where('status', Turn::STATUS_AWAITING)->count());

        // The book closes behind the chapter that tells it — coda last, and one.
        app(Narrator::class)->narrate($ended->fresh());

        $campaign = $campaign->fresh();
        $this->assertSame('completed', $campaign->status);
        $this->assertNotNull($campaign->ended_at);
        $this->assertSame(1, $campaign->chapters()->where('kind', 'coda')->count());
        $this->assertSame('coda', $campaign->chapters()->reorder('number', 'desc')->first()->kind);
        $this->assertNotNull(collect($this->prompts)->first(fn (string $p) => str_contains($p, 'closing coda')));
    }

    public function test_a_filled_reckoning_closes_the_book_the_same_way()
    {
        $campaign = $this->createCampaign();
        $this->openBareTurn($campaign);
        $this->ripen($campaign, withGrudge: false);

        $this->play($campaign);
        $this->play($campaign, 'face');

        // Every beat but the last, handed over directly: this test is about what
        // the FILL does, not about how often the dice have to be asked.
        $clock = Finale::reckoning($campaign->fresh());
        $clock->update(['filled' => $clock->segments - 1]);

        $ended = null;
        for ($i = 0; $i < 12 && $clock->fresh()->status === 'open'; $i++) {
            Meters::heal($campaign->character->fresh(), 20);
            $ended = $this->play($campaign, 'improvise');
        }

        $this->assertSame('filled', $clock->fresh()->status);
        $this->assertSame(Finale::COMPLETE, Finale::stateOf($campaign->fresh()));
        $this->assertTrue($ended->resolution['finale']['complete']);
        $this->assertSame(0, $campaign->fresh()->turns()->where('status', Turn::STATUS_AWAITING)->count());

        app(Narrator::class)->narrate($ended->fresh());

        $campaign = $campaign->fresh();
        $this->assertSame('completed', $campaign->status);
        $this->assertSame(1, $campaign->chapters()->where('kind', 'coda')->count());
    }

    public function test_a_fall_past_the_cap_mid_finale_ends_the_tale_through_its_own_path()
    {
        $campaign = $this->createCampaign();
        $this->openBareTurn($campaign);
        $this->ripen($campaign, withGrudge: false);

        $this->play($campaign);
        $this->play($campaign, 'face');
        $this->assertSame(Finale::UNDERWAY, Finale::stateOf($campaign->fresh()));

        // Two marks already carried, and the body runs out mid-stretch.
        $this->scar($campaign, 'dimmed_eye');
        Meters::damage($campaign->character->fresh(), 20);
        $ended = $this->play($campaign);

        // The scar cap's own path, unchanged and unaware of any of this.
        $this->assertTrue($ended->resolution['fall']['final']);
        $this->assertNull($ended->resolution['finale'], 'the finale special-cased a fall');
        $this->assertSame(Finale::UNDERWAY, Finale::stateOf($campaign->fresh()));
        $this->assertSame('resource_threshold', $ended->branch_trigger);

        app(Narrator::class)->narrate($ended->fresh());

        $campaign = $campaign->fresh();
        $this->assertSame('completed', $campaign->status);
        $this->assertSame(1, $campaign->chapters()->where('kind', 'coda')->count());
    }

    // ---- What the narrator is told ----

    public function test_the_narrator_is_told_to_write_toward_rest_and_never_told_a_mechanic()
    {
        $campaign = $this->createCampaign();
        $this->openBareTurn($campaign);
        $this->ripen($campaign, withGrudge: false);

        // Armed is not underway: a tale that has only been offered an ending
        // carries no instructions about one.
        $armed = $this->play($campaign);
        $this->assertSame('', Finale::narratorBlock($armed->fresh()));

        $begun = $this->play($campaign, 'face');
        app(Narrator::class)->narrate($begun->fresh());

        $prompt = collect($this->prompts)->first(fn (string $p) => str_contains($p, 'You are the narrator'));
        $this->assertNotNull($prompt);
        $this->assertStringContainsString('## The closing movement', $prompt);

        $block = mb_substr($prompt, mb_strpos($prompt, '## The closing movement'));
        $block = mb_substr($block, 0, mb_strpos($block, '## Where the vignette stops') ?: mb_strlen($block));

        $this->assertStringContainsString('last stretch', $block);
        $this->assertStringContainsString('Write toward rest', $block);
        $this->assertStringContainsString('never address the reader', $block);
        $this->assertDoesNotMatchRegularExpression('/\d/', $block);

        foreach (['dice', 'roll', 'card', 'meter', 'difficulty', 'DC', 'clock', 'signal'] as $mechanic) {
            $this->assertStringNotContainsStringIgnoringCase($mechanic, $block,
                "the closing block leaked mechanics language: {$mechanic}");
        }
    }
}
