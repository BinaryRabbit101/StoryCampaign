<?php

namespace Tests\Feature;

use App\Game\Engine\TurnResolver;
use App\Game\Meters;
use App\Models\Actor;
use App\Models\Campaign;
use App\Models\Chapter;
use App\Models\Character;
use App\Models\Grudge;
use App\Models\Memento;
use App\Models\Turn;
use App\Models\User;
use App\Models\Zone;
use App\Services\BookCompiler;
use App\Services\Claude\ClaudeCli;
use App\Services\Claude\Narrator;
use App\Services\Mementos;
use App\Services\TurnStarter;
use Database\Seeders\WorldSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Mementos: a trophy shelf that compiles into the book.
 *
 * Notable resolved moments leave an object behind, the shelf fills slowly, and
 * the finished book gains a closing section — what you carried home.
 *
 * The load-bearing claims these tests hold down: the engine mints them (from a
 * closed trigger list, with its own words, stored the instant the turn
 * resolves, so a memento survives an evening Claude is down), Claude may only
 * reword one inside a clamp, the shelf stays sparse, and the whole feature is
 * INERT — nothing under app/Game may so much as name the model.
 */
class MementoTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<string> */
    private array $prompts = [];

    /** What the mocked Claude answers with for a memento, if anything. */
    private ?array $reworded = null;

    private function createCampaign(string $name = 'Keepsake Tale'): Campaign
    {
        $this->seed(WorldSeeder::class);

        $this->prompts = [];
        $this->mock(ClaudeCli::class, function ($mock) {
            $mock->shouldReceive('promptForJson')->andReturnUsing(function (string $prompt) {
                $this->prompts[] = $prompt;

                return array_filter([
                    'chapter' => 'They walked out of it with something in their hand.',
                    'intent_line' => null,
                    'synopsis_line' => 'Something was carried away.',
                    'title' => 'What Was Carried',
                    'back_cover' => 'A tale of small things kept.',
                    'memento' => $this->reworded,
                ], fn ($value) => $value !== null);
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
            ['capability' => 'restrain', 'source' => 'creation'],
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

    /** Resolve a turn on the given card id (or the quietest one available). */
    private function play(Turn $turn, ?string $cardId = null): Turn
    {
        $cardId ??= (collect($turn->cards['main'])->firstWhere('verb', 'wait')
            ?? collect($turn->cards['main'])->first())['id'];

        $turn->update([
            'status' => Turn::STATUS_LOCKED,
            'submission' => ['main' => ['card_id' => $cardId, 'modifiers' => []]],
            'submitted_at' => now(),
        ]);

        app(TurnResolver::class)->resolve($turn->fresh());

        return $turn->fresh();
    }

    /**
     * Play quiet turns until $done says stop. The dice are seeded off the turn
     * id, so this walks a fixed sequence — the loop is how a test reaches an
     * outcome the engine alone decides, not a source of flakiness.
     */
    private function playUntil(Campaign $campaign, callable $done, ?callable $pick = null, int $limit = 24): Turn
    {
        $turn = $campaign->fresh()->currentTurn;

        for ($i = 0; $i < $limit && ! $done(); $i++) {
            $turn = $campaign->fresh()->currentTurn;
            if ($turn === null || ! $turn->isOpen()) {
                break;
            }

            // Keep the body out of it: this test is about what the tale keeps,
            // and a character bleeding out mid-loop would mint a fall instead.
            Meters::heal($campaign->character->fresh(), 20);

            $card = $pick === null ? null : $pick($turn);
            if ($pick !== null && $card === null) {
                break;
            }

            $turn = $this->play($turn, $card);
        }

        return $turn;
    }

    /** A turn already played and behind them — the shelf only cites those. */
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

    private function shelved(Campaign $campaign): ?Memento
    {
        return Memento::where('campaign_id', $campaign->id)->orderByDesc('id')->first();
    }

    /**
     * An old score closed for good is the rarest thing on the list, and the
     * keepsake carries the whole provenance: which turn, which chapter, and
     * whose it was.
     */
    public function test_a_settled_rival_leaves_a_keepsake_with_its_provenance()
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

        $memento = $this->shelved($campaign);
        $this->assertNotNull($memento, 'the settled score left nothing behind');
        $this->assertSame('rival_settled', $memento->trigger);
        $this->assertSame($campaign->id, $memento->campaign_id);
        $this->assertSame($turn->id, $memento->turn_id);
        $this->assertSame('the harbormaster', $memento->subject);
        $this->assertStringContainsString('harbormaster', "{$memento->name} {$memento->line}");

        // Minted during resolution, which is BEFORE the chapter telling it
        // exists. The citation is stamped when that chapter is written.
        $this->assertNull($memento->chapter_id);

        app(Narrator::class)->narrate($turn->fresh());

        $chapter = $campaign->chapters()->reorder('number', 'desc')->first();
        $this->assertSame($chapter->id, $memento->fresh()->chapter_id);
    }

    /** The fall that marked them keeps whatever it was that put them there. */
    public function test_the_fall_that_marked_them_leaves_something_behind()
    {
        $campaign = $this->createCampaign();
        $turn = $this->openBareTurn($campaign);

        Meters::damage($campaign->character->fresh(), 10);
        $turn = $this->play($turn);

        $this->assertNotNull($turn->resolution['fall']['scar']);

        $memento = $this->shelved($campaign);
        $this->assertNotNull($memento);
        $this->assertSame('scar_taken', $memento->trigger);
        $this->assertSame($turn->id, $memento->turn_id);
        $this->assertNotSame('', trim($memento->line));
    }

    /** An elite put down (or bound) is a moment the tale keeps something from. */
    public function test_an_elite_brought_down_leaves_a_keepsake()
    {
        $campaign = $this->createCampaign();
        $turn = $this->openBareTurn($campaign);
        $scene = $campaign->activeScene;

        $elite = Actor::create([
            'scene_id' => $scene->id,
            'zone_id' => $scene->zone_id,
            'name' => 'a harbor enforcer',
            'kind' => 'enemy',
            'tier' => 'elite',
            'stats' => ['health' => ['current' => 1, 'max' => 6], 'attack' => 1],
            'tags' => ['intent' => 'press'],
            'status' => 'active',
            'source' => 'seed',
        ]);

        // One quiet turn so the enforcer is standing on the cards, then swing
        // until they are not standing at all.
        $this->play($turn);

        $this->playUntil(
            $campaign,
            fn () => in_array($elite->fresh()->status, ['defeated', 'dead', 'restrained'], true),
            fn (Turn $open) => collect($open->cards['main'])->first(
                fn (array $card) => $card['verb'] === 'strike' && ($card['target']['id'] ?? null) === $elite->id,
            )['id'] ?? null,
        );

        $this->assertContains($elite->fresh()->status, ['defeated', 'dead', 'restrained']);

        $memento = Memento::where('campaign_id', $campaign->id)->where('trigger', 'elite_beaten')->first();
        $this->assertNotNull($memento, 'the elite went down and the tale kept nothing');
        $this->assertSame('a harbor enforcer', $memento->subject);
        $this->assertStringContainsString('enforcer', "{$memento->name} {$memento->line}");
    }

    /** A captive out of the grip and whole again is worth remembering. */
    public function test_a_freed_captive_leaves_a_keepsake()
    {
        $campaign = $this->createCampaign();
        $turn = $this->openBareTurn($campaign);
        $scene = $campaign->activeScene;

        $captive = Actor::create([
            'scene_id' => $scene->id,
            'zone_id' => $scene->zone_id,
            'name' => 'the lantern watchman',
            'kind' => 'npc',
            'tier' => 'regular',
            'stats' => ['health' => ['current' => 4, 'max' => 4], 'attack' => 1],
            'tags' => [],
            'status' => 'restrained',
            'source' => 'seed',
        ]);

        $this->play($turn);
        $this->playUntil($campaign, fn () => $captive->fresh()->status === 'active');

        $this->assertSame('active', $captive->fresh()->status);

        $memento = Memento::where('campaign_id', $campaign->id)->where('trigger', 'captive_freed')->first();
        $this->assertNotNull($memento, 'the captive walked and the tale kept nothing');
        $this->assertSame('the lantern watchman', $memento->subject);
    }

    /** New country leaves a pressed flower — once, and once only, per zone. */
    public function test_new_country_leaves_one_keepsake_and_only_one()
    {
        $campaign = $this->createCampaign();
        $turn = $this->openBareTurn($campaign);

        $frontier = Zone::create([
            'campaign_id' => $campaign->id,
            'slug' => 'the-far-shelf',
            'name' => 'The Far Shelf',
            'description' => 'Country nobody in this tale has walked yet.',
            'tags' => [],
            'source' => 'forge',
        ]);
        $campaign->update(['next_zone_id' => $frontier->id]);

        $this->play($turn);

        $this->playUntil(
            $campaign,
            fn () => $campaign->fresh()->activeScene->zone_id === $frontier->id,
            fn (Turn $open) => collect($open->cards['main'])->firstWhere('verb', 'venture')['id'] ?? null,
        );

        $this->assertSame($frontier->id, $campaign->fresh()->activeScene->zone_id);

        $first = Memento::where('campaign_id', $campaign->id)->where('trigger', 'first_ground')->get();
        $this->assertCount(1, $first);
        $this->assertSame('The Far Shelf', $first[0]->subject);

        // And the same ground never yields a second one, however it is reached.
        $this->assertNull(Mementos::mint($this->pastTurn($campaign, 99), [
            ['trigger' => 'first_ground', 'subject' => 'The Far Shelf', 'place' => 'the shelf road'],
        ]));
    }

    /**
     * The priority rule. Several moments can land in one chapter, and the
     * shelf takes the rarest — a settled score outranks a bested elite, which
     * outranks the ground being new.
     */
    public function test_only_the_rarest_trigger_mints_when_several_fire()
    {
        $campaign = $this->createCampaign();
        $this->openBareTurn($campaign);

        $order = [];
        foreach ([
            ['first_ground', 'The Far Shelf'],
            ['captive_freed', 'the lantern watchman'],
            ['elite_beaten', 'a harbor enforcer'],
            ['scar_taken', 'a dockside tough'],
            ['rival_settled', 'the harbormaster'],
        ] as $index => [$trigger, $subject]) {
            $turn = $this->pastTurn($campaign, 200 + $index);

            // Everything that has been offered so far fires at once; the
            // newest (rarer) entry must be the one that wins each time.
            $order[] = ['trigger' => $trigger, 'subject' => $subject, 'place' => 'the long quay'];

            $minted = Mementos::mint($turn, $order);
            $this->assertNotNull($minted);
            $this->assertSame($trigger, $minted->trigger);
            $this->assertSame($subject, $minted->subject);
        }
    }

    /** Sparse by design: one per chapter, and a ceiling on the whole tale. */
    public function test_the_caps_hold()
    {
        config(['game.mementos.per_campaign' => 3]);

        $campaign = $this->createCampaign();
        $this->openBareTurn($campaign);

        $candidates = [['trigger' => 'elite_beaten', 'subject' => 'a harbor enforcer', 'place' => 'the long quay']];

        $turns = collect(range(1, 5))->map(fn (int $n) => $this->pastTurn($campaign, 300 + $n));

        // One per chapter: a chapter is one turn's telling, so a second mint
        // on the same turn is refused outright.
        $this->assertNotNull(Mementos::mint($turns[0], $candidates));
        $this->assertNull(Mementos::mint($turns[0], $candidates));

        $this->assertNotNull(Mementos::mint($turns[1], $candidates));
        $this->assertNotNull(Mementos::mint($turns[2], $candidates));

        // And the tale's own ceiling stops the shelf becoming an inventory.
        $this->assertNull(Mementos::mint($turns[3], $candidates));
        $this->assertSame(3, Memento::where('campaign_id', $campaign->id)->count());
    }

    /**
     * The engine's words are the real ones. A narration that never happens
     * costs the memento nothing: it was written to the shelf the moment the
     * turn resolved, and it compiles into the book exactly as it stands.
     */
    public function test_a_broken_narration_leaves_the_templated_keepsake_standing()
    {
        $campaign = $this->createCampaign();
        $turn = $this->openBareTurn($campaign);

        Meters::damage($campaign->character->fresh(), 10);
        $turn = $this->play($turn);

        $memento = $this->shelved($campaign);
        $this->assertNotNull($memento);

        $this->mock(ClaudeCli::class, function ($mock) {
            $mock->shouldReceive('promptForJson')->andThrow(new \RuntimeException('claude is down'));
            $mock->shouldReceive('prompt')->andThrow(new \RuntimeException('claude is down'));
        });

        try {
            app(Narrator::class)->narrate($turn->fresh());
            $this->fail('the narration was supposed to fall over');
        } catch (\Throwable) {
            // exactly the evening this feature is for
        }

        $standing = $memento->fresh();
        $this->assertSame($memento->name, $standing->name);
        $this->assertSame($memento->line, $standing->line);

        $book = app(BookCompiler::class)->compile($campaign->fresh());
        $this->assertCount(1, $book['mementos']);
        $this->assertSame($memento->name, $book['mementos'][0]['name']);
        // No chapter was ever written, so there is no page to cite — and the
        // keepsake stands on the shelf anyway.
        $this->assertNull($book['mementos'][0]['chapter']);
    }

    /**
     * Claude is invited to word the object better, and that invitation is the
     * ONLY thing it may change about the row.
     */
    public function test_the_narrator_may_reword_the_keepsake_within_the_clamp()
    {
        $campaign = $this->createCampaign();
        $turn = $this->openBareTurn($campaign);
        $scene = $campaign->activeScene;

        Actor::create([
            'scene_id' => $scene->id,
            'zone_id' => $scene->zone_id,
            'name' => 'the harbormaster',
            'kind' => 'enemy',
            'tier' => 'regular',
            'stats' => ['health' => ['current' => 0, 'max' => 4], 'attack' => 2],
            'tags' => ['grudge_id' => Grudge::create([
                'campaign_id' => $campaign->id,
                'actor_name' => 'the harbormaster',
                'stats' => ['health' => ['current' => 0, 'max' => 4], 'attack' => 2],
                'tags' => [],
                'tier' => 'regular',
                'history' => [],
                'heat' => 1,
                'disposition' => 'vengeful',
                'status' => 'returning',
                'last_seen_chapter_id' => null,
            ])->id],
            'status' => 'defeated',
            'source' => 'grudge',
        ]);

        $turn = $this->play($turn);
        $memento = $this->shelved($campaign);
        $this->assertNotNull($memento);
        $before = $memento->only(['trigger', 'subject', 'turn_id', 'campaign_id']);

        $this->reworded = [
            'name' => "The harbormaster's brass whistle",
            'line' => 'Taken off the harbormaster on the quay, and never blown since.',
        ];

        app(Narrator::class)->narrate($turn->fresh());

        $after = $memento->fresh();
        $this->assertSame("The harbormaster's brass whistle", $after->name);
        $this->assertSame('Taken off the harbormaster on the quay, and never blown since.', $after->line);
        $this->assertSame($before, $after->only(['trigger', 'subject', 'turn_id', 'campaign_id']));

        // And the narrator was handed the object as a fixed fact, with the
        // clamp stated — not asked whether one should exist.
        $prompt = collect($this->prompts)->first(fn (string $p) => str_contains($p, 'You are the narrator of a living-world RPG'));
        $this->assertStringContainsString('## The keepsake this moment leaves behind', $prompt);
        $this->assertStringContainsString('the harbormaster', $prompt);
    }

    /**
     * Every way a rewording can stray, refused: too long, about something
     * else, or written in the language of the machinery underneath. On any of
     * them the engine's own words stand.
     */
    public function test_a_rewording_that_strays_is_refused()
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
            'a name that runs on' => ['name' => 'A name that goes on and on well past the eighth word here about the harbormaster', 'line' => 'Off the harbormaster.'],
            'a line that runs on' => ['name' => "The harbormaster's whistle", 'line' => 'A sentence about the harbormaster that keeps going and going and going and going and going and going well past twenty words.'],
            'a different subject' => ['name' => 'A cutpurse trinket', 'line' => 'Lifted from somebody else entirely, in another place.'],
            'the machinery underneath' => ['name' => "The harbormaster's token", 'line' => 'Kept from the harbormaster after a lucky roll of the dice.'],
            'blank words' => ['name' => '', 'line' => ''],
        ] as $label => $proposal) {
            $turn = $this->pastTurn($campaign, 400 + Turn::where('campaign_id', $campaign->id)->count());

            $memento = Mementos::mint($turn, [
                ['trigger' => 'rival_settled', 'subject' => 'the harbormaster', 'place' => 'the long quay'],
            ]);
            $this->assertNotNull($memento);

            Mementos::reword($turn, $chapter, $proposal);

            $after = $memento->fresh();
            $this->assertSame($memento->name, $after->name, "the clamp let through: {$label}");
            $this->assertSame($memento->line, $after->line, "the clamp let through: {$label}");
            // The chapter stamp still lands — the citation is the engine's,
            // not something Claude has to earn.
            $this->assertSame($chapter->id, $after->chapter_id);
        }
    }

    /**
     * The whole feature rests on this: a memento is memory, never mechanics.
     * Nothing under app/Game — cards, odds, hands, dice, the resolver — may so
     * much as name the model, which is what makes "it can never grant
     * anything" a property of the code instead of a promise in a comment.
     *
     * A rumor lives by exactly the same rule and is checked by exactly the
     * same sweep: it is news about elsewhere, it grants nothing, and the
     * engine may only ever detect the MOMENT and hand the pick outward.
     *
     * An echo is the third of them, pointed at the past. It quotes a line out
     * of a finished book and instantiates nothing — so the resolver detects
     * the RHYME and hands the pick outward, and `EchoLine` never appears here.
     */
    public function test_the_shelf_and_the_hearsay_never_reach_the_engine()
    {
        $offenders = [];

        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(app_path('Game'), \FilesystemIterator::SKIP_DOTS),
        );

        foreach ($files as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $source = (string) file_get_contents($file->getPathname());

            // `Rumors` (the service the resolver calls) is fine; `Rumor` (the
            // model) is not — the word boundary is what tells them apart. The
            // same pairing holds for `Echoes` against `EchoLine`.
            foreach (['Memento', 'Rumor', 'EchoLine'] as $model) {
                if (preg_match("/\\b{$model}\\b/", $source)) {
                    $offenders[] = "{$file->getPathname()} ({$model})";
                }
            }
        }

        $this->assertSame([], $offenders,
            'app/Game reached for an inert model — mementos, rumors, and echoes must stay mechanically inert');
    }

    /**
     * The book's closing section: every keepsake, in chapter order, each
     * citing the chapter it came out of. An empty shelf gets no section at
     * all — empty-group-absent, same as the board.
     */
    public function test_the_book_lists_the_shelf_in_chapter_order_and_an_empty_shelf_gets_no_section()
    {
        $campaign = $this->createCampaign();
        $this->openBareTurn($campaign);

        $empty = app(BookCompiler::class)->compile($campaign);
        $this->assertSame([], $empty['mementos']);
        $this->assertStringNotContainsString(
            'What you carried home',
            view('book', ['book' => $empty])->render(),
        );

        $chapters = collect([1, 2, 3])->map(fn (int $n) => Chapter::create([
            'campaign_id' => $campaign->id,
            'turn_id' => null,
            'number' => $n,
            'kind' => 'chapter',
            'body' => "Chapter {$n} happened.",
        ]));

        // Written to the shelf out of order on purpose: the book reads by
        // chapter, not by whichever row happens to be newest.
        foreach ([[2, 'later'], [0, 'earlier']] as [$index, $which]) {
            $turn = $this->pastTurn($campaign, 500 + $index);
            $memento = Mementos::mint($turn, [
                ['trigger' => 'elite_beaten', 'subject' => "a {$which} enforcer", 'place' => 'the long quay'],
            ]);
            $memento->update(['chapter_id' => $chapters[$index]->id]);
        }

        $book = app(BookCompiler::class)->compile($campaign->fresh());

        $this->assertCount(2, $book['mementos']);
        $this->assertSame(1, $book['mementos'][0]['chapter']);
        $this->assertSame(3, $book['mementos'][1]['chapter']);
        $this->assertStringContainsString('earlier enforcer', $book['mementos'][0]['name'].$book['mementos'][0]['line']);

        $printed = view('book', ['book' => $book])->render();
        $this->assertStringContainsString('What you carried home', $printed);
        $this->assertStringContainsString('— chapter 1', $printed);

        // No mechanics language reaches the closing section.
        foreach (['dice', 'roll', 'card', 'meter', 'difficulty'] as $mechanic) {
            foreach ($book['mementos'] as $entry) {
                $this->assertStringNotContainsStringIgnoringCase($mechanic, "{$entry['name']} {$entry['line']}");
            }
        }
    }

    /** The shelf is on the page the tale is played from, and on the widget. */
    public function test_the_campaign_page_and_the_widget_show_the_shelf()
    {
        $campaign = $this->createCampaign();
        $turn = $this->openBareTurn($campaign);

        $this->actingAs($campaign->user)->get(route('play.show', $campaign))
            ->assertInertia(fn ($page) => $page->has('mementos', 0));

        Meters::damage($campaign->character->fresh(), 10);
        $this->play($turn);

        $memento = $this->shelved($campaign);
        $this->assertNotNull($memento);

        $this->actingAs($campaign->user)->get(route('play.show', $campaign))
            ->assertInertia(fn ($page) => $page
                ->has('mementos', 1)
                ->where('mementos.0.name', $memento->name)
                ->where('mementos.0.line', $memento->line));

        $token = $campaign->user->ensureWidgetToken();
        $this->getJson(route('api.widget.status', ['token' => $token]))
            ->assertOk()
            ->assertJsonPath('memento', $memento->name);
    }

    /**
     * Append-only is not undeletable. A shelf that refused to let go of its
     * turn and chapter rows would make a memento the one thing in the game
     * that can block a player from throwing a tale away.
     */
    public function test_deleting_a_tale_takes_its_shelf_with_it()
    {
        $campaign = $this->createCampaign();
        $turn = $this->openBareTurn($campaign);

        Meters::damage($campaign->character->fresh(), 10);
        $turn = $this->play($turn);
        app(Narrator::class)->narrate($turn->fresh());

        $this->assertSame(1, Memento::where('campaign_id', $campaign->id)->count());

        $this->actingAs($campaign->user)
            ->delete(route('campaigns.destroy', $campaign))
            ->assertRedirect(route('campaigns.index'));

        $this->assertSame(0, Memento::count());
    }

    /**
     * The coda is told what the tale is carrying home. A closing page that
     * does not know what is in their pockets is closing over somebody else.
     */
    public function test_the_coda_is_handed_the_shelf()
    {
        $campaign = $this->createCampaign();
        $turn = $this->openBareTurn($campaign);

        Meters::damage($campaign->character->fresh(), 10);
        $this->play($turn);

        $memento = $this->shelved($campaign);
        $this->assertNotNull($memento);

        app(BookCompiler::class)->close($campaign->fresh(), early: true, withCoda: true);

        $coda = collect($this->prompts)->first(fn (string $p) => str_contains($p, 'closing coda'));
        $this->assertNotNull($coda);
        $this->assertStringContainsString($memento->name, $coda);
    }
}
