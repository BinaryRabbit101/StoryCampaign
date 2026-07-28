<?php

namespace Tests\Feature;

use App\Game\Engine\Ambient;
use App\Game\Engine\BeatOutcome;
use App\Game\Engine\CardComposer;
use App\Game\Engine\Dice;
use App\Game\Engine\Odds;
use App\Game\Engine\Scars;
use App\Game\Engine\TurnResolver;
use App\Game\Meters;
use App\Game\ScarCatalog;
use App\Game\TraitCatalog;
use App\Models\Actor;
use App\Models\Campaign;
use App\Models\Character;
use App\Models\Grudge;
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
 * Scars: going down marks you instead of erasing you.
 *
 * Health at zero used to write `downed` and stop. It now branches: the engine
 * rolls a permanent burden from a small closed table, appends it through the
 * ordinary constraint path, half-heals them onto safe ground, and hands Claude
 * the facts in plain words. Past the cap it ends the tale instead.
 *
 * The load-bearing claims these tests hold down: the scar is a REAL burden
 * (priced by the same ladder as a creation-time one, with no refund), the
 * engine alone picks it, two falls never leave the same injury, the third
 * closes the book, and a downed companion never touches any of it.
 */
class ScarTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<string> */
    private array $prompts = [];

    private function createCampaign(string $name = 'Scar Tale'): Campaign
    {
        $this->seed(WorldSeeder::class);

        $this->prompts = [];
        $this->mock(ClaudeCli::class, function ($mock) {
            $mock->shouldReceive('promptForJson')->andReturnUsing(function (string $prompt) {
                $this->prompts[] = $prompt;

                return [
                    'chapter' => 'She woke somewhere else, and it hurt to stand.',
                    'intent_line' => null,
                    'synopsis_line' => 'She went down and got up again.',
                    'title' => 'The Marked Year',
                    'back_cover' => 'A tale of what it cost.',
                ];
            })->byDefault();

            $mock->shouldReceive('prompt')->andReturnUsing(function (string $prompt) {
                $this->prompts[] = $prompt;

                return 'And so the tale went quiet.';
            })->byDefault();
        });

        $campaign = Campaign::create([
            'user_id' => User::factory()->create()->id,
            'name' => $name,
            'world_flavor' => 'harbor-city',
            'status' => 'active',
            'started_at' => now(),
        ]);

        $character = Character::create([
            'campaign_id' => $campaign->id,
            'name' => 'The Cat',
            'description' => 'A striking black cat with a long prehensile tail.',
            'meters' => Meters::default(),
            'status' => 'alive',
            'meters_regenerated_at' => now(),
        ]);

        foreach ([
            ['capability' => 'swing'],
            ['capability' => 'reach', 'magnitude' => 12],
            ['capability' => 'restrain'],
            ['capability' => 'squeeze', 'grade' => 'large'],
        ] as $capability) {
            $character->capabilities()->create($capability + ['source' => 'creation']);
        }

        return $campaign;
    }

    /**
     * Ground the test owns: nobody standing on it, so the only thing that moves
     * the health pool is what the test does to it.
     */
    private function openBareTurn(Campaign $campaign): Turn
    {
        $turn = app(TurnStarter::class)->openFirstTurn($campaign);
        $campaign->activeScene->actors()->delete();

        return $turn->fresh();
    }

    /** Take the character to zero and resolve the quietest possible turn on top of it. */
    private function fall(Campaign $campaign, ?Turn $turn = null): Turn
    {
        $turn ??= $this->openBareTurn($campaign);
        Meters::damage($campaign->character->fresh(), 10);

        return $this->resolveQuietly($turn);
    }

    private function resolveQuietly(Turn $turn): Turn
    {
        $wait = collect($turn->cards['main'])->firstWhere('verb', 'wait')
            ?? collect($turn->cards['main'])->first();

        $turn->update([
            'status' => Turn::STATUS_LOCKED,
            'submission' => ['main' => ['card_id' => $wait['id'], 'modifiers' => []]],
            'submitted_at' => now(),
        ]);

        app(TurnResolver::class)->resolve($turn->fresh());

        return $turn->fresh();
    }

    private function placeFeature(Scene $scene, string $name): SceneFeature
    {
        $template = SceneFeature::whereNull('scene_id')->where('name', $name)->firstOrFail();

        return SceneFeature::create([
            'scene_id' => $scene->id,
            'zone_id' => $scene->zone_id,
            'name' => $template->name,
            'feature_type' => $template->feature_type,
            'affordances' => $template->affordances,
            'state' => [],
            'source' => 'seed',
        ]);
    }

    /** The narration prompt, picked out of everything Claude was asked this test. */
    private function narrationPrompt(): string
    {
        $prompt = collect($this->prompts)
            ->first(fn (string $p) => str_contains($p, 'You are the narrator of a living-world RPG'));

        $this->assertNotNull($prompt, 'no narration prompt was built');

        return $prompt;
    }

    /**
     * The whole shape of a fall, in one pass: the scar lands on the sheet
     * through the ordinary constraint path with a provenance stamp, the clamp
     * runs over the sheet behind it, and the waking is a real recovery beat on
     * new ground at the configured fraction of the pool — not a respawn.
     */
    public function test_a_fall_marks_the_character_and_wakes_them_on_safe_ground()
    {
        $campaign = $this->createCampaign();
        $open = $this->openBareTurn($campaign);
        $fell = $campaign->activeScene;
        $turn = $this->fall($campaign, $open);

        $character = $campaign->character->fresh();

        // Back on their feet, at the configured fraction — never full.
        $this->assertSame('alive', $character->status);
        $this->assertSame(5, $character->meters['health']['current']);
        $this->assertSame(10, $character->meters['health']['max']);

        // The scar is an ordinary constraint row, marked as taken in play.
        $scars = $character->constraints()->where('source', 'scar')->get();
        $this->assertCount(1, $scars);
        $this->assertContains($scars[0]->name, ScarCatalog::keys());

        // Provenance: the book can cite the turn and chapter it happened in.
        $this->assertSame($turn->id, $scars[0]->params['turn_id']);
        $this->assertArrayHasKey('chapter_id', $scars[0]->params);
        $this->assertSame($fell->title, $scars[0]->params['taken_at']);

        // The clamp ran behind it: reach(12) is under the re-coupling threshold,
        // so the sheet leaves the fall carrying no invented liability.
        $this->assertCount(0, $character->constraints()->where('source', 'growth')->get());

        // The record the narrator will read.
        $fall = $turn->resolution['fall'];
        $this->assertFalse($fall['final']);
        $this->assertSame($fell->title, $fall['fell_at']);
        $this->assertSame(Scars::LEFT_WHERE_THEY_FELL, $fall['outcome']);
        $this->assertSame($scars[0]->name, $fall['scar']['key']);

        // The waking is a beat, not a skipped scene: new ground, dressed, and
        // empty of people — waking into a second fight is not a recovery.
        $woke = $campaign->fresh()->activeScene;
        $this->assertNotSame($fell->id, $woke->id);
        $this->assertSame($fall['woke_at'], $woke->title);
        $this->assertSame('past', $fell->fresh()->status);
        $this->assertSame(0, $woke->actors()->where('status', 'active')->count());

        // And there is a turn waiting on that ground, with real choices on it.
        $next = $campaign->fresh()->currentTurn;
        $this->assertSame($woke->id, $next->scene_id);
        $this->assertGreaterThanOrEqual(2, count($next->cards['main']));
    }

    /**
     * The engine rolls the scar, from seeded dice and a closed table. Same
     * seed, same injury — a fall is auditable and replayable like every other
     * outcome in the game.
     */
    public function test_the_scar_roll_is_seeded_and_stays_inside_the_table()
    {
        $first = ScarCatalog::roll('struck_down', [], new Dice(4242));
        $again = ScarCatalog::roll('struck_down', [], new Dice(4242));

        $this->assertSame($first['key'], $again['key']);
        $this->assertContains($first['key'], ScarCatalog::keys());

        // Every context can still answer once its own shortlist is exhausted —
        // a fall must never quietly do nothing.
        foreach (ScarCatalog::contexts() as $context) {
            $rolled = ScarCatalog::roll($context, [], new Dice(9));
            $this->assertNotNull($rolled);
            $this->assertContains($rolled['key'], ScarCatalog::keys());
        }
    }

    /**
     * The point of routing a scar through the constraint path: it prices
     * exactly as the same burden would have, had it been carried from
     * creation. There is no harsher ladder for injuries — how you came by a
     * burden is a story fact, and story facts never move numbers.
     */
    public function test_a_scar_prices_a_card_exactly_as_the_same_burden_taken_at_creation()
    {
        $marked = $this->createCampaign('Marked');
        $born = $this->createCampaign('Born With It');
        $whole = $this->createCampaign('Unmarked');

        $marked->character->constraints()->create([
            'name' => 'marked_limp', 'params' => ['scar' => 'marked_limp'], 'source' => 'scar',
        ]);
        $born->character->constraints()->create([
            'name' => 'marked_limp', 'params' => ['part' => 'a knee'], 'source' => 'creation',
        ]);

        $forecasts = [];
        foreach ([$marked, $born, $whole] as $campaign) {
            app(TurnStarter::class)->openFirstTurn($campaign);
            $scene = $campaign->activeScene;
            $scene->actors()->delete();
            // Clear air on all three, or the comparison is between three
            // different skies rather than between three different bodies.
            $scene->update(['state' => array_merge($scene->state ?? [], ['ambient' => Ambient::CLEAR])]);
            $this->placeFeature($scene, 'the warehouse roof');

            $cards = app(CardComposer::class)->compose(
                $campaign->character->fresh(), $scene->fresh(), new Dice(1),
            );

            $ascend = collect($cards['pre'])->firstWhere('verb', 'ascend');
            $this->assertNotNull($ascend, 'the swing up was never offered');
            $forecasts[] = $ascend['forecast'];
        }

        [$scarred, $chosen, $unhurt] = $forecasts;

        // Identical, part for part and number for number.
        $this->assertSame($chosen['difficulty'], $scarred['difficulty']);
        $this->assertSame($chosen['parts'], $scarred['parts']);
        $this->assertSame($chosen['stances'], $scarred['stances']);

        // And it is a real cost, itemized in plain words — never a silent one.
        $this->assertContains(
            ['label' => 'The old wound in your knee', 'amount' => 2],
            $scarred['parts'],
        );
        $this->assertSame($unhurt['difficulty'] + 2, $scarred['difficulty']);

        // The card's quoted number is the number the dice honor: same ledger,
        // same conditions, one call each.
        $this->assertSame($scarred['difficulty'], Odds::difficulty(
            ['verb' => 'ascend', 'risk' => $ascend['risk'], 'capability' => 'swing', 'slot' => 'pre'],
            'balanced',
            ['scars' => ['marked_limp']],
        )['value']);
    }

    /** A scar buys nothing. Creation burdens are currency; a fall is a price. */
    public function test_a_scar_refunds_no_points()
    {
        $this->assertSame(0, TraitCatalog::constraintRefund(['name' => 'marked_limp']));
        $this->assertSame(1, TraitCatalog::constraintRefund(['name' => 'craven']));
    }

    /** The player's own marks never make a companion's own attempt harder. */
    public function test_a_scar_never_prices_a_companions_own_beat()
    {
        $this->assertNotSame([], Odds::scarParts(['lingering_flinch'], 'strike', null, 'main'));
        $this->assertSame([], Odds::scarParts(['lingering_flinch'], 'strike', null, 'companion'));
    }

    /**
     * Two falls, two different injuries. A body that came back from two
     * separate disasters with the same limp twice would read as the engine
     * having nothing to say.
     */
    public function test_two_falls_leave_two_distinct_scars()
    {
        $campaign = $this->createCampaign();
        $this->fall($campaign);

        $second = $this->fall($campaign, $campaign->fresh()->currentTurn);

        $names = $campaign->character->fresh()->constraints()
            ->where('source', 'scar')->pluck('name')->all();

        $this->assertCount(2, $names);
        $this->assertSame($names, array_unique($names));
        $this->assertFalse($second->resolution['fall']['final']);
        $this->assertSame(5, $campaign->character->fresh()->meters['health']['current']);
    }

    /**
     * The cap is what keeps the stakes real without a death spiral: two
     * stacked burdens make the third fall likelier, and a third would make it
     * inevitable. So the third fall is not a burden — it is the end, told
     * through the book's own early-end/coda path.
     */
    public function test_the_third_fall_closes_the_tale_through_the_coda_path()
    {
        $campaign = $this->createCampaign();

        foreach (['marked_limp', 'dimmed_eye'] as $carried) {
            $campaign->character->constraints()->create([
                'name' => $carried, 'params' => ['scar' => $carried], 'source' => 'scar',
            ]);
        }

        $turn = $this->fall($campaign);

        // No third burden, and nobody gets up.
        $character = $campaign->character->fresh();
        $this->assertSame('downed', $character->status);
        $this->assertSame(0, $character->meters['health']['current']);
        $this->assertCount(2, $character->constraints()->where('source', 'scar')->get());

        // Nothing left to choose: the resolver opens no successor.
        $this->assertTrue($turn->resolution['fall']['final']);
        $this->assertNull($turn->resolution['fall']['scar']);
        $this->assertSame(1, $campaign->turns()->count());
        $this->assertSame(0, $campaign->turns()->where('status', Turn::STATUS_AWAITING)->count());

        // The tale closes behind its own last chapter — the coda must never
        // land in the book ahead of the fall it is closing over.
        app(Narrator::class)->narrate($turn->fresh());

        $campaign = $campaign->fresh();
        $this->assertSame('completed', $campaign->status);
        $this->assertTrue((bool) $campaign->ended_early);
        $this->assertNotNull($campaign->ended_at);

        $chapters = $campaign->chapters()->orderBy('number')->get();
        $this->assertSame('coda', $chapters->last()->kind);
        $this->assertSame('chapter', $chapters[$chapters->count() - 2]->kind);

        // And the coda was told what the tale took out of them — a body that
        // shows where it has been is the whole point of the closing page.
        $coda = collect($this->prompts)->first(fn (string $p) => str_contains($p, 'closing coda'));
        $this->assertNotNull($coda);
        $this->assertStringContainsString('A marked limp', $coda);
        $this->assertStringContainsString('A dimmed eye', $coda);
    }

    /**
     * The narrator is handed the fall as facts and nothing else: where they
     * went down, what happened to them, where they came round, and what it
     * permanently cost. No dice, no cards, no meters — and no invitation to
     * decide any of it.
     */
    public function test_the_fall_reaches_the_narrator_in_plain_words_and_no_mechanics()
    {
        $campaign = $this->createCampaign();
        $turn = $this->fall($campaign);

        app(Narrator::class)->narrate($turn->fresh());

        $prompt = $this->narrationPrompt();
        $fall = $turn->fresh()->resolution['fall'];

        $this->assertStringContainsString('## The fall and the waking', $prompt);

        // Extract the block, so the assertion is about what the fall says and
        // not about the standing "do not mention dice" rule elsewhere.
        $block = mb_substr($prompt, mb_strpos($prompt, '## The fall and the waking'));
        $block = mb_substr($block, 0, mb_strpos($block, '## Where the vignette stops') ?: mb_strlen($block));

        $this->assertStringContainsString($fall['fell_at'], $block);
        $this->assertStringContainsString($fall['woke_at'], $block);
        $this->assertStringContainsString($fall['scar']['fact'], $block);

        foreach (['dice', 'roll', 'card', 'meter', 'health', 'difficulty', 'DC', 'constraint', 'scar'] as $mechanic) {
            $this->assertStringNotContainsStringIgnoringCase($mechanic, $block,
                "the fall block leaked mechanics language: {$mechanic}");
        }

        // And later chapters are allowed to remember it: the standing block
        // carries the mark forward once the fall's own chapter is behind them.
        $next = $campaign->fresh()->currentTurn;
        $this->assertStringContainsString(
            $fall['scar']['fact'],
            Scars::marksBlock($campaign->character->fresh()),
        );
        $this->assertNotNull($next);
    }

    /**
     * A companion can be put down by their own failed request — that is the
     * risk that keeps them people. It is the actor's `downed` status and
     * nothing else: scars are the player character's only.
     */
    public function test_a_downed_companion_never_takes_a_scar()
    {
        $campaign = $this->createCampaign();
        $turn = $this->openBareTurn($campaign);
        $scene = $campaign->activeScene;

        Actor::create([
            'scene_id' => $scene->id,
            'zone_id' => $scene->zone_id,
            'name' => 'a dockside friend',
            'kind' => 'companion',
            'tier' => 'regular',
            'stats' => ['health' => ['current' => 0, 'max' => 3], 'attack' => 1],
            'tags' => [],
            'status' => 'downed',
            'source' => 'seed',
        ]);

        $turn = $this->resolveQuietly($turn->fresh());

        $character = $campaign->character->fresh();
        $this->assertSame('alive', $character->status);
        $this->assertNull($turn->resolution['fall']);
        $this->assertCount(0, $character->constraints()->where('source', 'scar')->get());
        $this->assertSame([], Scars::marks($character));
    }

    /**
     * The tale's memory keeps the worst of it. An old score that put the
     * character on the floor says so at the reunion — one line in the grudge's
     * history, and the whole payoff the next time they meet.
     */
    public function test_a_fall_against_an_old_score_is_remembered_by_it()
    {
        $campaign = $this->createCampaign();
        $turn = $this->openBareTurn($campaign);
        $scene = $campaign->activeScene;

        $grudge = Grudge::create([
            'campaign_id' => $campaign->id,
            'actor_name' => 'the harbormaster',
            'stats' => ['health' => ['current' => 4, 'max' => 4], 'attack' => 2],
            'tags' => [],
            'tier' => 'regular',
            'history' => [],
            'heat' => 2,
            'disposition' => 'vengeful',
            'status' => 'returning',
            'last_seen_chapter_id' => null,
        ]);

        Actor::create([
            'scene_id' => $scene->id,
            'zone_id' => $scene->zone_id,
            'name' => 'the harbormaster',
            'kind' => 'enemy',
            'tier' => 'regular',
            'stats' => ['health' => ['current' => 4, 'max' => 4], 'attack' => 2],
            'tags' => ['grudge_id' => $grudge->id, 'intent' => 'press'],
            'status' => 'active',
            'source' => 'grudge',
        ]);

        Meters::damage($campaign->character->fresh(), 10);
        $this->resolveQuietly($turn->fresh());

        $events = array_column($grudge->fresh()->history, 'event');
        $this->assertContains('downed_you', $events);
    }

    /**
     * The board is for the scene. A permanent mark lives on the sheet and in
     * the odds parts, where the player meets it at the moment it is charging
     * them something — not as a group repeating itself every turn forever.
     */
    public function test_the_situation_board_never_grows_a_scar_group()
    {
        $campaign = $this->createCampaign();
        $this->fall($campaign);

        $next = $campaign->fresh()->currentTurn;
        $scar = $campaign->character->fresh()->constraints()->where('source', 'scar')->first();
        $label = ScarCatalog::get($scar->name)['label'];

        foreach ($next->situation_board as $group) {
            $this->assertNotSame('scars', $group['key']);
            $this->assertStringNotContainsString($label, implode(' ', $group['items']));
        }

        $this->assertStringNotContainsString($label, $next->situation);
    }

    /** The count is on the strip, plainly, from the first fall onward. */
    public function test_the_play_page_names_the_marks_the_tale_has_taken()
    {
        $campaign = $this->createCampaign();

        $this->actingAs($campaign->user)->get(route('play.show', $campaign))
            ->assertInertia(fn ($page) => $page->has('character.scars', 0));

        $this->fall($campaign);
        $scar = $campaign->character->fresh()->constraints()->where('source', 'scar')->first();

        $this->actingAs($campaign->user)->get(route('play.show', $campaign))
            ->assertInertia(fn ($page) => $page
                ->has('character.scars', 1)
                ->where('character.scars.0.key', $scar->name)
                ->where('character.scars.0.label', ScarCatalog::get($scar->name)['label']));
    }

    /**
     * A companion still standing changes the waking: they are dragged clear
     * rather than left where they fell, and the companion comes along.
     */
    public function test_a_standing_companion_drags_them_clear()
    {
        $campaign = $this->createCampaign();
        $turn = $this->openBareTurn($campaign);
        $scene = $campaign->activeScene;

        $friend = Actor::create([
            'scene_id' => $scene->id,
            'zone_id' => $scene->zone_id,
            'name' => 'a dockside friend',
            'kind' => 'companion',
            'tier' => 'regular',
            'stats' => ['health' => ['current' => 3, 'max' => 3], 'attack' => 1],
            'tags' => [],
            'status' => 'active',
            'source' => 'seed',
        ]);

        Meters::damage($campaign->character->fresh(), 10);
        $turn = $this->resolveQuietly($turn->fresh());

        $fall = $turn->resolution['fall'];
        $this->assertSame(Scars::DRAGGED_CLEAR, $fall['outcome']);
        $this->assertStringContainsString('a dockside friend', implode(' ', $fall['facts']));

        // Companions walk the tale, not the scene: they wake on the new ground too.
        $this->assertSame($campaign->fresh()->activeScene->id, $friend->fresh()->scene_id);
    }

    /**
     * Which scar lands is keyed to how the character went down. The context is
     * read from what the resolver already knows, in a closed vocabulary.
     */
    public function test_the_finishing_blow_decides_which_scars_are_on_the_table()
    {
        $heavy = ['kind' => 'enemy', 'label' => 'The heavy blow', 'outcome' => 'Drew blood — 4 damage'];
        $unseen = [
            'kind' => 'enemy', 'label' => 'Presses the attack', 'outcome' => 'Drew blood — 3 damage',
            'bonus_parts' => [['label' => 'Out of nowhere', 'amount' => 4]],
        ];
        $plain = ['kind' => 'enemy', 'label' => 'Presses the attack', 'outcome' => 'Drew blood — 2 damage'];
        $missed = ['kind' => 'enemy', 'label' => 'Presses the attack', 'outcome' => 'Found nothing to hit'];

        $this->assertSame('crushed', Scars::contextFor([], [$heavy]));
        $this->assertSame('ambushed', Scars::contextFor([], [$unseen]));
        $this->assertSame('struck_down', Scars::contextFor([], [$plain]));

        // Nothing the scene threw landed, so the fall came from the player's
        // own last beat — the ground, not an enemy.
        $crossing = new BeatOutcome('main', 'cross', null, 'partial', 8, 8, 12, []);
        $this->assertSame('fall', Scars::contextFor([$crossing], [$missed]));
    }
}
