<?php

namespace App\Game\Engine;

use App\Game\BranchTrigger;
use App\Game\Hands;
use App\Models\Character;
use App\Models\Scene;

/**
 * The state of play, as groups.
 *
 * It used to be one paragraph — trigger, foes, telegraphs, captives, allies,
 * bystanders, ground, health — glued end to end, and it read as a wall the
 * eye slid off. Worse, it only appeared when no chapter carried the lead-in,
 * so the player who most needed to check what was standing where (mid-fight,
 * chapter in hand) was the one who could not see it.
 *
 * So: groups, always on screen, and empty groups simply absent. An empty
 * board is a legitimate reading — quiet ground with nobody on it — and saying
 * "no open threat stands against you" every single turn was noise pretending
 * to be information.
 *
 * The prose string survives for the narrator prompt, compiled from these same
 * groups, so the page and the chapter can never disagree about who is here.
 *
 * @phpstan-type Group array{key:string,title:string,tone:string,items:list<string>}
 */
class SituationBoard
{
    /** @return list<Group> */
    public static function for(Character $character, Scene $scene, ?BranchTrigger $trigger = null): array
    {
        $character = $character->fresh() ?? $character;
        $groups = [];

        if ($trigger !== null) {
            $groups[] = self::group('moment', 'Where this leaves you', 'neutral', [$trigger->description()]);
        }

        // A lurking ambusher is not yet the player's to know.
        $enemies = $scene->visibleActors()->filter(fn ($a) => $a->kind === 'enemy');
        $threats = [];
        foreach ($enemies as $enemy) {
            $tell = match (true) {
                (bool) ($enemy->tags['truce'] ?? false) => 'holding to a truce — they came to talk',
                (bool) ($enemy->tags['cornered'] ?? false) => 'cornered here, run to ground',
                ($enemy->tags['angle'] ?? false) === true => 'has an angle on you — move, or answer it',
                ($enemy->tags['intent'] ?? null) === 'windup' => 'winding up something heavy',
                ($enemy->tags['intent'] ?? null) === 'guard' => 'settled behind a tight guard',
                ($enemy->tags['intent'] ?? null) === 'circle' => 'circling, hunting an angle',
                default => null,
            };
            $threats[] = $enemy->name.($tell === null ? '' : " — {$tell}");
        }
        $groups[] = self::group('threats', 'Facing you', 'foe', $threats);

        // An old score, standing here again. Only what the player can see —
        // a wary grudge lurking unsprung stays as hidden as any ambusher.
        $groups[] = self::group('grudge', 'An old score', 'foe',
            Grudges::boardLines($enemies->filter(fn ($a) => isset($a->tags['grudge_id']))));

        if ((int) ($scene->state['alarm'] ?? 0) >= 2) {
            $groups[] = self::group('alarm', 'Closing in', 'foe', [
                'Shouts carry across the district — more trouble is close.',
            ]);
        }

        // The world's patience with stillness, from the very first tick. This
        // is scene state, so it belongs on the board (unlike a scar, which is
        // the sheet's) — and it has to be here early, because the whole point
        // of pressure is that waiting again is a readable choice rather than
        // an ambush the player walked into.
        $groups[] = self::group('pressure', 'The stillness', 'ground',
            array_values(array_filter([Pressure::line(Pressure::of($scene))])));

        // What the player set themselves to, and how far along it is. Neutral
        // on purpose: an endeavor is neither a threat nor a comfort, it is
        // simply the thing they said they were doing — and the count belongs
        // here, where they can price the next beat against it.
        $groups[] = self::group('endeavor', 'What you are set on', 'neutral',
            array_values(array_filter([Clocks::boardLine($scene)])));

        // And what somebody ELSE is set on, once the player has found out what
        // it is. Absent entirely until then — an undiscovered want is engine
        // state, and a board line about one would be the page telling the
        // player something nobody in the tale has said out loud.
        $groups[] = self::group('thread', 'What someone else needs', 'person',
            array_values(array_filter([Threads::boardLine($scene)])));

        $groups[] = self::group('captives', 'In your grip', 'foe',
            $scene->actors()->where('status', 'restrained')->pluck('name')->all());

        // What each of them is to the player, in plain words — and the ones on
        // the floor, who are still at their side until the scene turns. The
        // bond's number never appears here: a board that printed it would teach
        // the player to farm it instead of to notice it.
        $groups[] = self::group('allies', 'At your side', 'ally', Companions::boardLines($scene));

        // Bystanders, plus whoever has quietly attached themselves to the
        // player without being asked — and whoever is waiting on an answer.
        $strays = Companions::bystanderLines($scene);
        $named = collect($strays)->map(fn (string $l) => explode(' — ', $l)[0])->all();

        $groups[] = self::group('others', 'Also here', 'person', array_merge($strays,
            $scene->visibleActors()
                ->reject(fn ($a) => in_array($a->kind, ['enemy', 'companion'], true))
                ->reject(fn ($a) => in_array($a->name, $named, true))
                ->pluck('name')->all()));

        // Ground the offered options: name the features the cards reference,
        // so nothing the player can act on appears unannounced. What is in
        // their hands is no longer scenery — it is listed with them, below.
        $groups[] = self::group('ground', 'Around you', 'ground',
            $scene->visibleFeatures()
                ->reject(fn ($f) => Hands::isHolding($character, $f->id))
                ->pluck('name')->take(6)->all());

        // The air this ground stands in, and the light it stands in. Abstract,
        // because the engine's words have to work in a canopy town and on a
        // derelict station alike — the chapter is where it becomes rain, or
        // dust, or a deck venting, and where the dark hours become a horizon or
        // a dimmed shift. One group carries both: they are the same kind of
        // fact about the place, and splitting them would put two one-line
        // groups on the board saying nearly the same thing.
        //
        // Clear air and plain day each say nothing at all, so an ordinary scene
        // in ordinary light drops the whole group — the same rule that keeps
        // "no open threat stands against you" off the page every quiet turn.
        $groups[] = self::group('sky', 'The air and the light', 'ground',
            array_values(array_filter([
                Ambient::line(Ambient::of($scene)),
                Hours::line(Hours::of($scene->campaign)),
            ])));

        $self = [];
        $health = $character->meters['health'];
        $self[] = "Health {$health['current']}/{$health['max']}";
        if ($scene->state['elevated'] ?? false) {
            $self[] = 'You hold the high ground';
        }
        foreach (Hands::held($character) as $entry) {
            $self[] = 'In hand: '.$entry['name'].($entry['hands'] >= 2 ? ' (both hands)' : '');
        }
        $groups[] = self::group('self', 'You', 'self', $self);

        return array_values(array_filter($groups, fn (array $g) => $g['items'] !== []));
    }

    /**
     * The same board as one paragraph, for the narration prompt. The narrator
     * has always read this as prose and the bullets are a reading aid, not a
     * second source of truth — so the page and the chapter close on exactly
     * the same facts.
     *
     * Health is a meter and meters never reach prose, so it is left out here.
     * The endeavor's whole group goes the same way, and for the same reason:
     * "three of five" is a count, and a count in the narrator's copy of the
     * board is mechanics language on the page. The narrator gets the goal
     * itself instead, in plain words, from Clocks::narratorBlock — and somebody
     * else's want the same way, from Threads::narratorBlock.
     */
    public static function prose(array $groups): string
    {
        $parts = [];

        foreach ($groups as $group) {
            if (in_array($group['key'], ['endeavor', 'thread'], true)) {
                continue;
            }

            $items = $group['key'] === 'self'
                ? array_values(array_filter($group['items'], fn (string $i) => ! str_starts_with($i, 'Health ')))
                : $group['items'];

            if ($items === []) {
                continue;
            }

            $parts[] = match ($group['key']) {
                'moment', 'alarm', 'pressure', 'sky' => implode(' ', $items),
                'self' => implode('. ', $items).'.',
                default => "{$group['title']}: ".implode(', ', $items).'.',
            };
        }

        return $parts === []
            ? 'Nothing stands against them, and nothing here asks anything of them.'
            : implode(' ', $parts);
    }

    /**
     * @param  list<string>  $items
     * @return Group
     */
    private static function group(string $key, string $title, string $tone, array $items): array
    {
        return ['key' => $key, 'title' => $title, 'tone' => $tone, 'items' => array_values($items)];
    }
}
