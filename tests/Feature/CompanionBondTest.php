<?php

namespace Tests\Feature;

use App\Game\Engine\CardComposer;
use App\Game\Engine\Companions;
use App\Game\Engine\Dice;
use App\Game\Engine\Downtime;
use App\Game\Engine\SituationBoard;
use App\Game\Engine\TurnResolver;
use App\Game\Meters;
use App\Game\TraitCatalog;
use App\Models\Actor;
use App\Models\Campaign;
use App\Models\Character;
use App\Models\Memento;
use App\Models\Scene;
use App\Models\SceneFeature;
use App\Models\Turn;
use App\Models\User;
use App\Models\Zone;
use App\Services\Claude\ClaudeCli;
use App\Services\Claude\Narrator;
use App\Services\TurnStarter;
use Database\Seeders\WorldSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Companion bonds, campfire beats, and companions the road provides.
 *
 * The load-bearing claims: a bond moves only on engine events the resolver
 * already rolled (never on a note, a chapter, or a genre), sworn is sticky, the
 * cap holds, the fellow's signature is picked once from the right table, the
 * sworn interception fires unbidden at would-be-zero and reroutes the damage
 * before the scar path can ever see a fall, joining is consensual on both sides
 * on every path, and losing somebody costs the tale something it keeps.
 *
 * Claude is offline in every test here on purpose. All of the above is engine
 * work; the prose is additive, and an evening the CLI is down must cost the
 * player words and nothing else.
 */
class CompanionBondTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<string> */
    private array $prompts = [];

    private function createCampaign(bool $narrates = false): Campaign
    {
        $this->seed(WorldSeeder::class);

        $this->prompts = [];
        $this->mock(ClaudeCli::class, function ($mock) use ($narrates) {
            $call = $mock->shouldReceive('promptForJson')->andReturnUsing(function (string $prompt) use ($narrates) {
                $this->prompts[] = $prompt;

                if (! $narrates) {
                    throw new \RuntimeException('offline');
                }

                return [
                    'chapter' => 'They walked on together.',
                    'intent_line' => null,
                    'synopsis_line' => 'They walked on together.',
                ];
            });
            $call->byDefault();

            $mock->shouldReceive('prompt')->andReturn('A tale begins.')->byDefault();
        });

        $campaign = Campaign::create([
            'user_id' => User::factory()->create()->id,
            'name' => 'Company Tale',
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
            ['capability' => 'restrain', 'source' => 'creation'],
            ['capability' => 'squeeze', 'grade' => 'large', 'source' => 'creation'],
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

    /** Re-offer the turn after the test has changed who is standing in the scene. */
    private function recompose(Turn $turn): Turn
    {
        $campaign = $turn->campaign;
        $scene = $campaign->activeScene;

        $turn->update([
            'cards' => app(CardComposer::class)->compose($campaign->character->fresh(), $scene->fresh()),
            'downtime' => Downtime::offer($scene->fresh()),
        ]);

        return $turn->fresh();
    }

    private function placeEnemy(Scene $scene, string $name, int $health = 20, int $attack = 1): Actor
    {
        return Actor::create([
            'scene_id' => $scene->id,
            'zone_id' => $scene->zone_id,
            'name' => $name,
            'kind' => 'enemy',
            'tier' => 'regular',
            'stats' => ['health' => ['current' => $health, 'max' => $health], 'attack' => $attack],
            'tags' => ['intent' => 'press'],
            'status' => 'active',
            'source' => 'seed',
        ]);
    }

    private function makeCompanion(Scene $scene, string $name, int $bond = 0, array $tags = [], int $health = 40): Actor
    {
        return Actor::create([
            'scene_id' => $scene->id,
            'zone_id' => $scene->zone_id,
            'name' => $name,
            'kind' => 'companion',
            'tier' => 'regular',
            'stats' => ['health' => ['current' => $health, 'max' => $health], 'attack' => 1],
            'tags' => array_merge([
                'was_kind' => 'npc',
                'bond' => $bond,
                'bond_tier' => Companions::tierFor($bond),
                'joined_via' => Companions::ASKED,
            ], $tags),
            'status' => 'active',
            'source' => 'seed',
        ]);
    }

    /** Someone standing in the scene who is nobody's companion yet. */
    private function placeBystander(Scene $scene, string $name, array $tags = [], string $status = 'active'): Actor
    {
        return Actor::create([
            'scene_id' => $scene->id,
            'zone_id' => $scene->zone_id,
            'name' => $name,
            'kind' => 'npc',
            'tier' => 'regular',
            'stats' => ['health' => ['current' => 4, 'max' => 4], 'attack' => 1],
            'tags' => $tags,
            'status' => $status,
            'source' => 'seed',
        ]);
    }

    /** Resolve the turn on a submission, and hand back the turn that was played. */
    private function play(Turn $turn, array $submission = []): Turn
    {
        if (! isset($submission['main'])) {
            $wait = collect($turn->cards['main'])->firstWhere('verb', 'wait')
                ?? collect($turn->cards['main'])->first();
            $submission['main'] = ['card_id' => $wait['id'], 'modifiers' => []];
        }

        $turn->update([
            'status' => Turn::STATUS_LOCKED,
            'submission' => $submission,
            'submitted_at' => now(),
        ]);

        app(TurnResolver::class)->resolve($turn->fresh());

        return $turn->fresh();
    }

    /** One of a companion's own offered request cards, by verb. */
    private function requestCard(Turn $turn, int $companionId, string $verb): ?string
    {
        $entry = collect($turn->cards['companions'] ?? [])->firstWhere('id', $companionId);

        return collect($entry['cards'] ?? [])->firstWhere('verb', $verb)['id'] ?? null;
    }

    /**
     * Open the frontier and press on until the ground actually changes. The
     * venture card is the one movement every character can always reach for,
     * whatever their sheet happens to be able to do.
     */
    private function crossToNewGround(Campaign $campaign, int $limit = 12): Scene
    {
        $frontier = Zone::create([
            'campaign_id' => $campaign->id,
            'slug' => 'the-far-shelf-'.Zone::count(),
            'name' => 'The Far Shelf',
            'description' => 'Country nobody in this tale has walked yet.',
            'tags' => [],
            'source' => 'forge',
        ]);
        $campaign->update(['next_zone_id' => $frontier->id]);

        $from = $campaign->fresh()->activeScene->id;
        $this->recompose($campaign->fresh()->currentTurn);

        for ($i = 0; $i < $limit && $campaign->fresh()->activeScene->id === $from; $i++) {
            $turn = $campaign->fresh()->currentTurn;
            if ($turn === null || ! $turn->isOpen()) {
                break;
            }
            Meters::heal($campaign->character->fresh(), 20);

            $card = $this->mainCard($turn, 'venture');
            if ($card === null) {
                break;
            }
            $this->play($turn, ['main' => ['card_id' => $card, 'modifiers' => ['approach' => 'balanced']]]);
        }

        return $campaign->fresh()->activeScene;
    }

    private function mainCard(Turn $turn, string $verb, ?int $targetId = null): ?string
    {
        return collect($turn->cards['main'] ?? [])->first(
            fn (array $c) => $c['verb'] === $verb
                && ($targetId === null || ($c['target']['id'] ?? null) === $targetId),
        )['id'] ?? null;
    }

    // ------------------------------------------------------------- the ladder

    /**
     * The arithmetic of the ladder itself: never below zero, never past the
     * ceiling, one reason per chapter — and sworn is sticky, because the
     * history happened and a bad night cannot make it un-happen.
     */
    public function test_the_bond_ladder_clamps_and_sworn_sticks()
    {
        $campaign = $this->createCampaign();
        $this->openBareTurn($campaign);
        $scene = $campaign->activeScene;

        $companion = $this->makeCompanion($scene, 'the lantern watchman');
        $turn = $campaign->fresh()->currentTurn;

        // Never below zero.
        Companions::nudge($companion, -1);
        $this->assertSame(0, Companions::bond($companion));
        $this->assertSame(Companions::STRANGER, Companions::tier($companion));

        // The tiers derive from one place, and only one.
        $this->assertSame(Companions::STRANGER, Companions::tierFor(1));
        $this->assertSame(Companions::FELLOW, Companions::tierFor(2));
        $this->assertSame(Companions::FELLOW, Companions::tierFor(4));
        $this->assertSame(Companions::SWORN, Companions::tierFor(5));

        // One reason may only fire once per chapter: a turn where a request
        // landed AND the block it was part of turned a blow pays once.
        Companions::nudge($companion, 1, $turn, 'assist');
        Companions::nudge($companion, 1, $turn, 'assist');
        $this->assertSame(1, Companions::bond($companion));

        // ...and a different reason on the same turn is a different reason.
        Companions::nudge($companion, 1, $turn, 'campfire');
        $this->assertSame(2, Companions::bond($companion));
        $this->assertSame(Companions::FELLOW, Companions::tier($companion));

        // The ceiling holds.
        for ($i = 0; $i < 9; $i++) {
            Companions::nudge($companion, 1);
        }
        $this->assertSame(6, Companions::bond($companion));
        $this->assertSame(Companions::SWORN, Companions::tier($companion));

        // And once sworn, always sworn — the number falls, the tier does not.
        for ($i = 0; $i < 6; $i++) {
            Companions::nudge($companion, -1);
        }
        $this->assertSame(0, Companions::bond($companion));
        $this->assertSame(Companions::SWORN, Companions::tier($companion));
    }

    /**
     * Bond movement is fact-driven and nothing else. Every turn below carries
     * the player's own words on the request, and the words never move a point:
     * the beat that landed does, the beat that got them hurt does, and every
     * other turn leaves the number exactly where it was.
     */
    public function test_the_bond_moves_only_on_the_engine_events_that_earn_it()
    {
        $campaign = $this->createCampaign();
        $turn = $this->openBareTurn($campaign);
        $scene = $campaign->activeScene;

        $this->placeEnemy($scene, 'a dockside tough', health: 60, attack: 1);
        $companion = $this->makeCompanion($scene, 'the lantern watchman');
        $turn = $this->recompose($turn);

        $seen = ['landed' => 0, 'hurt' => 0, 'quiet' => 0];

        for ($i = 0; $i < 16; $i++) {
            $turn = $campaign->fresh()->currentTurn;
            if ($turn === null || ! $turn->isOpen()) {
                break;
            }

            // Keep the player's own body out of it: this test is about a bond.
            Meters::heal($campaign->character->fresh(), 20);

            $card = $this->requestCard($turn, $companion->id, 'companion_strike');
            if ($card === null) {
                break;
            }

            $before = $companion->fresh();
            $bondBefore = Companions::bond($before);
            $healthBefore = (int) $before->stats['health']['current'];

            $played = $this->play($turn, [
                'companions' => [(string) $companion->id => [
                    'card_id' => $card,
                    'modifiers' => [],
                    // Voice, and only voice. It reaches the chapter and never
                    // the ledger.
                    'note' => 'stay behind me if it turns',
                ]],
            ]);

            $beat = collect($played->resolution['beats'])->firstWhere('slot', 'companion');
            $after = $companion->fresh();
            $bondAfter = Companions::bond($after);

            if ((int) $after->stats['health']['current'] < $healthBefore) {
                $this->assertSame(max(0, $bondBefore - 1), $bondAfter,
                    'a request that got them hurt must dent the bond, and only dent it');
                $seen['hurt']++;
            } elseif (in_array($beat['degree'] ?? null, ['strong', 'success'], true)) {
                $this->assertSame(min(6, $bondBefore + 1), $bondAfter,
                    'a request that landed must earn exactly one point');
                $seen['landed']++;
            } else {
                $this->assertSame($bondBefore, $bondAfter,
                    'nothing but the listed engine events may move a bond');
                $seen['quiet']++;
            }

            $this->assertSame('stay behind me if it turns', $beat['note'] ?? null);
        }

        $this->assertGreaterThan(0, $seen['landed'], 'no request ever landed — the loop proved nothing');
        $this->assertGreaterThan(0, $seen['hurt'], 'no request ever cost them — the dent went untested');
    }

    /** A block that actually held is the clearest thing one person does for another. */
    public function test_a_block_that_turns_a_blow_earns_the_point()
    {
        $campaign = $this->createCampaign();
        $turn = $this->openBareTurn($campaign);
        $scene = $campaign->activeScene;

        $this->placeEnemy($scene, 'a dockside tough', health: 60, attack: 1);
        $companion = $this->makeCompanion($scene, 'the lantern watchman');
        $turn = $this->recompose($turn);

        $held = false;

        for ($i = 0; $i < 12 && ! $held; $i++) {
            $turn = $campaign->fresh()->currentTurn;
            if ($turn === null || ! $turn->isOpen()) {
                break;
            }
            Meters::heal($campaign->character->fresh(), 20);

            $card = $this->requestCard($turn, $companion->id, 'companion_block');
            if ($card === null) {
                break;
            }

            $bondBefore = Companions::bond($companion->fresh());
            $played = $this->play($turn, [
                'companions' => [(string) $companion->id => ['card_id' => $card, 'modifiers' => []]],
            ]);

            $reaction = implode(' ', $played->resolution['scene_reaction'] ?? []);
            if (str_contains($reaction, 'held at bay and never reached them')) {
                $held = true;
                $this->assertSame($bondBefore + 1, Companions::bond($companion->fresh()));
            }
        }

        $this->assertTrue($held, 'the line never held once — the block save went untested');
    }

    /** New country walked together is the one point nobody has to survive anything for. */
    public function test_crossing_into_new_country_together_earns_a_point()
    {
        $campaign = $this->createCampaign();
        $turn = $this->openBareTurn($campaign);
        $scene = $campaign->activeScene;

        $companion = $this->makeCompanion($scene, 'the lantern watchman');

        $frontier = Zone::create([
            'campaign_id' => $campaign->id,
            'slug' => 'the-far-shelf',
            'name' => 'The Far Shelf',
            'description' => 'Country nobody in this tale has walked yet.',
            'tags' => [],
            'source' => 'forge',
        ]);
        $campaign->update(['next_zone_id' => $frontier->id]);

        $turn = $this->recompose($turn);

        for ($i = 0; $i < 12; $i++) {
            $turn = $campaign->fresh()->currentTurn;
            if ($turn === null || ! $turn->isOpen()) {
                break;
            }
            if ($campaign->fresh()->activeScene->zone_id === $frontier->id) {
                break;
            }
            Meters::heal($campaign->character->fresh(), 20);

            $card = $this->mainCard($turn, 'venture');
            if ($card === null) {
                break;
            }
            $this->play($turn, ['main' => ['card_id' => $card, 'modifiers' => ['approach' => 'balanced']]]);
        }

        $this->assertSame($frontier->id, $campaign->fresh()->activeScene->zone_id);
        $this->assertSame($campaign->fresh()->activeScene->id, $companion->fresh()->scene_id);
        $this->assertGreaterThanOrEqual(1, Companions::bond($companion->fresh()),
            'the road itself moved nobody');
    }

    /** Two is the cap, and a third candidate's offer simply never fires. */
    public function test_the_cap_holds_against_every_path()
    {
        $campaign = $this->createCampaign();
        $turn = $this->openBareTurn($campaign);
        $scene = $campaign->activeScene;

        $this->makeCompanion($scene, 'the lantern watchman');
        $this->makeCompanion($scene, 'the wharf dog');
        $this->assertTrue(Companions::atCap($scene->fresh()));

        // The stray path: silent while full.
        $stray = $this->placeBystander($scene, 'a soaked stevedore', [
            'following' => true, 'witnessed' => true, 'stray_scenes' => 5,
        ]);
        $this->assertNull(Companions::maybeOfferStray($scene->fresh()));
        $this->assertArrayNotHasKey('offering', $stray->fresh()->tags);

        // The grateful path: silent while full.
        $freed = $this->placeBystander($scene, 'the lamp-trimmer');
        $this->assertNull(Companions::maybeOfferGrateful($scene->fresh(), new Dice(1), [$freed->id]));
        $this->assertArrayNotHasKey('offering', $freed->fresh()->tags);

        // And a spawned soul never takes an interest while there is no room.
        config(['game.companions.stray_chance' => 1.0]);
        $this->assertFalse(Companions::markStray($this->placeBystander($scene, 'a gull-picker'), $scene->fresh()));
    }

    // ---------------------------------------------------------- the signature

    /**
     * The signature is picked once, from the table keyed to what they ARE, and
     * it never changes afterwards. A companion whose one distinctive request
     * shifted between chapters would not read as a person at all.
     */
    public function test_the_signature_is_picked_once_from_the_right_table()
    {
        $campaign = $this->createCampaign();
        $turn = $this->openBareTurn($campaign);
        $scene = $campaign->activeScene;

        $dice = new Dice(7);

        // A talker: only the distract row claims a trader.
        $talker = $this->makeCompanion($scene, 'the ferry clerk', bond: 2, tags: ['was_kind' => 'trader']);
        $this->assertSame(Companions::DISTRACT, Companions::ensureSignature($talker, $dice));

        // A scout-ish soul: only the forage row claims a tracker.
        $tracker = $this->makeCompanion($scene, 'the tide-reader', bond: 2, tags: ['was_kind' => 'unlisted', 'tracker' => true]);
        $this->assertSame(Companions::FORAGE, Companions::ensureSignature($tracker, $dice));

        // A stranger has not earned one, and asking does not give them one.
        $newcomer = $this->makeCompanion($scene, 'the deckhand', bond: 1, tags: ['was_kind' => 'trader']);
        $this->assertNull(Companions::ensureSignature($newcomer, $dice));
        $this->assertArrayNotHasKey('signature', $newcomer->fresh()->tags);

        // Once picked, it is theirs: a second pass never re-rolls it.
        $again = Companions::ensureSignature($talker->fresh(), new Dice(999));
        $this->assertSame(Companions::DISTRACT, $again);
        $this->assertSame(Companions::DISTRACT, $talker->fresh()->tags['signature']);
    }

    /**
     * The signature is one more REQUEST in the companion's own slot — never a
     * card in the player's chain, and never something the player performs. It
     * appears at fellow and not before.
     */
    public function test_the_signature_card_appears_at_fellow_and_stays_a_request()
    {
        $campaign = $this->createCampaign();
        $turn = $this->openBareTurn($campaign);
        $scene = $campaign->activeScene;

        $this->placeEnemy($scene, 'a dockside tough');
        $companion = $this->makeCompanion($scene, 'the ferry clerk', bond: 1, tags: ['was_kind' => 'trader']);

        $cards = app(CardComposer::class)->compose($campaign->character->fresh(), $scene->fresh());
        $entry = collect($cards['companions'])->firstWhere('id', $companion->id);
        $this->assertNotContains(Companions::DISTRACT, collect($entry['cards'])->pluck('verb')->all());

        // Fellow, and the signature is picked and offered.
        Companions::nudge($companion, 1);
        Companions::ensureSignature($companion->fresh(), new Dice(3));

        $cards = app(CardComposer::class)->compose($campaign->character->fresh(), $scene->fresh());
        $entry = collect($cards['companions'])->firstWhere('id', $companion->id);
        $signature = collect($entry['cards'])->firstWhere('verb', Companions::DISTRACT);

        $this->assertNotNull($signature, 'the fellow never got their signature');
        $this->assertSame('companion', $signature['slot']);

        foreach (['pre', 'main', 'post'] as $slot) {
            $this->assertEmpty(
                collect($cards[$slot])->filter(fn ($c) => in_array($c['verb'], Companions::REQUEST_VERBS, true)),
                "a companion request must never occupy the player's {$slot} slot",
            );
        }
    }

    /** What the signature actually does, once it lands: the existing vocabulary, moved. */
    public function test_the_distract_signature_takes_an_enemy_off_their_windup()
    {
        $campaign = $this->createCampaign();
        $turn = $this->openBareTurn($campaign);
        $scene = $campaign->activeScene;

        $enemy = $this->placeEnemy($scene, 'a dockside tough', health: 60);
        $enemy->update(['tags' => ['intent' => 'windup']]);
        $companion = $this->makeCompanion($scene, 'the ferry clerk', bond: 2, tags: [
            'was_kind' => 'trader', 'signature' => Companions::DISTRACT,
        ]);
        $turn = $this->recompose($turn);

        $pulled = false;

        for ($i = 0; $i < 12 && ! $pulled; $i++) {
            $turn = $campaign->fresh()->currentTurn;
            if ($turn === null || ! $turn->isOpen()) {
                break;
            }
            Meters::heal($campaign->character->fresh(), 20);
            $enemy->update(['tags' => array_merge($enemy->fresh()->tags, ['intent' => 'windup'])]);

            $card = $this->requestCard($turn, $companion->id, Companions::DISTRACT);
            if ($card === null) {
                break;
            }

            $played = $this->play($turn, [
                'companions' => [(string) $companion->id => ['card_id' => $card, 'modifiers' => []]],
            ]);

            $beat = collect($played->resolution['beats'])->firstWhere('verb', Companions::DISTRACT);
            if (in_array($beat['degree'] ?? null, ['strong', 'success'], true)) {
                $pulled = true;
                $this->assertStringContainsString('came apart half-made', implode(' ', $beat['facts']));
            }
        }

        $this->assertTrue($pulled, 'the signature never landed once');
    }

    // -------------------------------------------------------- the interception

    /**
     * Only at would-be-zero, only from a sworn, only once per chapter — and the
     * damage lands on THEM. This is the emotional payload of the whole system,
     * and every one of those gates is what keeps it from being a shield.
     */
    public function test_the_interception_fires_only_at_would_be_zero_and_only_from_a_sworn()
    {
        $campaign = $this->createCampaign();
        $turn = $this->openBareTurn($campaign);
        $scene = $campaign->activeScene;
        $character = $campaign->character->fresh();

        Meters::damage($character, 7); // three left
        $character = $character->fresh();

        $fellow = $this->makeCompanion($scene, 'the deckhand', bond: 3, health: 12);

        // A fellow does not do this. It is what sworn MEANS.
        $this->assertNull(Companions::intercept($character, $scene->fresh(), $turn, 5));

        $sworn = $this->makeCompanion($scene, 'the lantern watchman', bond: 5, health: 12);

        // A blow they would survive is theirs to take.
        $this->assertNull(Companions::intercept($character, $scene->fresh(), $turn, 2));
        $this->assertSame(3, $character->fresh()->meters['health']['current']);

        // A blow that would finish it is not.
        $taken = Companions::intercept($character, $scene->fresh(), $turn, 4);
        $this->assertNotNull($taken);
        $this->assertSame('the lantern watchman', $taken['companion']);
        $this->assertFalse($taken['downed']);

        // Rerouted, not shared: the player is untouched, the companion is not.
        $this->assertSame(3, $character->fresh()->meters['health']['current']);
        $this->assertSame('alive', $character->fresh()->status);
        $this->assertSame(8, (int) $sworn->fresh()->stats['health']['current']);
        $this->assertSame(12, (int) $fellow->fresh()->stats['health']['current']);

        // Once per chapter. The second blow of the same chapter lands on them.
        $this->assertNull(Companions::intercept($character->fresh(), $scene->fresh(), $turn, 9));
    }

    /**
     * And in play: the blow that would have put them on the floor lands on
     * somebody else, so there is no fall for the scar path to roll against.
     * The interception fires BEFORE that path, which is the whole ordering
     * claim — a scar taken through a sworn companion's chest is not a scar.
     */
    public function test_an_interception_keeps_the_player_off_the_floor_and_out_of_the_scar_path()
    {
        $campaign = $this->createCampaign();
        $turn = $this->openBareTurn($campaign);
        $scene = $campaign->activeScene;

        $this->placeEnemy($scene, 'a dockside tough', health: 60, attack: 2);
        $sworn = $this->makeCompanion($scene, 'the lantern watchman', bond: 5, health: 80);
        $turn = $this->recompose($turn);

        // One health left: every blow that lands is a blow that would finish it.
        Meters::damage($campaign->character->fresh(), 9);
        $this->assertSame(1, $campaign->character->fresh()->meters['health']['current']);

        $intercepted = null;

        for ($i = 0; $i < 14 && $intercepted === null; $i++) {
            $turn = $campaign->fresh()->currentTurn;
            if ($turn === null || ! $turn->isOpen()) {
                break;
            }

            $played = $this->play($turn);
            $intercepted = $played->resolution['companions']['interception'] ?? null;

            // Whatever happened, the fall never did.
            $this->assertNull($played->resolution['fall'], 'the scar path fired despite a sworn companion standing there');
        }

        $this->assertNotNull($intercepted, 'nobody ever swung hard enough to test the interception');
        $this->assertSame('the lantern watchman', $intercepted['companion']);
        $this->assertSame('alive', $campaign->character->fresh()->status);
        $this->assertSame(1, $campaign->character->fresh()->meters['health']['current']);
        $this->assertLessThan(80, (int) $sworn->fresh()->stats['health']['current']);
        $this->assertCount(0, $campaign->character->fresh()->constraints()->where('source', 'scar')->get());
    }

    // -------------------------------------------------------------- campfires

    /** A fire is only on offer when there is somebody to share it with. */
    public function test_the_fire_is_offered_only_with_company()
    {
        $campaign = $this->createCampaign();
        $this->openBareTurn($campaign);
        $scene = $campaign->activeScene;

        $this->assertNotContains(Downtime::FIRE, array_column(Downtime::offer($scene->fresh())['offer'], 'id'));

        $this->makeCompanion($scene, 'the lantern watchman');
        $offer = Downtime::offer($scene->fresh())['offer'];

        $this->assertContains(Downtime::FIRE, array_column($offer, 'id'));
        $fire = collect($offer)->firstWhere('id', Downtime::FIRE);
        $this->assertNotEmpty($fire['note'], 'the fire must take the player\'s own words');
    }

    /**
     * Half the sleep, one point of bond to the companion who needed it most,
     * and one plain sentence of fact for the chapter. The player's own words
     * ride along as colour and touch nothing.
     */
    public function test_a_shared_fire_pays_half_the_rest_and_a_point_to_the_lowest_bond()
    {
        $campaign = $this->createCampaign();
        $turn = $this->openBareTurn($campaign);
        $scene = $campaign->activeScene;

        $close = $this->makeCompanion($scene, 'the lantern watchman', bond: 3);
        $newer = $this->makeCompanion($scene, 'the deckhand', bond: 1);

        Meters::damage($campaign->character->fresh(), 9);
        $turn = $this->recompose($turn);

        Downtime::choose($turn, Downtime::FIRE, now()->subMinutes(8 * 60), 'ask them why they stayed');
        $played = $this->play($turn->fresh());

        // Half of rest's eight hours.
        $this->assertSame(4, $played->downtime['payout']['healed']);
        $this->assertSame('the deckhand', $played->downtime['payout']['shared_with']);

        // The point lands on the one the fire was actually for.
        $this->assertSame(2, Companions::bond($newer->fresh()));
        $this->assertSame(3, Companions::bond($close->fresh()));

        $campfire = $played->resolution['companions']['campfire'];
        $this->assertSame('the deckhand', $campfire['companion']);
        $this->assertStringContainsString('the deckhand', $campfire['fact']);
        $this->assertSame('ask them why they stayed', $campfire['note']);

        // No mechanics language, and no numbers, reach the page.
        $this->assertDoesNotMatchRegularExpression('/\d/', $campfire['fact']);
        foreach (['bond', 'stance', 'health', 'roll'] as $word) {
            $this->assertStringNotContainsStringIgnoringCase($word, $campfire['fact']);
        }
    }

    /** The fire reaches the narrator as one quiet paragraph's worth of instruction, never a scene. */
    public function test_the_narrator_is_told_about_the_fire_in_plain_words()
    {
        $campaign = $this->createCampaign(narrates: true);
        $turn = $this->openBareTurn($campaign);
        $scene = $campaign->activeScene;

        $this->makeCompanion($scene, 'the lantern watchman', bond: 5);
        $turn = $this->recompose($turn);

        Downtime::choose($turn, Downtime::FIRE, now()->subMinutes(4 * 60), 'tell them about the harbor');
        $played = $this->play($turn->fresh());

        app(Narrator::class)->narrate($played->fresh());

        $prompt = collect($this->prompts)->first(fn (string $p) => str_contains($p, 'You are the narrator'));
        $this->assertNotNull($prompt);
        $this->assertStringContainsString('## The people walking with them', $prompt);
        $this->assertStringContainsString('the lantern watchman', $prompt);
        $this->assertStringContainsString('bled for them once', $prompt);
        $this->assertStringContainsString('ONE quiet paragraph', $prompt);
        $this->assertStringContainsString('tell them about the harbor', $prompt);

        // The tier is words. The number never leaves the engine.
        $this->assertStringNotContainsString('bond', $prompt);
    }

    // ------------------------------------------- companions the road provides

    /**
     * The grateful: only ever off a rescue the engine can read in its own
     * facts, only once at a time, and always ending in a pair the player
     * answers rather than a companion appearing beside them.
     */
    public function test_the_grateful_offer_needs_a_genuine_rescue()
    {
        $campaign = $this->createCampaign();
        $this->openBareTurn($campaign);
        $scene = $campaign->activeScene;

        $freed = $this->placeBystander($scene, 'the lamp-trimmer');

        // No rescue in the facts, no offer — whatever the dice would have said.
        $this->assertNull(Companions::maybeOfferGrateful($scene->fresh(), new Dice(1), []));
        $this->assertArrayNotHasKey('offering', $freed->fresh()->tags);

        // A rescue, and the roll lands.
        $asking = $this->rollUntilGrateful($scene, [$freed->id]);
        $this->assertNotNull($asking, 'the grateful path never fired across any seed');
        $this->assertSame(Companions::GRATEFUL, $asking->tags['offering']);

        // And one at a time: nobody else asks while an answer is outstanding.
        $second = $this->placeBystander($scene, 'the rope-splicer');
        $this->assertNull(Companions::maybeOfferGrateful($scene->fresh(), new Dice(1), [$second->id]));
        $this->assertArrayNotHasKey('offering', $second->fresh()->tags);
    }

    /** Roll the grateful path across seeds until the engine says yes. */
    private function rollUntilGrateful(Scene $scene, array $rescued): ?Actor
    {
        for ($seed = 1; $seed < 40; $seed++) {
            $asking = Companions::maybeOfferGrateful($scene->fresh(), new Dice($seed), $rescued);
            if ($asking !== null) {
                return $asking;
            }
        }

        return null;
    }

    /**
     * The offer pair is two ordinary main-slot cards, validated exactly as
     * every other card is. Saying yes puts them beside you; saying no parts
     * well and leaves one true thing behind — never a dead choice.
     */
    public function test_the_offer_pair_is_answered_like_any_other_card()
    {
        $campaign = $this->createCampaign();
        $turn = $this->openBareTurn($campaign);
        $scene = $campaign->activeScene;

        $asking = $this->placeBystander($scene, 'the lamp-trimmer', ['offering' => Companions::GRATEFUL]);
        $turn = $this->recompose($turn);

        $welcome = $this->mainCard($turn, 'companion_welcome', $asking->id);
        $sendAway = $this->mainCard($turn, 'companion_dismiss', $asking->id);
        $this->assertNotNull($welcome);
        $this->assertNotNull($sendAway);

        // Nothing else is on the table with them: the answer must be a decision.
        $theirs = collect($turn->cards['main'])->filter(
            fn (array $c) => ($c['target']['id'] ?? null) === $asking->id,
        )->pluck('verb')->unique()->sort()->values()->all();
        $this->assertSame(['companion_dismiss', 'companion_welcome', 'improvise'], $theirs);

        // A card the engine never offered is never resolved.
        $this->actingAs($campaign->user)
            ->post(route('play.submit', $campaign), ['main' => ['card_id' => 'forged']])
            ->assertSessionHasErrors('main');

        // The honest yes.
        $this->actingAs($campaign->user)
            ->post(route('play.submit', $campaign), ['main' => ['card_id' => $welcome, 'modifiers' => []]])
            ->assertRedirect();

        $joined = $asking->fresh();
        $this->assertSame('companion', $joined->kind);
        $this->assertSame(Companions::GRATEFUL, $joined->tags['joined_via']);
        $this->assertSame(0, Companions::bond($joined));
        $this->assertSame(Companions::STRANGER, Companions::tier($joined));
        $this->assertArrayNotHasKey('offering', $joined->tags);
    }

    /** Sending them on their way is a real choice: they go, and they leave something true. */
    public function test_sending_them_on_their_way_leaves_a_colour_fact_and_no_companion()
    {
        $campaign = $this->createCampaign();
        $turn = $this->openBareTurn($campaign);
        $scene = $campaign->activeScene;

        $asking = $this->placeBystander($scene, 'the lamp-trimmer', ['offering' => Companions::STRAY]);
        $turn = $this->recompose($turn);

        $played = $this->play($turn, [
            'main' => ['card_id' => $this->mainCard($turn, 'companion_dismiss', $asking->id), 'modifiers' => []],
        ]);

        $gone = $asking->fresh();
        $this->assertSame('npc', $gone->kind);
        $this->assertSame('departed', $gone->status);
        $this->assertSame(0, Companions::beside($scene->fresh())->count());

        $parted = $played->resolution['companions']['parted'];
        $this->assertCount(2, $parted);
        $this->assertStringContainsString('sent them on their way', $parted[0]);
        $this->assertNotSame('', trim($parted[1]));
    }

    /** The stray rate is config, and it never touches anyone hostile. */
    public function test_the_stray_spawn_rate_is_config_and_never_takes_an_enemy()
    {
        $campaign = $this->createCampaign();
        $this->openBareTurn($campaign);
        $scene = $campaign->activeScene->fresh();

        config(['game.companions.stray_chance' => 1.0]);
        $sure = $this->placeBystander($scene, 'a soaked stevedore');
        $this->assertTrue(Companions::markStray($sure, $scene));
        $this->assertTrue($sure->fresh()->tags['following']);
        $this->assertSame(1, $sure->fresh()->tags['stray_scenes']);
        $this->assertFalse($sure->fresh()->tags['witnessed']);

        // Never a hostile one, whatever the odds say.
        $this->assertFalse(Companions::markStray($this->placeEnemy($scene, 'a dockside tough'), $scene));

        config(['game.companions.stray_chance' => 0.0]);
        $never = $this->placeBystander($scene, 'a gull-picker');
        $this->assertFalse(Companions::markStray($never, $scene));
        $this->assertArrayNotHasKey('following', $never->fresh()->tags);
    }

    /**
     * A stray keeps to the edge: no companion slot, no craft worked on them,
     * nothing but the plain word anyone gets — and it walks transitions the way
     * a companion does.
     */
    public function test_a_stray_keeps_to_the_edge_and_walks_the_transitions()
    {
        $campaign = $this->createCampaign();
        $turn = $this->openBareTurn($campaign);
        $scene = $campaign->activeScene;

        $stray = $this->placeBystander($scene, 'a soaked stevedore', [
            'following' => true, 'stray_scenes' => 1, 'witnessed' => false,
            'companionable' => true, 'talkable' => true,
        ]);
        $turn = $this->recompose($turn);

        // No companion slot of their own, and no recruit card: a stray is
        // scenery with an opinion until it decides otherwise.
        $this->assertEmpty(collect($turn->cards['companions'])->firstWhere('id', $stray->id) ?? []);
        $theirs = collect($turn->cards['main'])->filter(
            fn (array $c) => ($c['target']['id'] ?? null) === $stray->id,
        )->pluck('verb')->unique()->sort()->values()->all();
        $this->assertSame(['improvise', 'speak'], $theirs);

        // The board says what they are without being asked.
        $board = SituationBoard::for($campaign->character->fresh(), $scene->fresh());
        $others = collect($board)->firstWhere('key', 'others');
        $this->assertContains('a soaked stevedore — keeping near you', $others['items']);

        // And they follow.
        $before = $scene->id;
        $moved = $this->crossToNewGround($campaign);

        $this->assertNotSame($before, $moved->id, 'the scene never turned — the walk went untested');
        $this->assertSame($moved->id, $stray->fresh()->scene_id);
        $this->assertGreaterThanOrEqual(2, (int) $stray->fresh()->tags['stray_scenes']);
    }

    /**
     * A stray converts only after it has watched something of the player's
     * actually work AND walked far enough to mean it. Neither alone is enough,
     * and a stray nobody ever answers simply stays a stray.
     */
    public function test_a_stray_asks_only_after_a_witnessed_success_and_enough_road()
    {
        $campaign = $this->createCampaign();
        $turn = $this->openBareTurn($campaign);
        $scene = $campaign->activeScene;

        $stray = $this->placeBystander($scene, 'a soaked stevedore', [
            'following' => true, 'stray_scenes' => 1, 'witnessed' => false,
        ]);

        // Road, but nothing seen.
        $stray->update(['tags' => array_merge($stray->tags, ['stray_scenes' => 4])]);
        $this->assertNull(Companions::maybeOfferStray($scene->fresh()));

        // Seen, but no road.
        $stray->update(['tags' => array_merge($stray->tags, ['stray_scenes' => 1, 'witnessed' => true])]);
        $this->assertNull(Companions::maybeOfferStray($scene->fresh()));

        // Failure teaches them nothing, either.
        $stray->update(['tags' => array_merge($stray->tags, ['witnessed' => false, 'stray_scenes' => 4])]);
        Companions::witness($scene->fresh(), false);
        $this->assertFalse($stray->fresh()->tags['witnessed']);

        // Something that worked, in front of them.
        Companions::witness($scene->fresh(), true);
        $this->assertTrue($stray->fresh()->tags['witnessed']);

        $asking = Companions::maybeOfferStray($scene->fresh());
        $this->assertNotNull($asking);
        $this->assertSame(Companions::STRAY, $asking->tags['offering']);

        // And ignoring the question changes nothing at all: they are still not
        // a companion, and the tale is under no obligation to them.
        $turn = $this->recompose($campaign->fresh()->currentTurn);
        $this->play($turn);

        $this->assertSame('npc', $stray->fresh()->kind);
        $this->assertSame(0, Companions::beside($campaign->fresh()->activeScene)->count());
    }

    // ------------------------------------------------------------------- loss

    /**
     * Scene exit decides it, and the bond is what it is decided against: a
     * stranger takes stock and goes; a fellow gets up and walks on at one
     * health; a sworn who went down taking a blow meant for the player, in a
     * fight the player did not win, may not get up at all.
     */
    public function test_the_loss_roll_respects_the_tier()
    {
        $campaign = $this->createCampaign();
        $this->openBareTurn($campaign);
        $scene = $campaign->activeScene;

        $stranger = $this->makeCompanion($scene, 'the deckhand', bond: 1);
        $fellow = $this->makeCompanion($scene, 'the lantern watchman', bond: 3);
        foreach ([$stranger, $fellow] as $down) {
            $stats = $down->stats;
            $stats['health']['current'] = 0;
            $down->update(['stats' => $stats, 'status' => 'downed']);
        }

        // On the board while the scene lasts — they do not vanish mid-fight.
        $board = SituationBoard::for($campaign->character->fresh(), $scene->fresh());
        $allies = collect($board)->firstWhere('key', 'allies');
        $this->assertContains('the deckhand — down, breathing', $allies['items']);

        $out = Companions::resolveDowned($scene->fresh(), new Dice(5), fightLost: false);

        $this->assertSame('departed', $stranger->fresh()->status);
        $this->assertSame(['the deckhand'], $out['lost']);

        $this->assertSame('active', $fellow->fresh()->status);
        $this->assertSame(1, (int) $fellow->fresh()->stats['health']['current']);

        // A sworn who stepped in front of it, in a fight that was lost.
        $sworn = $this->makeCompanion($scene, 'the wharf dog', bond: 5);
        $stats = $sworn->stats;
        $stats['health']['current'] = 0;
        $sworn->update(['stats' => $stats, 'status' => 'downed',
            'tags' => array_merge($sworn->tags, ['intercepted_fall' => true])]);

        config(['game.companions.sworn_final_chance' => 1.0]);
        $final = Companions::resolveDowned($scene->fresh(), new Dice(5), fightLost: true);

        $this->assertSame('dead', $sworn->fresh()->status);
        $this->assertSame(['the wharf dog'], $final['lost']);

        // ...and a fight that was won never takes them.
        $other = $this->makeCompanion($scene, 'the rope-splicer', bond: 5);
        $stats = $other->stats;
        $stats['health']['current'] = 0;
        $other->update(['stats' => $stats, 'status' => 'downed',
            'tags' => array_merge($other->tags, ['intercepted_fall' => true])]);

        Companions::resolveDowned($scene->fresh(), new Dice(5), fightLost: false);
        $this->assertSame('active', $other->fresh()->status);
    }

    /**
     * Losing somebody leaves something on the shelf, and the name never walks
     * back into the tale from any direction.
     */
    public function test_a_lost_companion_mints_a_keepsake_and_never_respawns()
    {
        $campaign = $this->createCampaign();
        $turn = $this->openBareTurn($campaign);
        $scene = $campaign->activeScene;

        $lost = $this->makeCompanion($scene, 'the deckhand', bond: 1);
        $stats = $lost->stats;
        $stats['health']['current'] = 0;
        $lost->update(['stats' => $stats, 'status' => 'downed']);

        $this->crossToNewGround($campaign);

        $this->assertSame('departed', $lost->fresh()->status);

        $memento = Memento::where('campaign_id', $campaign->id)->where('trigger', 'companion_lost')->first();
        $this->assertNotNull($memento, 'somebody was lost and the tale kept nothing of them');
        $this->assertSame('the deckhand', $memento->subject);
        $this->assertStringContainsString('deckhand', "{$memento->name} {$memento->line}");

        // And the road never provides that name a second time.
        config(['game.companions.stray_chance' => 1.0]);
        $again = $this->placeBystander($campaign->fresh()->activeScene, 'the deckhand');
        $this->assertFalse(Companions::markStray($again, $campaign->fresh()->activeScene));
        $this->assertNull(Companions::maybeOfferGrateful(
            $campaign->fresh()->activeScene, new Dice(1), [$again->id],
        ));
    }

    // ------------------------------------------------------------ reading out

    /** The board says the tier in words. It never says the number. */
    public function test_the_board_says_the_tier_in_plain_words_and_never_the_number()
    {
        $campaign = $this->createCampaign();
        $this->openBareTurn($campaign);
        $scene = $campaign->activeScene;

        $this->makeCompanion($scene, 'the deckhand', bond: 0);
        $this->makeCompanion($scene, 'the lantern watchman', bond: 5);

        $board = SituationBoard::for($campaign->character->fresh(), $scene->fresh());
        $allies = collect($board)->firstWhere('key', 'allies');

        $this->assertSame([
            'the deckhand',
            'the lantern watchman — sworn to you',
        ], $allies['items']);

        $prose = SituationBoard::prose($board);
        $this->assertStringContainsString('sworn to you', $prose);
        foreach (['bond', 'tier', 'fellow 5'] as $mechanic) {
            $this->assertStringNotContainsStringIgnoringCase($mechanic, implode(' ', $allies['items']));
        }
    }

    /**
     * The whole feature is engine work. Claude falling over costs the player
     * words and nothing else: the bond moved, the fire was shared, the offer
     * stands, and the shelf has what it should.
     */
    public function test_everything_survives_an_evening_claude_is_down()
    {
        $campaign = $this->createCampaign(); // promptForJson throws, always
        $turn = $this->openBareTurn($campaign);
        $scene = $campaign->activeScene;

        $companion = $this->makeCompanion($scene, 'the lantern watchman', bond: 1);
        $asking = $this->placeBystander($scene, 'the lamp-trimmer', ['offering' => Companions::GRATEFUL]);

        Meters::damage($campaign->character->fresh(), 6);
        $turn = $this->recompose($turn);

        Downtime::choose($turn, Downtime::FIRE, now()->subMinutes(6 * 60), 'thank them properly');
        $played = $this->play($turn->fresh(), [
            'main' => ['card_id' => $this->mainCard($turn->fresh(), 'companion_welcome', $asking->id), 'modifiers' => []],
        ]);

        // The fire paid out, the bond moved, and the yes took.
        $this->assertSame(2, Companions::bond($companion->fresh()));
        $this->assertSame(Companions::FELLOW, Companions::tier($companion->fresh()));
        $this->assertNotNull($played->resolution['companions']['campfire']);
        $this->assertNotEmpty($played->resolution['companions']['joined']);
        $this->assertSame('companion', $asking->fresh()->kind);

        // The signature was picked by the engine, with no help from anyone.
        $this->assertContains($companion->fresh()->tags['signature'],
            [Companions::HARRY, Companions::DISTRACT, Companions::FORAGE]);

        // And no chapter was ever written.
        $this->assertSame(0, $campaign->chapters()->where('kind', 'chapter')->count());
    }

    /** The engine, not the fiction, hands out signatures — and the trio stays capability-free. */
    public function test_a_forage_signature_reveals_what_the_ground_kept()
    {
        $campaign = $this->createCampaign();
        $turn = $this->openBareTurn($campaign);
        $scene = $campaign->activeScene;

        $scene->features()->delete();
        $hidden = SceneFeature::create([
            'scene_id' => $scene->id,
            'zone_id' => $scene->zone_id,
            'name' => 'a boarded hatch',
            'feature_type' => 'cover',
            'affordances' => ['hideable' => true],
            'state' => ['hidden' => true],
            'source' => 'seed',
        ]);

        $companion = $this->makeCompanion($scene, 'the tide-reader', bond: 2, tags: [
            'was_kind' => 'unlisted', 'tracker' => true, 'signature' => Companions::FORAGE,
        ]);
        $turn = $this->recompose($turn);

        $found = false;

        for ($i = 0; $i < 12 && ! $found; $i++) {
            $turn = $campaign->fresh()->currentTurn;
            if ($turn === null || ! $turn->isOpen()) {
                break;
            }
            $card = $this->requestCard($turn, $companion->id, Companions::FORAGE);
            if ($card === null) {
                break;
            }
            $this->play($turn, [
                'companions' => [(string) $companion->id => ['card_id' => $card, 'modifiers' => []]],
            ]);
            $found = ! ($hidden->fresh()->state['hidden'] ?? false);
        }

        $this->assertTrue($found, 'the forage signature never turned anything up');

        // And with nothing left hidden it stops being offered — a card that can
        // do nothing is a dead choice.
        $cards = app(CardComposer::class)->compose(
            $campaign->character->fresh(), $campaign->fresh()->activeScene,
        );
        $entry = collect($cards['companions'])->firstWhere('id', $companion->id);
        $this->assertNotContains(Companions::FORAGE, collect($entry['cards'] ?? [])->pluck('verb')->all());
    }

    /**
     * Asking somebody along is what a conversation can be FOR.
     *
     * It used to be a second verb standing beside "Speak with them" under the
     * board's SPEAK word, which put two entries there for what the player reads
     * as one act. Now it is a chip on the conversation — still an engine-offered,
     * engine-validated option, because a note colours the telling and can never
     * reach a mechanic, and "I ask them to join me" typed into a box has to stay
     * exactly as inert as every other note in the game.
     */
    public function test_asking_someone_along_is_an_intent_on_the_conversation()
    {
        $campaign = $this->createCampaign();
        $turn = $this->openBareTurn($campaign);
        $scene = $campaign->activeScene;

        $willing = $this->placeBystander($scene, 'the lamp-trimmer', ['companionable' => true]);
        $turn = $this->recompose($turn);

        // One card, not two: no recruit verb anywhere on the offer.
        $this->assertNull($this->mainCard($turn, 'recruit', $willing->id));

        $talk = collect($turn->cards['main'])->first(
            fn (array $c) => $c['verb'] === 'speak' && ($c['target']['id'] ?? null) === $willing->id,
        );
        $this->assertNotNull($talk);

        $intent = collect($talk['modifiers'])->firstWhere('key', 'intent');
        $this->assertNotNull($intent, 'the conversation never offered anything to ask for');
        $this->assertSame(['talk', 'recruit'], array_column($intent['options'], 'value'));

        // Both readings are priced identically, which is what makes the chip
        // honest: the DC on the card is the DC either answer is measured against.
        $this->assertSame(
            $talk['forecast']['difficulty'],
            $talk['forecast']['stances']['balanced'],
        );

        // Roll until the ask lands: the engine decides, and a refusal is a real
        // outcome rather than a bug.
        $joined = false;
        for ($attempt = 0; $attempt < 12 && ! $joined; $attempt++) {
            $open = $campaign->fresh()->currentTurn;

            if ($open === null) {
                break;
            }

            $card = $this->mainCard($this->recompose($open), 'speak', $willing->id);

            if ($card === null) {
                break;
            }

            $this->play($open->fresh(), [
                'main' => ['card_id' => $card, 'modifiers' => ['intent' => 'recruit']],
            ]);

            $joined = $willing->fresh()->kind === 'companion';
        }

        $this->assertTrue($joined, 'the ask never landed');

        // The same one door every companion comes through, whichever card
        // reached it: bond zero, stranger, and a joined_via that says how.
        $companion = $willing->fresh();
        $this->assertSame(Companions::ASKED, $companion->tags['joined_via']);
        $this->assertSame(0, Companions::bond($companion));
        $this->assertSame(Companions::STRANGER, Companions::tier($companion));
    }

    /**
     * Somebody who was already there when the tale started — and paid for.
     *
     * A companion is a whole extra beat every turn, so a crew, a friend, or a dog
     * that walked out of the interview for free would be the one gift in the game
     * nobody was priced for. They cost points on the same ledger a long reach
     * comes off, they are on their feet in the opening scene (with their own
     * request slot on the very first turn), and they start at bond zero like
     * everyone else: a long history is a story fact, and story facts never move
     * numbers.
     */
    public function test_a_companion_bought_at_creation_walks_in_with_them()
    {
        $campaign = $this->createCampaign();

        $turn = app(TurnStarter::class)->openFirstTurn($campaign, null, [
            ['name' => 'Bosun', 'kind' => 'npc', 'what' => 'Sailed with them for years.'],
        ]);

        $scene = $campaign->activeScene;
        $bosun = $scene->actors()->where('name', 'Bosun')->first();

        $this->assertNotNull($bosun, 'nobody walked in with them');
        $this->assertSame('companion', $bosun->kind);
        $this->assertSame(Companions::ORIGIN, $bosun->tags['joined_via']);
        $this->assertSame(0, Companions::bond($bosun));
        $this->assertSame(Companions::STRANGER, Companions::tier($bosun));

        // Their own request slot, on turn one. A companion the player paid for
        // and then could not ask anything of until turn two would read as the
        // sheet lying to them.
        $entry = collect($turn->cards['companions'])->firstWhere('id', $bosun->id);
        $this->assertNotNull($entry);
        $this->assertNotEmpty($entry['cards']);

        // The board says who is beside them, in plain words and never a number.
        $board = collect(SituationBoard::for($campaign->character->fresh(), $scene->fresh()))
            ->firstWhere('key', 'allies');
        $this->assertNotNull($board);
        $this->assertStringContainsString('Bosun', implode(' ', $board['items']));
    }

    /** Priced like a gift, capped below the party cap, and never Claude's to invent. */
    public function test_a_starting_companion_is_priced_and_capped()
    {
        $cost = TraitCatalog::companionCost();
        $this->assertGreaterThan(0, $cost, 'a free companion is unpriced power');

        $bare = TraitCatalog::sheetBalance([['capability' => 'swim']], []);
        $withCompany = TraitCatalog::sheetBalance(
            [['capability' => 'swim']], [], [['name' => 'Bosun', 'kind' => 'npc', 'what' => '']],
        );
        $this->assertSame($bare - $cost, $withCompany);

        // And it is named on the ledger, where the player can decide against it.
        $ledger = TraitCatalog::sheetLedger(
            [], [], [['name' => 'Bosun', 'kind' => 'npc', 'what' => '']],
        );
        $this->assertContains(
            ['label' => 'Bosun at your side', 'cost' => $cost],
            $ledger['gifts'],
        );

        // However many the interview names, only the cap walks in — the world
        // still has to have somewhere to put the grateful and the stray.
        $proposed = [
            ['name' => 'Bosun', 'kind' => 'npc'],
            ['name' => 'Deckhand', 'kind' => 'npc'],
            ['name' => 'The dog', 'kind' => 'creature'],
        ];
        $clean = TraitCatalog::companionsFrom($proposed);
        $this->assertCount(TraitCatalog::companionCap(), $clean);
        $this->assertLessThan((int) config('game.companions.cap'), count($clean));

        // The kind is the engine's, not Claude's: the signature table is keyed to
        // it, so anything it does not understand becomes a person.
        $this->assertSame('npc', TraitCatalog::companionsFrom([
            ['name' => 'Something', 'kind' => 'eldritch'],
        ])[0]['kind']);

        // Nameless proposals are not people and do not walk in.
        $this->assertSame([], TraitCatalog::companionsFrom([['kind' => 'npc']]));
    }

    /** The party cap holds against the sheet as hard as against the road. */
    public function test_a_bought_companion_still_yields_to_the_party_cap()
    {
        $campaign = $this->createCampaign();
        $scene = $this->openBareTurn($campaign)->scene;

        $this->makeCompanion($scene, 'the first');
        $this->makeCompanion($scene, 'the second');

        $this->assertTrue(Companions::atCap($scene->fresh()));
        $this->assertNull(Companions::plant($scene->fresh(), ['name' => 'the third', 'kind' => 'npc']));
        $this->assertNull($scene->fresh()->actors()->where('name', 'the third')->first());
    }
}
