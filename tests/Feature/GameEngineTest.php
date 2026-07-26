<?php

namespace Tests\Feature;

use App\Game\Engine\CardComposer;
use App\Game\Engine\ChapterEntities;
use App\Game\Engine\ChapterEvents;
use App\Game\Engine\RollTable;
use App\Game\Engine\TurnResolver;
use App\Game\Meters;
use App\Game\StoryAspects;
use App\Game\WorldFlavor;
use App\Models\Actor;
use App\Models\Campaign;
use App\Models\Character;
use App\Models\Item;
use App\Models\Scene;
use App\Models\SceneFeature;
use App\Models\Turn;
use App\Models\User;
use App\Models\Zone;
use App\Services\Claude\ClaudeCli;
use App\Services\Claude\Narrator;
use App\Services\Claude\StageBuilder;
use App\Services\Claude\ZoneForge;
use App\Services\TurnStarter;
use Database\Seeders\WorldSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GameEngineTest extends TestCase
{
    use RefreshDatabase;

    private function createCatCampaign(): Campaign
    {
        $this->seed(WorldSeeder::class);

        // No live CLI in tests: the forge's Claude call fails fast, so every
        // campaign world is cold-forged from the campaign's own land.
        $this->mock(ClaudeCli::class, function ($mock) {
            $mock->shouldReceive('promptForJson')->andThrow(new \RuntimeException('offline'))->byDefault();
            $mock->shouldReceive('prompt')->andReturn('A tale begins.')->byDefault();
        });

        // The land is normally the engine's roll; tests pin it so the cold
        // forge builds a known zone and card assertions stay deterministic.
        $campaign = Campaign::create([
            'user_id' => User::factory()->create()->id,
            'name' => 'Test Tale',
            'world_flavor' => 'harbor-city',
            'status' => 'active',
            'started_at' => now(),
        ]);

        $meters = Meters::default();
        $meters['tempo']['time_slow'] = ['current' => 2, 'max' => 3];

        $character = Character::create([
            'campaign_id' => $campaign->id,
            'name' => 'The Cat',
            'description' => 'A 200 lb striking black cat with a 12-foot prehensile tail.',
            'meters' => $meters,
            'status' => 'alive',
            'meters_regenerated_at' => now(),
        ]);

        foreach ([
            ['capability' => 'intimidate', 'scope' => ['vs' => 'regular']],
            ['capability' => 'time_slow'],
            ['capability' => 'leap', 'magnitude' => 1],
            ['capability' => 'grapple'],
            ['capability' => 'swing'],
            ['capability' => 'restrain'],
            ['capability' => 'reach', 'magnitude' => 12],
            ['capability' => 'carry_extra', 'magnitude' => 1],
            ['capability' => 'squeeze', 'grade' => 'large'],
        ] as $cap) {
            $character->capabilities()->create($cap + ['source' => 'creation']);
        }

        return $campaign;
    }

    /** Copy a zone template feature into the scene, so tests control exactly what ground offers. */
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

    /** Spawn a zone actor template into the scene by name. */
    private function placeActor(Scene $scene, string $name): Actor
    {
        $template = Actor::whereNull('scene_id')->where('name', $name)->firstOrFail();

        return Actor::create([
            'scene_id' => $scene->id,
            'zone_id' => $scene->zone_id,
            'name' => $template->name,
            'kind' => $template->kind,
            'tier' => $template->tier,
            'stats' => $template->stats,
            'tags' => $template->tags,
            'status' => 'active',
            'source' => 'seed',
        ]);
    }

    /** Recompose the turn's cards after the test changed the scene. */
    private function refreshCards(Turn $turn): Turn
    {
        $turn->update(['cards' => app(CardComposer::class)->compose(
            $turn->campaign->character->fresh(), $turn->scene->fresh(),
        )]);

        return $turn->fresh();
    }

    public function test_options_emerge_from_capability_affordance_intersection()
    {
        $campaign = $this->createCatCampaign();
        $turn = app(TurnStarter::class)->openFirstTurn($campaign);
        $this->placeFeature($campaign->activeScene, 'the warehouse roof');
        $this->placeActor($campaign->activeScene, 'a dockside tough');
        $turn = $this->refreshCards($turn);

        $preVerbs = collect($turn->cards['pre'])->pluck('verb');
        $mainLabels = collect($turn->cards['main'])->pluck('label');

        // reach(12) satisfies the warehouse roof's height(11) via swing
        $this->assertTrue($preVerbs->contains('ascend'));
        // composition: restrain + swing + carry_extra => the haul card, never hand-authored
        $this->assertTrue($mainLabels->contains(fn ($l) => str_contains($l, 'Haul')));
        // generic fallbacks always present, and every stop offers >= 2 cards
        $this->assertTrue(collect($turn->cards['main'])->pluck('verb')->contains('improvise'));
        $this->assertGreaterThanOrEqual(2, count($turn->cards['main']));
    }

    public function test_graceful_degradation_offers_risky_cards_not_hidden_ones()
    {
        $campaign = $this->createCatCampaign();
        $turn = app(TurnStarter::class)->openFirstTurn($campaign);
        $this->placeFeature($campaign->activeScene, 'the narrow alley');
        $this->placeFeature($campaign->activeScene, 'the collapsed archway');
        $turn = $this->refreshCards($turn);

        // a large character squeezing into a medium alley: degraded, not absent
        $alley = collect($turn->cards['main'])->first(fn ($c) => str_contains($c['label'], 'narrow alley'));
        $this->assertNotNull($alley);
        $this->assertSame('degraded', $alley['risk']);

        // the large archway fits cleanly
        $arch = collect($turn->cards['main'])->first(fn ($c) => str_contains($c['label'], 'archway'));
        $this->assertSame('safe', $arch['risk']);
    }

    public function test_intimidate_scope_does_not_flatten_tougher_encounters()
    {
        $campaign = $this->createCatCampaign();
        $turn = app(TurnStarter::class)->openFirstTurn($campaign);
        $scene = $turn->scene;
        $this->placeActor($scene, 'a dockside tough');

        Actor::create([
            'scene_id' => $scene->id,
            'zone_id' => $scene->zone_id,
            'name' => 'the harbor warden',
            'kind' => 'enemy',
            'tier' => 'elite',
            'stats' => ['health' => ['current' => 10, 'max' => 10], 'attack' => 3],
            'tags' => ['intimidatable' => true, 'type' => 'elite'],
            'status' => 'active',
            'source' => 'seed',
        ]);

        $cards = app(CardComposer::class)->compose($campaign->character, $scene->fresh());

        $intimidateTargets = collect($cards['main'])
            ->filter(fn ($c) => $c['verb'] === 'intimidate')
            ->pluck('target.name');

        $this->assertFalse($intimidateTargets->contains('the harbor warden'));
        $this->assertTrue($intimidateTargets->isNotEmpty());
    }

    public function test_slot_chain_resolves_and_opens_next_turn_with_fresh_cards()
    {
        $campaign = $this->createCatCampaign();
        $turn = app(TurnStarter::class)->openFirstTurn($campaign);
        $this->placeFeature($campaign->activeScene, 'the warehouse roof');
        $this->placeActor($campaign->activeScene, 'a dockside tough');
        $turn = $this->refreshCards($turn);

        $pre = collect($turn->cards['pre'])->first(fn ($c) => $c['verb'] === 'ascend');
        $main = collect($turn->cards['main'])->first(fn ($c) => $c['verb'] === 'strike');
        $post = collect($turn->cards['post'])->first(fn ($c) => $c['verb'] === 'catch_breath');

        $turn->update([
            'status' => Turn::STATUS_LOCKED,
            'submission' => [
                'pre' => ['card_id' => $pre['id'], 'modifiers' => []],
                'main' => ['card_id' => $main['id'], 'modifiers' => ['approach' => 'balanced', 'method' => 'a pounce']],
                'post' => ['card_id' => $post['id'], 'modifiers' => []],
                'intent_text' => null,
            ],
            'submitted_at' => now(),
        ]);

        $next = app(TurnResolver::class)->resolve($turn->fresh());
        $turn->refresh();

        $this->assertSame(Turn::STATUS_COMPLETE, $turn->status);
        $this->assertNotNull($turn->branch_trigger);
        $this->assertNotEmpty($turn->resolution['beats']);

        // The attack form is narration color: it reaches the beat's facts
        // for the narrator, but never the difficulty or the damage math.
        $strikeBeat = collect($turn->resolution['beats'])->firstWhere('verb', 'strike');
        $this->assertContains('The attack came as a pounce.', $strikeBeat['facts']);
        $this->assertSame(2, $next->number);
        $this->assertSame(Turn::STATUS_AWAITING, $next->status);
        $this->assertGreaterThanOrEqual(2, count($next->cards['main']));
    }

    public function test_tampered_card_ids_are_never_resolved()
    {
        $campaign = $this->createCatCampaign();
        $turn = app(TurnStarter::class)->openFirstTurn($campaign);

        $turn->update([
            'status' => Turn::STATUS_LOCKED,
            'submission' => [
                'main' => ['card_id' => 'not-a-real-card', 'modifiers' => []],
            ],
            'submitted_at' => now(),
        ]);

        app(TurnResolver::class)->resolve($turn->fresh());
        $turn->refresh();

        $this->assertSame([], $turn->resolution['beats']);
        $this->assertSame(Turn::STATUS_COMPLETE, $turn->status);
    }

    public function test_submitting_resolves_the_turn_immediately()
    {
        $campaign = $this->createCatCampaign();
        $turn = app(TurnStarter::class)->openFirstTurn($campaign);
        $main = collect($turn->cards['main'])->first(fn ($c) => $c['verb'] === 'wait');

        $this->actingAs($campaign->user);
        $this->mock(Narrator::class)->shouldReceive('narrate');

        $this->post("/play/{$campaign->id}", ['main' => ['card_id' => $main['id']]])
            ->assertRedirect("/play/{$campaign->id}");

        // No waiting window: the turn is resolved by the time the redirect
        // lands, and the next one is already open.
        $this->assertSame(Turn::STATUS_COMPLETE, $turn->fresh()->status);
        $this->assertNotNull($turn->fresh()->resolved_at);
        $this->assertSame(Turn::STATUS_AWAITING, $campaign->fresh()->currentTurn->status);
    }

    /**
     * Resolution is inline, so the window in which a second submission could
     * arrive for the same turn is now milliseconds wide — but it is not zero,
     * and a turn caught mid-resolution must still refuse one.
     */
    public function test_a_turn_already_in_flight_refuses_a_second_submission()
    {
        $campaign = $this->createCatCampaign();
        $turn = app(TurnStarter::class)->openFirstTurn($campaign);
        $main = collect($turn->cards['main'])->first(fn ($c) => $c['verb'] === 'wait');

        $turn->update(['status' => Turn::STATUS_LOCKED, 'submitted_at' => now()]);

        $this->actingAs($campaign->user)
            ->post("/play/{$campaign->id}", ['main' => ['card_id' => $main['id']]])
            ->assertStatus(409);
    }

    public function test_only_offered_cards_are_accepted_by_the_form()
    {
        $campaign = $this->createCatCampaign();
        app(TurnStarter::class)->openFirstTurn($campaign);

        $this->actingAs($campaign->user);

        $this->from("/play/{$campaign->id}")
            ->post("/play/{$campaign->id}", ['main' => ['card_id' => 'forged-card-id']])
            ->assertRedirect("/play/{$campaign->id}")
            ->assertSessionHasErrors('main');
    }

    /**
     * The sweep is a recovery path now, not the main one. A turn that has
     * only just been locked belongs to the request still resolving it — the
     * sweep must not race in and resolve it a second time.
     */
    public function test_the_sweep_leaves_a_freshly_locked_turn_to_the_request_resolving_it()
    {
        $campaign = $this->createCatCampaign();
        $turn = app(TurnStarter::class)->openFirstTurn($campaign);
        $main = collect($turn->cards['main'])->first(fn ($c) => $c['verb'] === 'wait');
        $turn->update([
            'status' => Turn::STATUS_LOCKED,
            'submission' => ['main' => ['card_id' => $main['id'], 'modifiers' => []]],
            'submitted_at' => now(),
        ]);

        $this->assertFalse($turn->fresh()->isAbandoned());
        $this->artisan('game:resolve-due')->assertSuccessful();
        $this->assertSame(Turn::STATUS_LOCKED, $turn->fresh()->status);

        // Once the window has passed, the request that held it is gone and
        // the sweep takes over.
        $turn->update(['submitted_at' => now()->subMinutes(5)]);
        $this->assertTrue($turn->fresh()->isAbandoned());
        $this->artisan('game:resolve-due')->assertSuccessful();
        $this->assertSame(Turn::STATUS_COMPLETE, $turn->fresh()->status);
    }

    public function test_play_page_shows_the_latest_chapter_not_the_prologue()
    {
        $campaign = $this->createCatCampaign();
        app(TurnStarter::class)->openFirstTurn($campaign);

        // chapters() bakes in an ascending order; the play page must reorder,
        // or the reader is pinned to the prologue forever.
        $campaign->chapters()->create(['turn_id' => null, 'number' => 1, 'kind' => 'prologue', 'body' => 'She was born under a black moon.']);
        $campaign->chapters()->create(['turn_id' => null, 'number' => 2, 'kind' => 'chapter', 'body' => 'The docks answered in kind.']);

        $this->actingAs($campaign->user)
            ->get("/play/{$campaign->id}")
            ->assertInertia(fn ($page) => $page
                ->where('latestChapter.number', 2)
                ->where('latestChapter.kind', 'chapter'));
    }

    public function test_a_held_captive_opens_shield_hurl_and_drag_options()
    {
        $campaign = $this->createCatCampaign();
        app(TurnStarter::class)->openFirstTurn($campaign);
        $scene = $campaign->activeScene;
        $this->placeFeature($scene, 'the warehouse roof');

        $captive = $this->placeActor($scene, 'a dockside tough');
        $captive->update(['status' => 'restrained']);

        $cards = app(CardComposer::class)->compose($campaign->character, $scene->fresh());

        $preVerbs = collect($cards['pre'])->pluck('verb');
        $mainCards = collect($cards['main']);

        // The grip itself is an affordance: shield with the captive, spend
        // them as a weapon, or take them up with you (carry_extra + a way up).
        $this->assertTrue($preVerbs->contains('shield'));
        $this->assertTrue($mainCards->pluck('verb')->contains('hurl'));
        $this->assertTrue($preVerbs->contains('haul'));

        // Captive-leverage cards target a restrained actor and must stay
        // legal at resolution time (restrained is a live state, not removal).
        $shield = collect($cards['pre'])->first(fn ($c) => $c['verb'] === 'shield');
        $this->assertSame($captive->id, $shield['target']['id']);
    }

    public function test_chapter_events_derive_from_resolution_and_anchors_strip_from_prose()
    {
        $campaign = $this->createCatCampaign();
        $turn = app(TurnStarter::class)->openFirstTurn($campaign);
        $turn->update(['resolution' => [
            'beats' => [
                ['slot' => 'pre', 'verb' => 'ascend', 'target' => null, 'degree' => 'success', 'roll' => 14, 'total' => 16, 'difficulty' => 10, 'facts' => ['They gained the height.'], 'skipped' => false],
                ['slot' => 'main', 'verb' => 'strike', 'target' => null, 'degree' => 'strong', 'roll' => 18, 'total' => 20, 'difficulty' => 10, 'facts' => ['The blow felled the tough.'], 'skipped' => false],
            ],
            'scene_reaction' => ['A cutpurse answered and drew blood (2 damage).'],
            'new_threat' => null,
        ]]);

        $events = ChapterEvents::for($turn->fresh());

        $this->assertSame(['e1', 'e2', 'e3'], array_column($events, 'id'));
        $this->assertSame(['highground', 'attack', 'injury'], array_column($events, 'icon'));
        $this->assertSame('Wounded — 2 damage taken', $events[2]['label']);
        $this->assertSame(['roll' => 18, 'total' => 20, 'difficulty' => 10, 'crit' => null], $events[1]['roll']);

        // The anchors live only in the play page's edition; every other
        // consumer (book, push, prompts) reads the plain body.
        $chapter = $campaign->chapters()->create([
            'turn_id' => $turn->id, 'number' => 1, 'kind' => 'chapter',
            'body' => 'She climbed. [[e1]] She struck. [[e2]] Blood answered. [[e3]]',
        ]);
        $this->assertSame('She climbed. She struck. Blood answered.', $chapter->plainBody());
    }

    public function test_the_stage_is_stored_and_a_world_is_forged_for_the_tale()
    {
        $campaign = $this->createCatCampaign();

        $this->mock(ClaudeCli::class)->shouldReceive('prompt')->andReturn('She returned.');

        $this->actingAs($campaign->user)->post('/campaigns', [
            'name' => 'Harbor Tale',
            'character_id' => $campaign->character->id,
            'premise' => 'Find my sister, whatever it costs.',
            'tone' => 'rain-soaked',
        ])->assertRedirect();

        $second = $campaign->user->campaigns()->where('name', 'Harbor Tale')->first();

        $this->assertSame('Find my sister, whatever it costs.', $second->premise);
        $this->assertStringContainsString('Premise and goal', $second->stageBrief());

        // The tale opens in its own world: a campaign-scoped zone (cold-forged
        // here, since no live CLI), never shared ground.
        $zone = $second->activeScene->zone;
        $this->assertSame($second->id, $zone->campaign_id);
        $this->assertSame($zone->id, $second->starting_zone_id);
        $this->assertTrue($zone->features()->whereNull('scene_id')->exists());

        // And it stands on a land the engine rolled, never the same one the
        // player's previous tale drew.
        $this->assertTrue(WorldFlavor::has($second->world_flavor));
        $this->assertNotSame($campaign->world_flavor, $second->world_flavor);
        $this->assertSame(
            WorldFlavor::coldPlan($second->world_flavor)['name'],
            $zone->name,
        );
    }

    public function test_new_tales_are_set_in_different_lands_never_a_shared_harbor()
    {
        $campaign = $this->createCatCampaign();
        $this->mock(ClaudeCli::class)->shouldReceive('prompt')->andReturn('She returned.');

        // Ten tales started back to back: the engine rolls the land each
        // time, so the openings are not one setting wearing new names.
        $lands = collect(range(1, 10))->map(function (int $i) use ($campaign) {
            $this->actingAs($campaign->user)->post('/campaigns', [
                'name' => "Tale {$i}",
                'character_id' => $campaign->character->id,
            ])->assertRedirect();

            return $campaign->user->campaigns()->where('name', "Tale {$i}")->value('world_flavor');
        });

        $this->assertGreaterThan(3, $lands->unique()->count());

        // A roll never repeats any of the last three lands played, so a run
        // of new tales cannot settle into one country.
        $lands->sliding(4)->each(fn ($window) => $this->assertCount(4, $window->unique()));

        // And every opening zone is built from ITS OWN campaign's land —
        // nothing was cloned out of the shared seed world.
        $campaign->user->campaigns()->where('name', 'like', 'Tale %')->get()
            ->each(function (Campaign $tale) {
                $zone = $tale->zones()->firstOrFail();
                $this->assertSame(WorldFlavor::coldPlan($tale->world_flavor)['name'], $zone->name);
                $this->assertSame('cold', $zone->source);
            });
    }

    public function test_the_genre_narrows_the_land_pool_and_typed_words_are_kept()
    {
        $campaign = $this->createCatCampaign();
        $this->mock(ClaudeCli::class)->shouldReceive('prompt')->andReturn('She returned.');

        // A starfaring tale cannot open on chalk downs: the roll draws only
        // from lands that can honestly wear the genre.
        foreach (['space', 'western', 'cyberpunk', 'pirates', 'anime'] as $i => $genre) {
            $this->actingAs($campaign->user)->post('/campaigns', [
                'name' => "Genre {$i}",
                'character_id' => $campaign->character->id,
                'genre' => $genre,
                'drive' => 'escape',
                'tech_level' => 'starfaring',
            ])->assertRedirect();

            $tale = $campaign->user->campaigns()->where('name', "Genre {$i}")->firstOrFail();
            $this->assertContains($tale->world_flavor, WorldFlavor::keysForGenre($genre));
            $this->assertContains($genre, WorldFlavor::get($tale->world_flavor)['genres']);

            // All three axes reach the prompts, and none of them reach a rule.
            $this->assertStringContainsString(WorldFlavor::get($tale->world_flavor)['title'], $tale->worldBrief());
            $this->assertStringContainsString('Genre of this world:', $tale->worldBrief());
            $this->assertStringContainsString('Magic and machinery here:', $tale->worldBrief());
            $this->assertStringContainsString('What drives this tale:', $tale->stageBrief());
        }

        // Words the catalog has never seen are kept verbatim — the menu is not
        // a fence — and simply leave the land pool open.
        $this->actingAs($campaign->user)->post('/campaigns', [
            'name' => 'My Own Words',
            'character_id' => $campaign->character->id,
            'genre' => 'dream-logic bureaucracy',
            'drive' => 'file the correct form',
        ])->assertRedirect();

        $typed = $campaign->user->campaigns()->where('name', 'My Own Words')->firstOrFail();
        $this->assertSame('dream-logic bureaucracy', $typed->genre);
        $this->assertStringContainsString('dream-logic bureaucracy', $typed->worldBrief());
        $this->assertStringContainsString('file the correct form', $typed->stageBrief());
        $this->assertTrue(WorldFlavor::has($typed->world_flavor));
    }

    public function test_every_genre_has_lands_and_typed_genres_resolve_by_alias()
    {
        // A genre with no land is a campaign that cannot open in it.
        foreach (array_keys(StoryAspects::genres()) as $genre) {
            $lands = WorldFlavor::keysForGenre($genre);
            $this->assertGreaterThanOrEqual(3, count($lands), "{$genre} has too few lands");
            $this->assertNotSame(WorldFlavor::keys(), $lands, "{$genre} matched nothing and fell back to everything");
        }

        // Every genre a land claims must exist in the catalog.
        foreach (WorldFlavor::all() as $key => $flavor) {
            $this->assertNotEmpty($flavor['genres'], "{$key} wears no genre");
            foreach ($flavor['genres'] as $genre) {
                $this->assertArrayHasKey($genre, StoryAspects::genres(), "{$key} claims unknown genre {$genre}");
            }
        }

        // Typed words resolve to a catalog key when they plainly mean one.
        $this->assertSame('space', StoryAspects::resolve(StoryAspects::genres(), 'sci-fi with aliens'));
        $this->assertSame('western', StoryAspects::resolve(StoryAspects::genres(), 'a wild west story'));
        $this->assertNull(StoryAspects::resolve(StoryAspects::genres(), 'dream-logic bureaucracy'));
    }

    public function test_a_player_may_name_the_land_and_an_unknown_one_is_refused()
    {
        $campaign = $this->createCatCampaign();
        $this->mock(ClaudeCli::class)->shouldReceive('prompt')->andReturn('She returned.');

        // The roll is the default, not the rule: a player who knows where they
        // want to be says so, and the world is built there.
        $this->actingAs($campaign->user)->post('/campaigns', [
            'name' => 'The Fells',
            'character_id' => $campaign->character->id,
            'world_flavor' => 'winter-fells',
        ])->assertRedirect();

        $chosen = $campaign->user->campaigns()->where('name', 'The Fells')->firstOrFail();
        $this->assertSame('winter-fells', $chosen->world_flavor);
        $this->assertSame(
            WorldFlavor::coldPlan('winter-fells')['name'],
            $chosen->zones()->firstOrFail()->name,
        );

        // A land outside the catalog is refused, never silently invented.
        $this->actingAs($campaign->user)
            ->post('/campaigns', ['name' => 'Nowhere', 'world_flavor' => 'atlantis'])
            ->assertSessionHasErrors('world_flavor');
        $this->assertNull($campaign->user->campaigns()->where('name', 'Nowhere')->first());
    }

    public function test_companions_are_recruited_then_coordinated_never_controlled()
    {
        $campaign = $this->createCatCampaign();
        app(TurnStarter::class)->openFirstTurn($campaign);
        $scene = $campaign->activeScene;
        $scene->actors()->delete();
        $this->placeActor($scene, 'a dockside tough');
        $this->placeActor($scene, 'the lantern watchman');

        // The companionable watchman offers a recruit card while enemies
        // are present.
        $cards = app(CardComposer::class)->compose($campaign->character, $scene->fresh());
        $recruit = collect($cards['main'])->first(fn ($c) => $c['verb'] === 'recruit');
        $this->assertNotNull($recruit);
        $this->assertSame('the lantern watchman', $recruit['target']['name']);

        // Once a companion, coordination requests appear in the companion's
        // OWN slot — requests aimed at the companion, never direct control,
        // and never a claim on the player's pre/main/post chain.
        $watchman = $scene->actors()->where('name', 'the lantern watchman')->first();
        $watchman->update(['kind' => 'companion']);

        $cards = app(CardComposer::class)->compose($campaign->character, $scene->fresh());
        $entry = collect($cards['companions'])->firstWhere('name', 'the lantern watchman');
        $this->assertNotNull($entry);
        $verbs = collect($entry['cards'])->pluck('verb');
        $this->assertTrue($verbs->contains('companion_block'));
        $this->assertTrue($verbs->contains('companion_flank'));
        $this->assertTrue($verbs->contains('companion_strike'));
        $this->assertTrue($verbs->contains('companion_scout'));

        foreach (['pre', 'main', 'post'] as $slot) {
            $this->assertEmpty(
                collect($cards[$slot])->filter(fn ($c) => str_starts_with($c['verb'], 'companion_')),
                "companion requests must not occupy the player's {$slot} slot",
            );
        }

        // A scouted exit becomes a real, safe way out on the next compose.
        $scene->update(['state' => ['exit_scouted' => true]]);
        $exit = collect(app(CardComposer::class)->compose($campaign->character, $scene->fresh())['main'])
            ->first(fn ($c) => $c['label'] === 'Take the scouted way out');
        $this->assertNotNull($exit);
        $this->assertSame('flee', $exit['verb']);
    }

    public function test_a_companion_acts_from_their_own_slot_beside_the_players_chain()
    {
        $campaign = $this->createCatCampaign();
        app(TurnStarter::class)->openFirstTurn($campaign);
        $scene = $campaign->activeScene;
        $scene->actors()->delete();
        $this->placeActor($scene, 'a dockside tough');
        $watchman = $this->placeActor($scene, 'the lantern watchman');
        $watchman->update(['kind' => 'companion']);

        $turn = $campaign->currentTurn;
        $cards = app(CardComposer::class)->compose($campaign->character, $scene->fresh());
        $turn->update(['cards' => $cards]);

        $flank = collect($cards['companions'][0]['cards'])->firstWhere('verb', 'companion_flank');
        $main = collect($cards['main'])->first(fn ($c) => $c['verb'] === 'strike');

        $turn->update([
            'status' => Turn::STATUS_LOCKED,
            'submission' => [
                'main' => ['card_id' => $main['id'], 'modifiers' => ['approach' => 'balanced']],
                'companions' => [(string) $watchman->id => ['card_id' => $flank['id'], 'modifiers' => []]],
                'intent_text' => null,
            ],
            'submitted_at' => now(),
        ]);

        app(TurnResolver::class)->resolve($turn->fresh());
        $turn->refresh();

        // The companion's request resolves as its own beat, before the act
        // it supports — the player's main action still happened in full.
        $slots = collect($turn->resolution['beats'])->pluck('slot')->values()->all();
        $this->assertSame(['companion', 'main'], $slots);
        $this->assertSame('companion_flank', $turn->resolution['beats'][0]['verb']);

        // A forged companion card id is never resolved.
        $turn2 = $campaign->fresh()->currentTurn;
        $turn2->update([
            'status' => Turn::STATUS_LOCKED,
            'submission' => [
                'companions' => [(string) $watchman->id => ['card_id' => 'forged', 'modifiers' => []]],
            ],
            'submitted_at' => now(),
        ]);
        app(TurnResolver::class)->resolve($turn2->fresh());
        $this->assertSame([], $turn2->fresh()->resolution['beats']);
    }

    public function test_stage_built_openings_are_clamped_and_scene_scoped()
    {
        $campaign = $this->createCatCampaign();

        // Whatever the LLM proposes, the sanitizer holds the stage budget
        // and the same stat clamps the evolver lives under.
        $plan = app(StageBuilder::class)->sanitize([
            'scene_title' => 'The Gull-Bone Pier',
            'scene_description' => 'Where the search for her sister begins.',
            'features' => array_map(fn ($i) => ['name' => "prop {$i}", 'feature_type' => 'cover', 'affordances' => ['hideable' => true]], range(1, 6)),
            'actors' => [
                ['name' => 'a press-gang captain', 'kind' => 'enemy', 'tier' => 'boss', 'stats' => ['health' => ['current' => 40, 'max' => 40], 'attack' => 9], 'tags' => []],
                ['name' => 'a tide-worn ferryman', 'kind' => 'npc', 'tier' => 'regular', 'stats' => ['health' => ['current' => 5, 'max' => 5], 'attack' => 1], 'tags' => ['talkable' => true]],
                ['name' => 'a gull-eyed lookout', 'kind' => 'npc', 'tier' => 'regular', 'stats' => ['health' => ['current' => 4, 'max' => 4], 'attack' => 1], 'tags' => []],
                ['name' => 'one too many', 'kind' => 'npc', 'tier' => 'regular', 'stats' => [], 'tags' => []],
            ],
        ]);

        $this->assertCount(4, $plan['features']);
        $this->assertCount(3, $plan['actors']);
        $this->assertSame('regular', $plan['actors'][0]['tier']);
        $this->assertSame(12, $plan['actors'][0]['stats']['health']['max']);
        $this->assertSame(4, $plan['actors'][0]['stats']['attack']);

        $turn = app(TurnStarter::class)->openFirstTurn($campaign, $plan);
        $scene = $campaign->activeScene;

        // The opening is this campaign's own: stage content is bound to the
        // scene, never to the shared world, and no stock templates spawned.
        $this->assertSame('The Gull-Bone Pier', $scene->title);
        $this->assertSame(3, $scene->actors()->where('source', 'stage')->count());
        $this->assertSame(0, $scene->actors()->where('source', 'seed')->count());
        $this->assertSame(4, $scene->features()->where('source', 'stage')->count());
        // The zone still lends the stage some of its own ground (features
        // only, never actors — the cast is the campaign's own).
        $this->assertGreaterThanOrEqual(2, $scene->features()->where('source', '!=', 'stage')->count());
        $this->assertSame(0, Actor::whereNull('scene_id')->where('source', 'stage')->count());

        // The situation names the stage-built cast, and cards intersect with
        // the stage-built affordances (hideable × conceal is absent — the cat
        // has no conceal — but the fallbacks still guarantee two options).
        $this->assertStringContainsString('a press-gang captain', $turn->situation);
        $this->assertGreaterThanOrEqual(2, count($turn->cards['main']));
    }

    public function test_a_new_campaign_can_begin_with_a_returning_character()
    {
        $campaign = $this->createCatCampaign();
        $original = $campaign->character;
        $original->update(['meters' => array_replace_recursive($original->meters, ['health' => ['current' => 3]])]);

        $this->mock(ClaudeCli::class)
            ->shouldReceive('prompt')->andReturn('The Cat stepped into a new tale.');

        $this->actingAs($campaign->user)
            ->post('/campaigns', ['name' => 'Second Tale', 'character_id' => $original->id])
            ->assertRedirect();

        $second = $campaign->user->campaigns()->where('name', 'Second Tale')->first();
        $returned = $second->character;

        // Active immediately — no interview — with the sheet carried exactly
        // and the pools refilled; the world is opened and turn 1 is waiting.
        $this->assertSame('active', $second->status);
        $this->assertSame($original->name, $returned->name);
        $this->assertSame($original->capabilities()->count(), $returned->capabilities()->count());
        $this->assertSame($returned->meters['health']['max'], $returned->meters['health']['current']);
        $this->assertSame('prologue', $second->chapters()->first()->kind);
        $this->assertSame(1, $second->currentTurn->number);

        // A stranger's character can never be returned.
        $stranger = User::factory()->create();
        $this->actingAs($stranger)
            ->post('/campaigns', ['name' => 'Theft', 'character_id' => $original->id])
            ->assertNotFound();
    }

    public function test_a_campaign_can_be_deleted_with_its_whole_story()
    {
        $campaign = $this->createCatCampaign();
        $turn = app(TurnStarter::class)->openFirstTurn($campaign);
        $campaign->chapters()->create(['turn_id' => $turn->id, 'number' => 1, 'kind' => 'chapter', 'body' => 'It began.']);
        $scene = $campaign->activeScene;

        // A stranger cannot burn someone else's book.
        $this->actingAs(User::factory()->create())
            ->delete("/campaigns/{$campaign->id}")
            ->assertStatus(403);

        $this->actingAs($campaign->user)
            ->delete("/campaigns/{$campaign->id}")
            ->assertRedirect('/campaigns');

        // The whole story is gone: chapters, turns, scenes and their
        // scene-scoped actors, the forged world, the character, the
        // campaign itself.
        $this->assertDatabaseMissing('campaigns', ['id' => $campaign->id]);
        $this->assertDatabaseMissing('chapters', ['campaign_id' => $campaign->id]);
        $this->assertDatabaseMissing('turns', ['campaign_id' => $campaign->id]);
        $this->assertDatabaseMissing('scenes', ['campaign_id' => $campaign->id]);
        $this->assertDatabaseMissing('characters', ['campaign_id' => $campaign->id]);
        $this->assertDatabaseMissing('actors', ['scene_id' => $scene->id]);
        $this->assertDatabaseMissing('zones', ['campaign_id' => $campaign->id]);
        $this->assertDatabaseMissing('scene_features', ['zone_id' => $scene->zone_id]);

        // The shared world survives untouched.
        $this->assertDatabaseHas('zones', ['slug' => 'old-district']);
        $this->assertTrue(Actor::whereNull('scene_id')->whereHas('zone', fn ($q) => $q->whereNull('campaign_id'))->exists());
    }

    public function test_movement_opens_new_dressed_ground_not_a_copy_of_the_old()
    {
        $campaign = $this->createCatCampaign();
        app(TurnStarter::class)->openFirstTurn($campaign);
        $scene = $campaign->activeScene;
        $scene->features()->delete();
        $scene->actors()->delete();
        $this->placeFeature($scene, 'the collapsed archway');
        $before = $scene->id;

        // Flee until a non-failure lands (partial counts: the facts say they
        // got through, so the ground must actually change).
        for ($i = 0; $i < 8 && $campaign->fresh()->activeScene->id === $before; $i++) {
            $turn = $this->refreshCards($campaign->fresh()->currentTurn);
            $flee = collect($turn->cards['main'])->first(fn ($c) => $c['verb'] === 'flee');
            $this->assertNotNull($flee);
            $turn->update([
                'status' => Turn::STATUS_LOCKED,
                'submission' => ['main' => ['card_id' => $flee['id'], 'modifiers' => []]],
                'submitted_at' => now(),
            ]);
            app(TurnResolver::class)->resolve($turn->fresh());
        }

        $next = $campaign->fresh()->activeScene;
        $this->assertNotSame($before, $next->id);

        // The new ground is a named locale with its own dressed draw of the
        // zone's features — never 'Beyond X' with the same template overlay.
        $this->assertTrue((bool) ($next->state['dressed'] ?? false));
        $locales = collect($next->zone->tags['locales'])->pluck('title');
        $this->assertTrue($locales->contains($next->title));
        $this->assertGreaterThanOrEqual(3, $next->features()->count());
    }

    public function test_hidden_features_wait_for_discovery()
    {
        $campaign = $this->createCatCampaign();
        $turn = app(TurnStarter::class)->openFirstTurn($campaign);
        $scene = $campaign->activeScene;
        $scene->features()->delete();
        $scene->actors()->delete();
        $door = $this->placeFeature($scene, "the smuggler's door", ['hidden' => true]);
        $turn = $this->refreshCards($turn);

        // Hidden means hidden: no card, no situation mention.
        $this->assertFalse(collect($turn->cards['main'])->contains(fn ($c) => ($c['target']['id'] ?? null) === $door->id));

        // Examine has teeth: it finds what the scene hides.
        $examine = collect($turn->cards['main'])->first(fn ($c) => $c['verb'] === 'examine');
        $turn->update([
            'status' => Turn::STATUS_LOCKED,
            'submission' => ['main' => ['card_id' => $examine['id'], 'modifiers' => []]],
            'submitted_at' => now(),
        ]);
        $next = app(TurnResolver::class)->resolve($turn->fresh());

        $this->assertFalse((bool) ($door->fresh()->state['hidden'] ?? false));
        $facts = collect($turn->fresh()->resolution['beats'])->firstWhere('verb', 'examine')['facts'];
        $this->assertStringContainsString("the smuggler's door", implode(' ', $facts));
        $this->assertTrue(collect($next->cards['main'])->contains(fn ($c) => $c['verb'] === 'flee' && ($c['target']['name'] ?? '') === "the smuggler's door"));
    }

    public function test_enemy_telegraphs_offer_interrupt_and_brace()
    {
        $campaign = $this->createCatCampaign();
        app(TurnStarter::class)->openFirstTurn($campaign);
        $scene = $campaign->activeScene;
        $scene->actors()->delete();
        $tough = $this->placeActor($scene, 'a dockside tough');
        $tough->update(['tags' => $tough->tags + ['intent' => 'windup']]);

        $cards = app(CardComposer::class)->compose($campaign->character, $scene->fresh());

        $strike = collect($cards['main'])->first(fn ($c) => $c['verb'] === 'strike');
        $this->assertStringContainsString('mid-windup', $strike['description']);
        $this->assertTrue(collect($cards['main'])->pluck('verb')->contains('interrupt'));
        $this->assertTrue(collect($cards['pre'])->pluck('verb')->contains('brace'));

        // A guarded enemy telegraphs too, but offers no interrupt.
        $tough->update(['tags' => array_merge($tough->fresh()->tags, ['intent' => 'guard'])]);
        $cards = app(CardComposer::class)->compose($campaign->character, $scene->fresh());
        $strike = collect($cards['main'])->first(fn ($c) => $c['verb'] === 'strike');
        $this->assertStringContainsString('guarded', $strike['description']);
        $this->assertFalse(collect($cards['main'])->pluck('verb')->contains('interrupt'));
    }

    public function test_a_lurking_ambusher_is_invisible_then_springs()
    {
        $campaign = $this->createCatCampaign();
        $turn = app(TurnStarter::class)->openFirstTurn($campaign);
        $scene = $campaign->activeScene;
        $scene->actors()->delete();
        $lurker = $this->placeActor($scene, 'a wiry cutpurse');
        $lurker->update(['tags' => $lurker->tags + ['lurking' => true, 'lurking_since' => 0]]);
        $turn = $this->refreshCards($turn);

        // Invisible to cards and to the situation until it moves.
        $this->assertFalse(collect($turn->cards['main'])->contains(fn ($c) => ($c['target']['id'] ?? null) === $lurker->id));

        // A character with detect gets the one card that can beat the spring.
        $campaign->character->capabilities()->create(['capability' => 'detect', 'source' => 'creation']);
        $cards = app(CardComposer::class)->compose($campaign->character->fresh(), $scene->fresh());
        $this->assertTrue(collect($cards['pre'])->pluck('verb')->contains('detect'));

        // Left alone, the ambush springs during the next resolution and
        // announces itself as the mid-scene arrival.
        $wait = collect($turn->cards['main'])->first(fn ($c) => $c['verb'] === 'wait');
        $turn->update([
            'status' => Turn::STATUS_LOCKED,
            'submission' => ['main' => ['card_id' => $wait['id'], 'modifiers' => []]],
            'submitted_at' => now(),
        ]);
        app(TurnResolver::class)->resolve($turn->fresh());
        $turn->refresh();

        $this->assertFalse((bool) ($lurker->fresh()->tags['lurking'] ?? false));
        $this->assertSame('new_threat', $turn->branch_trigger);
        $this->assertSame('a wiry cutpurse', $turn->resolution['new_threat']['name']);
        $this->assertStringContainsString('burst from hiding', implode(' ', $turn->resolution['scene_reaction']));
    }

    public function test_camping_a_fight_raises_the_alarm_and_brings_reinforcements()
    {
        $campaign = $this->createCatCampaign();
        app(TurnStarter::class)->openFirstTurn($campaign);
        $scene = $campaign->activeScene;
        $scene->actors()->delete();
        $enemy = $this->placeActor($scene, 'a harbor enforcer');
        $before = $scene->actors()->count();

        for ($i = 0; $i < 3; $i++) {
            $turn = $campaign->fresh()->currentTurn;
            $wait = collect($turn->cards['main'])->first(fn ($c) => $c['verb'] === 'wait');
            $turn->update([
                'status' => Turn::STATUS_LOCKED,
                'submission' => ['main' => ['card_id' => $wait['id'], 'modifiers' => []]],
                'submitted_at' => now(),
            ]);
            app(TurnResolver::class)->resolve($turn->fresh());
        }

        // Three turns toe-to-toe: the district answered.
        $this->assertGreaterThan($before, $scene->fresh()->actors()->count());
    }

    public function test_track_turns_a_fled_enemy_into_a_pursuit()
    {
        $campaign = $this->createCatCampaign();
        $campaign->character->capabilities()->create(['capability' => 'track', 'source' => 'creation']);
        app(TurnStarter::class)->openFirstTurn($campaign);
        $scene = $campaign->activeScene;
        $scene->actors()->delete();
        $quarry = $this->placeActor($scene, "a smuggler's lookout");
        $quarry->update(['status' => 'fled']);
        $before = $scene->id;

        for ($i = 0; $i < 8 && $campaign->fresh()->activeScene->id === $before; $i++) {
            $turn = $this->refreshCards($campaign->fresh()->currentTurn);
            $track = collect($turn->cards['main'])->first(fn ($c) => $c['verb'] === 'track');
            $this->assertNotNull($track);
            $this->assertSame($quarry->id, $track['target']['id']);
            $turn->update([
                'status' => Turn::STATUS_LOCKED,
                'submission' => ['main' => ['card_id' => $track['id'], 'modifiers' => []]],
                'submitted_at' => now(),
            ]);
            app(TurnResolver::class)->resolve($turn->fresh());
        }

        // The trail led somewhere real, and the quarry stands there, cornered.
        $next = $campaign->fresh()->activeScene;
        $this->assertNotSame($before, $next->id);
        $quarry->refresh();
        $this->assertSame($next->id, $quarry->scene_id);
        $this->assertSame('active', $quarry->status);
        $this->assertTrue((bool) ($quarry->tags['cornered'] ?? false));
    }

    public function test_scout_and_command_cards_come_from_their_capabilities()
    {
        $campaign = $this->createCatCampaign();
        $campaign->character->capabilities()->create(['capability' => 'scout', 'source' => 'creation']);
        $campaign->character->capabilities()->create(['capability' => 'command', 'source' => 'creation']);
        app(TurnStarter::class)->openFirstTurn($campaign);
        $scene = $campaign->activeScene;
        $scene->actors()->delete();
        $ally = $this->placeActor($scene, 'the lantern watchman');
        $ally->update(['kind' => 'companion']);

        $cards = app(CardComposer::class)->compose($campaign->character->fresh(), $scene->fresh());
        $preVerbs = collect($cards['pre'])->pluck('verb');

        $this->assertTrue($preVerbs->contains('scout'));
        $this->assertTrue($preVerbs->contains('command'));
    }

    public function test_the_seed_world_offers_three_zones_with_locales_and_secrets()
    {
        $this->seed(WorldSeeder::class);

        $this->assertSame(3, Zone::count());
        Zone::all()->each(function (Zone $zone) {
            $this->assertNotEmpty($zone->tags['locales'] ?? [], "{$zone->name} has no locales");
            $this->assertTrue(
                $zone->features()->whereNull('scene_id')->get()
                    ->contains(fn ($f) => $f->affordances['hidden'] ?? false),
                "{$zone->name} has no hidden discovery content",
            );
            $this->assertGreaterThanOrEqual(6, $zone->features()->whereNull('scene_id')->count());
            $this->assertGreaterThanOrEqual(4, $zone->actors()->whereNull('scene_id')->count());
        });
    }

    public function test_the_zone_forge_clamps_whatever_the_llm_proposes()
    {
        $campaign = $this->createCatCampaign();

        $this->mock(ClaudeCli::class)->shouldReceive('promptForJson')->andReturn([
            'name' => 'The Gullet',
            'description' => 'A throat of wet stone swallowing the road.',
            'locales' => array_map(fn ($i) => ['title' => "Gullet {$i}", 'description' => 'A wet step.'], range(1, 9)),
            'features' => array_merge(
                [[
                    'name' => 'the bone ladder',
                    'feature_type' => 'building',
                    'affordances' => [
                        'reachable_via' => ['climb', 'fly', 'teleport'],
                        'height' => 900,
                        'lift_weight' => 9999,
                        'explode_via' => ['boom'],
                        'hidden' => true,
                    ],
                ]],
                array_map(fn ($i) => ['name' => "prop {$i}", 'feature_type' => 'cover', 'affordances' => ['hideable' => true]], range(1, 10)),
            ),
            'actors' => array_map(fn ($i) => [
                'name' => "brute {$i}",
                'kind' => 'enemy',
                'tier' => 'boss',
                'stats' => ['health' => ['current' => 40, 'max' => 40], 'attack' => 9],
                'tags' => [],
            ], range(1, 8)),
        ]);

        $zone = app(ZoneForge::class)->forge($campaign, null);

        // Budget: 6 locales, 8 features, 5 actors — no matter what came back.
        $this->assertSame($campaign->id, $zone->campaign_id);
        $this->assertCount(6, $zone->tags['locales']);
        $this->assertSame(8, $zone->features()->count());
        $this->assertSame(5, $zone->actors()->count());

        // The affordance grammar holds: unknown keys and unknown capability
        // names are dropped, magnitudes clamped, hidden survives.
        $ladder = $zone->features()->where('name', 'the bone ladder')->first();
        $this->assertSame(['climb'], $ladder->affordances['reachable_via']);
        $this->assertSame(30, $ladder->affordances['height']);
        $this->assertSame(400, $ladder->affordances['lift_weight']);
        $this->assertArrayNotHasKey('explode_via', $ladder->affordances);
        $this->assertTrue($ladder->affordances['hidden']);

        // Actor clamps: tier boss denied, stats bounded.
        $brute = $zone->actors()->first();
        $this->assertSame('regular', $brute->tier);
        $this->assertSame(12, $brute->stats['health']['max']);
        $this->assertSame(4, $brute->stats['attack']);
    }

    public function test_every_land_can_be_cold_forged_into_a_playable_zone()
    {
        // The cold forge is the offline path, so a gap in ANY land's kit is a
        // campaign that cannot open. Every one must build the full skeleton:
        // something to climb, cross, hide behind, flee into, break, and find.
        foreach (WorldFlavor::keys() as $key) {
            $plan = WorldFlavor::coldPlan($key);

            $this->assertNotSame('', $plan['name'], $key);
            $this->assertCount(6, $plan['features'], $key);
            $this->assertCount(4, $plan['actors'], $key);
            $this->assertCount(3, $plan['locales'], $key);
            $this->assertStringContainsString(WorldFlavor::get($key)['title'], WorldFlavor::brief($key));

            $affordances = collect($plan['features'])->pluck('affordances');
            foreach (['reachable_via', 'crossable_via', 'hideable', 'flee_destination', 'breakable', 'hidden'] as $affordance) {
                $this->assertTrue($affordances->contains(fn ($a) => isset($a[$affordance])), "{$key} lacks {$affordance}");
            }

            // A second zone in the same land is new ground, not a repeat.
            $next = WorldFlavor::coldPlan($key, [$plan['name']]);
            $this->assertNotSame($plan['name'], $next['name'], $key);
            $this->assertNotSame($plan['locales'], $next['locales'], $key);
        }
    }

    public function test_the_frontier_forges_the_next_zone_after_enough_ranging()
    {
        $campaign = $this->createCatCampaign();
        app(TurnStarter::class)->openFirstTurn($campaign);
        $scene = $campaign->activeScene;

        // One scene in: the world holds its hand.
        app(ZoneForge::class)->ensureFrontierZone($campaign->fresh());
        $this->assertNull($campaign->fresh()->next_zone_id);

        // Four scenes ranged: the next zone is pre-forged, campaign-scoped,
        // and distinct from the ground being left.
        foreach (range(1, 3) as $i) {
            Scene::create([
                'campaign_id' => $campaign->id, 'zone_id' => $scene->zone_id,
                'title' => "Past ground {$i}", 'description' => '…', 'status' => 'past', 'state' => ['dressed' => true],
            ]);
        }
        app(ZoneForge::class)->ensureFrontierZone($campaign->fresh());

        $campaign->refresh();
        $this->assertNotNull($campaign->next_zone_id);
        $this->assertSame($campaign->id, $campaign->nextZone->campaign_id);
        $this->assertNotSame($scene->zone->name, $campaign->nextZone->name);

        // Idempotent: a second pass forges nothing new.
        $zoneCount = Zone::count();
        app(ZoneForge::class)->ensureFrontierZone($campaign->fresh());
        $this->assertSame($zoneCount, Zone::count());
    }

    public function test_venturing_crosses_into_the_pre_forged_zone()
    {
        $campaign = $this->createCatCampaign();
        app(TurnStarter::class)->openFirstTurn($campaign);
        $scene = $campaign->activeScene;
        $scene->actors()->delete();

        $frontier = app(ZoneForge::class)->forge($campaign, $scene->zone);
        $campaign->update(['next_zone_id' => $frontier->id]);
        $before = $scene->id;

        for ($i = 0; $i < 8 && $campaign->fresh()->activeScene->id === $before; $i++) {
            $turn = $this->refreshCards($campaign->fresh()->currentTurn);
            $venture = collect($turn->cards['main'])->first(fn ($c) => $c['verb'] === 'venture');
            $this->assertNotNull($venture);
            $this->assertStringContainsString($frontier->name, $venture['label']);
            $turn->update([
                'status' => Turn::STATUS_LOCKED,
                'submission' => ['main' => ['card_id' => $venture['id'], 'modifiers' => []]],
                'submitted_at' => now(),
            ]);
            app(TurnResolver::class)->resolve($turn->fresh());
        }

        // The whole zone changed underfoot, the frontier slot is clear, and
        // the new ground was dressed from the new zone's own templates.
        $next = $campaign->fresh()->activeScene;
        $this->assertSame($frontier->id, $next->zone_id);
        $this->assertNull($campaign->fresh()->next_zone_id);
        $this->assertTrue((bool) ($next->state['dressed'] ?? false));
        $this->assertGreaterThanOrEqual(1, $next->features()->count());
    }

    public function test_interview_questions_carry_tappable_answer_suggestions()
    {
        $this->seed(WorldSeeder::class);
        $user = User::factory()->create();

        $this->mock(ClaudeCli::class, function ($mock) {
            $mock->shouldReceive('promptForJson')->andReturn([
                'reply' => 'Where does the gift come due?',
                'suggestions' => [
                    '  The tail tangles in tight spaces.  ',
                    str_repeat('x', 500),
                    42,
                    'Her frame cannot fit where others slip through.',
                    'The slowed world leaves her spent after.',
                    'A fifth suggestion that must be dropped.',
                ],
                'complete' => false,
                'character' => null,
                'prologue' => null,
            ])->byDefault();
        });

        $this->actingAs($user)->post('/campaigns', ['name' => 'Suggestion Tale'])->assertRedirect();
        $campaign = $user->campaigns()->first();

        // The opening question always offers starting points.
        $this->assertNotEmpty($campaign->interviewMessages()->first()->suggestions);

        $this->actingAs($user)->post("/campaigns/{$campaign->id}/interview", ['body' => 'A great black cat.']);

        // Sanitized: strings only, trimmed, ≤ 200 chars, capped at four.
        $reply = $campaign->interviewMessages()->orderByDesc('id')->first();
        $this->assertSame('narrator', $reply->role);
        $this->assertCount(4, $reply->suggestions);
        $this->assertSame('The tail tangles in tight spaces.', $reply->suggestions[0]);
        $this->assertSame(200, mb_strlen($reply->suggestions[1]));
        $this->assertNotContains('A fifth suggestion that must be dropped.', $reply->suggestions);

        // The page hands the chips to the client alongside the question.
        $this->actingAs($user)->get("/campaigns/{$campaign->id}/interview")
            ->assertInertia(fn ($page) => $page
                ->where('messages.2.suggestions.0', 'The tail tangles in tight spaces.'));
    }

    public function test_a_narrator_that_cannot_answer_leaves_the_players_words_in_their_hands()
    {
        $this->seed(WorldSeeder::class);
        $user = User::factory()->create();

        // The CLI is down (or answers twice-malformed): the interview must
        // not swallow the turn the way a stranded player message does.
        $this->mock(ClaudeCli::class, function ($mock) {
            $mock->shouldReceive('promptForJson')->andThrow(new \RuntimeException('offline'))->byDefault();
            $mock->shouldReceive('prompt')->andThrow(new \RuntimeException('offline'))->byDefault();
        });

        $this->actingAs($user)->post('/campaigns', ['name' => 'Broken Telling'])->assertRedirect();
        $campaign = $user->campaigns()->first();
        $before = $campaign->interviewMessages()->count();

        $this->actingAs($user)->from("/campaigns/{$campaign->id}/interview")
            ->post("/campaigns/{$campaign->id}/interview", ['body' => 'A huge black cat with a prehensile tail.'])
            ->assertRedirect("/campaigns/{$campaign->id}/interview")
            ->assertSessionHasErrors('body');

        // Nothing was written down — no half-exchange, no stranded message.
        $this->assertSame($before, $campaign->interviewMessages()->count());
        $this->assertSame('interview', $campaign->fresh()->status);
    }

    public function test_a_character_can_be_built_from_the_trait_catalog_but_never_overspent()
    {
        $this->seed(WorldSeeder::class);
        $user = User::factory()->create();
        $this->mock(ClaudeCli::class, function ($mock) {
            $mock->shouldReceive('promptForJson')->andThrow(new \RuntimeException('offline'))->byDefault();
        });

        $this->actingAs($user)->post('/campaigns', ['name' => 'Point Tale']);
        $campaign = $user->campaigns()->first();

        // Overspent: gifts alone cost 9 against an allowance of 3.
        $this->actingAs($user)->from("/campaigns/{$campaign->id}/interview")
            ->post("/campaigns/{$campaign->id}/interview/build", ['traits' => ['prehensile-grip', 'time-slow']])
            ->assertSessionHasErrors('traits');

        // Two frames at once: exclusive-group conflict, even though the
        // points would balance.
        $this->actingAs($user)->from("/campaigns/{$campaign->id}/interview")
            ->post("/campaigns/{$campaign->id}/interview/build", ['traits' => ['slight-frame', 'massive-frame']])
            ->assertSessionHasErrors('traits');

        // Burdens only: a character needs at least one gift.
        $this->actingAs($user)->from("/campaigns/{$campaign->id}/interview")
            ->post("/campaigns/{$campaign->id}/interview/build", ['traits' => ['frail']])
            ->assertSessionHasErrors('traits');

        $this->assertSame('interview', $campaign->fresh()->status);

        // A balanced bargain is born: cost 6, refund 4, allowance 3 → +1.
        $this->actingAs($user)->post("/campaigns/{$campaign->id}/interview/build", [
            'name' => 'Brindle',
            'traits' => ['prehensile-grip', 'grappler', 'frail', 'ponderous'],
        ])->assertRedirect("/play/{$campaign->id}");

        $campaign->refresh();
        $character = $campaign->character;
        $this->assertSame('active', $campaign->status);
        $this->assertSame('Brindle', $character->name);

        // The engine compiled the sheet: gifts became clamped capabilities,
        // burdens became real debits — health down, constraint recorded.
        $capabilities = $character->capabilities->keyBy('capability');
        $this->assertSame(12, $capabilities['reach']->magnitude);
        $this->assertTrue($capabilities->has('restrain'));
        $this->assertTrue($capabilities->has('grapple'));
        $this->assertSame(8, $character->meters['health']['max']);
        $this->assertTrue($character->constraints->pluck('name')->contains('ponderous'));

        // The tale opened for real: prologue written (stock — the forge is
        // cold here), turn 1 waiting, and the bargain in the transcript.
        $this->assertSame('prologue', $campaign->chapters()->first()->kind);
        $this->assertSame(1, $campaign->currentTurn->number);
        $this->assertTrue(
            $campaign->interviewMessages()->where('role', 'player')->get()
                ->contains(fn ($m) => str_contains($m->body, 'old patterns')),
        );

        // The interview page hands the priced catalog to the client.
        $this->actingAs($user)->post('/campaigns', ['name' => 'Second Point Tale']);
        $second = $user->campaigns()->where('name', 'Second Point Tale')->first();
        $this->actingAs($user)->get("/campaigns/{$second->id}/interview")
            ->assertInertia(fn ($page) => $page
                ->where('catalog.points', 3)
                ->has('catalog.positives')
                ->has('catalog.negatives'));

        // The override: the same overspent build walks in when the choice
        // is named — carrying the shortfall as a recorded debt (9 spent
        // against 3, owing 6). Group conflicts stay refused even overridden.
        $this->actingAs($user)->from("/campaigns/{$second->id}/interview")
            ->post("/campaigns/{$second->id}/interview/build", ['traits' => ['slight-frame', 'massive-frame'], 'override' => true])
            ->assertSessionHasErrors('traits');

        $this->actingAs($user)->post("/campaigns/{$second->id}/interview/build", [
            'traits' => ['prehensile-grip', 'time-slow'],
            'override' => true,
        ])->assertRedirect("/play/{$second->id}");

        $second->refresh();
        $debt = $second->character->constraints->firstWhere('name', 'debt_to_the_world');
        $this->assertSame('active', $second->status);
        $this->assertNotNull($debt);
        $this->assertSame(6, $debt->params['shortfall']);
    }

    /**
     * The bargain is visible while it is being struck, not weighed in secret
     * and announced at the end — and the narrator never decides the interview
     * is over. Speaking always continues the conversation; only Begin starts
     * the tale.
     */
    public function test_the_running_balance_is_visible_and_only_begin_starts_the_tale()
    {
        $this->seed(WorldSeeder::class);
        $user = User::factory()->create();

        // Every draft is all gift, no debt: cost 7 against an allowance of 3.
        $this->mock(ClaudeCli::class, function ($mock) {
            $mock->shouldReceive('promptForJson')->andReturn([
                'reply' => 'And what does it cost you?',
                'suggestions' => ['My size betrays me everywhere.'],
                'character' => [
                    'name' => 'The Unpaid',
                    'description' => 'All gift, no debt.',
                    'capabilities' => [
                        ['capability' => 'reach', 'magnitude' => 12],
                        ['capability' => 'intimidate', 'scope' => ['vs' => 'regular']],
                    ],
                    'constraints' => [],
                ],
                'prologue' => 'They arrived unpaid.',
            ])->byDefault();
        });

        $this->actingAs($user)->post('/campaigns', ['name' => 'Owing Tale']);
        $campaign = $user->campaigns()->first();

        // Nothing drafted yet: there is no character to step into the world.
        $this->actingAs($user)->post("/campaigns/{$campaign->id}/interview/begin")->assertStatus(409);

        $this->actingAs($user)
            ->post("/campaigns/{$campaign->id}/interview", ['body' => 'I am mighty.'])
            ->assertRedirect("/campaigns/{$campaign->id}/interview");

        // The narrator drafting a whole character does NOT start the tale.
        $campaign->refresh();
        $this->assertSame('interview', $campaign->status);
        $this->assertNotNull($campaign->pending_sheet);

        // The ledger reaches the page itemised: reach(12) 4 + intimidate 3
        // against an allowance of 3 leaves −4, and it is not begin-able.
        $this->actingAs($user)->get("/campaigns/{$campaign->id}/interview")
            ->assertInertia(fn ($page) => $page
                ->where('draft.balance', -4)
                ->where('draft.ready', false)
                ->where('draft.name', 'The Unpaid')
                ->has('draft.gifts', 2));

        // Begin without owning the shortfall is refused, in-world, and the
        // conversation carries on exactly where it was.
        $this->actingAs($user)->post("/campaigns/{$campaign->id}/interview/begin")
            ->assertRedirect("/campaigns/{$campaign->id}/interview");
        $this->assertSame('interview', $campaign->fresh()->status);
        $this->assertStringContainsString(
            'the scales still refuse',
            $campaign->interviewMessages()->orderByDesc('id')->first()->body,
        );

        // Owning it births the sheet, shortfall on the record.
        $this->actingAs($user)->post("/campaigns/{$campaign->id}/interview/begin", ['owing' => true])
            ->assertRedirect("/play/{$campaign->id}");

        $campaign->refresh();
        $this->assertSame('active', $campaign->status);
        $this->assertNull($campaign->pending_sheet);
        $this->assertSame(12, $campaign->character->capabilities->firstWhere('capability', 'reach')->magnitude);
        $this->assertSame(4, $campaign->character->constraints->firstWhere('name', 'debt_to_the_world')->params['shortfall']);
    }

    /**
     * The reported bug: the interview finished, the story started, and the
     * page sat there. Anyone landing back on the interview of a tale that has
     * already begun goes straight to it.
     */
    public function test_the_interview_of_a_started_tale_leads_to_the_story()
    {
        $campaign = $this->createCatCampaign();
        app(TurnStarter::class)->openFirstTurn($campaign);

        $this->actingAs($campaign->user)
            ->get("/campaigns/{$campaign->id}/interview")
            ->assertRedirect("/play/{$campaign->id}");

        // And the watchdog's own endpoint says so without an Inertia visit —
        // it is polled while a request is still in flight, so it must never
        // be one itself.
        $this->actingAs($campaign->user)
            ->getJson("/campaigns/{$campaign->id}/interview/status")
            ->assertOk()
            ->assertJsonPath('status', 'active')
            ->assertJsonPath('play_url', route('play.show', $campaign));
    }

    public function test_the_interview_sheet_pays_the_same_coin_as_the_point_buy()
    {
        $this->seed(WorldSeeder::class);
        $user = User::factory()->create();

        $sheet = fn (array $constraints) => [
            'reply' => 'It is done.',
            'suggestions' => [],
            'complete' => true,
            'character' => [
                'name' => 'The Weighed',
                'description' => 'A long-limbed presence.',
                'capabilities' => [
                    ['capability' => 'reach', 'magnitude' => 12],
                    ['capability' => 'intimidate', 'scope' => ['vs' => 'regular']],
                ],
                'constraints' => $constraints,
            ],
            'prologue' => 'They arrived.',
        ];

        // First completion attempt names no price (cost 7 vs allowance 3);
        // the second carries constraints worth 4 and breaks even.
        $this->mock(ClaudeCli::class, function ($mock) use ($sheet) {
            $mock->shouldReceive('promptForJson')->andReturn(
                $sheet([]),
                $sheet([
                    ['name' => 'ponderous', 'params' => ['pace' => 'slow']],
                    ['name' => 'stealth_penalty', 'params' => ['reason' => 'unmistakable']],
                ]),
            )->byDefault();
        });

        $this->actingAs($user)->post('/campaigns', ['name' => 'Weighed Tale']);
        $campaign = $user->campaigns()->first();

        // All gift, no debt: cost 7 against an allowance of 3. The tale does
        // not open, and the player can see exactly how short they are.
        $this->actingAs($user)->post("/campaigns/{$campaign->id}/interview", ['body' => 'I am mighty.']);
        $this->assertSame('interview', $campaign->fresh()->status);
        $this->actingAs($user)->get("/campaigns/{$campaign->id}/interview")
            ->assertInertia(fn ($page) => $page->where('draft.balance', -4)->where('draft.ready', false));

        // A price named: ponderous and stealth_penalty pay back 2 each, and
        // the same gifts now break even. Still nothing has begun — the sheet
        // is merely begin-able, which is the whole point.
        $this->actingAs($user)->post("/campaigns/{$campaign->id}/interview", ['body' => 'I am slow, and remembered everywhere.']);
        $this->assertSame('interview', $campaign->fresh()->status);
        $this->actingAs($user)->get("/campaigns/{$campaign->id}/interview")
            ->assertInertia(fn ($page) => $page->where('draft.balance', 0)->where('draft.ready', true));

        // The player decides.
        $this->actingAs($user)->post("/campaigns/{$campaign->id}/interview/begin")
            ->assertRedirect("/play/{$campaign->id}");

        $campaign->refresh();
        $this->assertSame('active', $campaign->status);
        $this->assertSame(12, $campaign->character->capabilities->firstWhere('capability', 'reach')->magnitude);
        $this->assertTrue($campaign->character->constraints->pluck('name')->contains('ponderous'));
        // Breaking even means no debt walks in with them.
        $this->assertFalse($campaign->character->constraints->pluck('name')->contains('debt_to_the_world'));
    }

    /**
     * The staleness bug: a scene whose affordances miss the character's gifts
     * used to produce no cards at all, so three turns running showed the same
     * three fallbacks. Everything visible must be actionable regardless.
     */
    public function test_a_feature_no_capability_fits_is_still_something_to_act_on()
    {
        $campaign = $this->createCatCampaign();
        $turn = app(TurnStarter::class)->openFirstTurn($campaign);
        // lift_weight(180) with no lift capability: the old composer had
        // nothing to say about the harbor chain at all.
        $chain = $this->placeFeature($campaign->activeScene, 'the harbor chain');
        $turn = $this->refreshCards($turn);

        $capabilityCards = collect($turn->cards['main'])
            ->filter(fn ($c) => ($c['target']['id'] ?? null) === $chain->id && $c['capability'] !== null);
        $this->assertTrue($capabilityCards->isEmpty(), 'no capability of the Cat fits this feature');

        $inspect = collect($turn->cards['pre'])
            ->first(fn ($c) => $c['verb'] === 'inspect' && $c['target']['id'] === $chain->id);
        $this->assertNotNull($inspect);

        $improvise = collect($turn->cards['main'])
            ->first(fn ($c) => $c['verb'] === 'improvise' && ($c['target']['id'] ?? null) === $chain->id);
        $this->assertNotNull($improvise);
        $this->assertSame('risky', $improvise['risk']);

        // The untargeted escape hatch survives beside the grounded ones.
        $this->assertTrue(collect($turn->cards['main'])
            ->contains(fn ($c) => $c['verb'] === 'improvise' && $c['target'] === null));
    }

    public function test_inspecting_a_feature_reads_it_in_plain_language()
    {
        $campaign = $this->createCatCampaign();
        $turn = app(TurnStarter::class)->openFirstTurn($campaign);
        $roof = $this->placeFeature($campaign->activeScene, 'the warehouse roof');
        $turn = $this->refreshCards($turn);

        $inspect = collect($turn->cards['pre'])
            ->first(fn ($c) => $c['verb'] === 'inspect' && $c['target']['id'] === $roof->id);
        $wait = collect($turn->cards['main'])->firstWhere('verb', 'wait');

        $turn->update([
            'status' => Turn::STATUS_LOCKED,
            'submission' => [
                'pre' => ['card_id' => $inspect['id'], 'modifiers' => []],
                'main' => ['card_id' => $wait['id'], 'modifiers' => []],
            ],
            'submitted_at' => now(),
        ]);

        app(TurnResolver::class)->resolve($turn->fresh());

        $beat = collect($turn->fresh()->resolution['beats'])->firstWhere('verb', 'inspect');
        $reading = implode(' ', $beat['facts']);

        $this->assertStringContainsString('the warehouse roof', $reading);
        $this->assertStringContainsString('climbed', $reading);
        // The engine's magnitudes stay backstage: these facts reach the
        // narrator, so height(11) must arrive as sight, not as a number.
        $this->assertStringNotContainsString('11', $reading);
    }

    public function test_anyone_not_being_fought_can_be_spoken_to_without_a_social_gift()
    {
        $campaign = $this->createCatCampaign();
        $turn = app(TurnStarter::class)->openFirstTurn($campaign);
        $dockhand = $this->placeActor($campaign->activeScene, 'a stray dockhand');
        $turn = $this->refreshCards($turn);

        $verbs = collect($turn->cards['main'])
            ->filter(fn ($c) => ($c['target']['id'] ?? null) === $dockhand->id)
            ->pluck('verb');

        // The Cat has no persuade/deceive/calm, so the trained verbs are absent…
        $this->assertEmpty($verbs->intersect(['persuade', 'deceive', 'calm']));
        // …but plain conversation is not a gift.
        $this->assertTrue($verbs->contains('speak'));
    }

    public function test_a_per_beat_note_reaches_the_narrator_and_never_the_dice()
    {
        $campaign = $this->createCatCampaign();
        $turn = app(TurnStarter::class)->openFirstTurn($campaign);
        $this->placeActor($campaign->activeScene, 'a dockside tough');
        $turn = $this->refreshCards($turn);

        $strike = collect($turn->cards['main'])->firstWhere('verb', 'strike');

        $turn->update([
            'status' => Turn::STATUS_LOCKED,
            'submission' => [
                'main' => [
                    'card_id' => $strike['id'],
                    'modifiers' => ['approach' => 'balanced', 'method' => 'unspecified'],
                    'note' => 'I go low and quiet, and I do not say a word.',
                ],
            ],
            'submitted_at' => now(),
        ]);

        app(TurnResolver::class)->resolve($turn->fresh());
        $turn->refresh();

        $beat = collect($turn->resolution['beats'])->firstWhere('verb', 'strike');

        // risky(+3) on a balanced approach against an untelegraphed enemy:
        // exactly 13, whatever the player wrote beside it.
        $this->assertSame(13, $beat['difficulty']);
        $this->assertSame('I go low and quiet, and I do not say a word.', $beat['note']);
        $this->assertNotContains($beat['note'], $beat['facts']);

        $line = ChapterEvents::promptLine(collect(ChapterEvents::for($turn))->firstWhere('verb', 'strike'));
        $this->assertStringContainsString('I go low and quiet', $line);
        $this->assertStringContainsString('cannot change the outcome', $line);
    }

    public function test_chapter_entities_name_what_is_seen_and_omit_what_is_hidden()
    {
        $campaign = $this->createCatCampaign();
        $turn = app(TurnStarter::class)->openFirstTurn($campaign);
        $scene = $campaign->activeScene;

        $this->placeFeature($scene, 'the warehouse roof');
        $this->placeFeature($scene, "the smuggler's door", ['hidden' => true]);
        $this->placeActor($scene, 'the lantern watchman');

        $lurker = $this->placeActor($scene, 'a dockside tough');
        $lurker->update(['tags' => ($lurker->tags ?? []) + ['lurking' => true, 'lurking_since' => 1]]);

        $names = collect(ChapterEntities::for($campaign->fresh(), $turn->fresh()))->pluck('name');

        $this->assertTrue($names->contains('the warehouse roof'));
        $this->assertTrue($names->contains('the lantern watchman'));
        // Hidden is hidden from the reader's detail card too.
        $this->assertFalse($names->contains("the smuggler's door"));
        $this->assertFalse($names->contains('a dockside tough'));

        // Longest first, so a long name always wins over one nested in it.
        $lengths = $names->map(fn ($n) => mb_strlen($n))->all();
        $this->assertSame($lengths, collect($lengths)->sortDesc()->values()->all());

        $roof = collect(ChapterEntities::for($campaign->fresh(), $turn->fresh()))
            ->firstWhere('name', 'the warehouse roof');
        $this->assertSame('feature', $roof['kind']);
        $this->assertStringContainsString('climbed', implode(' ', $roof['lines']));
    }

    /**
     * The world names a thing "Stacked Cargo Crates"; the chapter says "the
     * crates". Without the short forms the prose highlighting catches almost
     * nothing, which is the whole point of it.
     */
    public function test_entities_answer_to_the_short_names_narration_actually_writes()
    {
        $campaign = $this->createCatCampaign();
        $turn = app(TurnStarter::class)->openFirstTurn($campaign);
        $scene = $campaign->activeScene;

        foreach ([
            'Stacked Cargo Crates',   // head noun is distinctive: claims "Crates"
            'Fish-Hoist Winch Arm',   // head noun is anatomy: must claim only "Winch Arm"
            'Slack Mooring Line',     // head noun is a prose word: only "Mooring Line"
            'Rope-Bound Crates',      // contests "Crates" with the first feature
        ] as $name) {
            SceneFeature::create([
                'scene_id' => $scene->id,
                'zone_id' => $scene->zone_id,
                'name' => $name,
                'feature_type' => 'cover',
                'affordances' => ['hideable' => true],
                'state' => [],
                'source' => 'seed',
            ]);
        }

        $byName = collect(ChapterEntities::for($campaign->fresh(), $turn->fresh()))->keyBy('name');

        // A distinctive head noun is claimed…
        $this->assertContains('Winch Arm', $byName['Fish-Hoist Winch Arm']['aliases']);
        $this->assertContains('Mooring Line', $byName['Slack Mooring Line']['aliases']);
        // …but never one the prose owns for other purposes.
        $this->assertNotContains('Arm', $byName['Fish-Hoist Winch Arm']['aliases']);
        $this->assertNotContains('Line', $byName['Slack Mooring Line']['aliases']);
        // …and never one two things in the scene would both answer to.
        $this->assertNotContains('Crates', $byName['Stacked Cargo Crates']['aliases']);
        $this->assertNotContains('Crates', $byName['Rope-Bound Crates']['aliases']);
        // The full name always survives, however contested its parts.
        $this->assertContains('Stacked Cargo Crates', $byName['Stacked Cargo Crates']['aliases']);
    }

    /**
     * Colour is how the reader tells a foe from a friend from a wall before
     * tapping anything — the dotted underline alone is too quiet to notice
     * inside a paragraph of serif prose.
     */
    public function test_entities_carry_the_tone_the_page_colours_them_by()
    {
        $campaign = $this->createCatCampaign();
        $turn = app(TurnStarter::class)->openFirstTurn($campaign);
        $scene = $campaign->activeScene;

        foreach ([
            'Harbor Cutthroat' => 'enemy',
            'Wharf-Dog Bardolph' => 'companion',
            'Net-Mender Alys' => 'npc',
        ] as $name => $kind) {
            Actor::create([
                'scene_id' => $scene->id,
                'zone_id' => $scene->zone_id,
                'name' => $name,
                'kind' => $kind,
                'tier' => 'regular',
                'stats' => ['health' => ['current' => 6, 'max' => 6]],
                'tags' => [],
                'status' => 'active',
                'source' => 'seed',
            ]);
        }

        $this->placeFeature($scene, 'the warehouse roof');

        $byName = collect(ChapterEntities::for($campaign->fresh(), $turn->fresh()))->keyBy('name');

        $this->assertSame('foe', $byName['Harbor Cutthroat']['tone']);
        $this->assertSame('ally', $byName['Wharf-Dog Bardolph']['tone']);
        $this->assertSame('person', $byName['Net-Mender Alys']['tone']);
        $this->assertSame('ground', $byName['the warehouse roof']['tone']);
    }

    public function test_a_chapter_without_a_turn_still_names_the_ground_it_stands_on()
    {
        $campaign = $this->createCatCampaign();
        app(TurnStarter::class)->openFirstTurn($campaign);
        $this->placeFeature($campaign->activeScene, 'the warehouse roof');

        // A prologue or chronicle has no turn behind it; the campaign's own
        // scene answers for it rather than the page losing every anchor.
        $names = collect(ChapterEntities::for($campaign->fresh(), null))->pluck('name');

        $this->assertTrue($names->contains('the warehouse roof'));
    }

    /**
     * The two faces that overrule the arithmetic. The dice are seeded from the
     * turn id, so rather than fight the seed for a chosen face this walks a
     * long run of turns and asserts the rule holds every time it comes up —
     * and that over that many throws, both faces do come up.
     */
    public function test_natural_twenty_and_natural_one_overrule_the_margin()
    {
        $campaign = $this->createCatCampaign();
        app(TurnStarter::class)->openFirstTurn($campaign);
        $full = $campaign->character->meters;

        $crits = ['success' => 0, 'failure' => 0];

        for ($i = 0; $i < 140; $i++) {
            $campaign = $campaign->fresh();
            // Keep the run clean: nothing wanders in to wound the character
            // and end the loop early on a death.
            $campaign->activeScene->actors()->delete();
            $campaign->character->update(['meters' => $full]);

            $turn = $this->refreshCards($campaign->currentTurn);
            $card = collect($turn->cards['main'])->firstWhere('verb', 'improvise');
            $this->assertNotNull($card);

            $turn->update([
                'status' => Turn::STATUS_LOCKED,
                'submission' => ['main' => ['card_id' => $card['id'], 'modifiers' => []]],
                'submitted_at' => now(),
            ]);
            app(TurnResolver::class)->resolve($turn->fresh());

            $beat = collect($turn->fresh()->resolution['beats'])->firstWhere('verb', 'improvise');
            if ($beat === null) {
                continue;
            }

            if ($beat['roll'] === 20) {
                $crits['success']++;
                $this->assertSame('success', $beat['crit']);
                $this->assertSame('strong', $beat['degree']);
            } elseif ($beat['roll'] === 1) {
                $crits['failure']++;
                $this->assertSame('failure', $beat['crit']);
                $this->assertSame('failure', $beat['degree']);
            } else {
                $this->assertNull($beat['crit']);
            }
        }

        $this->assertGreaterThan(0, $crits['success'], 'no natural 20 in 140 throws');
        $this->assertGreaterThan(0, $crits['failure'], 'no natural 1 in 140 throws');
    }

    /**
     * A crit is real in the state, not only loud in the prose: the fumble
     * spends the ground the character was standing on and turns the effort
     * back on them.
     */
    public function test_a_critical_failure_costs_the_ground_it_was_standing_on()
    {
        $campaign = $this->createCatCampaign();
        app(TurnStarter::class)->openFirstTurn($campaign);
        $full = $campaign->character->meters;

        for ($i = 0; $i < 140; $i++) {
            $campaign = $campaign->fresh();
            $campaign->activeScene->actors()->delete();
            $campaign->character->update(['meters' => $full]);
            // Standing on the high ground is what the fumble has to cost.
            $scene = $campaign->activeScene;
            $scene->update(['state' => array_merge($scene->state ?? [], ['elevated' => true])]);

            $turn = $this->refreshCards($campaign->currentTurn);
            $card = collect($turn->cards['main'])->firstWhere('verb', 'improvise');
            $turn->update([
                'status' => Turn::STATUS_LOCKED,
                'submission' => ['main' => ['card_id' => $card['id'], 'modifiers' => []]],
                'submitted_at' => now(),
            ]);
            app(TurnResolver::class)->resolve($turn->fresh());

            $beat = collect($turn->fresh()->resolution['beats'])->firstWhere('verb', 'improvise');
            if (($beat['crit'] ?? null) !== 'failure') {
                continue;
            }

            $facts = implode(' ', $beat['facts']);
            $this->assertStringContainsString('CRITICAL FAILURE', $facts);
            $this->assertStringContainsString('the high ground', $facts);
            // The height is scene state, not just a condition flag.
            $this->assertFalse((bool) ($campaign->fresh()->activeScene->state['elevated'] ?? false));

            return;
        }

        $this->fail('no natural 1 in 140 throws');
    }

    /**
     * A crit that only changes an adjective is not a crit. The triumph has to
     * leave something behind that outlives the beat — and it has to be named
     * neutrally, so the land the campaign was forged in decides what a hole
     * in the ground actually is.
     */
    public function test_a_critical_success_on_force_tears_the_ground_open()
    {
        $campaign = $this->createCatCampaign();
        app(TurnStarter::class)->openFirstTurn($campaign);
        $full = $campaign->character->meters;

        for ($i = 0; $i < 200; $i++) {
            $campaign = $campaign->fresh();
            $scene = $campaign->activeScene;
            $scene->actors()->delete();
            $scene->features()->where('source', 'crit')->delete();
            $campaign->character->update(['meters' => $full]);
            $this->placeActor($scene, 'a dockside tough');

            $turn = $this->refreshCards($campaign->currentTurn);
            $strike = collect($turn->cards['main'])->firstWhere('verb', 'strike');
            if ($strike === null) {
                continue;
            }
            $turn->update([
                'status' => Turn::STATUS_LOCKED,
                'submission' => ['main' => ['card_id' => $strike['id'], 'modifiers' => []]],
                'submitted_at' => now(),
            ]);
            app(TurnResolver::class)->resolve($turn->fresh());

            $beat = collect($turn->fresh()->resolution['beats'])->firstWhere('verb', 'strike');
            if (($beat['crit'] ?? null) !== 'success') {
                continue;
            }

            $breach = $scene->fresh()->features()->where('feature_type', 'crit_breach')->first();
            $this->assertNotNull($breach, 'a critical blow left no mark on the scene');
            $this->assertSame('crit', $breach->source);
            // Neutral ground: the engine says torn open, never what is under
            // it. Naming the fire in the crevasse is the narrator's job, in
            // the land this campaign was forged in.
            $this->assertStringNotContainsStringIgnoringCase('fire', $breach->name);
            $this->assertStringContainsString('torn open', implode(' ', $beat['facts']));

            return;
        }

        $this->fail('no critical success on a strike in 200 throws');
    }

    /**
     * The signature fumble: the weapon leaves their hands, and the loss is
     * real in the form, not only in the prose — an item's granted powers go
     * with it, and getting it back costs a whole beat.
     */
    public function test_a_critical_failure_sends_the_weapon_somewhere_they_must_go_and_get_it()
    {
        $campaign = $this->createCatCampaign();
        app(TurnStarter::class)->openFirstTurn($campaign);
        $full = $campaign->character->meters;

        $item = Item::create([
            'slug' => 'harbor-iron-hook',
            'name' => 'the harbor-iron hook',
            'description' => 'A boarding hook worn smooth by other hands.',
            'power' => 2,
            'grants' => [['capability' => 'break', 'magnitude' => 3]],
        ]);
        $campaign->character->items()->attach($item->id, ['equipped' => true, 'charges' => null]);

        for ($i = 0; $i < 200; $i++) {
            $campaign = $campaign->fresh();
            $scene = $campaign->activeScene;
            $scene->actors()->delete();
            $scene->features()->where('source', 'crit')->delete();
            $campaign->character->update(['meters' => $full]);
            $campaign->character->items()->updateExistingPivot($item->id, ['equipped' => true]);
            $this->placeActor($scene, 'a dockside tough');

            $turn = $this->refreshCards($campaign->currentTurn);
            $strike = collect($turn->cards['main'])->firstWhere('verb', 'strike');
            if ($strike === null) {
                continue;
            }
            $turn->update([
                'status' => Turn::STATUS_LOCKED,
                'submission' => ['main' => ['card_id' => $strike['id'], 'modifiers' => []]],
                'submitted_at' => now(),
            ]);
            app(TurnResolver::class)->resolve($turn->fresh());

            $beat = collect($turn->fresh()->resolution['beats'])->firstWhere('verb', 'strike');
            if (($beat['crit'] ?? null) !== 'failure') {
                continue;
            }

            $character = $campaign->character->fresh()->load('items');
            $this->assertFalse((bool) $character->items->firstWhere('id', $item->id)->pivot->equipped);
            // Unequipped is not cosmetic: the granted capability goes too.
            $this->assertArrayNotHasKey('break', $character->effectiveCapabilities());

            $dropped = $scene->fresh()->features()->where('feature_type', 'dropped_item')->first();
            $this->assertNotNull($dropped);

            // And it is recoverable — no capability gates picking your own
            // gear back up, but it costs a whole main beat.
            $next = $this->refreshCards($campaign->fresh()->currentTurn);
            $recover = collect($next->cards['main'])->firstWhere('verb', 'recover');
            $this->assertNotNull($recover, 'the dropped weapon was unreachable');
            $this->assertSame($dropped->id, $recover['target']['id']);

            return;
        }

        $this->fail('no critical failure on a strike in 200 throws');
    }

    public function test_the_narration_prompt_makes_a_crit_the_chapter_spine()
    {
        $campaign = $this->createCatCampaign();
        $turn = app(TurnStarter::class)->openFirstTurn($campaign);
        $turn->update([
            'status' => Turn::STATUS_COMPLETE,
            'branch_trigger' => 'decision_point',
            'resolution' => [
                'beats' => [[
                    'slot' => 'main', 'verb' => 'strike',
                    'target' => ['type' => 'actor', 'id' => 1, 'name' => 'a dockside tough'],
                    'degree' => 'strong', 'roll' => 20, 'total' => 22, 'difficulty' => 15,
                    'facts' => ['The ground is torn open where the blow struck.'],
                    'skipped' => false, 'crit' => 'success',
                ]],
                'scene_reaction' => [],
                'reaction_rolls' => [],
                'new_threat' => null,
            ],
        ]);

        $prompt = (new \ReflectionMethod(Narrator::class, 'buildPrompt'))
            ->invoke(app(Narrator::class), $turn->fresh());

        $this->assertStringContainsString('The moment this chapter turns on', $prompt);
        $this->assertStringContainsString('CRITICAL SUCCESS on strike (a dockside tough)', $prompt);
        $this->assertStringContainsString('what lies UNDER the ground in THIS land', $prompt);
        // Scale is the narrator's; outcomes are not. Without that bound a
        // "make it enormous" instruction invents deaths the engine never rolled.
        $this->assertStringContainsString('Scale is yours; outcomes are not', $prompt);

        // An ordinary turn must not carry instructions to be enormous.
        $turn->update(['resolution' => array_merge($turn->resolution, [
            'beats' => [[
                'slot' => 'main', 'verb' => 'strike', 'target' => null,
                'degree' => 'success', 'roll' => 12, 'total' => 14, 'difficulty' => 12,
                'facts' => ['The strike wounded the tough.'], 'skipped' => false, 'crit' => null,
            ]],
        ])]);
        $plain = (new \ReflectionMethod(Narrator::class, 'buildPrompt'))
            ->invoke(app(Narrator::class), $turn->fresh());
        $this->assertStringNotContainsString('The moment this chapter turns on', $plain);
    }

    public function test_the_scene_records_its_own_dice_not_only_its_prose()
    {
        $campaign = $this->createCatCampaign();
        app(TurnStarter::class)->openFirstTurn($campaign);
        $scene = $campaign->activeScene;
        $scene->actors()->delete();
        $this->placeActor($scene, 'a dockside tough');

        $turn = $this->refreshCards($campaign->fresh()->currentTurn);
        $wait = collect($turn->cards['main'])->firstWhere('verb', 'wait');
        $turn->update([
            'status' => Turn::STATUS_LOCKED,
            'submission' => ['main' => ['card_id' => $wait['id'], 'modifiers' => []]],
            'submitted_at' => now(),
        ]);
        app(TurnResolver::class)->resolve($turn->fresh());

        $rolls = $turn->fresh()->resolution['reaction_rolls'];
        $this->assertCount(1, $rolls);
        $this->assertSame('a dockside tough', $rolls[0]['actor']);
        $this->assertSame('enemy', $rolls[0]['kind']);
        // The die the player never got to see is now data, not a sentence to
        // be regex'd back out of the narration.
        $this->assertGreaterThanOrEqual(1, $rolls[0]['roll']);
        $this->assertLessThanOrEqual(20, $rolls[0]['roll']);
        $this->assertGreaterThan(0, $rolls[0]['difficulty']);
        $this->assertNotNull($rolls[0]['outcome']);
    }

    public function test_the_dice_table_derives_from_the_resolution_and_bands_the_difficulty()
    {
        $campaign = $this->createCatCampaign();
        $turn = app(TurnStarter::class)->openFirstTurn($campaign);
        $turn->update(['resolution' => [
            'beats' => [
                ['slot' => 'pre', 'verb' => 'ready', 'target' => null, 'degree' => 'success', 'roll' => 0, 'total' => 0, 'difficulty' => 0, 'facts' => ['They set themselves.'], 'skipped' => false, 'crit' => null],
                ['slot' => 'main', 'verb' => 'strike', 'target' => ['type' => 'actor', 'id' => 1, 'name' => 'a dockside tough'], 'degree' => 'strong', 'roll' => 20, 'total' => 22, 'difficulty' => 15, 'facts' => ['CRITICAL SUCCESS: it went perfectly.', 'The blow felled the tough.'], 'skipped' => false, 'crit' => 'success'],
                ['slot' => 'post', 'verb' => 'loot', 'target' => null, 'degree' => 'failure', 'roll' => 3, 'total' => 3, 'difficulty' => 8, 'facts' => ['Nothing worth taking.'], 'skipped' => true, 'crit' => null],
            ],
            'scene_reaction' => [],
            'reaction_rolls' => [[
                'actor' => 'a dockside tough', 'kind' => 'enemy', 'verb' => 'attack',
                'label' => 'Presses the attack', 'roll' => 1, 'total' => 3, 'difficulty' => 12,
                'crit' => 'failure', 'degree' => 'failure', 'outcome' => 'Overreached and left themselves open',
            ]],
            'new_threat' => null,
        ]]);

        $rows = RollTable::for($turn->fresh());

        // Quiet beats cast no die and skipped beats never happened; a table
        // of blank cards would teach the player to distrust it.
        $this->assertSame(['r1', 'r2'], array_column($rows, 'id'));
        $this->assertSame(['player', 'foe'], array_column($rows, 'side'));

        $this->assertSame('The Cat', $rows[0]['actor']);
        $this->assertSame('Strike — a dockside tough', $rows[0]['action']);
        $this->assertSame('Hard', $rows[0]['band']);
        $this->assertSame(2, $rows[0]['modifier']);
        $this->assertSame('success', $rows[0]['crit']);
        // The crit banner is a badge on the card; the line beneath it says
        // what actually changed.
        $this->assertSame('The blow felled the tough.', $rows[0]['outcome']);

        $this->assertSame('a dockside tough', $rows[1]['actor']);
        $this->assertSame('Medium', $rows[1]['band']);
        $this->assertSame('failure', $rows[1]['crit']);
    }

    public function test_the_dice_table_gates_the_chapter_exactly_once()
    {
        $campaign = $this->createCatCampaign();
        app(TurnStarter::class)->openFirstTurn($campaign);
        $scene = $campaign->activeScene;
        $scene->actors()->delete();

        $turn = $this->refreshCards($campaign->fresh()->currentTurn);
        $card = collect($turn->cards['main'])->firstWhere('verb', 'improvise');
        $turn->update([
            'status' => Turn::STATUS_LOCKED,
            'submission' => ['main' => ['card_id' => $card['id'], 'modifiers' => []]],
            'submitted_at' => now(),
        ]);
        app(TurnResolver::class)->resolve($turn->fresh());

        $this->actingAs($campaign->user)
            ->get(route('play.show', $campaign))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('rollTable.turn_id', $turn->id)
                ->has('rollTable.rows', 1));

        $this->actingAs($campaign->user)
            ->post(route('play.rolls-seen', $campaign), ['turn_id' => $turn->id])
            ->assertRedirect();

        // Watched once is watched for good — on this device and every other.
        $this->assertNotNull($turn->fresh()->rolls_seen_at);
        $this->actingAs($campaign->user)
            ->get(route('play.show', $campaign))
            ->assertInertia(fn ($page) => $page->where('rollTable', null));
    }

    public function test_widget_endpoint_requires_a_valid_token()
    {
        $campaign = $this->createCatCampaign();
        app(TurnStarter::class)->openFirstTurn($campaign);

        $this->getJson('/api/widget/status?token=wrong')->assertStatus(401);

        $token = $campaign->user->ensureWidgetToken();
        $this->getJson("/api/widget/status?token={$token}")
            ->assertOk()
            ->assertJsonPath('character', 'The Cat')
            ->assertJsonPath('awaiting_player', true);
    }
}
