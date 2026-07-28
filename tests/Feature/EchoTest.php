<?php

namespace Tests\Feature;

use App\Game\Engine\CardComposer;
use App\Game\Engine\Dice;
use App\Game\Engine\SituationBoard;
use App\Game\Engine\TurnResolver;
use App\Game\Meters;
use App\Models\Actor;
use App\Models\Campaign;
use App\Models\Chapter;
use App\Models\Character;
use App\Models\EchoLine;
use App\Models\Memento;
use App\Models\Turn;
use App\Models\User;
use App\Services\Claude\ClaudeCli;
use App\Services\Claude\Narrator;
use App\Services\Echoes;
use App\Services\TurnStarter;
use Database\Seeders\WorldSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Echoes: the shelf of finished books becomes one library.
 *
 * The load-bearing claims: a memory is always a QUOTATION of a line the player
 * really lived, traceable to the persisted row it came out of; only their OWN
 * ENDED tales may speak, and a first tale is silent forever; each rhyme draws
 * from its own column and no other; the caps keep it found rather than
 * announced; the narrator may move the wrapper and never the quote; and an echo
 * is colour in every direction — never a card, never an odds part, never a
 * board group, and never a write into the tale it came from.
 */
class EchoTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<string> */
    private array $prompts = [];

    /** What the mocked Claude proposes back for the echo, if anything. */
    private mixed $reworded = null;

    private User $player;

    protected function setUp(): void
    {
        parent::setUp();

        $this->player = User::factory()->create();

        // Every test below decides for itself whether a memory happens; the
        // dice are the only thing that should ever be in doubt.
        config(['game.echoes.chance' => 1.0]);
    }

    private function createCampaign(string $name = 'The Present Tale', ?User $user = null, string $flavor = 'harbor-city'): Campaign
    {
        $this->seed(WorldSeeder::class);
        Notification::fake();

        $this->prompts = [];
        $this->mock(ClaudeCli::class, function ($mock) {
            $mock->shouldReceive('promptForJson')->andReturnUsing(function (string $prompt) {
                $this->prompts[] = $prompt;

                return array_filter([
                    'chapter' => 'They went on, and something old went with them.',
                    'intent_line' => null,
                    'synopsis_line' => 'Something surfaced.',
                    'echo' => $this->reworded,
                ], fn ($value) => $value !== null);
            })->byDefault();
            $mock->shouldReceive('prompt')->andReturn('A tale begins.')->byDefault();
        });

        $campaign = Campaign::create([
            'user_id' => ($user ?? $this->player)->id,
            'name' => $name,
            'world_flavor' => $flavor,
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
     * A closed book, seeded rather than compiled: the shelf only ever reads
     * mementos, chapters, and a status, so a synthetic ended tale is the same
     * tale as far as this feature can tell.
     */
    private function endedTale(
        string $title = 'The Long Winter',
        string $flavor = 'harbor-city',
        string $status = 'completed',
        ?User $user = null,
    ): Campaign {
        return Campaign::create([
            'user_id' => ($user ?? $this->player)->id,
            'name' => 'A Finished Tale',
            'title' => $title,
            'world_flavor' => $flavor,
            'status' => $status,
            'started_at' => now()->subMonth(),
            'ended_at' => $status === 'completed' ? now()->subWeek() : null,
        ]);
    }

    private function keepsake(Campaign $tale, string $trigger, string $line, string $subject = 'the harbormaster'): Memento
    {
        return Memento::create([
            'campaign_id' => $tale->id,
            'turn_id' => null,
            'chapter_id' => null,
            'trigger' => $trigger,
            'subject' => $subject,
            'name' => 'Something kept',
            'line' => $line,
        ]);
    }

    private function page(Campaign $tale, int $number, ?string $intent, string $kind = 'chapter'): Chapter
    {
        return Chapter::create([
            'campaign_id' => $tale->id,
            'turn_id' => null,
            'number' => $number,
            'kind' => $kind,
            'intent_line' => $intent,
            'body' => 'It happened, and then it was over.',
        ]);
    }

    /** An open turn on the present tale, with the ground cleared to nothing. */
    private function openBareTurn(Campaign $campaign): Turn
    {
        $turn = app(TurnStarter::class)->openFirstTurn($campaign);

        $scene = $campaign->activeScene;
        $scene->actors()->delete();
        $scene->features()->delete();
        Actor::whereNull('scene_id')->delete();

        return $turn->fresh();
    }

    /** A resolved turn to hang a memory on, without resolving anything. */
    private function bareTurn(Campaign $campaign, int $number): Turn
    {
        return Turn::create([
            'campaign_id' => $campaign->id,
            'scene_id' => $campaign->fresh()->activeScene?->id,
            'number' => $number,
            'status' => Turn::STATUS_COMPLETE,
            'situation' => 'Ground already walked.',
            'cards' => ['main' => []],
            'resolution' => [],
        ]);
    }

    /** Re-offer the open turn from the ground as it stands, then resolve a verb. */
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

    // ---- Silence is the default ----

    public function test_a_first_tale_never_echoes_and_the_narrator_is_never_asked_to_remember()
    {
        $campaign = $this->createCampaign();
        $turn = $this->openBareTurn($campaign);

        // Every rhyme at once, and the chance certain. There is simply nothing
        // behind this player.
        $this->assertNull(Echoes::consider($turn, Echoes::RHYMES, new Dice(7)));
        $this->assertSame(0, EchoLine::count());
        $this->assertSame('', Echoes::narratorBlock($turn));

        $resolved = $this->play($campaign, 'examine');
        $this->assertNull($resolved->resolution['echo']);

        app(Narrator::class)->narrate($resolved->fresh());

        $prompt = collect($this->prompts)->first(fn (string $p) => str_contains($p, 'You are the narrator'));
        $this->assertStringNotContainsString('Something they remember from another life', $prompt);
        $this->assertStringNotContainsString('"echo"', $prompt);
    }

    public function test_a_sibling_tale_still_being_played_is_not_a_memory_yet()
    {
        $running = $this->endedTale('The Tale Still Going', status: 'active');
        $this->keepsake($running, 'scar_taken', 'It came away with them from the lower stair.');

        $campaign = $this->createCampaign();
        $turn = $this->openBareTurn($campaign);

        $this->assertNull(Echoes::consider($turn, [Echoes::THE_MARK], new Dice(7)));
        $this->assertSame(0, EchoLine::count());
    }

    public function test_another_players_finished_tale_is_not_this_players_life()
    {
        $stranger = User::factory()->create();
        $theirs = $this->endedTale('Somebody Else’s Winter', user: $stranger);
        $this->keepsake($theirs, 'scar_taken', 'It came away with them from the lower stair.');

        $campaign = $this->createCampaign();
        $turn = $this->openBareTurn($campaign);

        $this->assertNull(Echoes::consider($turn, [Echoes::THE_MARK], new Dice(7)));
        $this->assertSame(0, EchoLine::count());
    }

    // ---- Each rhyme, and only its own column ----

    public function test_the_mark_and_the_rival_each_draw_only_from_their_own_column()
    {
        $tale = $this->endedTale();
        $mark = $this->keepsake($tale, 'scar_taken', 'It came away with them from the lower stair.');
        $rival = $this->keepsake($tale, 'rival_settled', 'Kept from the quay, the day the score closed for good.');
        $this->keepsake($tale, 'elite_beaten', 'Off the big one, who did not get up again.');

        $campaign = $this->createCampaign();
        $this->openBareTurn($campaign);

        $first = Echoes::consider($this->bareTurn($campaign, 90), [Echoes::THE_MARK], new Dice(3));
        $this->assertSame(Echoes::THE_MARK, $first['rhyme']);
        $this->assertStringContainsString($mark->line, $first['line']);
        $this->assertStringContainsString('The Long Winter', $first['line']);

        config(['game.echoes.cooldown_chapters' => 0]);

        $second = Echoes::consider($this->bareTurn($campaign, 91), [Echoes::THE_RIVAL], new Dice(3));
        $this->assertSame(Echoes::THE_RIVAL, $second['rhyme']);
        $this->assertStringContainsString($rival->line, $second['line']);

        // And nothing ever reached for the keepsake that belongs to neither.
        foreach (EchoLine::all() as $echo) {
            $this->assertNotSame('elite_beaten', Memento::find($echo->source_id)->trigger);
        }
    }

    public function test_a_rhyme_with_an_empty_column_is_silent_rather_than_borrowing()
    {
        $tale = $this->endedTale();
        $this->keepsake($tale, 'scar_taken', 'It came away with them from the lower stair.');

        $campaign = $this->createCampaign();
        $this->openBareTurn($campaign);

        $this->assertNull(Echoes::consider($this->bareTurn($campaign, 90), [Echoes::THE_RIVAL], new Dice(3)));
        $this->assertSame(0, EchoLine::count());
    }

    public function test_the_company_draws_on_somebody_who_was_lost()
    {
        $tale = $this->endedTale();
        $lost = $this->keepsake($tale, 'companion_lost', 'It was Bren’s, and afterwards there was nobody to give it back to.');

        $campaign = $this->createCampaign();
        $this->openBareTurn($campaign);

        $echo = Echoes::consider($this->bareTurn($campaign, 90), [Echoes::THE_COMPANY], new Dice(3));

        $this->assertSame(Echoes::THE_COMPANY, $echo['rhyme']);
        $this->assertStringContainsString($lost->line, $echo['line']);
        $this->assertSame($lost->id, EchoLine::first()->source_id);
    }

    public function test_old_ground_matches_the_land_and_prefers_the_first_ground_keepsake()
    {
        $here = $this->endedTale('The Long Winter', flavor: 'harbor-city');
        $this->page($here, 1, 'She came in on the tide.');
        $souvenir = $this->keepsake($here, 'first_ground', 'Picked up at the quay, the first of that country they walked.');

        $elsewhere = $this->endedTale('The Ash Road', flavor: 'ash-steppe');
        $this->keepsake($elsewhere, 'first_ground', 'Taken off the dust, where nothing grew.');

        $campaign = $this->createCampaign(flavor: 'harbor-city');
        $this->openBareTurn($campaign);

        $echo = Echoes::consider($this->bareTurn($campaign, 90), [Echoes::OLD_GROUND], new Dice(3));

        $this->assertSame(Echoes::OLD_GROUND, $echo['rhyme']);
        $this->assertStringContainsString($souvenir->line, $echo['line']);
        $this->assertStringNotContainsString('where nothing grew', $echo['line']);
        $this->assertSame($here->id, EchoLine::first()->source_campaign_id);
    }

    public function test_old_ground_falls_back_to_the_opening_line_and_stays_silent_on_a_different_land()
    {
        $here = $this->endedTale('The Long Winter', flavor: 'harbor-city');
        $opening = $this->page($here, 1, 'She came in on the tide, with nothing.');
        $this->page($here, 2, 'The second morning cost her a boat.');

        $campaign = $this->createCampaign(flavor: 'harbor-city');
        $this->openBareTurn($campaign);

        $echo = Echoes::consider($this->bareTurn($campaign, 90), [Echoes::OLD_GROUND], new Dice(3));

        $this->assertStringContainsString($opening->intent_line, $echo['line']);
        $this->assertSame(Echoes::CHAPTER, EchoLine::first()->source_type);
        $this->assertSame($opening->id, EchoLine::first()->source_id);

        // A tale set somewhere else is not ground they have stood on.
        $other = $this->createCampaign('A Tale Elsewhere', flavor: 'ash-steppe');
        $this->openBareTurn($other);

        $this->assertNull(Echoes::consider($this->bareTurn($other, 90), [Echoes::OLD_GROUND], new Dice(3)));
        $this->assertSame(1, EchoLine::count());
    }

    public function test_the_gathering_quotes_the_closing_line_of_a_finished_tale()
    {
        $tale = $this->endedTale();
        $this->page($tale, 1, 'She came in on the tide.');
        $closing = $this->page($tale, 9, 'She went back down to the water to finish it.');
        // A coda carries no line of its own, and is passed over rather than
        // quoted out of its body.
        $this->page($tale, 10, null, kind: 'coda');

        $campaign = $this->createCampaign();
        $this->openBareTurn($campaign);

        $echo = Echoes::consider($this->bareTurn($campaign, 90), [Echoes::THE_GATHERING], new Dice(3));

        $this->assertSame(Echoes::THE_GATHERING, $echo['rhyme']);
        $this->assertStringContainsString($closing->intent_line, $echo['line']);
        $this->assertSame($closing->id, EchoLine::first()->source_id);
    }

    public function test_the_rhyme_list_order_is_the_priority_rule()
    {
        $tale = $this->endedTale();
        $mark = $this->keepsake($tale, 'scar_taken', 'It came away with them from the lower stair.');
        $this->keepsake($tale, 'first_ground', 'Picked up at the quay, the first of that country they walked.');

        $campaign = $this->createCampaign();
        $this->openBareTurn($campaign);

        $echo = Echoes::consider(
            $this->bareTurn($campaign, 90),
            [Echoes::OLD_GROUND, Echoes::THE_MARK],
            new Dice(3),
        );

        $this->assertSame(Echoes::THE_MARK, $echo['rhyme']);
        $this->assertStringContainsString($mark->line, $echo['line']);
    }

    // ---- Rare, capped, and found ----

    public function test_a_failed_chance_roll_is_silence()
    {
        config(['game.echoes.chance' => 0.0]);

        $tale = $this->endedTale();
        $this->keepsake($tale, 'scar_taken', 'It came away with them from the lower stair.');

        $campaign = $this->createCampaign();
        $this->openBareTurn($campaign);

        $this->assertNull(Echoes::consider($this->bareTurn($campaign, 90), [Echoes::THE_MARK], new Dice(3)));
        $this->assertSame(0, EchoLine::count());
    }

    public function test_a_source_line_speaks_at_most_once_in_one_tale()
    {
        config(['game.echoes.cooldown_chapters' => 0]);

        $tale = $this->endedTale();
        $only = $this->keepsake($tale, 'scar_taken', 'It came away with them from the lower stair.');

        $campaign = $this->createCampaign();
        $this->openBareTurn($campaign);

        $this->assertNotNull(Echoes::consider($this->bareTurn($campaign, 90), [Echoes::THE_MARK], new Dice(3)));
        $this->assertNull(Echoes::consider($this->bareTurn($campaign, 91), [Echoes::THE_MARK], new Dice(3)));
        $this->assertSame(1, EchoLine::count());
        $this->assertSame($only->id, EchoLine::first()->source_id);

        // The same line is free to speak again in a DIFFERENT tale: the rule is
        // once per new campaign, not once per lifetime.
        $next = $this->createCampaign('A Later Tale');
        $this->openBareTurn($next);

        $this->assertNotNull(Echoes::consider($this->bareTurn($next, 90), [Echoes::THE_MARK], new Dice(3)));
        $this->assertSame(2, EchoLine::count());
    }

    public function test_the_campaign_cap_closes_the_shelf()
    {
        config(['game.echoes.cooldown_chapters' => 0, 'game.echoes.campaign_cap' => 2]);

        $tale = $this->endedTale();
        foreach (range(1, 5) as $n) {
            $this->keepsake($tale, 'scar_taken', "It came away with them from stair number {$n}.");
        }

        $campaign = $this->createCampaign();
        $this->openBareTurn($campaign);

        foreach (range(90, 94) as $number) {
            Echoes::consider($this->bareTurn($campaign, $number), [Echoes::THE_MARK], new Dice(3));
        }

        $this->assertSame(2, Echoes::count($campaign));
    }

    public function test_a_cooldown_stands_between_two_memories()
    {
        config(['game.echoes.cooldown_chapters' => 3]);

        $tale = $this->endedTale();
        foreach (range(1, 5) as $n) {
            $this->keepsake($tale, 'scar_taken', "It came away with them from stair number {$n}.");
        }

        $campaign = $this->createCampaign();
        $this->openBareTurn($campaign);

        $this->assertNotNull(Echoes::consider($this->bareTurn($campaign, 90), [Echoes::THE_MARK], new Dice(3)));
        // Two chapters later is still inside the wait.
        $this->assertNull(Echoes::consider($this->bareTurn($campaign, 92), [Echoes::THE_MARK], new Dice(3)));
        // Three is not.
        $this->assertNotNull(Echoes::consider($this->bareTurn($campaign, 93), [Echoes::THE_MARK], new Dice(3)));

        $this->assertSame(2, Echoes::count($campaign));
    }

    public function test_at_most_one_memory_per_turn()
    {
        config(['game.echoes.cooldown_chapters' => 0]);

        $tale = $this->endedTale();
        $this->keepsake($tale, 'scar_taken', 'It came away with them from the lower stair.');
        $this->keepsake($tale, 'rival_settled', 'Kept from the quay, the day the score closed for good.');

        $campaign = $this->createCampaign();
        $this->openBareTurn($campaign);
        $turn = $this->bareTurn($campaign, 90);

        $this->assertNotNull(Echoes::consider($turn, [Echoes::THE_MARK], new Dice(3)));
        $this->assertNull(Echoes::consider($turn, [Echoes::THE_RIVAL], new Dice(3)));
        $this->assertSame(1, EchoLine::where('turn_id', $turn->id)->count());
    }

    public function test_the_pick_is_seeded()
    {
        config(['game.echoes.cooldown_chapters' => 0]);

        $seed = fn () => tap($this->endedTale(), function (Campaign $tale) {
            foreach (range(1, 6) as $n) {
                $this->keepsake($tale, 'scar_taken', "It came away with them from stair number {$n}.");
            }
        });

        $seed();
        $first = $this->createCampaign('First Run');
        $this->openBareTurn($first);
        $a = Echoes::consider($this->bareTurn($first, 90), [Echoes::THE_MARK], new Dice(4242));

        $second = $this->createCampaign('Second Run');
        $this->openBareTurn($second);
        $b = Echoes::consider($this->bareTurn($second, 90), [Echoes::THE_MARK], new Dice(4242));

        $this->assertSame($a['line'], $b['line']);
        $this->assertNotSame(
            $a['line'],
            Echoes::consider($this->bareTurn($this->createCampaign('Third Run'), 90), [Echoes::THE_MARK], new Dice(9))['line'],
        );
    }

    // ---- Traceability ----

    public function test_every_memory_traces_back_to_a_real_line_of_an_ended_tale_of_the_same_player()
    {
        config(['game.echoes.cooldown_chapters' => 0]);

        $tale = $this->endedTale();
        $this->keepsake($tale, 'scar_taken', 'It came away with them from the lower stair.');
        $this->keepsake($tale, 'companion_lost', 'It was Bren’s, and afterwards there was nobody to give it back to.');
        $this->page($tale, 1, 'She came in on the tide.');
        $this->page($tale, 9, 'She went back down to the water to finish it.');

        $campaign = $this->createCampaign();
        $this->openBareTurn($campaign);

        $number = 90;
        foreach ([Echoes::THE_MARK, Echoes::THE_COMPANY, Echoes::OLD_GROUND, Echoes::THE_GATHERING] as $rhyme) {
            config(['game.echoes.campaign_cap' => 99]);
            Echoes::consider($this->bareTurn($campaign, $number++), [$rhyme], new Dice(3));
        }

        $this->assertGreaterThan(0, EchoLine::count());

        foreach (EchoLine::all() as $echo) {
            $source = Campaign::find($echo->source_campaign_id);

            $this->assertNotNull($source, 'a memory pointed at a tale that does not exist');
            $this->assertSame('completed', $source->status);
            $this->assertSame($this->player->id, $source->user_id);

            $quote = Echoes::quoteOf($echo);
            $this->assertNotNull($quote, 'a memory could not be traced back to the words it quotes');
            $this->assertStringContainsString($quote, $echo->line);

            $row = $echo->source_type === Echoes::MEMENTO
                ? Memento::find($echo->source_id)
                : Chapter::find($echo->source_id);
            $this->assertNotNull($row);
            $this->assertSame($source->id, $row->campaign_id);
        }
    }

    // ---- What the narrator may and may not do with it ----

    public function test_the_narrator_is_handed_the_memory_as_a_quotation_and_may_move_only_the_wrapper()
    {
        $tale = $this->endedTale();
        $souvenir = $this->keepsake($tale, 'first_ground', 'Picked up at the quay, the first of that country they walked.');

        $campaign = $this->createCampaign();
        $this->openBareTurn($campaign);
        $turn = $this->play($campaign, 'examine');

        $this->assertNotNull($turn->resolution['echo'], 'the resolver surfaced nothing on ground walked before');
        $engineWords = EchoLine::first()->line;

        $this->reworded = 'The salt came back to them out of an older year. "'.$souvenir->line.'" — from the tale of The Long Winter.';

        app(Narrator::class)->narrate($turn->fresh());

        $prompt = collect($this->prompts)->first(fn (string $p) => str_contains($p, 'You are the narrator'));
        $this->assertStringContainsString('Something they remember from another life', $prompt);
        $this->assertStringContainsString($engineWords, $prompt);
        $this->assertStringContainsString('Nobody in this scene knows it', $prompt);

        $after = EchoLine::first();
        $this->assertSame($this->reworded, $after->line);
        // The quotation survived the rewording word for word, and the citation
        // the engine owns landed on top of it.
        $this->assertStringContainsString($souvenir->line, $after->line);
        $this->assertNotNull($after->chapter_id);
        $this->assertSame($souvenir->id, $after->source_id);
    }

    public function test_a_rewording_that_touches_the_quote_leaves_the_engine_s_words_standing()
    {
        config(['game.echoes.cooldown_chapters' => 0]);

        $tale = $this->endedTale();
        $mark = $this->keepsake($tale, 'scar_taken', 'It came away with them from the lower stair.');

        $campaign = $this->createCampaign();
        $this->openBareTurn($campaign);

        $chapter = $this->page($campaign, 1, null);

        foreach ([
            'nothing at all' => null,
            'the quote edited' => 'They remembered it. "It came away with them from the upper stair." — from the tale of The Long Winter.',
            'the quote dropped' => 'They remembered something out of an older year — from the tale of The Long Winter.',
            'the tale unnamed' => 'They remembered it. "'.$mark->line.'"',
            'a wrapper that runs on' => 'They remembered it and went on remembering it and kept on remembering it and would not stop remembering it and could not put it down at all. "'.$mark->line.'" — from the tale of The Long Winter.',
            'the machinery underneath' => 'A lucky roll of the dice brought it back. "'.$mark->line.'" — from the tale of The Long Winter.',
            'blank words' => '   ',
            'not even a sentence' => ['line' => $mark->line],
        ] as $label => $proposal) {
            $turn = $this->bareTurn($campaign, 400 + Turn::where('campaign_id', $campaign->id)->count());

            $written = Echoes::consider($turn, [Echoes::THE_MARK], new Dice(3));
            if ($written === null) {
                // One source line speaks once per tale; re-arm it so each
                // proposal below is judged against a real row.
                EchoLine::where('campaign_id', $campaign->id)->delete();
                $written = Echoes::consider($turn, [Echoes::THE_MARK], new Dice(3));
            }

            $echo = Echoes::forTurn($turn);
            $this->assertNotNull($echo);

            Echoes::reword($turn, $chapter, $proposal);

            $after = $echo->fresh();
            $this->assertSame($written['line'], $after->line, "the clamp let through: {$label}");
            // The chapter stamp still lands — the citation is the engine's, not
            // something Claude has to earn.
            $this->assertSame($chapter->id, $after->chapter_id);
        }
    }

    // ---- Colour, and nothing else ----

    public function test_the_resolver_surfaces_a_memory_and_it_never_becomes_a_card_an_odds_part_or_a_board_group()
    {
        $tale = $this->endedTale();
        $souvenir = $this->keepsake($tale, 'first_ground', 'Picked up at the marker stone, the first of that country they walked.');
        $sourceLine = $souvenir->line;

        $campaign = $this->createCampaign();
        $this->openBareTurn($campaign);

        $turn = $this->play($campaign, 'examine');

        $echo = $turn->resolution['echo'];
        $this->assertNotNull($echo, 'the ground opened on a land they had walked before and nothing came back');
        $this->assertSame(Echoes::OLD_GROUND, $echo['rhyme']);
        $this->assertStringContainsString($sourceLine, $echo['line']);

        // The screen carries it at the weight of the news line beside it.
        $this->assertSame($echo['line'], $turn->resolution['echo']['line']);

        // Nothing about the finished tale moved: an echo reads, and never writes.
        $this->assertSame($sourceLine, $souvenir->fresh()->line);
        $this->assertSame('completed', $tale->fresh()->status);
        $this->assertSame(0, Actor::where('scene_id', $campaign->fresh()->activeScene->id)
            ->where('name', 'like', '%Long Winter%')->count());

        $next = $campaign->fresh()->currentTurn;
        $cards = collect($next->cards['pre'])
            ->concat($next->cards['main'])
            ->concat($next->cards['post']);

        foreach ($cards as $card) {
            $printed = "{$card['label']} {$card['description']}";
            $this->assertStringNotContainsString('marker stone', $printed);
            $this->assertStringNotContainsString('Long Winter', $printed);
            foreach ($card['forecast']['parts'] as $part) {
                $this->assertStringNotContainsString('Long Winter', $part['label']);
            }
            foreach ($card['forecast']['bonus_parts'] as $part) {
                $this->assertStringNotContainsString('Long Winter', $part['label']);
            }
        }

        $board = SituationBoard::for($campaign->character->fresh(), $campaign->fresh()->activeScene);
        $this->assertNotContains('echo', collect($board)->pluck('key')->all());
        $this->assertStringNotContainsString('Long Winter', SituationBoard::prose($board));
        $this->assertStringNotContainsString('Long Winter', $next->situation);
    }
}
