<?php

namespace Tests\Feature;

use App\Game\Engine\Ambient;
use App\Game\Engine\BeatOutcome;
use App\Game\Engine\CardComposer;
use App\Game\Engine\Dice;
use App\Game\Engine\Odds;
use App\Game\Engine\SituationBoard;
use App\Game\Engine\Standings;
use App\Game\Engine\TurnResolver;
use App\Game\Meters;
use App\Models\Actor;
use App\Models\Campaign;
use App\Models\Character;
use App\Models\Grudge;
use App\Models\Scene;
use App\Models\SceneFeature;
use App\Models\Standing;
use App\Models\Turn;
use App\Models\User;
use App\Services\Claude\ClaudeCli;
use App\Services\Claude\Narrator;
use App\Services\TurnStarter;
use Database\Seeders\WorldSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Standing: places remember what you did there.
 *
 * A grudge is one enemy's memory of the player; a standing is the GROUND's.
 * One clamped ledger per campaign per zone, moved only by a closed table of
 * facts the resolver had already fixed, priced as one small itemized part on
 * the social verbs alone, and carried into words by the board and the narrator.
 *
 * Zero is the whole discipline: most ground has no opinion, and an unknown must
 * emit nothing anywhere — no line, no part, no fact.
 */
class StandingTest extends TestCase
{
    use RefreshDatabase;

    private function createCampaign(array $attributes = []): Campaign
    {
        $this->seed(WorldSeeder::class);

        $this->mock(ClaudeCli::class, function ($mock) {
            $mock->shouldReceive('promptForJson')->andThrow(new \RuntimeException('offline'))->byDefault();
            $mock->shouldReceive('prompt')->andReturn('A tale begins.')->byDefault();
        });

        $campaign = Campaign::create($attributes + [
            'user_id' => User::factory()->create()->id,
            'name' => 'The Long Memory',
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

        $campaign->character->capabilities()->create(['capability' => 'break', 'source' => 'creation']);

        return $campaign->fresh();
    }

    /** A turn on ground the test controls: no strangers, no leftover props, no weather. */
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

    /** Stand the tale at a given score with this ground, the way resolved turns would have left it. */
    private function setStanding(Campaign $campaign, int $score, ?Scene $scene = null): Scene
    {
        $scene ??= $campaign->activeScene;

        Standing::updateOrCreate(
            ['campaign_id' => $campaign->id, 'zone_id' => $scene->zone_id],
            ['score' => $score, 'history' => []],
        );

        return $scene->fresh();
    }

    private function somebodyToTalkTo(Scene $scene, array $tags = []): Actor
    {
        return Actor::create([
            'scene_id' => $scene->id,
            'zone_id' => $scene->zone_id,
            'name' => 'a quay-side fishwife',
            'kind' => 'npc',
            'tier' => 'regular',
            'stats' => ['health' => ['current' => 4, 'max' => 4], 'attack' => 1],
            'tags' => $tags,
            'status' => 'active',
            'source' => 'seed',
        ]);
    }

    private function enemy(Scene $scene, string $name, array $tags = []): Actor
    {
        return Actor::create([
            'scene_id' => $scene->id,
            'zone_id' => $scene->zone_id,
            'name' => $name,
            'kind' => 'enemy',
            'tier' => 'regular',
            'stats' => ['health' => ['current' => 6, 'max' => 6], 'attack' => 1],
            'tags' => $tags,
            'status' => 'active',
            'source' => 'seed',
        ]);
    }

    private function breakable(Scene $scene, string $name): SceneFeature
    {
        return SceneFeature::create([
            'scene_id' => $scene->id,
            'zone_id' => $scene->zone_id,
            'name' => $name,
            'feature_type' => 'obstacle',
            'affordances' => ['breakable' => true],
            'state' => [],
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

    private function cardFor(Turn $turn, string $slot, string $verb): array
    {
        $card = collect($turn->cards[$slot])->firstWhere('verb', $verb);
        $this->assertNotNull($card, "no {$verb} card was offered in the {$slot} slot");

        return $card;
    }

    /** Submit one card and resolve, returning the beat the dice actually paid. */
    private function resolveCard(Turn $turn, string $slot, array $card, ?string $note = null): array
    {
        $turn->update([
            'status' => Turn::STATUS_LOCKED,
            'submission' => [$slot => ['card_id' => $card['id'], 'modifiers' => [], 'note' => $note]],
            'submitted_at' => now(),
        ]);

        app(TurnResolver::class)->resolve($turn->fresh());

        $beat = collect($turn->fresh()->resolution['beats'])->firstWhere('verb', $card['verb']);
        $this->assertNotNull($beat, "the {$card['verb']} beat never resolved");

        return $beat;
    }

    private function scoreOf(Campaign $campaign, ?Scene $scene = null): int
    {
        return Standings::of(($scene ?? $campaign->activeScene)->fresh());
    }

    private function anyTurn(Campaign $campaign): Turn
    {
        return $campaign->turns()->orderByDesc('number')->firstOrFail();
    }

    /** @return list<string> */
    private function labels(array $parts): array
    {
        return array_column($parts, 'label');
    }

    /** The board's standing line, or null when the board is not carrying one. */
    private function standingLine(array $board): ?string
    {
        return collect($board)->firstWhere('key', 'standing')['items'][0] ?? null;
    }

    /**
     * The closed table, event by event: one point apiece, in the direction the
     * table says, and every one of them written down.
     */
    public function test_every_event_in_the_table_moves_the_score_by_exactly_one_and_writes_it_down()
    {
        $campaign = $this->createCampaign();
        $this->openBareTurn($campaign);
        $turn = $this->anyTurn($campaign);
        $scene = $campaign->activeScene;

        $this->assertSame([
            Standings::CAPTIVE_FREED => 1,
            Standings::ELITE_BEATEN => 1,
            Standings::RIVAL_SPARED => 1,
            Standings::GRUDGE_BORN => -1,
            Standings::GROUND_WRECKED => -1,
            Standings::ALARM_ANSWERED => -1,
        ], Standings::EVENTS);

        foreach (Standings::EVENTS as $event => $shift) {
            Standing::query()->delete();

            $fact = Standings::record($scene, $turn, [$event]);

            $this->assertSame($shift, $this->scoreOf($campaign), "{$event} did not move the score by its own point");
            $this->assertNotNull($fact, "{$event} moved the score and said nothing about it");

            $row = Standing::firstOrFail();
            $this->assertSame([['turn_id' => $turn->id, 'event' => $event, 'shift' => $shift]], $row->history);
        }

        // Append-only: a second turn's events join the record, never replace it.
        Standing::query()->delete();
        Standings::record($scene, $turn, [Standings::CAPTIVE_FREED]);
        Standings::record($scene, $turn, [Standings::GROUND_WRECKED]);

        $this->assertCount(2, Standing::firstOrFail()->history);
        $this->assertSame(0, $this->scoreOf($campaign));

        // Anything the table does not know is not an event. A word the player
        // typed is exactly that, and it moves nothing and records nothing.
        Standing::query()->delete();
        $this->assertNull(Standings::record($scene, $turn, ['they were magnificent about it']));
        $this->assertSame(0, Standing::count());
    }

    /** Three either way, and no further however long the tale keeps at it. */
    public function test_the_clamp_holds_at_both_ends_and_the_record_keeps_going()
    {
        $campaign = $this->createCampaign();
        $this->openBareTurn($campaign);
        $turn = $this->anyTurn($campaign);
        $scene = $campaign->activeScene;

        Standings::record($scene, $turn, array_fill(0, 6, Standings::CAPTIVE_FREED));
        $this->assertSame(3, $this->scoreOf($campaign));

        // The score stopped; the ledger did not — a well wrecked at the floor
        // still happened, and the history is what the narrator may quote.
        $this->assertCount(6, Standing::firstOrFail()->history);

        // ...and a run at the ceiling says nothing, because nothing moved.
        $this->assertNull(Standings::record($scene, $turn, [Standings::ELITE_BEATEN]));

        Standings::record($scene, $turn, array_fill(0, 9, Standings::GRUDGE_BORN));
        $this->assertSame(-3, $this->scoreOf($campaign));
        $this->assertNull(Standings::record($scene, $turn, [Standings::ALARM_ANSWERED]));

        // And a score that cancels itself out over one turn is not news either.
        $this->assertNull(Standings::record($scene, $turn, [Standings::CAPTIVE_FREED, Standings::GRUDGE_BORN]));

        $this->assertSame(-3, $this->scoreOf($campaign));

        // The clamp is the config's, wherever it is set.
        config(['game.standing.clamp' => 1]);
        $this->assertSame(-1, $this->scoreOf($campaign));
    }

    /**
     * The resolver reads every one of these off facts it had already fixed.
     * Detection only: nothing here is a new event source.
     */
    public function test_the_resolver_detects_the_table_from_the_facts_it_already_fixed()
    {
        $campaign = $this->createCampaign();
        $this->openBareTurn($campaign);
        $turn = $this->anyTurn($campaign);
        $scene = $campaign->activeScene;

        $detect = new ReflectionMethod(TurnResolver::class, 'standingEvents');
        $events = fn (array $outcomes, array $captives, array $elites, bool $forced) => $detect->invoke(
            app(TurnResolver::class), $turn, $scene->fresh(), $scene->fresh(), $outcomes, $captives, $elites, $forced,
        );

        // Somebody cut loose: in a grip when the turn began, loose and whole now.
        $captive = $this->somebodyToTalkTo($scene);
        $this->assertSame([Standings::CAPTIVE_FREED], $events([], [$captive->id], [], false));

        // The thing this place could not handle, handled.
        $elite = $this->enemy($scene, 'the harbormaster of the third pier');
        $elite->update(['tier' => 'elite', 'status' => 'defeated']);
        $this->assertSame([Standings::ELITE_BEATEN], $events([], [], [$elite->id], false));

        // An old score closed without killing: bargained out, or taken and kept.
        $rival = $this->enemy($scene, 'the one who ran', ['called_off' => true]);
        $rival->update(['status' => 'fled']);
        Grudge::create([
            'campaign_id' => $campaign->id,
            'actor_name' => 'the one who ran',
            'stats' => $rival->stats,
            'tags' => [],
            'tier' => 'regular',
            'history' => [['turn_id' => $turn->id, 'event' => 'resolved', 'detail' => 'Settled.', 'place' => null]],
            'heat' => 1,
            'disposition' => 'scheming',
            'status' => 'resolved',
            'last_seen_chapter_id' => null,
        ]);
        $this->assertSame([Standings::RIVAL_SPARED], $events([], [], [], false));

        // ...and a score closed by killing them is not sparing anybody.
        $rival->update(['status' => 'dead']);
        $this->assertSame([], $events([], [], [], false));
        $rival->delete();
        Grudge::query()->delete();

        // Somebody broke and ran from here, and the tale wrote it down.
        Grudge::create([
            'campaign_id' => $campaign->id,
            'actor_name' => 'a dockside tough',
            'stats' => ['health' => ['current' => 1, 'max' => 6], 'attack' => 1],
            'tags' => [],
            'tier' => 'regular',
            'history' => [['turn_id' => $turn->id, 'event' => 'fled', 'detail' => 'Ran.', 'place' => 'the quay']],
            'heat' => 1,
            'disposition' => 'vengeful',
            'status' => 'simmering',
            'last_seen_chapter_id' => null,
        ]);
        $this->assertSame([Standings::GRUDGE_BORN], $events([], [], [], false));
        Grudge::query()->delete();

        // A piece of this place put beyond use by the player's own hand...
        $broke = new BeatOutcome('main', 'break', null, BeatOutcome::SUCCESS, 15, 15, 10);
        $this->assertSame([Standings::GROUND_WRECKED], $events([$broke], [], [], false));

        // ...once per scene, however many crates go after it.
        $this->assertSame([], $events([$broke, $broke], [], [], false));

        // The district had to come and deal with it.
        $this->assertSame([Standings::ALARM_ANSWERED], $events([], [], [], true));

        // A break that did not land wrecked nothing, and neither did one that
        // never happened at all.
        $scene->update(['state' => array_merge($scene->state ?? [], ['standing_wrecked' => 0])]);
        $held = new BeatOutcome('main', 'break', null, BeatOutcome::FAILURE, 3, 3, 10);
        $this->assertSame([], $events([$held], [], [], false));
    }

    /**
     * End to end: the ground remembers a wrecking, says one plain sentence
     * about it, and does not say it again for the next four crates.
     */
    public function test_a_wrecked_scene_costs_the_ground_once_and_is_recorded_in_plain_words()
    {
        $campaign = $this->createCampaign();
        $turn = $this->openBareTurn($campaign);
        $scene = $campaign->activeScene;

        $this->breakable($scene, 'the winch housing');
        $this->breakable($scene, 'the tally-board');
        $turn = $this->refreshCards($turn);

        $card = collect($turn->cards['main'])->firstWhere('label', 'Break the winch housing');
        $this->assertNotNull($card);

        // Break beats roll, so keep going until one lands — the standing is
        // interested in what happened, never in how the die got there.
        $landed = false;
        foreach (range(1, 12) as $attempt) {
            $beat = $this->resolveCard($turn, 'main', $card);
            $resolution = $turn->fresh()->resolution;

            if (in_array($beat['degree'], [BeatOutcome::SUCCESS, BeatOutcome::STRONG], true)) {
                $landed = true;
                $this->assertSame(-1, $this->scoreOf($campaign));
                $this->assertNotNull($resolution['standing']);
                $this->assertStringContainsString('not kindly', $resolution['standing']);
                break;
            }

            $this->assertSame(0, $this->scoreOf($campaign));
            $this->assertNull($resolution['standing']);

            $turn = $this->refreshCards($this->anyTurn($campaign));
            $card = collect($turn->cards['main'])->firstWhere('label', 'Break the winch housing');
            $this->assertNotNull($card);
        }

        $this->assertTrue($landed, 'nothing ever broke, so nothing could be remembered about it');

        // The second thing taken apart in the same room is the same room.
        foreach (range(1, 6) as $attempt) {
            $turn = $this->refreshCards($this->anyTurn($campaign));
            $next = collect($turn->cards['main'])->firstWhere('label', 'Break the tally-board');
            if ($next === null) {
                break;
            }
            $this->resolveCard($turn, 'main', $next);
            $this->assertSame(-1, $this->scoreOf($campaign), 'one room was charged for twice');
        }
    }

    /** The district answering an alarm is something this place holds against them. */
    public function test_an_alarm_the_district_had_to_answer_costs_the_ground()
    {
        $campaign = $this->createCampaign();
        $turn = $this->openBareTurn($campaign);
        $scene = $campaign->activeScene;

        $this->enemy($scene, 'a dockside tough', ['intent' => 'guard']);
        $turn = $this->refreshCards($turn);

        // Three turns toe-to-toe in the same place, which is what the alarm
        // clock is counting. Nothing else here moves the ledger.
        foreach (range(1, 3) as $round) {
            $this->resolveCard($turn, 'main', $this->cardFor($turn, 'main', 'wait'));
            $turn = $this->refreshCards($this->anyTurn($campaign));
        }

        $this->assertSame(-1, $this->scoreOf($campaign));

        $history = Standing::firstOrFail()->history;
        $this->assertSame(Standings::ALARM_ANSWERED, $history[0]['event']);
    }

    /** Nothing. No line, no part, no fact, no block, no row. */
    public function test_zero_is_silent_everywhere()
    {
        $campaign = $this->createCampaign();
        $turn = $this->openBareTurn($campaign);
        $scene = $campaign->activeScene;
        $this->somebodyToTalkTo($scene);

        $this->assertSame('silent', Standings::tier(0));
        $this->assertNull(Standings::line(0));
        $this->assertNull(Standings::fact(0));

        foreach (['speak', 'persuade', 'intimidate', 'strike', 'hide', 'ascend'] as $verb) {
            $this->assertSame([], Odds::standingParts(0, $verb, 'persuade'));
        }

        $turn = $this->refreshCards($turn);
        $speak = $this->cardFor($turn, 'main', 'speak');
        $this->assertSame(['Base difficulty'], $this->labels($speak['forecast']['parts']));
        $this->assertSame(Odds::BASE, $speak['forecast']['difficulty']);

        $board = SituationBoard::for($campaign->character->fresh(), $scene->fresh(), null);
        $this->assertNotContains('standing', array_column($board, 'key'));

        // An ordinary turn carries no instructions about a place with no
        // opinion, and no record of one either.
        $this->resolveCard($turn, 'main', $speak);
        $this->assertNull($turn->fresh()->resolution['standing']);
        $this->assertSame(0, Standing::count());
        $this->assertSame('', Standings::narratorBlock($turn->fresh()));
    }

    /**
     * The load-bearing promise: what the card quoted is what the dice charged,
     * on ground that welcomes them and ground that does not. One ladder, two
     * readers.
     */
    public function test_the_forecast_part_is_the_resolved_part_on_both_kinds_of_ground()
    {
        $expected = [
            3 => ['Your name carries here', -1],
            1 => ['Your name carries here', -1],
            -1 => ['Your name is spat here', 1],
            -3 => ['Your name is spat here', 1],
        ];

        foreach ($expected as $score => [$label, $amount]) {
            $campaign = $this->createCampaign();
            $turn = $this->openBareTurn($campaign);
            $this->somebodyToTalkTo($campaign->activeScene);
            $this->setStanding($campaign, $score);
            $turn = $this->refreshCards($turn);

            $speak = $this->cardFor($turn, 'main', 'speak');
            $this->assertContains($label, $this->labels($speak['forecast']['parts']), "no standing part on the card at {$score}");
            $this->assertSame(Odds::BASE + $amount, $speak['forecast']['difficulty']);

            $beat = $this->resolveCard($turn, 'main', $speak);
            $this->assertSame($speak['forecast']['difficulty'], $beat['difficulty'], "the dice went off the card at {$score}");
            $this->assertContains($label, $this->labels($beat['difficulty_parts']));
            $this->assertSame($amount, collect($beat['difficulty_parts'])->firstWhere('label', $label)['amount']);
        }
    }

    /**
     * One point, social and presence verbs only. Stealth, steel, and stone do
     * not care what the town thinks, and a score at the far end is worth
     * exactly what a score at the near end is.
     */
    public function test_the_part_touches_the_social_verbs_only_and_never_grows()
    {
        $social = ['speak', 'persuade', 'deceive', 'calm', 'intimidate', 'recruit'];
        $everythingElse = [
            'strike', 'interrupt', 'restrain', 'hide', 'ascend', 'cross', 'flee',
            'detect', 'scout', 'track', 'hurl', 'lift', 'break', 'improvise',
            'bandage', 'ride', 'loot', 'haul', 'recover',
            // Verbs that cast no die can never be charged a price.
            'command', 'bargain', 'wait', 'examine', 'ready',
            // A companion's own request is their body and their nerve.
            'companion_strike', 'companion_scout', 'companion_harry',
        ];

        foreach ([-3, -2, -1, 1, 2, 3] as $score) {
            foreach ($social as $verb) {
                $parts = Odds::standingParts($score, $verb);
                $this->assertCount(1, $parts, "{$verb} was not priced at {$score}");
                $this->assertSame(1, abs($parts[0]['amount']), 'standing grew past its one point');
                $this->assertSame($score > 0 ? -1 : 1, $parts[0]['amount']);
            }

            foreach ($everythingElse as $verb) {
                $this->assertSame([], Odds::standingParts($score, $verb, 'climb'), "{$verb} was priced by the town's opinion");
            }

            // The trained tongues price through the gift as well as the verb.
            foreach (['persuade', 'deceive', 'calm', 'intimidate'] as $capability) {
                $this->assertCount(1, Odds::standingParts($score, 'improvise', $capability));
            }
        }
    }

    /**
     * Never a dead choice. A shunned zone still offers every word it offered
     * before — the price moved, the options did not.
     */
    public function test_a_shunned_zone_offers_exactly_the_cards_a_welcoming_one_does()
    {
        $campaign = $this->createCampaign();
        $this->openBareTurn($campaign);
        $this->somebodyToTalkTo($campaign->activeScene);

        $offered = [];
        foreach ([-3, -1, 0, 1, 3] as $score) {
            $scene = $this->setStanding($campaign, $score);
            $cards = app(CardComposer::class)->compose($campaign->character->fresh(), $scene);
            $offered[$score] = collect($cards)->only(['pre', 'main', 'post'])
                ->flatMap(fn ($slot) => array_column($slot, 'label'))->sort()->values()->all();
        }

        foreach ([-3, -1, 1, 3] as $score) {
            $this->assertSame($offered[0], $offered[$score], "the offers changed at {$score}");
        }

        $this->assertContains('Speak with a quay-side fishwife', $offered[-3]);
    }

    /**
     * The bias is a lean and nothing more, it lands on a newcomer's FIRST
     * telegraph only, and it bends the draw the existing machinery already
     * cast rather than casting one of its own.
     */
    public function test_the_arrival_bias_leans_a_newcomers_first_telegraph_and_nothing_else()
    {
        // The pure shape of it, first: silent ground changes nothing at all.
        foreach (range(1, 6) as $roll) {
            $this->assertSame($roll, Standings::bendFirstIntent($roll, 0));
            $this->assertLessThanOrEqual($roll, Standings::bendFirstIntent($roll, -3));
            $this->assertGreaterThanOrEqual($roll, Standings::bendFirstIntent($roll, 2));
            $this->assertContains(Standings::bendFirstIntent($roll, -1), range(1, 6));
            $this->assertContains(Standings::bendFirstIntent($roll, 1), range(1, 6));
        }

        $campaign = $this->createCampaign();
        $this->openBareTurn($campaign);
        $scene = $campaign->activeScene;

        $roll = new ReflectionMethod(TurnResolver::class, 'rollEnemyIntents');

        $seen = [];
        foreach ([-3, 0, 3] as $score) {
            $seen[$score] = ['newcomer' => [], 'standing' => []];

            foreach (range(1, 24) as $seed) {
                $scene->actors()->delete();
                $newcomer = $this->enemy($scene, 'somebody who just walked in');
                $veteran = $this->enemy($scene, 'somebody already here', ['intent' => 'circle']);
                $lurker = $this->enemy($scene, 'somebody unseen', ['lurking' => true, 'lurking_since' => 1]);
                $this->setStanding($campaign, $score);

                $roll->invoke(app(TurnResolver::class), $scene->fresh(), new Dice($seed));

                $seen[$score]['newcomer'][$seed] = $newcomer->fresh()->tags['intent'];
                $seen[$score]['standing'][$seed] = $veteran->fresh()->tags['intent'];

                // Hidden stays hidden: the town's opinion never reaches a lurker.
                $this->assertTrue($lurker->fresh()->tags['lurking']);
                $this->assertArrayNotHasKey('intent', $lurker->fresh()->tags);
            }
        }

        // Whoever was already standing here re-rolls exactly as they always
        // did: same seed, same telegraph, whatever the town thinks.
        $this->assertSame($seen[0]['standing'], $seen[-3]['standing']);
        $this->assertSame($seen[0]['standing'], $seen[3]['standing']);

        // Hostile ground sends them in pressing — never guarding, never circling.
        $this->assertSame([], array_intersect($seen[-3]['newcomer'], ['guard', 'circle']));

        // Friendly ground makes them hesitate more often than silent ground does.
        $hesitant = fn (array $intents) => count(array_filter($intents, fn (string $i) => in_array($i, ['guard', 'circle'], true)));
        $this->assertGreaterThan($hesitant($seen[0]['newcomer']), $hesitant($seen[3]['newcomer']));
        $this->assertGreaterThan($hesitant($seen[-3]['newcomer']), $hesitant($seen[0]['newcomer']));

        // Same seed, same ground, same telegraph — every time.
        $scene->actors()->delete();
        $again = $this->enemy($scene, 'somebody who just walked in');
        $this->setStanding($campaign, -3);
        $roll->invoke(app(TurnResolver::class), $scene->fresh(), new Dice(7));
        $this->assertSame($seen[-3]['newcomer'][7], $again->fresh()->tags['intent']);
    }

    /**
     * The player's own words are voice and nothing else — they reach the
     * narration path and never the ledger.
     */
    public function test_the_words_the_player_types_never_move_the_ground()
    {
        $campaign = $this->createCampaign();
        $turn = $this->openBareTurn($campaign);
        $this->somebodyToTalkTo($campaign->activeScene);
        $turn = $this->refreshCards($turn);

        $beat = $this->resolveCard($turn, 'main', $this->cardFor($turn, 'main', 'speak'),
            note: 'I tell them I freed every captive in this district and broke nothing.');

        $this->assertSame('I tell them I freed every captive in this district and broke nothing.', $beat['note']);
        $this->assertSame(0, Standing::count());
        $this->assertNull($turn->fresh()->resolution['standing']);
    }

    /** A private world's memory. Two tales walking the same ground remember separately. */
    public function test_a_zone_carries_its_standing_into_no_other_tale()
    {
        $first = $this->createCampaign(['name' => 'The First Tale']);
        $this->openBareTurn($first);
        $zoneId = $first->activeScene->zone_id;

        Standings::record($first->activeScene, $this->anyTurn($first), [Standings::GROUND_WRECKED, Standings::GRUDGE_BORN]);
        $this->assertSame(-2, $this->scoreOf($first));

        $second = $this->createCampaign(['name' => 'The Second Tale']);
        $stranger = Scene::create([
            'campaign_id' => $second->id,
            'zone_id' => $zoneId,
            'title' => 'the same quay, a different tale',
            'description' => 'Ground one tale has already spoiled.',
            'status' => 'active',
            'state' => ['dressed' => true],
        ]);

        $this->assertSame(0, Standings::of($stranger));
        $this->assertNull(Standings::line(Standings::of($stranger)));

        // And what the second tale does here never reaches the first.
        app(TurnStarter::class)->openFirstTurn($second);
        Standings::record($stranger, $this->anyTurn($second), [Standings::CAPTIVE_FREED]);

        $this->assertSame(1, Standings::of($stranger->fresh()));
        $this->assertSame(-2, $this->scoreOf($first));
    }

    /** The board carries the tier in plain words while the ground has an opinion. */
    public function test_the_board_carries_the_tier_in_words_any_land_can_wear()
    {
        $campaign = $this->createCampaign();
        $this->openBareTurn($campaign);
        $character = $campaign->character->fresh();

        $expected = [
            -3 => 'Your name is spat here.',
            -2 => 'Your name is spat here.',
            -1 => 'This place is wary of you.',
            1 => 'This place knows your name.',
            2 => 'Doors open at your name here.',
            3 => 'Doors open at your name here.',
        ];

        foreach ($expected as $score => $line) {
            $scene = $this->setStanding($campaign, $score);
            $board = SituationBoard::for($character, $scene, null);

            $this->assertSame($line, $this->standingLine($board), "the board misread {$score}");
            $this->assertStringContainsString($line, SituationBoard::prose($board));

            // No numbers, no engine words, nothing a player could farm.
            $this->assertDoesNotMatchRegularExpression('/\d/', $line);
            foreach (['standing', 'score', 'tier', 'reputation', 'bonus', 'roll'] as $word) {
                $this->assertStringNotContainsStringIgnoringCase($word, $line);
            }
        }

        $this->assertNull($this->standingLine(SituationBoard::for($character, $this->setStanding($campaign, 0), null)));
    }

    /** The narrator gets people and doors — never a number, never a stat. */
    public function test_the_narration_prompt_carries_the_ground_in_plain_facts()
    {
        $campaign = $this->createCampaign();
        $turn = $this->openBareTurn($campaign);
        $this->setStanding($campaign, -3);

        $turn->update([
            'status' => Turn::STATUS_COMPLETE,
            'branch_trigger' => 'decision_point',
            'resolution' => [
                'beats' => [[
                    'slot' => 'main', 'verb' => 'examine', 'target' => null,
                    'degree' => 'success', 'roll' => 14, 'total' => 14, 'difficulty' => 10,
                    'facts' => ['The crates held nothing.'], 'skipped' => false, 'crit' => null,
                ]],
                'scene_reaction' => [], 'reaction_rolls' => [], 'new_threat' => null,
                'downtime' => null, 'standing' => 'What they did here will be talked about, and not kindly.',
            ],
        ]);

        $prompt = (new ReflectionMethod(Narrator::class, 'buildPrompt'))
            ->invoke(app(Narrator::class), $turn->fresh());

        $this->assertStringContainsString('How this place holds them', $prompt);
        $this->assertStringContainsString('what they remember is bad', $prompt);
        $this->assertStringContainsString('not kindly', $prompt);
        $this->assertStringContainsString('Let it show ONCE', $prompt);

        // Plain welcome reads the other way, and silent ground says nothing.
        $this->setStanding($campaign, 2);
        $welcome = (new ReflectionMethod(Narrator::class, 'buildPrompt'))
            ->invoke(app(Narrator::class), $turn->fresh());
        $this->assertStringContainsString('glad they came back', $welcome);

        $this->setStanding($campaign, 0);
        $turn->update(['resolution' => array_merge($turn->fresh()->resolution, ['standing' => null])]);
        $silent = (new ReflectionMethod(Narrator::class, 'buildPrompt'))
            ->invoke(app(Narrator::class), $turn->fresh());
        $this->assertStringNotContainsString('How this place holds them', $silent);

        // No mechanics language anywhere in what the ground says about itself.
        foreach ([-3, -1, 1, 3] as $score) {
            $fact = Standings::fact($score);
            $this->assertDoesNotMatchRegularExpression('/\d/', $fact);
            foreach (['standing', 'score', 'card', 'roll', 'die', 'meter', 'bonus'] as $word) {
                $this->assertStringNotContainsStringIgnoringCase($word, $fact);
            }
        }
    }

    /**
     * The evolver tends grudges, zones, and actors. It does not tend this —
     * a world that quietly forgave you overnight would make the whole ledger a
     * question of how long the app stayed closed.
     */
    public function test_the_evolver_never_touches_a_standing()
    {
        foreach (['app/Services/Claude/WorldEvolver.php', 'app/Console/Commands'] as $path) {
            $files = is_dir(base_path($path)) ? File::allFiles(base_path($path)) : [base_path($path)];

            foreach ($files as $file) {
                $this->assertStringNotContainsString('Standing', File::get((string) $file),
                    'evolution reached for a standing — the ground remembers only what the player did in front of it');
            }
        }
    }
}
