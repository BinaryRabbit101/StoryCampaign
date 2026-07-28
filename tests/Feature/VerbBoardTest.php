<?php

namespace Tests\Feature;

use App\Game\Engine\CardComposer;
use App\Game\Engine\TurnResolver;
use App\Game\Meters;
use App\Game\VerbFamily;
use App\Models\Actor;
use App\Models\Campaign;
use App\Models\Character;
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
 * The board is a LENS.
 *
 * It rearranges how a card is reached — a word, then the thing, then the
 * manner — and changes nothing about what is reached. A submission assembled
 * by walking the board has to be the same card id, the same payload, and the
 * same resolution as one taken off the old flat list, or the redesign has
 * quietly become a second composition path.
 */
class VerbBoardTest extends TestCase
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
            'name' => 'Board Tale',
            'world_flavor' => 'harbor-city',
            'status' => 'active',
            'started_at' => now(),
        ]);

        $character = Character::create([
            'campaign_id' => $campaign->id,
            'name' => 'The Cat',
            'description' => 'A 200 lb striking black cat with a 12-foot prehensile tail.',
            'meters' => Meters::default(),
            'status' => 'alive',
            'meters_regenerated_at' => now(),
        ]);

        foreach ([
            ['capability' => 'ready'],
            ['capability' => 'swing'],
            ['capability' => 'reach', 'magnitude' => 12],
            ['capability' => 'squeeze', 'grade' => 'large'],
        ] as $cap) {
            $character->capabilities()->create($cap + ['source' => 'creation']);
        }

        return $campaign;
    }

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

    private function refreshCards(Turn $turn): Turn
    {
        $turn->update(['cards' => app(CardComposer::class)->compose(
            $turn->campaign->character->fresh(), $turn->scene->fresh(),
        )]);

        return $turn->fresh();
    }

    /**
     * Walking the board the way the screen does: light a word, open its verbs,
     * point at a thing. Every step narrows the SAME offered list.
     */
    private function walkBoard(array $mainCards, VerbFamily $family, string $verb, ?string $target): array
    {
        $lit = collect($mainCards)->groupBy('family');
        $this->assertTrue($lit->has($family->value), "The board never lit {$family->value}.");

        $verbs = $lit[$family->value]->groupBy('verb');
        $this->assertTrue($verbs->has($verb), "{$family->value} never opened onto {$verb}.");

        $card = $verbs[$verb]->first(
            fn (array $c) => $target === null || ($c['target']['name'] ?? null) === $target,
        );

        $this->assertNotNull($card, "No card under {$verb} was aimed at {$target}.");

        return $card;
    }

    public function test_the_board_reaches_the_same_card_the_flat_list_did()
    {
        $campaign = $this->createCampaign();
        $turn = app(TurnStarter::class)->openFirstTurn($campaign);
        $this->placeActor($campaign->activeScene, 'a dockside tough');
        $turn = $this->refreshCards($turn);

        // The old way: scan every main-slot card for the one you wanted.
        $flat = collect($turn->cards['main'])->first(
            fn (array $c) => $c['verb'] === 'strike' && ($c['target']['name'] ?? null) === 'a dockside tough',
        );

        // The new way: FIGHT → strike → the tough.
        $walked = $this->walkBoard($turn->cards['main'], VerbFamily::Fight, 'strike', 'a dockside tough');

        $this->assertNotNull($flat);
        $this->assertSame($flat['id'], $walked['id']);
        $this->assertSame($flat['forecast'], $walked['forecast']);
    }

    public function test_a_submission_assembled_from_the_board_validates_and_resolves()
    {
        $campaign = $this->createCampaign();
        $turn = app(TurnStarter::class)->openFirstTurn($campaign);
        $this->placeActor($campaign->activeScene, 'a dockside tough');
        $turn = $this->refreshCards($turn);

        $act = $this->walkBoard($turn->cards['main'], VerbFamily::Fight, 'strike', 'a dockside tough');

        // Riders: the same walk over the pre and post slots the board presents
        // as "First…" and "Afterward…".
        $first = collect($turn->cards['pre'])->firstWhere('verb', 'ready');
        $after = collect($turn->cards['post'])->firstWhere('verb', 'catch_breath');
        $this->assertNotNull($first);
        $this->assertNotNull($after);
        $this->assertSame(VerbFamily::Tend->value, $first['family']);
        $this->assertSame(VerbFamily::Tend->value, $after['family']);

        // The payload shape is exactly what it always was: card ids, clamped
        // modifiers, notes. The board never changed the wire.
        $this->actingAs($campaign->user)
            ->post("/play/{$campaign->id}", [
                'pre' => ['card_id' => $first['id'], 'modifiers' => [], 'note' => null],
                'main' => [
                    'card_id' => $act['id'],
                    'modifiers' => ['approach' => 'cautious', 'method' => 'a lunge'],
                    'note' => 'Straight for the knee.',
                ],
                'post' => ['card_id' => $after['id'], 'modifiers' => [], 'note' => null],
                'companions' => [],
                'intent_text' => null,
            ])
            ->assertRedirect("/play/{$campaign->id}");

        $turn->refresh();

        $this->assertSame(Turn::STATUS_COMPLETE, $turn->status);
        $this->assertSame($act['id'], $turn->submission['main']['card_id']);
        $this->assertSame('cautious', $turn->submission['main']['modifiers']['approach']);
        $this->assertSame('Straight for the knee.', $turn->submission['main']['note']);

        $verbs = collect($turn->resolution['beats'])->pluck('verb');
        $this->assertTrue($verbs->contains('ready'));
        $this->assertTrue($verbs->contains('strike'));
    }

    public function test_a_riders_quoted_delta_is_the_grant_the_resolver_applies()
    {
        $campaign = $this->createCampaign();
        $turn = app(TurnStarter::class)->openFirstTurn($campaign);
        $this->placeActor($campaign->activeScene, 'a dockside tough');
        $turn = $this->refreshCards($turn);

        $rider = collect($turn->cards['pre'])->firstWhere('verb', 'ready');
        $act = $this->walkBoard($turn->cards['main'], VerbFamily::Fight, 'strike', 'a dockside tough');

        // What the rider line quotes, straight off the engine's forecast.
        $grant = $rider['forecast']['grant'];
        $this->assertNotNull($grant);
        $this->assertTrue($grant['certain']);
        $this->assertNull($grant['verbs']);

        $turn->update([
            'status' => Turn::STATUS_LOCKED,
            'submission' => [
                'pre' => ['card_id' => $rider['id'], 'modifiers' => []],
                'main' => ['card_id' => $act['id'], 'modifiers' => ['approach' => 'balanced']],
            ],
            'submitted_at' => now(),
        ]);

        app(TurnResolver::class)->resolve($turn->fresh());
        $turn->refresh();

        // ...and the same line, itemized, in what the die was actually
        // measured with. One ladder, extended to the riders.
        $strike = collect($turn->resolution['beats'])->firstWhere('verb', 'strike');
        $this->assertContains(
            ['label' => $grant['label'], 'amount' => $grant['amount']],
            $strike['bonus_parts'],
        );
    }

    public function test_the_board_lights_only_what_the_ground_offers()
    {
        $campaign = $this->createCampaign();
        $turn = app(TurnStarter::class)->openFirstTurn($campaign);
        $scene = $campaign->activeScene;
        $scene->update(['state' => array_merge($scene->state ?? [], ['dressed' => true])]);
        $scene->actors()->delete();
        $this->placeFeature($scene->fresh(), 'the warehouse roof');
        $turn = $this->refreshCards($turn);

        $lit = collect($turn->cards['main'])->pluck('family')->unique();

        // Nobody to fight and nobody to talk to: those words stay dark, which
        // is a reading of the ground rather than a missing option.
        $this->assertNotContains(VerbFamily::Fight->value, $lit);
        $this->assertNotContains(VerbFamily::Speak->value, $lit);
        $this->assertContains(VerbFamily::Look->value, $lit);
        $this->assertContains(VerbFamily::Wait->value, $lit);
        $this->assertContains(VerbFamily::Do->value, $lit);

        // ...and the moment somebody is standing there, FIGHT lights, with no
        // change to anything but the cards the composer offered.
        $this->placeActor($scene->fresh(), 'a dockside tough');
        $turn = $this->refreshCards($turn);

        $this->assertContains(
            VerbFamily::Fight->value,
            collect($turn->cards['main'])->pluck('family')->unique(),
        );
    }
}
