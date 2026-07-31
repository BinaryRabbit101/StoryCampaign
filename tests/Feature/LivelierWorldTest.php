<?php

namespace Tests\Feature;

use App\Game\BranchTrigger;
use App\Game\Engine\Dice;
use App\Game\Engine\SceneDresser;
use App\Game\Engine\SituationBoard;
use App\Game\Meters;
use App\Models\Actor;
use App\Models\Campaign;
use App\Models\Character;
use App\Models\Scene;
use App\Models\SceneFeature;
use App\Models\Turn;
use App\Models\User;
use App\Models\Zone;
use App\Services\Claude\ClaudeCli;
use App\Services\Claude\Narrator;
use App\Services\Claude\ZoneForge;
use App\Services\TurnStarter;
use Database\Seeders\WorldSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * The 2026-07-30 playthrough audit, world half.
 *
 * One tale ran nine turns and met nothing: a dressed scene arrived holding a
 * single prop, the character's five bought gifts produced no cards at all, the
 * forged zone had exactly one enemy template to draw on, and a premise about
 * banishing a demon had nothing anywhere in the engine pointing at a demon.
 * Meanwhile the narrator kept a wounded stranger walking across ground her
 * actor row never left.
 *
 * Everything here is about the world having something to say — and none of it
 * moves a number: the dresser decides which ground appears, the forge decides
 * what may spawn, and the narrator is told who is standing where.
 */
class LivelierWorldTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<string> */
    private array $prompts = [];

    private function campaign(array $attributes = []): Campaign
    {
        $this->seed(WorldSeeder::class);
        Notification::fake();

        $this->prompts = [];
        $this->mock(ClaudeCli::class, function ($mock) {
            $mock->shouldReceive('promptForJson')->andReturnUsing(function (string $prompt) {
                $this->prompts[] = $prompt;

                throw new \RuntimeException('offline');
            })->byDefault();
            $mock->shouldReceive('prompt')->andReturn('A tale begins.')->byDefault();
        });

        $campaign = Campaign::create(array_merge([
            'user_id' => User::factory()->create()->id,
            'name' => 'The Quiet World',
            'world_flavor' => 'harbor-city',
            'status' => 'active',
            'started_at' => now(),
        ], $attributes));

        Character::create([
            'campaign_id' => $campaign->id,
            'name' => 'The Stray',
            'description' => 'Nobody in particular.',
            'meters' => Meters::default(),
            'status' => 'alive',
            'meters_regenerated_at' => now(),
        ]);

        return $campaign->fresh();
    }

    /** A zone of this campaign's own, holding only the templates the test names. */
    private function zoneWith(Campaign $campaign, array $features): Zone
    {
        $zone = Zone::create([
            'campaign_id' => $campaign->id,
            'slug' => 'test-ground-c'.$campaign->id,
            'name' => 'Test Ground',
            'description' => 'Ground the test owns outright.',
            'tags' => ['locales' => []],
            'source' => 'forge',
        ]);

        foreach ($features as $name => $affordances) {
            SceneFeature::create([
                'scene_id' => null,
                'zone_id' => $zone->id,
                'name' => $name,
                'feature_type' => 'landmark',
                'affordances' => $affordances,
                'source' => 'forge',
            ]);
        }

        return $zone;
    }

    private function bareScene(Campaign $campaign, Zone $zone): Scene
    {
        return Scene::create([
            'campaign_id' => $campaign->id,
            'zone_id' => $zone->id,
            'title' => 'Somewhere In It',
            'description' => 'Ground.',
            'status' => 'active',
            'state' => ['dressed' => true],
        ]);
    }

    // ------------------------------------------------------------------
    // The dressing reads the sheet
    // ------------------------------------------------------------------

    public function test_a_dressed_scene_holds_something_the_sheet_can_act_on()
    {
        $campaign = $this->campaign();
        $campaign->character->capabilities()->create(['capability' => 'break', 'source' => 'creation']);

        $zone = $this->zoneWith($campaign, [
            'a stack of nets' => [],
            'a coil of rope' => [],
            'a painted sign' => [],
            'a rotten shutter' => ['breakable' => true],
            'a heap of sacking' => [],
        ]);

        // Every seed, not a lucky one: the bias is a rule, not a coin flip.
        foreach (range(1, 12) as $seed) {
            $scene = $this->bareScene($campaign, $zone);
            app(SceneDresser::class)->instantiateFeatures(
                $scene, new Dice($seed), 1, 2, $campaign->character->fresh(),
            );

            $drawn = $scene->features()->get();
            $this->assertGreaterThanOrEqual(2, $drawn->count(),
                "seed {$seed}: a room with one thing in it is a corridor with a prop");
            $this->assertTrue(
                $drawn->contains(fn (SceneFeature $f) => ($f->affordances['breakable'] ?? false) === true),
                "seed {$seed}: the zone had something this character's gift could touch and the draw missed it",
            );
        }
    }

    public function test_the_biased_draw_is_still_the_same_draw_every_time()
    {
        $campaign = $this->campaign();
        $campaign->character->capabilities()->create(['capability' => 'climb', 'source' => 'creation']);

        $zone = $this->zoneWith($campaign, [
            'a stack of nets' => [],
            'a coil of rope' => [],
            'a harbor wall' => ['reachable_via' => ['climb'], 'height' => 9],
            'a painted sign' => [],
        ]);

        $names = collect(range(1, 2))->map(function () use ($campaign, $zone) {
            $scene = $this->bareScene($campaign, $zone);
            app(SceneDresser::class)->instantiateFeatures(
                $scene, new Dice(4242), 1, 3, $campaign->character->fresh(),
            );

            return $scene->features()->pluck('name')->sort()->values()->all();
        });

        $this->assertSame($names[0], $names[1], 'the same seed must dress the same ground');
        $this->assertContains('a harbor wall', $names[0]);
    }

    public function test_a_zone_with_nothing_for_the_sheet_still_dresses_normally()
    {
        $campaign = $this->campaign();
        $campaign->character->capabilities()->create(['capability' => 'burrow', 'source' => 'creation']);

        $zone = $this->zoneWith($campaign, [
            'a stack of nets' => [],
            'a coil of rope' => [],
        ]);

        $scene = $this->bareScene($campaign, $zone);
        app(SceneDresser::class)->instantiateFeatures($scene, new Dice(9), 1, 2, $campaign->character);

        $this->assertSame(2, $scene->features()->count(), 'a gift with nothing to fit changes nothing');
    }

    // ------------------------------------------------------------------
    // The world has something to send
    // ------------------------------------------------------------------

    public function test_the_forge_tops_a_thin_roster_up_to_two_threats()
    {
        $campaign = $this->campaign();

        $this->mock(ClaudeCli::class, function ($mock) {
            $mock->shouldReceive('promptForJson')->andReturn([
                'name' => 'The Thin Reach',
                'description' => 'Ground with almost nobody on it.',
                'locales' => [['title' => 'A Spot', 'description' => 'Somewhere.']],
                'features' => [['name' => 'a low wall', 'feature_type' => 'obstacle', 'affordances' => ['breakable' => true]]],
                'actors' => [
                    ['name' => 'a lone cutpurse', 'kind' => 'enemy', 'tier' => 'regular',
                        'stats' => ['health' => ['current' => 4, 'max' => 4], 'attack' => 2], 'tags' => []],
                    ['name' => 'a net-mender', 'kind' => 'npc', 'tier' => 'regular',
                        'stats' => ['health' => ['current' => 4, 'max' => 4], 'attack' => 1], 'tags' => ['talkable' => true]],
                ],
            ]);
            $mock->shouldReceive('prompt')->andReturn('A tale begins.')->byDefault();
        });

        $zone = app(ZoneForge::class)->forge($campaign, leaving: null);

        $this->assertGreaterThanOrEqual(2, $zone->actors()->where('kind', 'enemy')->count(),
            'a region with one threat in it is a region nothing can ever happen in');
        $this->assertNotNull($zone->actors()->firstWhere('name', 'a lone cutpurse'),
            'the forge’s own threat is kept, never replaced');

        $names = $zone->actors()->pluck('name');
        $this->assertSame($names->count(), $names->unique()->count(),
            'a top-up must never stand the same figure in the room twice');
    }

    public function test_a_zone_with_no_threat_at_all_is_topped_up_with_two_different_ones()
    {
        $campaign = $this->campaign();

        $this->mock(ClaudeCli::class, function ($mock) {
            $mock->shouldReceive('promptForJson')->andReturn([
                'name' => 'The Empty Reach',
                'description' => 'Nobody dangerous anywhere.',
                'locales' => [['title' => 'A Spot', 'description' => 'Somewhere.']],
                'features' => [],
                'actors' => [
                    ['name' => 'a net-mender', 'kind' => 'npc', 'tier' => 'regular',
                        'stats' => ['health' => ['current' => 4, 'max' => 4], 'attack' => 1], 'tags' => []],
                ],
            ]);
            $mock->shouldReceive('prompt')->andReturn('A tale begins.')->byDefault();
        });

        $zone = app(ZoneForge::class)->forge($campaign, leaving: null);
        $threats = $zone->actors()->where('kind', 'enemy')->pluck('name');

        $this->assertCount(2, $threats);
        $this->assertSame(2, $threats->unique()->count());
    }

    public function test_a_premise_gets_a_body_in_the_opening_zone()
    {
        $campaign = $this->campaign(['premise' => 'Banish the demon that walks the lower decks.']);

        // Cold forge: Claude is offline, so this is the engine's own guarantee.
        $zone = app(ZoneForge::class)->forge($campaign, leaving: null);

        $anchor = $zone->actors()->get()
            ->first(fn (Actor $a) => ($a->tags['premise_anchor'] ?? false) === true);

        $this->assertNotNull($anchor, 'a premise with nothing in the world pointing at it is a wish');
        $this->assertContains($anchor->kind, ['enemy', 'creature'], 'the anchor has to be something that can stand in the way');
        $this->assertNull($anchor->scene_id, 'it is a spawn template, not a cast member');
    }

    public function test_a_tale_with_no_premise_gets_no_anchor()
    {
        $campaign = $this->campaign();
        $zone = app(ZoneForge::class)->forge($campaign, leaving: null);

        $this->assertFalse(
            $zone->actors()->get()->contains(fn (Actor $a) => ($a->tags['premise_anchor'] ?? false) === true),
            'nothing to anchor means nothing planted',
        );
    }

    public function test_the_frontier_gets_no_anchor_however_strong_the_premise()
    {
        $campaign = $this->campaign(['premise' => 'Banish the demon.']);
        $opening = app(ZoneForge::class)->forge($campaign, leaving: null);

        $frontier = app(ZoneForge::class)->forge($campaign->fresh(), leaving: $opening);

        $this->assertFalse(
            $frontier->actors()->get()->contains(fn (Actor $a) => ($a->tags['premise_anchor'] ?? false) === true),
            'the premise is embodied once, where the tale opens',
        );
    }

    // ------------------------------------------------------------------
    // The player's own words for the land
    // ------------------------------------------------------------------

    public function test_a_typed_setting_outranks_what_the_genre_finds_plausible()
    {
        $campaign = $this->campaign([
            'setting' => 'A forbidden section of a voyager spaceship',
            'genre' => 'modern-realistic',
        ]);

        app(ZoneForge::class)->forge($campaign, leaving: null);

        $prompt = $this->prompts[0] ?? '';
        $this->assertStringContainsString('A forbidden section of a voyager spaceship', $prompt);
        $this->assertStringContainsString('outranks the genre', $campaign->worldBrief());
        $this->assertStringContainsString('never swap it for a more ordinary cousin', $prompt,
            'the forge must be told the typed words beat the genre’s idea of realism');
    }

    // ------------------------------------------------------------------
    // Who is actually standing here
    // ------------------------------------------------------------------

    public function test_a_transition_chapter_is_told_who_stayed_behind()
    {
        $campaign = $this->campaign();
        $turn = app(TurnStarter::class)->openFirstTurn($campaign);
        $old = $campaign->fresh()->activeScene;
        $old->actors()->delete();

        Actor::create([
            'scene_id' => $old->id, 'zone_id' => $old->zone_id,
            'name' => 'Priya', 'kind' => 'npc', 'tier' => 'regular',
            'stats' => ['health' => ['current' => 3, 'max' => 4], 'attack' => 1],
            'tags' => ['talkable' => true], 'status' => 'active', 'source' => 'stage',
        ]);

        $turn->update([
            'status' => Turn::STATUS_COMPLETE,
            'branch_trigger' => BranchTrigger::SceneTransition->value,
            'resolution' => ['beats' => [], 'scene_reaction' => [], 'reaction_rolls' => [], 'new_threat' => null],
            'resolved_at' => now(),
        ]);

        // The ground the tale moved onto, with the next turn standing in it.
        $new = Scene::create([
            'campaign_id' => $campaign->id,
            'zone_id' => $old->zone_id,
            'title' => 'The Far Landing',
            'description' => 'Ground reached in motion.',
            'status' => 'active',
            'state' => ['dressed' => true],
            'from_scene_id' => $old->id,
        ]);
        $old->update(['status' => 'past']);

        Turn::create([
            'campaign_id' => $campaign->id,
            'scene_id' => $new->id,
            'number' => $turn->number + 1,
            'status' => Turn::STATUS_AWAITING,
            'situation' => 'New ground.',
            'cards' => ['pre' => [], 'main' => [], 'post' => []],
        ]);

        // The forge already spoke while the tale was opening; only the
        // narration prompt is under test here.
        $this->prompts = [];
        $this->mock(ClaudeCli::class, function ($mock) {
            $mock->shouldReceive('promptForJson')->andReturnUsing(function (string $prompt) {
                $this->prompts[] = $prompt;

                return ['intent_line' => null, 'chapter' => 'They walked on.', 'synopsis_line' => 'They moved.'];
            });
        });

        app(Narrator::class)->narrate($turn->fresh());

        $prompt = $this->prompts[0] ?? '';
        $this->assertStringContainsString('Stayed behind on the old ground and is NOT here: Priya', $prompt);
        $this->assertStringContainsString('Nobody crossed with them', $prompt);
        $this->assertStringContainsString('MEMORY, never a roster', $prompt,
            'the standing rule has to ride on every chapter, not only this one');
    }

    // ------------------------------------------------------------------
    // Arriving somewhere
    // ------------------------------------------------------------------

    public function test_arriving_somewhere_says_where()
    {
        $campaign = $this->campaign();
        app(TurnStarter::class)->openFirstTurn($campaign);
        $scene = $campaign->fresh()->activeScene;
        $scene->update(['title' => 'The Cargo Yard']);

        $board = SituationBoard::for($campaign->character, $scene->fresh(), BranchTrigger::SceneTransition);
        $moment = collect($board)->firstWhere('key', 'moment');

        $this->assertNotNull($moment);
        $this->assertStringNotContainsString('entered a new space', $moment['items'][0],
            'the placeholder said nothing about a moment that cost a whole turn');
        $this->assertStringContainsString('The Cargo Yard', $moment['items'][0]);

        // Same ground, same greeting, every time it is asked.
        $again = SituationBoard::for($campaign->character, $scene->fresh(), BranchTrigger::SceneTransition);
        $this->assertSame($moment['items'][0], collect($again)->firstWhere('key', 'moment')['items'][0]);
    }

    public function test_every_other_stop_keeps_its_plain_sentence()
    {
        $campaign = $this->campaign();
        app(TurnStarter::class)->openFirstTurn($campaign);
        $scene = $campaign->fresh()->activeScene;

        $board = SituationBoard::for($campaign->character, $scene, BranchTrigger::MeaningfulFork);

        $this->assertSame(
            BranchTrigger::MeaningfulFork->description(),
            collect($board)->firstWhere('key', 'moment')['items'][0],
        );
    }
}
