<?php

namespace Tests\Feature;

use App\Game\Engine\Dice;
use App\Game\Engine\Pressure;
use App\Game\Engine\TurnResolver;
use App\Game\Meters;
use App\Models\Actor;
use App\Models\Campaign;
use App\Models\Chapter;
use App\Models\Character;
use App\Models\Grudge;
use App\Models\Scene;
use App\Models\SceneFeature;
use App\Models\Turn;
use App\Models\User;
use App\Services\Claude\ClaudeCli;
use App\Services\TurnStarter;
use Database\Seeders\WorldSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Pressure: the world moves when you don't.
 *
 * Waiting used to be the null action — two waits in a row bought exactly two
 * paragraphs of nothing. The stall counter makes ceding the initiative mean
 * something, on a cadence the player can read before they commit: the board
 * says the stillness is wearing thin, the wait card says when one more wait
 * breaks it, and the beat itself routes through machinery that already existed.
 */
class PressureTest extends TestCase
{
    use RefreshDatabase;

    private function createCampaign(): Campaign
    {
        $this->seed(WorldSeeder::class);

        $this->mock(ClaudeCli::class, function ($mock) {
            $mock->shouldReceive('promptForJson')->andThrow(new \RuntimeException('offline'))->byDefault();
            $mock->shouldReceive('prompt')->andReturn('A tale begins.')->byDefault();
        });

        $campaign = Campaign::create([
            'user_id' => User::factory()->create()->id,
            'name' => 'Pressure Tale',
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

    /**
     * Open on ground with exactly one thing on it and nothing else in play:
     * no company, no spawn templates to draw from, nothing hidden, no old
     * scores. The only beat that can land here is the accident, so every
     * assertion about the CADENCE is about the cadence and not about a roll.
     */
    private function openOnBareGround(Campaign $campaign): Turn
    {
        $turn = app(TurnStarter::class)->openFirstTurn($campaign);

        $scene = $campaign->activeScene;
        $scene->actors()->delete();
        Actor::whereNull('scene_id')->delete();
        $scene->features()->delete();
        $this->ground($scene, 'a stack of empty crates');

        return $turn->fresh();
    }

    private function ground(Scene $scene, string $name, array $state = [], string $source = 'seed'): SceneFeature
    {
        return SceneFeature::create([
            'scene_id' => $scene->id,
            'zone_id' => $scene->zone_id,
            'name' => $name,
            'feature_type' => 'cover',
            'affordances' => ['hideable' => true],
            'state' => $state,
            'source' => $source,
        ]);
    }

    /** Submit the turn's card for this verb and resolve it. Returns the turn the resolver opened. */
    private function resolveMain(Turn $turn, string $verb): Turn
    {
        $card = collect($turn->cards['main'])->first(fn ($c) => $c['verb'] === $verb);
        $this->assertNotNull($card, "No {$verb} card was offered.");

        $turn->update([
            'status' => Turn::STATUS_LOCKED,
            'submission' => ['main' => ['card_id' => $card['id'], 'modifiers' => []]],
            'submitted_at' => now(),
        ]);

        return app(TurnResolver::class)->resolve($turn->fresh());
    }

    private function stall(Campaign $campaign): int
    {
        return Pressure::of($campaign->activeScene->fresh());
    }

    /** The board's pressure line, or null when the board is not carrying one. */
    private function pressureLine(Turn $turn): ?string
    {
        return collect($turn->situation_board)->firstWhere('key', 'pressure')['items'][0] ?? null;
    }

    public function test_two_waits_break_the_stillness_and_the_second_one_was_announced()
    {
        $campaign = $this->createCampaign();
        $first = $this->openOnBareGround($campaign);

        // One wait: the counter moves, the chapter gets an omen and nothing
        // else, and the board starts saying so.
        $second = $this->resolveMain($first, 'wait');
        $first->refresh();

        $this->assertSame(2, $this->stall($campaign));
        $this->assertSame([Pressure::omen(2)], $first->resolution['world']);
        $this->assertSame('The stillness here is wearing thin.', $this->pressureLine($second));

        // ...and the card the player is about to commit to says outright that
        // waiting again is what breaks it.
        $wait = collect($second->cards['main'])->firstWhere('verb', 'wait');
        $this->assertStringContainsString('moves without you', $wait['description']);

        // The second wait spends it: the accident is the only beat this bare
        // ground can offer, so the ground is what gives.
        $this->resolveMain($second, 'wait');
        $second->refresh();

        $this->assertSame(0, $this->stall($campaign));
        $this->assertNotNull($second->resolution['world']);
        $this->assertNotSame([Pressure::omen(2)], $second->resolution['world']);
        $this->assertTrue(
            $campaign->activeScene->features()->get()->every(fn (SceneFeature $f) => $f->state['destroyed'] ?? false),
            'The mishap beat did not reach the ground it was the only beat able to reach.',
        );
    }

    public function test_idle_poking_takes_three_turns_where_waiting_takes_two()
    {
        $campaign = $this->createCampaign();
        $turn = $this->openOnBareGround($campaign);

        foreach ([1, 2] as $expected) {
            $next = $this->resolveMain($turn, 'examine');
            $turn->refresh();

            $this->assertSame($expected, $this->stall($campaign));
            $this->assertSame([Pressure::omen($expected)], $turn->resolution['world']);

            $turn = $next;
        }

        $this->resolveMain($turn, 'examine');
        $turn->refresh();

        $this->assertSame(0, $this->stall($campaign));
        $this->assertNotNull($turn->resolution['world']);
        $this->assertNull(Pressure::omen(0));
    }

    public function test_a_turn_that_casts_a_die_puts_the_counter_back_to_nothing()
    {
        $campaign = $this->createCampaign();
        $turn = $this->openOnBareGround($campaign);

        $turn = $this->resolveMain($turn, 'wait');
        $this->assertSame(2, $this->stall($campaign));

        // Improvising rolls, however it lands — and a turn that asked the dice
        // for anything is not a turn spent holding still.
        $this->resolveMain($turn, 'improvise');
        $turn->refresh();

        $this->assertSame(0, $this->stall($campaign));
        $this->assertNull($turn->resolution['world']);

        // The same is true of moving, which the resolver reports separately and
        // which never reaches the counter as anything but "not quiet".
        $scene = $campaign->activeScene->fresh();
        Pressure::tick($scene, quiet: true, waited: true);
        $this->assertSame(2, Pressure::of($scene->fresh()));
        Pressure::tick($scene->fresh(), quiet: false, waited: false);
        $this->assertSame(0, $this->stall($campaign));
    }

    public function test_the_counter_never_moves_while_something_stands_in_the_open()
    {
        $campaign = $this->createCampaign();
        $turn = $this->openOnBareGround($campaign);
        $scene = $campaign->activeScene;

        Actor::create([
            'scene_id' => $scene->id,
            'zone_id' => $scene->zone_id,
            'name' => 'a dockside tough',
            'kind' => 'enemy',
            'tier' => 'regular',
            'stats' => ['health' => ['current' => 6, 'max' => 6], 'attack' => 1],
            'tags' => ['intent' => 'guard'],
            'status' => 'active',
            'source' => 'seed',
        ]);

        // Twice, which would have broken the stillness on empty ground. The
        // alarm clock owns escalation while a fight is on, and it owns it alone.
        foreach ([1, 2] as $ignored) {
            $next = $this->resolveMain($turn, 'wait');
            $turn->refresh();

            $this->assertSame(0, $this->stall($campaign));
            $this->assertNull($turn->resolution['world']);
            $this->assertNull($this->pressureLine($next));

            $turn = $next;
        }

        $this->assertGreaterThan(0, (int) $campaign->activeScene->fresh()->state['alarm']);
    }

    public function test_the_arrival_beat_walks_somebody_in_and_the_turn_reads_it_as_a_new_threat()
    {
        $campaign = $this->createCampaign();
        $turn = $this->openOnBareGround($campaign);
        $scene = $campaign->activeScene;

        config()->set('game.pressure.beats', [Pressure::ARRIVAL => 1]);

        Actor::create([
            'scene_id' => null,
            'zone_id' => $scene->zone_id,
            'name' => 'a harbor enforcer',
            'kind' => 'enemy',
            'tier' => 'regular',
            'stats' => ['health' => ['current' => 6, 'max' => 6], 'attack' => 1],
            'tags' => [],
            'status' => 'active',
            'source' => 'seed',
        ]);

        $turn = $this->resolveMain($turn, 'wait');
        $this->resolveMain($turn, 'wait');
        $turn->refresh();

        $arrived = $scene->actors()->where('name', 'a harbor enforcer')->first();
        $this->assertNotNull($arrived);
        $this->assertArrayNotHasKey('lurking', $arrived->tags ?? []);

        // The trigger ladder saw it, which is the whole reason the beat fires
        // where it does: a turn something walked into is not a quiet one.
        $this->assertSame('a harbor enforcer', $turn->resolution['new_threat']['name']);
        $this->assertSame('new_threat', $turn->branch_trigger);
        $this->assertStringContainsString('a harbor enforcer', $turn->resolution['world'][0]);
    }

    public function test_the_reveal_beat_is_the_engine_exposing_what_it_withheld()
    {
        $campaign = $this->createCampaign();
        $scene = $this->sceneFor($campaign);
        config()->set('game.pressure.beats', [Pressure::REVEAL => 1]);

        $kept = $this->ground($scene, "the smuggler's door", ['hidden' => true]);

        $beat = Pressure::fire($scene->fresh(), $campaign->character, $this->anyTurn($campaign), new Dice(11), fn () => null);

        $this->assertSame(Pressure::REVEAL, $beat['key']);
        $this->assertFalse($kept->fresh()->state['hidden']);
        $this->assertStringContainsString("the smuggler's door", $beat['facts'][0]);
    }

    public function test_a_lurker_the_reveal_stands_up_never_gets_the_ambush_drop()
    {
        $campaign = $this->createCampaign();
        $scene = $this->sceneFor($campaign);
        config()->set('game.pressure.beats', [Pressure::REVEAL => 1]);

        $lurker = Actor::create([
            'scene_id' => $scene->id,
            'zone_id' => $scene->zone_id,
            'name' => 'the patient one',
            'kind' => 'enemy',
            'tier' => 'regular',
            'stats' => ['health' => ['current' => 5, 'max' => 5], 'attack' => 1],
            'tags' => ['lurking' => true, 'lurking_since' => 1],
            'status' => 'active',
            'source' => 'seed',
        ]);

        $beat = Pressure::fire($scene->fresh(), $campaign->character, $this->anyTurn($campaign), new Dice(3), fn () => null);

        $this->assertSame(Pressure::REVEAL, $beat['key']);

        $tags = $lurker->fresh()->tags;
        $this->assertArrayNotHasKey('lurking', $tags);
        $this->assertArrayNotHasKey('ambush', $tags);
        $this->assertSame('press', $tags['intent']);
    }

    public function test_the_reveal_beat_never_fires_with_nothing_to_reveal()
    {
        $campaign = $this->createCampaign();
        $scene = $this->sceneFor($campaign);
        config()->set('game.pressure.beats', [Pressure::REVEAL => 1]);

        $this->ground($scene, 'a stack of empty crates');

        $this->assertNull(
            Pressure::fire($scene->fresh(), $campaign->character, $this->anyTurn($campaign), new Dice(5), fn () => null),
        );
    }

    public function test_the_grudge_beat_is_a_doorway_and_never_a_second_set_of_clamps()
    {
        $campaign = $this->createCampaign();
        $scene = $this->sceneFor($campaign);
        config()->set('game.pressure.beats', [Pressure::GRUDGE => 1]);

        $grudge = $this->simmering($campaign);

        // Inside the chapter floor: a score that only just left cannot be back
        // already, however long the player stands still.
        $this->assertNull(
            Pressure::fire($scene->fresh(), $campaign->character, $this->anyTurn($campaign), new Dice(4), fn () => null),
        );

        $this->chapters($campaign, 2);

        // One old score per scene: somebody already carrying a settled history
        // is standing here, so the door is shut.
        $standing = Actor::create([
            'scene_id' => $scene->id,
            'zone_id' => $scene->zone_id,
            'name' => 'someone else entirely',
            'kind' => 'enemy',
            'tier' => 'regular',
            'stats' => ['health' => ['current' => 4, 'max' => 4], 'attack' => 1],
            'tags' => ['grudge_id' => $grudge->id],
            'status' => 'active',
            'source' => 'grudge',
        ]);
        $this->assertNull(
            Pressure::fire($scene->fresh(), $campaign->character, $this->anyTurn($campaign), new Dice(4), fn () => null),
        );

        // Clear of both, the return is the return machinery's own — same spawn,
        // same history, same tags. The heat odds are still a roll and still
        // theirs, so the beat is offered a fixed run of seeds rather than told
        // which one to come up on; a beat that declined leaves nothing behind.
        $standing->delete();
        $beat = null;
        foreach (range(1, 20) as $seed) {
            $beat = Pressure::fire($scene->fresh(), $campaign->character, $this->anyTurn($campaign), new Dice($seed), fn () => null);
            if ($beat !== null) {
                break;
            }
        }

        $this->assertNotNull($beat, 'A score long past the floor never once came back.');
        $this->assertSame(Pressure::GRUDGE, $beat['key']);
        $this->assertSame('returning', $grudge->fresh()->status);
        $this->assertNotNull($scene->actors()->where('name', 'the one who ran')->first());
    }

    public function test_a_mishap_wrecks_the_ground_and_never_the_player_or_a_companion()
    {
        $campaign = $this->createCampaign();
        $scene = $this->sceneFor($campaign);
        config()->set('game.pressure.beats', [Pressure::MISHAP => 1]);

        $staged = $this->ground($scene, 'the shrine the player asked for', [], 'stage');

        $companion = Actor::create([
            'scene_id' => $scene->id,
            'zone_id' => $scene->zone_id,
            'name' => 'the one who stayed',
            'kind' => 'companion',
            'tier' => 'regular',
            'stats' => ['health' => ['current' => 4, 'max' => 4], 'attack' => 1],
            'tags' => ['bond' => 3],
            'status' => 'active',
            'source' => 'seed',
        ]);

        $character = $campaign->character;
        Meters::damage($character, 3);
        $health = $character->fresh()->meters['health']['current'];

        // Every seed the pool can hand it: the ground the player set is never
        // the thing that gives, and the people beside them are never the cost.
        foreach (range(1, 12) as $seed) {
            $rotten = $this->ground($scene, 'a rotten gantry');
            $beat = Pressure::fire($scene->fresh(), $character, $this->anyTurn($campaign), new Dice($seed), fn () => null);

            $this->assertSame(Pressure::MISHAP, $beat['key']);
            $this->assertTrue($rotten->fresh()->state['destroyed']);
            $this->assertFalse($staged->fresh()->state['destroyed'] ?? false);
        }

        $this->assertSame($health, $campaign->character->fresh()->meters['health']['current']);
        $this->assertSame('alive', $campaign->character->fresh()->status);
        $this->assertSame(4, $companion->fresh()->stats['health']['current']);
        $this->assertSame('active', $companion->fresh()->status);
    }

    public function test_a_mishap_may_catch_a_bystander_standing_near_it()
    {
        $campaign = $this->createCampaign();
        $scene = $this->sceneFor($campaign);
        config()->set('game.pressure.beats', [Pressure::MISHAP => 1]);

        $bystander = Actor::create([
            'scene_id' => $scene->id,
            'zone_id' => $scene->zone_id,
            'name' => 'a stray dockhand',
            'kind' => 'npc',
            'tier' => 'regular',
            'stats' => ['health' => ['current' => 1, 'max' => 5], 'attack' => 1],
            'tags' => [],
            'status' => 'active',
            'source' => 'seed',
        ]);

        $caught = false;
        foreach (range(1, 12) as $seed) {
            $this->ground($scene, 'a rotten gantry');
            Pressure::fire($scene->fresh(), $campaign->character, $this->anyTurn($campaign), new Dice($seed), fn () => null);

            if ($bystander->fresh()->status === 'downed') {
                $caught = true;
                break;
            }
        }

        $this->assertTrue($caught, 'The accident never once caught anybody standing in it.');
    }

    public function test_an_empty_pool_holds_the_counter_instead_of_inventing_something()
    {
        $campaign = $this->createCampaign();
        $turn = $this->openOnBareGround($campaign);

        // Bare in every direction the table can reach: nothing to break, nobody
        // to send, nothing kept back, no score outstanding.
        $campaign->activeScene->features()->delete();

        $turn = $this->resolveMain($turn, 'wait');
        $turn = $this->resolveMain($turn, 'wait');
        $this->assertSame(3, $this->stall($campaign));

        // Re-armed rather than spent, and the chapter was told nothing happened
        // — because nothing did.
        $turn->refresh();
        $this->assertSame(3, $this->stall($campaign));

        // ...and it fires the moment the world has something to spend again.
        $this->ground($campaign->activeScene, 'a rotten gantry');
        $this->resolveMain($turn, 'wait');
        $turn->refresh();

        $this->assertSame(0, $this->stall($campaign));
        $this->assertNotNull($turn->resolution['world']);
    }

    public function test_the_same_seed_reaches_for_the_same_beat()
    {
        $campaign = $this->createCampaign();
        $zoneId = $this->sceneFor($campaign)->zone_id;

        $keys = [];
        foreach (['first ground', 'second ground'] as $title) {
            $scene = Scene::create([
                'campaign_id' => $campaign->id,
                'zone_id' => $zoneId,
                'title' => $title,
                'description' => 'Identical ground, twice.',
                'status' => 'past',
                'state' => ['dressed' => true],
            ]);
            $this->ground($scene, 'a rotten gantry');
            $this->ground($scene, 'a boarded hatch', ['hidden' => true]);

            $keys[] = Pressure::fire($scene->fresh(), $campaign->character, $this->anyTurn($campaign), new Dice(9), fn () => null)['key'];
        }

        $this->assertSame($keys[0], $keys[1]);
    }

    public function test_the_narrator_is_handed_plain_facts_and_no_mechanics()
    {
        $campaign = $this->createCampaign();
        $turn = $this->openOnBareGround($campaign);

        $turn = $this->resolveMain($turn, 'wait');
        $this->resolveMain($turn, 'wait');

        foreach ($turn->fresh()->resolution['world'] as $fact) {
            $this->assertDoesNotMatchRegularExpression('/\d/', $fact);
            foreach (['stall', 'pressure', 'mishap', 'arrival', 'reveal', 'grudge', 'roll', 'card'] as $word) {
                $this->assertStringNotContainsStringIgnoringCase($word, $fact);
            }
        }
    }

    /** Ground with nothing on it, for the beats tested straight through Pressure::fire. */
    private function sceneFor(Campaign $campaign): Scene
    {
        app(TurnStarter::class)->openFirstTurn($campaign);

        $scene = $campaign->activeScene;
        $scene->actors()->delete();
        $scene->features()->delete();
        Actor::whereNull('scene_id')->delete();

        return $scene->fresh();
    }

    private function anyTurn(Campaign $campaign): Turn
    {
        return $campaign->turns()->orderByDesc('number')->firstOrFail();
    }

    private function chapters(Campaign $campaign, int $count): void
    {
        foreach (range(1, $count) as $number) {
            Chapter::create([
                'campaign_id' => $campaign->id,
                'number' => $number,
                'kind' => 'chapter',
                'body' => 'It happened.',
            ]);
        }
    }

    private function simmering(Campaign $campaign): Grudge
    {
        return Grudge::create([
            'campaign_id' => $campaign->id,
            'actor_name' => 'the one who ran',
            'stats' => ['health' => ['current' => 2, 'max' => 5], 'attack' => 2],
            'tags' => [],
            'tier' => 'regular',
            'history' => [],
            'heat' => 3,
            'disposition' => 'vengeful',
            'status' => 'simmering',
            'last_seen_chapter_id' => null,
        ]);
    }
}
