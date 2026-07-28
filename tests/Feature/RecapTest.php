<?php

namespace Tests\Feature;

use App\Game\Engine\TurnResolver;
use App\Game\Meters;
use App\Models\Campaign;
use App\Models\Chapter;
use App\Models\Character;
use App\Models\Memento;
use App\Models\Turn;
use App\Models\User;
use App\Services\Claude\ClaudeCli;
use App\Services\Recap;
use App\Services\TurnStarter;
use Database\Seeders\WorldSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Previously On: the thread regained.
 *
 * The load-bearing claims these tests hold down: the panel is COMPILED (every
 * line is a string something already persisted, and no Claude call happens
 * anywhere on the path), it appears only after a real absence, it mutates
 * nothing, its selection is arithmetic rather than dice, and an empty section
 * is simply absent.
 */
class RecapTest extends TestCase
{
    use RefreshDatabase;

    private function createCampaign(): Campaign
    {
        $this->seed(WorldSeeder::class);

        // Offline on purpose: everything the panel reads is written by the
        // engine, so these tests must pass with the narrator unavailable.
        $this->mock(ClaudeCli::class, function ($mock) {
            $mock->shouldReceive('promptForJson')->andThrow(new \RuntimeException('offline'))->byDefault();
            $mock->shouldReceive('prompt')->andThrow(new \RuntimeException('offline'))->byDefault();
        });

        $campaign = Campaign::create([
            'user_id' => User::factory()->create()->id,
            'name' => 'The Long Quay',
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
        ]);

        return $campaign;
    }

    /**
     * One turn played through, so the tale has a resolved turn behind it and an
     * open one in front. The room is emptied first: these tests are about what
     * the panel repeats, not about who is swinging.
     *
     * @return array{0:Turn, 1:Turn}
     */
    private function playOneTurn(Campaign $campaign): array
    {
        $turn = app(TurnStarter::class)->openFirstTurn($campaign);
        $campaign->activeScene->actors()->delete();
        $turn = $turn->fresh();

        $card = collect($turn->cards['main'])->first(fn ($c) => $c['verb'] === 'wait')
            ?? collect($turn->cards['main'])->first();

        $turn->update([
            'status' => Turn::STATUS_LOCKED,
            'submission' => ['main' => ['card_id' => $card['id'], 'modifiers' => []]],
            'submitted_at' => now(),
        ]);

        app(TurnResolver::class)->resolve($turn->fresh());

        $turns = $campaign->turns()->orderBy('number')->get();

        return [$turns->first(), $turns->last()];
    }

    /** As though the last resolution happened this long ago. */
    private function away(Turn $resolved, int $hours): void
    {
        $resolved->forceFill(['resolved_at' => now()->subHours($hours)])->save();
    }

    private function recapFor(Campaign $campaign): ?array
    {
        return Recap::for(Campaign::findOrFail($campaign->id));
    }

    /** Every line of every section, flattened. */
    private function lines(?array $recap): array
    {
        return collect($recap['sections'] ?? [])->flatMap(fn (array $s) => $s['lines'])->all();
    }

    private function section(?array $recap, string $key): ?array
    {
        return collect($recap['sections'] ?? [])->firstWhere('key', $key);
    }

    // ------------------------------------------------------------ threshold

    public function test_it_stays_away_below_the_absence_threshold(): void
    {
        $campaign = $this->createCampaign();
        [$resolved] = $this->playOneTurn($campaign);

        $this->away($resolved, 11);

        $this->assertNull($this->recapFor($campaign));
    }

    public function test_it_appears_at_the_absence_threshold(): void
    {
        $campaign = $this->createCampaign();
        [$resolved] = $this->playOneTurn($campaign);

        $this->away($resolved, (int) config('game.recap.absence_hours'));

        $this->assertNotNull($this->recapFor($campaign));
    }

    public function test_the_threshold_is_configurable(): void
    {
        $campaign = $this->createCampaign();
        [$resolved] = $this->playOneTurn($campaign);

        $this->away($resolved, 3);
        $this->assertNull($this->recapFor($campaign));

        config(['game.recap.absence_hours' => 2]);
        $this->assertNotNull($this->recapFor($campaign));
    }

    public function test_it_is_gone_once_the_open_turn_is_submitted(): void
    {
        $campaign = $this->createCampaign();
        [$resolved, $open] = $this->playOneTurn($campaign);

        $this->away($resolved, 30);
        $this->assertNotNull($this->recapFor($campaign));

        // The player is here now. Whatever they missed, the chapter about to
        // be written is the thing that tells them.
        $open->forceFill(['status' => Turn::STATUS_LOCKED, 'submitted_at' => now()])->save();

        $this->assertNull($this->recapFor($campaign));
    }

    public function test_a_brand_new_campaign_gets_no_panel_on_its_first_turn(): void
    {
        $campaign = $this->createCampaign();

        $turn = app(TurnStarter::class)->openFirstTurn($campaign);

        // Even opened long ago: nothing has happened yet, and there is no
        // previously to be previously on.
        $turn->forceFill(['created_at' => now()->subDays(4)])->save();

        $this->assertNull($this->recapFor($campaign));
    }

    // -------------------------------------------------------------- sources

    public function test_every_line_traces_back_to_something_already_persisted(): void
    {
        $campaign = $this->createCampaign();
        [$resolved, $open] = $this->playOneTurn($campaign);

        $resolved->forceFill(['resolution' => array_merge($resolved->resolution, [
            'world' => ['Something came down the quay in the dark.'],
            'downtime' => 'They slept badly and woke stiff.',
            'rumor' => ['line' => 'Word going round is that the pier is watched.'],
        ])])->save();

        $open->forceFill(['situation_board' => [
            ['key' => 'threats', 'title' => 'Facing you', 'tone' => 'foe', 'items' => ['A dog']],
            ['key' => 'grudge', 'title' => 'An old score', 'tone' => 'foe', 'items' => ['Bellmark — you have met before.']],
            ['key' => 'endeavor', 'title' => 'What you are set on', 'tone' => 'neutral', 'items' => ['the search of the long quay — 2 of 5']],
        ]])->save();

        Chapter::create([
            'campaign_id' => $campaign->id,
            'turn_id' => $resolved->id,
            'number' => 1,
            'kind' => 'chapter',
            'intent_line' => 'She chose to take the rooftops.',
            'body' => 'A chapter body nobody quotes here.',
        ]);

        $this->away($resolved, 20);
        $recap = $this->recapFor($campaign);

        $this->assertNotNull($recap);

        // The tale's own standing: a count and the last chapter's own words.
        $this->assertSame([
            'The Long Quay — 1 chapter so far.',
            'Chapter 1 — She chose to take the rooftops.',
        ], $this->section($recap, 'standing')['lines']);

        // The wait, in the engine's own sentence, and what was heard.
        $this->assertSame([
            'They slept badly and woke stiff.',
            'Word going round is that the pier is watched.',
        ], $this->section($recap, 'away')['lines']);

        // The board's own lines, verbatim, and only the two that stand open.
        $this->assertSame([
            'the search of the long quay — 2 of 5',
            'Bellmark — you have met before.',
        ], $this->section($recap, 'open')['lines']);

        // And nothing in the middle section that the resolution did not say.
        $persisted = $this->persistedFactStrings($resolved);
        foreach ($this->section($recap, 'happened')['lines'] as $line) {
            $this->assertContains($line, $persisted,
                "The panel printed a line the resolution never wrote: {$line}");
        }

        // The chapter body is never quoted — the book is where a page belongs.
        $this->assertNotContains('A chapter body nobody quotes here.', $this->lines($recap));
    }

    public function test_a_published_chronicle_is_cited_and_never_quoted(): void
    {
        $campaign = $this->createCampaign();
        [$resolved] = $this->playOneTurn($campaign);
        $this->away($resolved, 20);

        Chapter::create([
            'campaign_id' => $campaign->id,
            'turn_id' => null,
            'number' => 1,
            'kind' => 'chronicle',
            'intent_line' => null,
            'body' => 'The northern mines collapsed overnight, and something new stirs below.',
        ]);

        $recap = $this->recapFor($campaign);
        $lines = $this->lines($recap);

        $this->assertContains(
            'The world was tended while you were gone — a new chronicle stands at chapter 1.',
            $lines,
        );
        $this->assertNotContains('The northern mines collapsed overnight, and something new stirs below.', $lines);

        // And the chronicle is the world's page, not the tale's: it never
        // becomes the "last chapter" line.
        $this->assertSame(['The Long Quay — 1 chapter so far.'], $this->section($recap, 'standing')['lines']);
    }

    public function test_a_chronicle_older_than_the_last_resolution_is_not_news(): void
    {
        $campaign = $this->createCampaign();
        [$resolved] = $this->playOneTurn($campaign);
        $this->away($resolved, 20);

        Chapter::create([
            'campaign_id' => $campaign->id,
            'turn_id' => null,
            'number' => 1,
            'kind' => 'chronicle',
            'intent_line' => null,
            'body' => 'Old news.',
        ])->forceFill(['created_at' => now()->subDays(3)])->save();

        $this->assertStringNotContainsString(
            'a new chronicle',
            implode(' ', $this->lines($this->recapFor($campaign))),
        );
    }

    public function test_the_keepsake_this_turn_left_behind_is_repeated_word_for_word(): void
    {
        $campaign = $this->createCampaign();
        [$resolved] = $this->playOneTurn($campaign);

        $resolved->forceFill(['resolution' => ['beats' => []]])->save();

        Memento::create([
            'campaign_id' => $campaign->id,
            'turn_id' => $resolved->id,
            'chapter_id' => null,
            'trigger' => 'first_ground',
            'subject' => 'the long quay',
            'name' => 'First ground of the long quay',
            'line' => 'Picked up at the pier head, the first of the long quay they ever walked.',
        ]);

        $this->away($resolved, 20);

        $this->assertContains(
            'Picked up at the pier head, the first of the long quay they ever walked.',
            $this->lines($this->recapFor($campaign)),
        );
    }

    // ------------------------------------------------------------- shaping

    public function test_empty_sections_are_skipped_entirely(): void
    {
        $campaign = $this->createCampaign();
        [$resolved, $open] = $this->playOneTurn($campaign);

        // A turn that did nothing worth saying, on ground with nothing open,
        // in a tale with no chapters written yet.
        $resolved->forceFill(['resolution' => ['beats' => [], 'downtime' => null, 'rumor' => null]])->save();
        $open->forceFill(['situation_board' => []])->save();

        $this->away($resolved, 20);

        // Nothing at all to say: no panel, rather than a panel of headings.
        $this->assertNull($this->recapFor($campaign));

        // One line back, and exactly one section returns with it.
        $resolved->forceFill(['resolution' => ['beats' => [], 'world' => ['A shutter banged somewhere below.']]])->save();

        $recap = $this->recapFor($campaign);
        $this->assertNotNull($recap);
        $this->assertSame(['happened'], collect($recap['sections'])->pluck('key')->all());
        $this->assertSame(['A shutter banged somewhere below.'], $recap['sections'][0]['lines']);
    }

    public function test_fact_selection_favours_kind_then_recency_and_is_deterministic(): void
    {
        $campaign = $this->createCampaign();
        [$resolved, $open] = $this->playOneTurn($campaign);
        $open->forceFill(['situation_board' => []])->save();

        $resolved->forceFill(['resolution' => [
            'beats' => [
                ['skipped' => false, 'facts' => ['The first beat landed.']],
                ['skipped' => true, 'facts' => ['This one never happened.']],
                ['skipped' => false, 'facts' => ['The last beat landed.']],
            ],
            'scene_reaction' => ['The scene answered back.'],
            'world' => ['The world moved on its own.'],
            'fall' => ['facts' => ['They went down at the pier head.']],
            'endeavor' => ['The work of the search was finished.'],
            'companions' => [
                'campfire' => ['companion' => 'Reeve', 'fact' => 'Reeve talked about the water.'],
                'loss' => ['Reeve did not come back out of it.'],
            ],
        ]])->save();

        $this->away($resolved, 20);

        // Four lines, and they are the four rarest kinds in their fixed order.
        $this->assertSame([
            'They went down at the pier head.',
            'The world moved on its own.',
            'The work of the search was finished.',
            'Reeve talked about the water.',
        ], $this->section($this->recapFor($campaign), 'happened')['lines']);

        // Given room, the rest arrive in the same fixed order — and the
        // player's own beats come last, most recent first, with the beats that
        // never happened left out of it.
        config(['game.recap.fact_lines' => 9]);

        $this->assertSame([
            'They went down at the pier head.',
            'The world moved on its own.',
            'The work of the search was finished.',
            'Reeve talked about the water.',
            'Reeve did not come back out of it.',
            'The scene answered back.',
            'The last beat landed.',
            'The first beat landed.',
        ], $this->section($this->recapFor($campaign), 'happened')['lines']);

        // Read twice, said twice the same. Nothing here rolls.
        config(['game.recap.fact_lines' => 4]);
        $first = $this->recapFor($campaign);
        for ($i = 0; $i < 5; $i++) {
            $this->assertSame($first, $this->recapFor($campaign));
        }
    }

    public function test_the_line_count_is_configurable(): void
    {
        $campaign = $this->createCampaign();
        [$resolved, $open] = $this->playOneTurn($campaign);
        $open->forceFill(['situation_board' => []])->save();

        $resolved->forceFill(['resolution' => ['beats' => [], 'world' => ['One.', 'Two.', 'Three.', 'Four.', 'Five.']]])->save();
        $this->away($resolved, 20);

        config(['game.recap.fact_lines' => 2]);
        $this->assertCount(2, $this->section($this->recapFor($campaign), 'happened')['lines']);

        config(['game.recap.fact_lines' => 5]);
        $this->assertCount(5, $this->section($this->recapFor($campaign), 'happened')['lines']);
    }

    // ------------------------------------------------------- read-only path

    public function test_building_the_panel_mutates_nothing(): void
    {
        $campaign = $this->createCampaign();
        [$resolved] = $this->playOneTurn($campaign);
        $this->away($resolved, 20);

        Chapter::create([
            'campaign_id' => $campaign->id,
            'turn_id' => $resolved->id,
            'number' => 1,
            'kind' => 'chapter',
            'intent_line' => 'She chose to take the rooftops.',
            'body' => 'Prose.',
        ]);

        $before = $this->worldSnapshot();

        $this->assertNotNull($this->recapFor($campaign));
        $this->assertNotNull($this->recapFor($campaign));

        $this->assertSame($before, $this->worldSnapshot());
    }

    public function test_no_claude_call_is_ever_made_on_this_path(): void
    {
        $campaign = $this->createCampaign();
        [$resolved] = $this->playOneTurn($campaign);
        $this->away($resolved, 20);

        // From here on, any word to Claude is a failure: the panel is a
        // compilation and there is nothing on it left to write.
        $this->mock(ClaudeCli::class, function ($mock) {
            $mock->shouldNotReceive('prompt');
            $mock->shouldNotReceive('promptForJson');
        });

        $this->assertNotNull($this->recapFor($campaign));

        $this->actingAs($campaign->user)
            ->get(route('play.show', $campaign))
            ->assertOk();
    }

    // ------------------------------------------------------------ the screen

    public function test_the_play_screen_carries_the_panel_only_after_a_real_absence(): void
    {
        $campaign = $this->createCampaign();
        [$resolved, $open] = $this->playOneTurn($campaign);

        $open->forceFill(['situation_board' => [
            ['key' => 'endeavor', 'title' => 'What you are set on', 'tone' => 'neutral', 'items' => ['the search of the long quay — 2 of 5']],
        ]])->save();

        $this->actingAs($campaign->user)
            ->get(route('play.show', $campaign))
            ->assertInertia(fn ($page) => $page->component('Play')->where('recap', null));

        $this->away($resolved, 20);

        $this->actingAs($campaign->user)
            ->get(route('play.show', $campaign))
            ->assertInertia(fn ($page) => $page->component('Play')
                ->where('recap.turn_id', $open->id)
                ->has('recap.sections'));
    }

    // ----------------------------------------------------------- test tools

    /**
     * Everything the stored resolution actually says, in words. Any line the
     * middle section prints has to be one of these.
     *
     * @return list<string>
     */
    private function persistedFactStrings(Turn $turn): array
    {
        $resolution = $turn->resolution ?? [];
        $strings = [];

        foreach ($resolution['beats'] ?? [] as $beat) {
            foreach ($beat['facts'] ?? [] as $fact) {
                $strings[] = $fact;
            }
        }

        foreach (['scene_reaction', 'world', 'endeavor'] as $key) {
            foreach ($resolution[$key] ?? [] as $fact) {
                $strings[] = $fact;
            }
        }

        foreach ($resolution['fall']['facts'] ?? [] as $fact) {
            $strings[] = $fact;
        }

        foreach ((array) ($resolution['companions'] ?? []) as $value) {
            foreach (is_array($value) ? $value : [$value] as $inner) {
                if (is_string($inner)) {
                    $strings[] = $inner;
                }
            }
        }

        foreach (Memento::where('turn_id', $turn->id)->pluck('line') as $line) {
            $strings[] = $line;
        }

        return array_values(array_filter(array_map('trim', $strings), fn ($s) => $s !== ''));
    }

    /** Every row the panel could conceivably touch, as it stands right now. */
    private function worldSnapshot(): array
    {
        return [
            'campaigns' => Campaign::orderBy('id')->get()->toArray(),
            'turns' => Turn::orderBy('id')->get()->toArray(),
            'chapters' => Chapter::orderBy('id')->get()->toArray(),
            'mementos' => Memento::orderBy('id')->get()->toArray(),
        ];
    }
}
