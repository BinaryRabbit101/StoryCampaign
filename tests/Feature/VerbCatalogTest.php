<?php

namespace Tests\Feature;

use App\Game\Engine\CardComposer;
use App\Game\Meters;
use App\Game\Verb;
use App\Game\VerbFamily;
use App\Models\Campaign;
use App\Models\Character;
use App\Models\Scene;
use App\Models\User;
use App\Models\Zone;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The vocabulary, guarded.
 *
 * The verb strings used to live in three places at once — the composer's
 * construction sites, the resolver's switch arms, and a family table in the
 * play screen — and nothing anywhere checked that the three agreed. These
 * tests are that check: a verb the composer can emit and the catalog has never
 * heard of fails here, before it can reach a player as a card the resolver
 * quietly drops on the floor.
 */
class VerbCatalogTest extends TestCase
{
    use RefreshDatabase;

    /** @return list<string> Every verb the source names, however it names it. */
    private function verbsNamedIn(string $path, string $literalPattern, string $catalogPattern): array
    {
        $source = file_get_contents(base_path($path));
        $names = array_column(Verb::cases(), 'name');
        $found = [];

        // The old way of naming a verb. None should survive the adoption, and
        // any that appears tomorrow has to be a real catalog entry.
        preg_match_all($literalPattern, $source, $literals);
        foreach ($literals[1] as $literal) {
            $this->assertNotNull(
                Verb::tryFrom($literal),
                "{$path} names the verb '{$literal}', which is not in App\\Game\\Verb.",
            );
            $found[] = $literal;
        }

        preg_match_all($catalogPattern, $source, $cases);
        foreach ($cases[1] as $case) {
            $this->assertContains(
                $case, $names,
                "{$path} refers to Verb::{$case}, which is not a case of App\\Game\\Verb.",
            );
            $found[] = constant(Verb::class."::{$case}")->value;
        }

        return array_values(array_unique($found));
    }

    public function test_every_verb_the_composer_can_emit_is_in_the_catalog()
    {
        $emitted = $this->verbsNamedIn(
            'app/Game/Engine/CardComposer.php',
            "/verb:\s*'([a-z_]+)'/",
            '/verb:\s*Verb::([A-Za-z]+)->value/',
        );

        // A scan that matches nothing would pass forever without reading a
        // line of the composer.
        $this->assertGreaterThan(30, count($emitted));
        $this->assertContains('strike', $emitted);
        $this->assertContains('improvise', $emitted);
    }

    public function test_every_verb_the_resolver_switches_on_is_in_the_catalog()
    {
        $resolved = $this->verbsNamedIn(
            'app/Game/Engine/TurnResolver.php',
            "/case\s+'([a-z_]+)':/",
            '/case\s+Verb::([A-Za-z]+):/',
        );

        $this->assertGreaterThan(30, count($resolved));
        $this->assertContains('wait', $resolved);
        $this->assertContains('companion_forage', $resolved);
    }

    public function test_every_verb_has_exactly_one_family()
    {
        $filed = [];

        foreach (VerbFamily::cases() as $family) {
            foreach ($family->verbs() as $verb) {
                $this->assertArrayNotHasKey(
                    $verb->value, $filed,
                    "{$verb->value} is filed under two families at once.",
                );
                $filed[$verb->value] = $family;
            }
        }

        // Every case placed, and placed once: the board is the whole
        // vocabulary, so a verb belonging nowhere would be a card the player
        // is offered and can never reach.
        $this->assertCount(count(Verb::cases()), $filed);

        foreach (Verb::cases() as $verb) {
            $this->assertSame($filed[$verb->value], $verb->family());
            $this->assertNotSame('', $verb->label());
        }
    }

    public function test_a_card_carries_the_family_the_catalog_gives_its_verb()
    {
        [$character, $scene] = $this->bareGround();

        $cards = app(CardComposer::class)->compose($character, $scene);

        foreach (['pre', 'main', 'post'] as $slot) {
            foreach ($cards[$slot] as $card) {
                $this->assertNotNull(
                    Verb::tryFrom($card['verb']),
                    "The composer offered '{$card['verb']}', which is not in the catalog.",
                );
                $this->assertSame(Verb::familyOf($card['verb'])->value, $card['family']);
                $this->assertSame(Verb::labelOf($card['verb']), $card['verb_label']);
            }
        }
    }

    public function test_the_board_floor_lights_look_wait_and_do_on_empty_ground()
    {
        [$character, $scene] = $this->bareGround();

        $cards = app(CardComposer::class)->compose($character, $scene);
        $lit = collect($cards['main'])->pluck('family')->unique()->values();

        // LOOK, WAIT and DO are unconditional, so the stable row can never
        // stand with fewer than two words lit — the ≥2-legal-cards invariant,
        // read off the board instead of off the list.
        $this->assertContains(VerbFamily::Look->value, $lit);
        $this->assertContains(VerbFamily::Wait->value, $lit);
        $this->assertContains(VerbFamily::Do->value, $lit);
        $this->assertGreaterThanOrEqual(2, $lit->count());
    }

    /**
     * Ground with nothing on it: no features, no actors, no zone templates to
     * overlay. An empty room is a legitimate reading of a place, and the board
     * still has to work in one.
     *
     * @return array{0: Character, 1: Scene}
     */
    private function bareGround(): array
    {
        $campaign = Campaign::create([
            'user_id' => User::factory()->create()->id,
            'name' => 'Empty Ground',
            'world_flavor' => 'harbor-city',
            'status' => 'active',
            'started_at' => now(),
        ]);

        $character = Character::create([
            'campaign_id' => $campaign->id,
            'name' => 'Nobody',
            'description' => 'A person standing in an empty room.',
            'meters' => Meters::default(),
            'status' => 'alive',
            'meters_regenerated_at' => now(),
        ]);

        $zone = Zone::create([
            'campaign_id' => $campaign->id,
            'slug' => 'empty-ground',
            'name' => 'Empty Ground',
            'description' => 'Nothing here but the floor.',
            'source' => 'forge',
        ]);

        $scene = Scene::create([
            'campaign_id' => $campaign->id,
            'zone_id' => $zone->id,
            'title' => 'The empty room',
            'description' => 'Nothing but the ground and you.',
            'status' => 'active',
            'state' => [],
        ]);

        return [$character, $scene];
    }
}
