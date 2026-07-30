<?php

namespace Tests\Feature;

use App\Game\Engine\TurnResolver;
use App\Game\Meters;
use App\Models\Actor;
use App\Models\Campaign;
use App\Models\Character;
use App\Models\GrowthEntry;
use App\Models\Grudge;
use App\Models\InterviewMessage;
use App\Models\Turn;
use App\Models\User;
use App\Services\Claude\ClaudeCli;
use App\Services\GrowthLedger;
use App\Services\TurnStarter;
use Database\Seeders\WorldSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * The growth ledger: the tale pays for what the sheet learns.
 *
 * Creation is priced to the point and growth was priced at nothing — the
 * interview wrote whatever Claude marked granted, with no cost, no cap, and no
 * record. That was the one place in the game where an LLM decided whether a
 * player may have a power.
 *
 * The load-bearing claims these tests hold down: the ENGINE alone mints (from a
 * closed earn table, off moments a resolution already fixed, never twice for the
 * same moment, and never for a scar), the ENGINE alone prices (a grant the
 * ledger cannot afford is refused however Claude answered), a granted spend is
 * recorded once, growth refunds nothing, and the balance is visible in both
 * places it is spoken — the prompt and the panel.
 */
class GrowthLedgerTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<string> */
    private array $prompts = [];

    /** What the mocked world answers the next growth request with. */
    private array $answer = ['reply' => 'Not yet.', 'granted' => false, 'changes' => null];

    private function createCampaign(): Campaign
    {
        $this->seed(WorldSeeder::class);
        Notification::fake();

        $this->prompts = [];
        $this->mock(ClaudeCli::class, function ($mock) {
            $mock->shouldReceive('promptForJson')->andReturnUsing(function (string $prompt) {
                $this->prompts[] = $prompt;

                if (str_contains($prompt, 'A player asks to evolve their character')) {
                    return $this->answer;
                }

                return [
                    'chapter' => 'They walked on.',
                    'intent_line' => null,
                    'synopsis_line' => 'They walked on.',
                ];
            })->byDefault();

            $mock->shouldReceive('prompt')->andReturnUsing(function (string $prompt) {
                $this->prompts[] = $prompt;

                return 'And so the tale went on.';
            })->byDefault();
        });

        $campaign = Campaign::create([
            'user_id' => User::factory()->create()->id,
            'name' => 'The Learning',
            'world_flavor' => 'harbor-city',
            'status' => 'active',
            'started_at' => now(),
        ]);

        Character::create([
            'campaign_id' => $campaign->id,
            'name' => 'The Cat',
            'description' => 'A striking black cat with a long prehensile tail.',
            'meters' => Meters::default(),
            'status' => 'alive',
            'meters_regenerated_at' => now(),
        ])->capabilities()->createMany([
            ['capability' => 'swing', 'source' => 'creation'],
            ['capability' => 'reach', 'magnitude' => 12, 'source' => 'creation'],
            ['capability' => 'leap', 'magnitude' => 1, 'source' => 'creation'],
        ]);

        return $campaign;
    }

    /** Ground the test owns: nobody on it but whoever the test puts there. */
    private function openBareTurn(Campaign $campaign): Turn
    {
        $turn = app(TurnStarter::class)->openFirstTurn($campaign);
        $campaign->activeScene->actors()->delete();

        return $turn->fresh();
    }

    /** Resolve a turn on the quietest card available. */
    private function play(Turn $turn): Turn
    {
        $card = collect($turn->cards['main'])->firstWhere('verb', 'wait')
            ?? collect($turn->cards['main'])->first();

        $turn->update([
            'status' => Turn::STATUS_LOCKED,
            'submission' => ['main' => ['card_id' => $card['id'], 'modifiers' => []]],
            'submitted_at' => now(),
        ]);

        app(TurnResolver::class)->resolve($turn->fresh());

        return $turn->fresh();
    }

    /** A turn already played and behind them — the ledger only cites those. */
    private function pastTurn(Campaign $campaign, int $number): Turn
    {
        return Turn::create([
            'campaign_id' => $campaign->id,
            'scene_id' => $campaign->fresh()->activeScene->id,
            'number' => $number,
            'status' => Turn::STATUS_COMPLETE,
            'situation' => 'Ground already walked.',
            'cards' => ['main' => []],
        ]);
    }

    /** Points the tale already paid, without playing the turns that paid them. */
    private function grant(Campaign $campaign, int $points): void
    {
        GrowthEntry::create([
            'campaign_id' => $campaign->id,
            'character_id' => $campaign->character->id,
            'turn_id' => null,
            'kind' => GrowthEntry::EARN,
            'points' => $points,
            'event' => 'elite_beaten',
            'label' => 'something formidable, brought down',
        ]);
    }

    private function ask(Campaign $campaign, string $words = 'I want to change.'): void
    {
        $this->actingAs($campaign->user)->post("/campaigns/{$campaign->id}/grow", ['body' => $words]);
    }

    private function lastGrowthPrompt(): string
    {
        $growth = array_values(array_filter(
            $this->prompts,
            fn (string $p) => str_contains($p, 'A player asks to evolve their character'),
        ));

        $this->assertNotEmpty($growth, 'no growth prompt was built');

        return end($growth);
    }

    private function spends(Campaign $campaign)
    {
        return GrowthEntry::where('campaign_id', $campaign->id)->where('kind', GrowthEntry::SPEND)->get();
    }

    /**
     * Every moment on the closed earn table pays exactly what config says it
     * pays, once, keyed to the turn that fixed it.
     */
    public function test_each_earn_writes_one_row_worth_what_the_moment_is_worth()
    {
        $campaign = $this->createCampaign();
        $this->openBareTurn($campaign);

        foreach ([
            ['elite_beaten', 'a harbor enforcer', 2],
            ['endeavor_filled', 'the long watch', 2],
            ['rival_settled', 'the harbormaster', 2],
            ['first_ground', 'The Far Shelf', 1],
            ['captive_freed', 'the lantern watchman', 1],
        ] as $index => [$event, $subject, $points]) {
            $turn = $this->pastTurn($campaign, 600 + $index);

            $this->assertSame($points, GrowthLedger::earn($turn, [
                ['trigger' => $event, 'subject' => $subject, 'place' => 'the long quay'],
            ]));

            $rows = GrowthEntry::where('turn_id', $turn->id)->get();
            $this->assertCount(1, $rows, "{$event} wrote the wrong number of rows");
            $this->assertSame(GrowthEntry::EARN, $rows[0]->kind);
            $this->assertSame($points, $rows[0]->points);
            $this->assertSame($event, $rows[0]->event);
            $this->assertSame($campaign->character->id, $rows[0]->character_id);
            $this->assertStringContainsString($subject, $rows[0]->label);

            // And the plain words never speak the language of the machinery.
            foreach (['point', 'dice', 'roll', 'card'] as $mechanic) {
                $this->assertStringNotContainsStringIgnoringCase($mechanic, $rows[0]->label);
            }
        }

        $this->assertSame(8, GrowthLedger::balance($campaign->character));
    }

    /**
     * The same moment never pays twice. A resolution that runs again pays
     * nothing more, and new country is new exactly once however often the tale
     * walks back over it.
     */
    public function test_the_same_moment_never_pays_twice()
    {
        $campaign = $this->createCampaign();
        $this->openBareTurn($campaign);

        $turn = $this->pastTurn($campaign, 700);
        $moments = [['trigger' => 'elite_beaten', 'subject' => 'a harbor enforcer', 'place' => 'the long quay']];

        $this->assertSame(2, GrowthLedger::earn($turn, $moments));
        $this->assertSame(0, GrowthLedger::earn($turn, $moments), 're-resolving the turn paid again');
        $this->assertSame(2, GrowthLedger::balance($campaign->character));

        // A different turn, a different elite: both are real moments.
        $this->assertSame(2, GrowthLedger::earn($this->pastTurn($campaign, 701), [
            ['trigger' => 'elite_beaten', 'subject' => 'a dockside bruiser', 'place' => 'the long quay'],
        ]));

        // New country, on the other hand, is paid once per ground for good.
        $ground = [['trigger' => 'first_ground', 'subject' => 'The Far Shelf', 'place' => 'the shelf road']];
        $this->assertSame(1, GrowthLedger::earn($this->pastTurn($campaign, 702), $ground));
        $this->assertSame(0, GrowthLedger::earn($this->pastTurn($campaign, 703), $ground));

        $this->assertSame(5, GrowthLedger::balance($campaign->character));
    }

    /** Nothing off the closed table pays anything, whatever it is called. */
    public function test_moments_off_the_table_pay_nothing()
    {
        $campaign = $this->createCampaign();
        $this->openBareTurn($campaign);

        $this->assertSame(0, GrowthLedger::earn($this->pastTurn($campaign, 800), [
            ['trigger' => 'scar_taken', 'subject' => 'a dockside tough', 'place' => 'the long quay'],
            ['trigger' => 'companion_lost', 'subject' => 'the fisher', 'place' => 'the long quay'],
            ['trigger' => 'invented_by_somebody', 'subject' => 'nothing', 'place' => 'nowhere'],
        ]));

        $this->assertSame(0, GrowthEntry::count());
    }

    /**
     * The resolver's own detection reaches the ledger — the same list the shelf
     * is handed, read once off facts the turn already fixed.
     */
    public function test_a_settled_score_pays_the_ledger_from_the_resolution_itself()
    {
        $campaign = $this->createCampaign();
        $turn = $this->openBareTurn($campaign);
        $scene = $campaign->activeScene;

        $grudge = Grudge::create([
            'campaign_id' => $campaign->id,
            'actor_name' => 'the harbormaster',
            'stats' => ['health' => ['current' => 0, 'max' => 4], 'attack' => 2],
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
            'stats' => ['health' => ['current' => 0, 'max' => 4], 'attack' => 2],
            'tags' => ['grudge_id' => $grudge->id],
            'status' => 'defeated',
            'source' => 'grudge',
        ]);

        $turn = $this->play($turn);

        $row = GrowthEntry::where('campaign_id', $campaign->id)->first();
        $this->assertNotNull($row, 'the score closed and the ledger paid nothing');
        $this->assertSame('rival_settled', $row->event);
        $this->assertSame($turn->id, $row->turn_id);
        $this->assertSame(2, GrowthLedger::balance($campaign->character->fresh()));
    }

    /**
     * A scar pays NOTHING, in any coin. The fall is a price, and pricing it as
     * currency would make going down a way to shop; the sanctioned relief valve
     * stays the interview acknowledging it in words.
     */
    public function test_a_scar_pays_the_ledger_nothing()
    {
        $campaign = $this->createCampaign();
        $turn = $this->openBareTurn($campaign);

        Meters::damage($campaign->character->fresh(), 10);
        $turn = $this->play($turn);

        $this->assertNotNull($turn->resolution['fall']['scar'], 'the fall left no mark to test against');
        $this->assertSame(0, GrowthEntry::count());
        $this->assertSame(0, GrowthLedger::balance($campaign->character->fresh()));
    }

    /**
     * A grant the tale has paid for: the sheet changes, one spend is recorded,
     * and the balance falls by exactly the catalog price of what was bought.
     */
    public function test_an_affordable_grant_is_written_and_charged_once()
    {
        $campaign = $this->createCampaign();
        $this->openBareTurn($campaign);
        $this->grant($campaign, 3);

        $this->answer = [
            'reply' => 'The water holds you now.',
            'granted' => true,
            'changes' => [['capability' => 'swim', 'magnitude' => null, 'grade' => null, 'scope' => null]],
        ];

        $this->ask($campaign, 'I want to swim.');

        $character = $campaign->character->fresh();
        $this->assertNotNull($character->capabilities()->where('capability', 'swim')->first());

        $spends = $this->spends($campaign);
        $this->assertCount(1, $spends);
        $this->assertSame(1, $spends[0]->points, 'swim costs one at creation and must cost one here');
        $this->assertStringContainsString('swim', $spends[0]->label);

        $this->assertSame(2, GrowthLedger::balance($character));

        $verdict = InterviewMessage::where('campaign_id', $campaign->id)
            ->where('role', 'narrator')->orderByDesc('id')->first();
        $this->assertTrue((bool) $verdict->granted);
    }

    /**
     * A grant the tale has NOT paid for is refused outright — nothing on the
     * sheet, nothing on the ledger — however emphatically Claude said yes.
     */
    public function test_a_grant_the_ledger_cannot_afford_is_refused_however_claude_answered()
    {
        $campaign = $this->createCampaign();
        $this->openBareTurn($campaign);

        $this->answer = [
            'reply' => 'Yes! Take it, it is yours, all of it.',
            'granted' => true,
            'changes' => [['capability' => 'detect', 'magnitude' => null, 'grade' => null, 'scope' => null]],
        ];

        $this->ask($campaign, 'I want to feel an ambush coming.');

        $character = $campaign->character->fresh();
        $this->assertNull($character->capabilities()->where('capability', 'detect')->first(), 'an unpaid grant reached the sheet');
        $this->assertSame(0, GrowthEntry::count());
        $this->assertSame(0, GrowthLedger::balance($character));

        // And the screen is told the truth rather than the reply.
        $verdict = InterviewMessage::where('campaign_id', $campaign->id)
            ->where('role', 'narrator')->orderByDesc('id')->first();
        $this->assertFalse((bool) $verdict->granted);
        $this->assertSame('refused', $verdict->changes[0]['kind']);
        $this->assertStringContainsString('3', $verdict->changes[0]['detail']);
    }

    /**
     * Deepening something already carried is priced by the step — a point
     * apiece — which is what makes a longer leap cheaper than a whole new gift.
     */
    public function test_raising_a_clamped_magnitude_two_steps_costs_two()
    {
        $campaign = $this->createCampaign();
        $this->openBareTurn($campaign);
        $this->grant($campaign, 3);

        // leap is clamped 1-3 by the bible; the sheet carries it at 1.
        $this->answer = [
            'reply' => 'Your legs learn the distance.',
            'granted' => true,
            'changes' => [['capability' => 'leap', 'magnitude' => 3, 'grade' => null, 'scope' => null]],
        ];

        $this->ask($campaign, 'I want to clear the wide gaps.');

        $character = $campaign->character->fresh();
        $this->assertSame(3, $character->capabilities()->where('capability', 'leap')->first()->magnitude);

        $spends = $this->spends($campaign);
        $this->assertCount(1, $spends);
        $this->assertSame(2, $spends[0]->points);
        $this->assertSame(1, GrowthLedger::balance($character));
    }

    /**
     * A burden through growth earns NOTHING. Refunding one would make asking to
     * be worse a way to shop — a farming vector in a character-development
     * costume — so a big frame arrives as a real change costing nothing and
     * paying nothing.
     */
    public function test_a_burden_taken_through_growth_earns_nothing()
    {
        $campaign = $this->createCampaign();
        $this->openBareTurn($campaign);

        $this->answer = [
            'reply' => 'You have grown too broad for the narrow ways.',
            'granted' => true,
            'changes' => [['capability' => 'squeeze', 'magnitude' => null, 'grade' => 'large', 'scope' => null]],
        ];

        $this->ask($campaign, 'I have grown heavy.');

        $character = $campaign->character->fresh();
        $this->assertSame('large', $character->capabilities()->where('capability', 'squeeze')->first()->grade);

        $this->assertSame(0, GrowthLedger::balance($character), 'a burden paid points into the ledger');
        $this->assertCount(0, $this->spends($campaign));
    }

    /**
     * Acknowledging a scar, and every other pure-prose exchange, stays free and
     * unchanged: nothing is granted, nothing is spent, and the conversation is
     * still written down.
     */
    public function test_a_prose_exchange_costs_and_earns_nothing()
    {
        $campaign = $this->createCampaign();
        $this->openBareTurn($campaign);
        $this->grant($campaign, 2);

        $this->answer = [
            'reply' => 'The knee will trouble you in the cold. Carry it well.',
            'granted' => false,
            'changes' => null,
        ];

        $this->ask($campaign, 'My knee has never been right since the fall.');

        $this->assertSame(2, GrowthLedger::balance($campaign->character->fresh()));
        $this->assertCount(0, $this->spends($campaign));
        $this->assertSame(2, InterviewMessage::where('campaign_id', $campaign->id)->where('kind', 'growth')->count());
    }

    /**
     * The prompt carries the running balance — the creation interview's rule,
     * applied where it was missing. A narrator that cannot see the ledger has
     * only two ways to be safe, and it picks one at random.
     */
    public function test_the_growth_prompt_carries_the_running_balance()
    {
        $campaign = $this->createCampaign();
        $this->openBareTurn($campaign);

        $this->ask($campaign, 'What could I become?');
        $empty = $this->lastGrowthPrompt();
        $this->assertStringContainsString('Nothing in hand yet', $empty);
        $this->assertStringContainsString('the engine REFUSES it whatever you answer', $empty);
        $this->assertStringContainsString('hand back a way FORWARD', $empty);

        $this->grant($campaign, 3);
        $this->ask($campaign, 'And now?');
        $this->assertStringContainsString('3 points in hand', $this->lastGrowthPrompt());
    }

    /** The panel states the balance, zero included — a hidden purse is a mystery. */
    public function test_the_play_page_states_the_balance()
    {
        $campaign = $this->createCampaign();
        $this->openBareTurn($campaign);

        $this->actingAs($campaign->user)->get(route('play.show', $campaign))
            ->assertInertia(fn ($page) => $page
                ->where('growthLedger.balance', 0)
                ->where('growthLedger.line', 'Nothing in hand yet — what a tale teaches is earned inside it first.'));

        $this->grant($campaign, 2);

        $this->actingAs($campaign->user)->get(route('play.show', $campaign))
            ->assertInertia(fn ($page) => $page
                ->where('growthLedger.balance', 2)
                ->where('growthLedger.line', '2 points in hand, earned in this tale.'));
    }

    /**
     * The currency never reaches the machinery. Nothing under app/Game may so
     * much as name the ledger row — the engine detects the MOMENT and hands the
     * minting outward, exactly as it does for the shelf. That is what keeps a
     * spendable number out of the cards, the odds, and the dice.
     */
    public function test_the_ledger_row_never_reaches_the_engine()
    {
        $offenders = [];

        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(app_path('Game'), \FilesystemIterator::SKIP_DOTS),
        );

        foreach ($files as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            // `GrowthLedger` (the service the resolver hands moments to) is
            // fine; `GrowthEntry` (the row) is not.
            if (preg_match('/\bGrowthEntry\b/', (string) file_get_contents($file->getPathname()))) {
                $offenders[] = $file->getPathname();
            }
        }

        $this->assertSame([], $offenders,
            'app/Game reached for the growth ledger row — the currency must stay outside the engine');
    }

    /**
     * Deleting a tale takes its accounts with it. An append-only ledger is not
     * an undeletable one, and a currency that could block a player from
     * throwing a campaign away would be the only thing in the game that can.
     */
    public function test_deleting_a_tale_takes_its_ledger_with_it()
    {
        $campaign = $this->createCampaign();
        $this->openBareTurn($campaign);
        $this->grant($campaign, 2);

        $this->actingAs($campaign->user)
            ->delete(route('campaigns.destroy', $campaign))
            ->assertRedirect(route('campaigns.index'));

        $this->assertSame(0, GrowthEntry::count());
    }
}
