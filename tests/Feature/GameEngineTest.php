<?php

namespace Tests\Feature;

use App\Game\Engine\CardComposer;
use App\Game\Engine\ChapterEvents;
use App\Game\Engine\TurnResolver;
use App\Game\Meters;
use App\Models\Actor;
use App\Models\Campaign;
use App\Models\Character;
use App\Models\Turn;
use App\Models\User;
use App\Models\Zone;
use App\Services\Claude\ClaudeCli;
use App\Services\Claude\Narrator;
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

    public function test_options_emerge_from_capability_affordance_intersection()
    {
        $campaign = $this->createCatCampaign();
        $turn = app(TurnStarter::class)->openFirstTurn($campaign);

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
        $scene = $campaign->scenes()->first() ?? null;
        $turn = app(TurnStarter::class)->openFirstTurn($campaign);
        $scene = $turn->scene;

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

        $pre = collect($turn->cards['pre'])->first(fn ($c) => $c['verb'] === 'ascend');
        $main = collect($turn->cards['main'])->first(fn ($c) => $c['verb'] === 'strike');
        $post = collect($turn->cards['post'])->first(fn ($c) => $c['verb'] === 'catch_breath');

        $turn->update([
            'status' => Turn::STATUS_LOCKED,
            'submission' => [
                'pre' => ['card_id' => $pre['id'], 'modifiers' => []],
                'main' => ['card_id' => $main['id'], 'modifiers' => ['approach' => 'balanced']],
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

        $captive = $scene->actors()->where('kind', 'enemy')->first();
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

    public function test_the_stage_is_stored_and_the_chosen_zone_opens_the_tale()
    {
        $campaign = $this->createCatCampaign();
        $harbor = Zone::create(['slug' => 'harbor', 'name' => 'The Drowned Harbor', 'description' => 'Piers and hulks.', 'source' => 'seed']);

        $this->mock(ClaudeCli::class)->shouldReceive('prompt')->andReturn('She returned.');

        $this->actingAs($campaign->user)->post('/campaigns', [
            'name' => 'Harbor Tale',
            'character_id' => $campaign->character->id,
            'premise' => 'Find my sister, whatever it costs.',
            'tone' => 'rain-soaked',
            'starting_zone_id' => $harbor->id,
        ])->assertRedirect();

        $second = $campaign->user->campaigns()->where('name', 'Harbor Tale')->first();

        $this->assertSame('Find my sister, whatever it costs.', $second->premise);
        $this->assertSame($harbor->id, $second->activeScene->zone_id);
        $this->assertStringContainsString('Premise and goal', $second->stageBrief());
    }

    public function test_companions_are_recruited_then_coordinated_never_controlled()
    {
        $campaign = $this->createCatCampaign();
        app(TurnStarter::class)->openFirstTurn($campaign);
        $scene = $campaign->activeScene;

        // The companionable watchman (seeded, spawned as the third actor)
        // offers a recruit card while enemies are present.
        $cards = app(CardComposer::class)->compose($campaign->character, $scene->fresh());
        $recruit = collect($cards['main'])->first(fn ($c) => $c['verb'] === 'recruit');
        $this->assertNotNull($recruit);
        $this->assertSame('the lantern watchman', $recruit['target']['name']);

        // Once a companion, coordination requests appear — and they are
        // requests aimed at the companion, never direct control.
        $watchman = $scene->actors()->where('name', 'the lantern watchman')->first();
        $watchman->update(['kind' => 'companion']);

        $cards = app(CardComposer::class)->compose($campaign->character, $scene->fresh());
        $preVerbs = collect($cards['pre'])->pluck('verb');
        $this->assertTrue($preVerbs->contains('companion_block'));
        $this->assertTrue($preVerbs->contains('companion_flank'));
        $this->assertTrue(collect($cards['main'])->pluck('verb')->contains('companion_scout'));

        // A scouted exit becomes a real, safe way out on the next compose.
        $scene->update(['state' => ['exit_scouted' => true]]);
        $exit = collect(app(CardComposer::class)->compose($campaign->character, $scene->fresh())['main'])
            ->first(fn ($c) => $c['label'] === 'Take the scouted way out');
        $this->assertNotNull($exit);
        $this->assertSame('flee', $exit['verb']);
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
