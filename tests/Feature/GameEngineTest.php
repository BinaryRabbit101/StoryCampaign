<?php

namespace Tests\Feature;

use App\Game\Engine\CardComposer;
use App\Game\Engine\ChapterEntities;
use App\Game\Engine\ChapterEvents;
use App\Game\Engine\TurnResolver;
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

        // No live CLI in tests: the forge's Claude call fails fast, so
        // every campaign world falls back to a shared-zone clone.
        $this->mock(ClaudeCli::class, function ($mock) {
            $mock->shouldReceive('promptForJson')->andThrow(new \RuntimeException('offline'))->byDefault();
            $mock->shouldReceive('prompt')->andReturn('A tale begins.')->byDefault();
        });

        $campaign = Campaign::create([
            'user_id' => User::factory()->create()->id,
            'name' => 'Test Tale',
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

    public function test_the_form_locks_after_submission()
    {
        $campaign = $this->createCatCampaign();
        $turn = app(TurnStarter::class)->openFirstTurn($campaign);
        $main = collect($turn->cards['main'])->first(fn ($c) => $c['verb'] === 'wait');

        config(['game.turn_cadence_minutes' => 30]);
        $this->actingAs($campaign->user);

        $this->post("/play/{$campaign->id}", ['main' => ['card_id' => $main['id']]])
            ->assertRedirect("/play/{$campaign->id}");

        $this->assertSame(Turn::STATUS_LOCKED, $turn->fresh()->status);

        // one form per turn: a second submission is refused until resolution
        $this->post("/play/{$campaign->id}", ['main' => ['card_id' => $main['id']]])
            ->assertStatus(409);
    }

    public function test_only_offered_cards_are_accepted_by_the_form()
    {
        $campaign = $this->createCatCampaign();
        app(TurnStarter::class)->openFirstTurn($campaign);

        config(['game.turn_cadence_minutes' => 30]);
        $this->actingAs($campaign->user);

        $this->from("/play/{$campaign->id}")
            ->post("/play/{$campaign->id}", ['main' => ['card_id' => 'forged-card-id']])
            ->assertRedirect("/play/{$campaign->id}")
            ->assertSessionHasErrors('main');
    }

    public function test_a_committed_turn_can_be_resolved_on_demand()
    {
        $campaign = $this->createCatCampaign();
        $turn = app(TurnStarter::class)->openFirstTurn($campaign);
        $main = collect($turn->cards['main'])->first(fn ($c) => $c['verb'] === 'wait');

        config(['game.turn_cadence_minutes' => 30]);
        $this->actingAs($campaign->user);
        $this->mock(Narrator::class)->shouldReceive('narrate');

        // Nothing committed yet: resolve-now must refuse (no Claude run
        // can ever fire without a player choice behind it).
        $this->post("/play/{$campaign->id}/resolve-now")->assertStatus(409);

        $this->post("/play/{$campaign->id}", ['main' => ['card_id' => $main['id']]]);
        $this->assertSame(Turn::STATUS_LOCKED, $turn->fresh()->status);

        $this->post("/play/{$campaign->id}/resolve-now")
            ->assertRedirect("/play/{$campaign->id}");

        $this->assertSame(Turn::STATUS_COMPLETE, $turn->fresh()->status);
        $this->assertSame(Turn::STATUS_AWAITING, $campaign->fresh()->currentTurn->status);
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
        $this->assertSame(['roll' => 18, 'total' => 20, 'difficulty' => 10], $events[1]['roll']);

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

        // The tale opens in its own world: a campaign-scoped zone (the
        // cold-forge clone here, since no live CLI), never shared ground.
        $zone = $second->activeScene->zone;
        $this->assertSame($second->id, $zone->campaign_id);
        $this->assertSame($zone->id, $second->starting_zone_id);
        $this->assertTrue($zone->features()->whereNull('scene_id')->exists());
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
        // The zone still lends the stage some shared ground (features only,
        // never actors — the cast is the campaign's own).
        $this->assertGreaterThanOrEqual(2, $scene->features()->where('source', 'seed')->count());
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

    public function test_the_player_may_step_through_the_refused_interview_sheet_owing()
    {
        $this->seed(WorldSeeder::class);
        $user = User::factory()->create();

        // Every completion attempt is all gift, no debt: cost 7 against 3.
        $this->mock(ClaudeCli::class, function ($mock) {
            $mock->shouldReceive('promptForJson')->andReturn([
                'reply' => 'It is done.',
                'suggestions' => [],
                'complete' => true,
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

        // No refused sheet parked yet: nothing to insist on.
        $this->actingAs($user)->post("/campaigns/{$campaign->id}/interview/insist")->assertStatus(409);

        $this->actingAs($user)->post("/campaigns/{$campaign->id}/interview", ['body' => 'I am mighty.']);
        $campaign->refresh();
        $this->assertSame('interview', $campaign->status);
        $this->assertNotNull($campaign->pending_sheet);
        $this->actingAs($user)->get("/campaigns/{$campaign->id}/interview")
            ->assertInertia(fn ($page) => $page->where('canInsist', true));

        // Insisting births the refused sheet, shortfall on the record.
        $this->actingAs($user)->post("/campaigns/{$campaign->id}/interview/insist")
            ->assertRedirect("/play/{$campaign->id}");

        $campaign->refresh();
        $this->assertSame('active', $campaign->status);
        $this->assertNull($campaign->pending_sheet);
        $this->assertSame(12, $campaign->character->capabilities->firstWhere('capability', 'reach')->magnitude);
        $debt = $campaign->character->constraints->firstWhere('name', 'debt_to_the_world');
        $this->assertSame(4, $debt->params['shortfall']);
        $this->assertTrue(
            $campaign->interviewMessages()->where('role', 'player')->get()
                ->contains(fn ($m) => str_contains($m->body, 'step through regardless')),
        );
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

        // All gift, no debt: the world refuses in-world and the interview
        // continues, with burden suggestions on the refusal.
        $this->actingAs($user)->post("/campaigns/{$campaign->id}/interview", ['body' => 'I am mighty.']);
        $campaign->refresh();
        $this->assertSame('interview', $campaign->status);
        $refusal = $campaign->interviewMessages()->orderByDesc('id')->first();
        $this->assertStringContainsString('the scales refuse it', $refusal->body);
        $this->assertNotEmpty($refusal->suggestions);

        // A price named: the same gifts now break even, and the tale opens.
        $this->actingAs($user)->post("/campaigns/{$campaign->id}/interview", ['body' => 'I am slow, and remembered everywhere.']);
        $campaign->refresh();
        $this->assertSame('active', $campaign->status);
        $this->assertSame(12, $campaign->character->capabilities->firstWhere('capability', 'reach')->magnitude);
        $this->assertTrue($campaign->character->constraints->pluck('name')->contains('ponderous'));
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

        $names = collect(ChapterEntities::for($turn->fresh()))->pluck('name');

        $this->assertTrue($names->contains('the warehouse roof'));
        $this->assertTrue($names->contains('the lantern watchman'));
        // Hidden is hidden from the reader's detail card too.
        $this->assertFalse($names->contains("the smuggler's door"));
        $this->assertFalse($names->contains('a dockside tough'));

        // Longest first, so a long name always wins over one nested in it.
        $lengths = $names->map(fn ($n) => mb_strlen($n))->all();
        $this->assertSame($lengths, collect($lengths)->sortDesc()->values()->all());

        $roof = collect(ChapterEntities::for($turn->fresh()))->firstWhere('name', 'the warehouse roof');
        $this->assertSame('feature', $roof['kind']);
        $this->assertStringContainsString('climbed', implode(' ', $roof['lines']));
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
