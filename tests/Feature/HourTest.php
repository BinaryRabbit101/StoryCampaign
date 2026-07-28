<?php

namespace Tests\Feature;

use App\Game\Engine\Ambient;
use App\Game\Engine\CardComposer;
use App\Game\Engine\Hours;
use App\Game\Engine\Odds;
use App\Game\Engine\SituationBoard;
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
use App\Services\Claude\Narrator;
use App\Services\TurnStarter;
use Database\Seeders\WorldSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The hour: the wheel the wait turns.
 *
 * Four phases on the campaign — dawn, day, dusk, night — advanced by the turns
 * played and by the REAL minutes of the idle wait, priced as itemized parts on
 * the one ladder both a card's forecast and the die read. The vocabulary is
 * abstract for the same reason ambient's is: it has to work on an ash steppe
 * and aboard a derelict station alike, so the engine never says sunrise and
 * never names a clock time. The narrator is the only thing that turns a phase
 * into a horizon.
 */
class HourTest extends TestCase
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
            'name' => 'The Long Watch',
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

        // Everything the hour table touches: cover to take, ground to read,
        // and a way up worth losing your footing on.
        foreach (['conceal', 'scout', 'climb'] as $capability) {
            $campaign->character->capabilities()->create([
                'capability' => $capability,
                'source' => 'creation',
            ]);
        }

        return $campaign->fresh();
    }

    /** A turn on ground the test controls: no strangers, no leftover props. */
    private function openBareTurn(Campaign $campaign): Turn
    {
        $turn = app(TurnStarter::class)->openFirstTurn($campaign);
        $scene = $campaign->activeScene;
        $scene->actors()->delete();
        $scene->features()->delete();

        // The air is rolled when the ground is dressed; every test below that
        // is not about stacking wants it out of the arithmetic entirely.
        $scene->update(['state' => array_merge($scene->state ?? [], ['ambient' => Ambient::CLEAR])]);

        return $turn->fresh();
    }

    /** Stand the tale at a given point on the wheel, the way the advance would have left it. */
    private function setHour(Campaign $campaign, string $phase, int $progress = 0): Campaign
    {
        $campaign->forceFill(['hour_phase' => $phase, 'hour_progress' => $progress])->save();

        return $campaign->fresh();
    }

    private function setAmbient(Scene $scene, string $ambient): Scene
    {
        $scene->update(['state' => array_merge($scene->state ?? [], ['ambient' => $ambient])]);

        return $scene->fresh();
    }

    /** Copy a seeded zone template into the scene, so the ground is exactly what the test wants. */
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
    private function resolveCard(Turn $turn, string $slot, array $card): array
    {
        $turn->update([
            'status' => Turn::STATUS_LOCKED,
            'submission' => [$slot => ['card_id' => $card['id'], 'modifiers' => []]],
            'submitted_at' => now(),
        ]);

        app(TurnResolver::class)->resolve($turn->fresh());

        $beat = collect($turn->fresh()->resolution['beats'])->firstWhere('verb', $card['verb']);
        $this->assertNotNull($beat, "the {$card['verb']} beat never resolved");

        return $beat;
    }

    /** @return list<string> */
    private function labels(array $parts): array
    {
        return array_column($parts, 'label');
    }

    /**
     * The wheel turns one way and only one way, three played beats to a phase,
     * and it comes round rather than running off the end.
     */
    public function test_the_turns_played_step_the_wheel_in_order_and_wrap()
    {
        config(['game.hours.turns_per_phase' => 3]);
        $campaign = $this->createCampaign();

        $this->assertSame(Hours::DAY, Hours::of($campaign));

        // Two turns move progress and nothing else — the light does not lurch
        // every time somebody swings at something.
        $this->assertNull(Hours::advance($campaign));
        $this->assertSame(Hours::DAY, $campaign->hour_phase);
        $this->assertSame(1, $campaign->hour_progress);

        $this->assertNull(Hours::advance($campaign));
        $this->assertSame(2, $campaign->hour_progress);

        // The third steps, and says so.
        $this->assertSame(Hours::changed(Hours::DUSK), Hours::advance($campaign));
        $this->assertSame(Hours::DUSK, $campaign->hour_phase);
        $this->assertSame(0, $campaign->hour_progress);

        // All the way round, in the wheel's own order.
        $seen = [Hours::DUSK];
        for ($turn = 0; $turn < 9; $turn++) {
            Hours::advance($campaign);
            if ($campaign->hour_progress === 0) {
                $seen[] = $campaign->hour_phase;
            }
        }

        $this->assertSame([Hours::DUSK, Hours::NIGHT, Hours::DAWN, Hours::DAY], $seen);
        $this->assertSame(Hours::DAY, $campaign->hour_phase);

        // And the order itself, stated plainly.
        $this->assertSame([Hours::DAWN, Hours::DAY, Hours::DUSK, Hours::NIGHT], Hours::WHEEL);
        $this->assertSame(Hours::DAY, Hours::next(Hours::DAWN));
        $this->assertSame(Hours::DAWN, Hours::next(Hours::NIGHT));
    }

    /**
     * The real wait turns it too — that is the whole point — and a fortnight
     * away is a day later rather than a number a modulo picked.
     */
    public function test_the_real_wait_turns_the_wheel_and_a_long_absence_goes_no_further_than_once_around()
    {
        config(['game.hours.turns_per_phase' => 3, 'game.hours.minutes_per_phase' => 240]);

        // A coffee break is not a wait: under the phase it buys nothing, and
        // the turn's own step is all that moves.
        $campaign = $this->setHour($this->createCampaign(), Hours::DAY);
        Hours::advance($campaign, now()->subMinutes(239));
        $this->assertSame(Hours::DAY, $campaign->hour_phase);
        $this->assertSame(1, $campaign->hour_progress);

        // Four hours away is one phase of light, and it lands at the TOP of the
        // new one — arriving three-quarters through a light you have only just
        // reached is arithmetic nobody could read.
        $campaign = $this->setHour($campaign, Hours::DAY, 2);
        $this->assertSame(Hours::changed(Hours::DUSK), Hours::advance($campaign, now()->subMinutes(240)));
        $this->assertSame(Hours::DUSK, $campaign->hour_phase);
        $this->assertSame(1, $campaign->hour_progress);

        // A night away is two.
        $campaign = $this->setHour($campaign, Hours::DAY);
        Hours::advance($campaign, now()->subMinutes(480));
        $this->assertSame(Hours::NIGHT, $campaign->hour_phase);

        // A fortnight away is one full turn of the wheel and no more: same
        // light, and nothing to report about it.
        $campaign = $this->setHour($campaign, Hours::DUSK);
        $this->assertNull(Hours::advance($campaign, now()->subMinutes(14 * 24 * 60)));
        $this->assertSame(Hours::DUSK, $campaign->hour_phase);

        // Cap or no cap, the wheel never lands anywhere off it.
        foreach ([0, 60, 240, 700, 5000, 60000] as $minutes) {
            $campaign = $this->setHour($campaign, Hours::DAWN);
            Hours::advance($campaign, now()->subMinutes($minutes));
            $this->assertContains($campaign->hour_phase, Hours::WHEEL);
        }
    }

    /** Day is this wheel's clear air: no parts, no line, no fact, nothing anywhere. */
    public function test_plain_day_emits_nothing_anywhere()
    {
        $campaign = $this->createCampaign();
        $turn = $this->openBareTurn($campaign);
        $scene = $campaign->activeScene;

        $this->assertSame(Hours::DAY, Hours::of($campaign));
        $this->assertNull(Hours::line(Hours::DAY));
        $this->assertNull(Hours::fact(Hours::DAY));
        $this->assertNull(Hours::changed(Hours::DAY));

        foreach (['hide', 'scout', 'detect', 'ascend', 'cross', 'bandage', 'recover', 'strike', 'flee'] as $verb) {
            $this->assertSame([], Odds::hourParts(Hours::DAY, $verb, 'conceal'));
            $this->assertSame([], Odds::hourParts(Hours::DAY, $verb, 'climb'));
            // A tale from before the wheel existed reads exactly the same way.
            $this->assertSame([], Odds::hourParts(null, $verb));
        }

        $this->placeFeature($scene, 'a wall of stacked crates');
        $this->placeFeature($scene, 'the warehouse roof');
        $turn = $this->refreshCards($turn);

        foreach ([['pre', 'hide'], ['pre', 'ascend'], ['pre', 'scout']] as [$slot, $verb]) {
            $card = $this->cardFor($turn, $slot, $verb);
            $this->assertSame(['Base difficulty'], $this->labels($card['forecast']['parts']));
            $this->assertSame(Odds::BASE, $card['forecast']['difficulty']);
        }

        // Clear air in plain day is not a bullet saying nothing.
        $board = SituationBoard::for($campaign->character->fresh(), $scene->fresh(), null);
        $this->assertNotContains('sky', array_column($board, 'key'));
    }

    /**
     * The load-bearing promise: what the card quoted is what the dice charged,
     * at every point on the wheel. One ladder, two readers.
     */
    public function test_the_forecast_part_is_the_resolved_part_at_every_phase()
    {
        $expected = [
            Hours::DAWN => ['The world is waking around you', 1],
            Hours::DUSK => ['Failing light to keep out of', -1],
            Hours::NIGHT => ['The dark to move in', -2],
        ];

        foreach ($expected as $phase => [$label, $amount]) {
            $campaign = $this->createCampaign();
            $turn = $this->openBareTurn($campaign);
            $this->placeFeature($campaign->activeScene, 'a wall of stacked crates');
            $this->setHour($campaign, $phase);
            $turn = $this->refreshCards($turn);

            $hide = $this->cardFor($turn, 'pre', 'hide');
            $this->assertContains($label, $this->labels($hide['forecast']['parts']), "no hour part on the card at {$phase}");
            $this->assertSame(Odds::BASE + $amount, $hide['forecast']['difficulty']);

            // The quoted DC is the paid DC, itemized under the same name.
            $beat = $this->resolveCard($turn, 'pre', $hide);
            $this->assertSame($hide['forecast']['difficulty'], $beat['difficulty'], "the dice went off the card at {$phase}");
            $this->assertContains($label, $this->labels($beat['difficulty_parts']));
            $this->assertSame($amount, collect($beat['difficulty_parts'])->firstWhere('label', $label)['amount']);
        }
    }

    /**
     * Every non-day phase helps something and hinders something. A one-sided
     * hour would be difficulty creep on a schedule the player cannot refuse.
     */
    public function test_every_phase_that_speaks_at_all_speaks_both_ways()
    {
        $probe = ['hide', 'scout', 'detect', 'ascend', 'cross', 'bandage', 'recover'];

        foreach ([Hours::DAWN, Hours::DUSK, Hours::NIGHT] as $phase) {
            $amounts = [];
            foreach ($probe as $verb) {
                foreach (Odds::hourParts($phase, $verb, 'climb') as $part) {
                    $amounts[] = $part['amount'];
                }
            }

            $this->assertNotEmpty(array_filter($amounts, fn (int $a) => $a < 0), "{$phase} helps nothing");
            $this->assertNotEmpty(array_filter($amounts, fn (int $a) => $a > 0), "{$phase} costs nothing");

            // Small on purpose: the hour stacks with the air, and it may never
            // outweigh something the player built.
            foreach ($amounts as $amount) {
                $this->assertLessThanOrEqual(2, abs($amount), "{$phase} swings harder than the air does");
            }
        }
    }

    /**
     * A gloomy night is darker than either alone — and the pair stays inside
     * the ±4 spread the conditions ladder lives in, itemized as two named
     * reasons rather than one number nobody can account for.
     */
    public function test_the_air_and_the_light_stack_and_stay_inside_the_conditions_envelope()
    {
        $campaign = $this->createCampaign();
        $turn = $this->openBareTurn($campaign);
        $scene = $campaign->activeScene;

        $this->placeFeature($scene, 'a wall of stacked crates');
        $this->setAmbient($scene, Ambient::GLOOM);
        $this->setHour($campaign, Hours::NIGHT);
        $turn = $this->refreshCards($turn);

        $hide = $this->cardFor($turn, 'pre', 'hide');
        $labels = $this->labels($hide['forecast']['parts']);
        $this->assertContains('Little light to be seen by', $labels);
        $this->assertContains('The dark to move in', $labels);
        $this->assertSame(Odds::BASE - 4, $hide['forecast']['difficulty']);

        $scout = $this->cardFor($turn, 'pre', 'scout');
        $this->assertContains('Too little light to read the ground by', $this->labels($scout['forecast']['parts']));
        $this->assertContains('The dark to look through', $this->labels($scout['forecast']['parts']));
        $this->assertSame(Odds::BASE + 4, $scout['forecast']['difficulty']);

        // Nowhere on the wheel, under any sky, does the pair break the
        // envelope. Only cards the composer can actually produce are swept —
        // a scout spent through a climbing gift is not a card, and pricing an
        // impossible one would be measuring a beat nobody can take.
        $cards = [
            ['hide', 'conceal'], ['hide', 'quiet_move'], ['scout', null], ['detect', null],
            ['hurl', null], ['flee', null], ['track', null], ['ride', null],
            ['ascend', 'climb'], ['ascend', 'swing'], ['cross', 'leap'], ['cross', 'glide'],
            ['bandage', null], ['recover', null], ['strike', null],
        ];

        foreach (Ambient::KEYS as $air) {
            foreach (Hours::WHEEL as $phase) {
                foreach ($cards as [$verb, $capability]) {
                    $sum = array_sum(array_column(array_merge(
                        Odds::ambientParts($air, $verb, $capability),
                        Odds::hourParts($phase, $verb, $capability),
                    ), 'amount'));

                    $this->assertLessThanOrEqual(4, abs($sum),
                        "{$air} at {$phase} moves {$verb} by {$sum}");
                }
            }
        }
    }

    /**
     * The hour moves the odds of finding things; it never finds them. A hidden
     * door and a lurking ambusher are exactly as hidden at every point on the
     * wheel, and the same cards are offered at every one.
     */
    public function test_the_light_never_reveals_or_conceals_anything_by_itself()
    {
        $campaign = $this->createCampaign();
        $this->openBareTurn($campaign);
        $scene = $campaign->activeScene;

        $this->placeFeature($scene, 'a wall of stacked crates');
        $hidden = $this->placeFeature($scene, "the smuggler's door", ['hidden' => true]);
        $lurker = Actor::create([
            'scene_id' => $scene->id,
            'zone_id' => $scene->zone_id,
            'name' => "a smuggler's lookout",
            'kind' => 'enemy',
            'tier' => 'regular',
            'stats' => ['health' => ['current' => 4, 'max' => 4], 'attack' => 1],
            'tags' => ['lurking' => true, 'lurking_since' => 1],
            'status' => 'active',
            'source' => 'seed',
        ]);

        $seen = [];
        foreach (Hours::WHEEL as $phase) {
            $this->setHour($campaign, $phase);
            $scene = $scene->fresh();
            $character = $campaign->character->fresh();

            $this->assertTrue($hidden->fresh()->state['hidden']);
            $this->assertTrue($lurker->fresh()->tags['lurking']);
            $this->assertNotContains($hidden->name, $scene->visibleFeatures()->pluck('name')->all());
            $this->assertNotContains($lurker->name, $scene->visibleActors()->pluck('name')->all());

            $prose = SituationBoard::prose(SituationBoard::for($character, $scene, null));
            $this->assertStringNotContainsString($hidden->name, $prose);
            $this->assertStringNotContainsString($lurker->name, $prose);

            $cards = app(CardComposer::class)->compose($character, $scene);
            $seen[$phase] = collect($cards)->only(['pre', 'main', 'post'])
                ->flatMap(fn ($slot) => array_column($slot, 'label'))->sort()->values()->all();
        }

        // Same offers under every light: only the prices moved.
        foreach (Hours::WHEEL as $phase) {
            $this->assertSame($seen[Hours::DAY], $seen[$phase]);
        }
    }

    /** Genre, drive, tech level, and the land never touch the wheel: it turns the same for everyone. */
    public function test_the_wheel_turns_the_same_whatever_the_tale_wears()
    {
        config(['game.hours.turns_per_phase' => 3, 'game.hours.minutes_per_phase' => 240]);

        $harbor = $this->createCampaign([
            'world_flavor' => 'harbor-city',
            'genre' => 'grounded adventure',
            'drive' => 'a debt to settle',
            'tech_level' => 'no magic at all',
        ]);

        $station = $this->createCampaign([
            'world_flavor' => 'derelict-station',
            'genre' => 'science fiction',
            'drive' => 'somebody has to be found',
            'tech_level' => 'machinery well past ours',
        ]);

        $since = now()->subMinutes(300);
        foreach (range(1, 7) as $step) {
            $this->assertSame(
                Hours::advance($harbor, $since),
                Hours::advance($station, $since),
            );
            $this->assertSame($harbor->hour_phase, $station->hour_phase);
            $this->assertSame($harbor->hour_progress, $station->hour_progress);
        }
    }

    /** One board line for the air and the light both, in words any land can wear. */
    public function test_the_board_folds_the_light_into_the_line_the_air_already_had()
    {
        $campaign = $this->createCampaign();
        $this->openBareTurn($campaign);
        $character = $campaign->character->fresh();

        foreach ([Hours::DAWN, Hours::DUSK, Hours::NIGHT] as $phase) {
            $this->setHour($campaign, $phase);
            $scene = $this->setAmbient($campaign->activeScene, Ambient::GLOOM);

            $board = SituationBoard::for($character, $scene, null);
            $sky = collect($board)->firstWhere('key', 'sky');

            $this->assertNotNull($sky, "no sky group at {$phase}");
            $this->assertSame('The air and the light', $sky['title']);
            $this->assertSame([Ambient::line(Ambient::GLOOM), Hours::line($phase)], $sky['items']);

            // The engine never says sunrise, and never says what o'clock it is.
            foreach (['sun', 'moon', 'star', 'dawn', 'dusk', 'night', 'o\'clock', 'a.m.', 'p.m.'] as $word) {
                $this->assertStringNotContainsStringIgnoringCase($word, Hours::line($phase));
            }

            $this->assertStringContainsString(Hours::line($phase), SituationBoard::prose($board));
        }

        // Clear air in plain day drops the whole group again.
        $this->setHour($campaign, Hours::DAY);
        $scene = $this->setAmbient($campaign->activeScene, Ambient::CLEAR);
        $this->assertNotContains('sky', array_column(SituationBoard::for($character, $scene, null), 'key'));
    }

    /**
     * The narrator is handed the phase in plain words — the light coming back,
     * the light going, the dark — and told to render it in whatever way this
     * land keeps time. Never a clock face: the engine does not know what hour
     * it is in this world and would be inventing one to say so.
     */
    public function test_the_narration_prompt_carries_the_light_in_plain_words_and_never_a_clock()
    {
        $campaign = $this->createCampaign();
        $turn = $this->openBareTurn($campaign);
        $this->setHour($campaign, Hours::NIGHT);

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
                'downtime' => null, 'hour' => Hours::changed(Hours::NIGHT),
            ],
        ]);

        $prompt = (new \ReflectionMethod(Narrator::class, 'buildPrompt'))
            ->invoke(app(Narrator::class), $turn->fresh());

        $this->assertStringContainsString('The light this scene stands in', $prompt);
        $this->assertStringContainsString(Hours::fact(Hours::NIGHT), $prompt);
        $this->assertStringContainsString(Hours::changed(Hours::NIGHT), $prompt);
        $this->assertStringContainsString('Render it exactly ONCE', $prompt);
        $this->assertStringContainsString('Never name a clock time', $prompt);

        // No clock face reaches the page, and no engine key does either.
        foreach (['o\'clock', 'a.m.', 'p.m.', 'midnight', 'sunrise', 'sunset'] as $clock) {
            $this->assertStringNotContainsStringIgnoringCase($clock, $prompt);
        }

        // Plain day carries no instructions about the light at all.
        $this->setHour($campaign, Hours::DAY);
        $plain = (new \ReflectionMethod(Narrator::class, 'buildPrompt'))
            ->invoke(app(Narrator::class), $turn->fresh());
        $this->assertStringNotContainsString('The light this scene stands in', $plain);
    }

    /**
     * A step that lands mid-play is recorded as one plain fact, and the cards
     * that follow are priced under the light that turned — never the beats that
     * were already committed to under the old one.
     */
    public function test_a_step_mid_play_is_recorded_and_the_next_cards_are_priced_under_it()
    {
        config(['game.hours.turns_per_phase' => 3]);

        $campaign = $this->createCampaign();
        $turn = $this->openBareTurn($campaign);
        $this->placeFeature($campaign->activeScene, 'a wall of stacked crates');

        // One beat short of the step: this turn's cards were sold under dusk.
        $this->setHour($campaign, Hours::DUSK, 2);
        $turn = $this->refreshCards($turn);

        $hide = $this->cardFor($turn, 'pre', 'hide');
        $this->assertSame(Odds::BASE - 1, $hide['forecast']['difficulty']);

        $beat = $this->resolveCard($turn, 'pre', $hide);

        // The die honored the card, and the light moved behind it.
        $this->assertSame(Odds::BASE - 1, $beat['difficulty']);
        $this->assertSame(Hours::changed(Hours::NIGHT), $turn->fresh()->resolution['hour']);
        $this->assertSame(Hours::NIGHT, $campaign->fresh()->hour_phase);

        // And what comes next is priced under the dark.
        $next = $campaign->fresh()->turns()->where('status', Turn::STATUS_AWAITING)->orderByDesc('number')->first();
        $nextHide = collect($next->cards['pre'])->firstWhere('verb', 'hide');
        $this->assertNotNull($nextHide);
        $this->assertContains('The dark to move in', $this->labels($nextHide['forecast']['parts']));
        $this->assertSame(Odds::BASE - 2, $nextHide['forecast']['difficulty']);

        // A turn that steps nothing says nothing about the light.
        $this->setHour($campaign, Hours::NIGHT);
        $next = $this->refreshCards($next->fresh());
        $this->resolveCard($next, 'pre', collect($next->cards['pre'])->firstWhere('verb', 'hide'));
        $this->assertNull($next->fresh()->resolution['hour']);
    }
}
