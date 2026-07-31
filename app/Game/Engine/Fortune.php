<?php

namespace App\Game\Engine;

use App\Models\Character;
use App\Models\Scene;

/**
 * The fortune die — the world's own weather on the beats that never roll.
 *
 * Quiet beats keep their whole contract: what the card promised still simply
 * happens, certain as ever, and nothing here can refuse it. What the fortune
 * die adds is the texture every OTHER beat already has — a moment can break
 * your way or against you while you do the sure thing. High faces are a lucky
 * find, low faces an unfortunate wrinkle, and the wide middle is exactly what
 * quiet beats were before this existed: nothing at all.
 *
 * Three rules keep it honest:
 *  - It never touches the beat's own outcome. A quiet beat cannot fail, and
 *    the forecast's "Certain" stays true — fortune is the same class of thing
 *    as the alarm clock and the pressure table, the world moving on its own.
 *  - Every effect routes through machinery that already existed: the tempo
 *    pools, the scene's alarm counter, an enemy's angle tag, the scouted
 *    exit, the same engine-sanctioned reveal the pressure table uses. No new
 *    levers, and nothing an ordinary verb couldn't also have done.
 *  - It only fires where it can mean something. A lucky face with nothing to
 *    give and an unlucky face with nothing to cost both pass in silence —
 *    a consequence invented to fill a die face is noise wearing a number.
 *
 * Declarations and consent (undertake, face, welcoming a companion, taking a
 * grudge's terms) stay entirely outside it: answering a question is not an
 * act the world gets to trip you on.
 */
class Fortune
{
    /**
     * The quiet verbs the die rides on — the ones where a body is actually
     * doing something in the scene. Closed list; a new quiet verb stays
     * fortune-free until it is deliberately added here.
     */
    private const ELIGIBLE = [
        'time_slow', 'haste', 'ready', 'examine', 'inspect', 'wait',
        'catch_breath', 'reposition', 'shield', 'brace', 'command', 'drop',
        // Walking out through a way nothing is contesting. It reaches this
        // list as an ACT — a body moving through the scene — and it reaches
        // the die at all only where Odds::certain already made it roll-free,
        // which is exactly the open door. A contested crossing has its own
        // d20 and takes nothing from here.
        'cross',
    ];

    public static function eligible(string $verb): bool
    {
        return in_array($verb, self::ELIGIBLE, true);
    }

    /**
     * Cast the fortune die for one quiet beat.
     *
     * Returns the record the beat carries: the face, which way it broke, and
     * the plain-words fact (null fact when the face was ordinary or the
     * moment had nothing to give or take). Never null itself for an eligible
     * verb — the player asked to see dice on everything, so the die is always
     * shown, even when it lands flat.
     *
     * @return array{roll:int,kind:string,fact:?string}|null
     */
    public static function roll(Dice $dice, string $verb, Character $character, Scene $scene): ?array
    {
        if (! self::eligible($verb)) {
            return null;
        }

        $face = $dice->d20();
        $luckyFrom = (int) config('game.fortune.lucky_from', 17);
        $unluckyTo = (int) config('game.fortune.unlucky_to', 2);

        if ($face >= $luckyFrom) {
            return ['roll' => $face, 'kind' => 'lucky', 'fact' => self::lucky($character, $scene)];
        }

        if ($face <= $unluckyTo) {
            return ['roll' => $face, 'kind' => 'unlucky', 'fact' => self::unlucky($character, $scene)];
        }

        return ['roll' => $face, 'kind' => 'plain', 'fact' => null];
    }

    /**
     * A moment breaking their way. First applicable wins, ordered so a fight
     * pays out in the fight and open ground pays out in discovery.
     */
    private static function lucky(Character $character, Scene $scene): ?string
    {
        // An enemy's worked angle collapses on its own — a slip, a shifted
        // crate, a light gone wrong for them. Reposition's machinery.
        foreach ($scene->visibleActors() as $actor) {
            if ($actor->kind === 'enemy' && ($actor->tags['angle'] ?? false)) {
                $tags = $actor->tags;
                unset($tags['angle']);
                $actor->update(['tags' => $tags]);

                return "A stroke of luck: the angle {$actor->name} had been working fell apart on its own.";
            }
        }

        // Something the scene was keeping steps into view — the same
        // engine-sanctioned reveal the pressure table's REVEAL uses.
        $hidden = $scene->features()->get()->first(
            fn ($f) => ($f->state['hidden'] ?? false) && ! ($f->state['destroyed'] ?? false),
        );
        if ($hidden !== null) {
            $hidden->update(['state' => array_merge($hidden->state ?? [], ['hidden' => false])]);

            return "A lucky find: without looking for it, they noticed {$hidden->name}.";
        }

        // The ground gives up its way out unasked. Scout's machinery.
        if (! ($scene->state['exit_scouted'] ?? false)) {
            $scene->update(['state' => array_merge($scene->state ?? [], ['exit_scouted' => true])]);

            return 'A lucky find: the lay of the ground showed them a clean way out, unlooked for.';
        }

        // Wind at their back: one spent tempo charge comes home early.
        $meters = $character->meters;
        foreach ($meters['tempo'] ?? [] as $name => $pool) {
            if ($pool['current'] < $pool['max']) {
                $meters['tempo'][$name]['current']++;
                $character->forceFill(['meters' => $meters])->save();

                return 'A stroke of luck: the moment gave them back a breath they thought was spent.';
            }
        }

        return null;
    }

    /**
     * A moment breaking against them. First applicable wins; where nothing
     * could cost anything, the bad face passes in silence rather than
     * inventing a bill.
     */
    private static function unlucky(Character $character, Scene $scene): ?string
    {
        // Noise at the wrong moment, with people around to hear it. The
        // scene's own alarm counter — the same one loud bargains pay into.
        $hostilities = $scene->visibleActors()->contains(fn ($a) => $a->kind === 'enemy');
        if ($hostilities) {
            $state = $scene->state ?? [];
            $scene->update(['state' => array_merge($state, ['alarm' => (int) ($state['alarm'] ?? 0) + 1])]);

            return 'An unlucky moment: something gave them away — a scrape, a shifted stone — and the wrong ears caught it.';
        }

        // A charge slips away — footing lost, a grip gone, the moment spent
        // recovering instead of ready.
        $meters = $character->meters;
        foreach ($meters['tempo'] ?? [] as $name => $pool) {
            if ($pool['current'] > 0) {
                $meters['tempo'][$name]['current']--;
                $character->forceFill(['meters' => $meters])->save();

                return 'An unlucky moment: a stumble at the wrong instant cost them a breath they had been saving.';
            }
        }

        return null;
    }
}
