<?php

namespace App\Game\Engine;

use App\Game\Verb;
use App\Models\Scene;

/**
 * A way that has already been tried and did not work.
 *
 * Some beats are questions about a specific piece of ground, and a failure is
 * the ground's answer. Fleeing into the toppled shelving and finding it will not
 * take you does not become truer on the second attempt — offering the identical
 * card again is the engine asking a question it has already answered, and it is
 * the fastest way to teach a player that a failure costs nothing but a turn.
 *
 * So a failure CLOSES that exact route, for that scene only, and the card stops
 * being offered.
 *
 * Two rules keep this from eating the game:
 *
 * 1. THE LIST IS CLOSED, and it is short. Only verbs where a failure genuinely
 *    settles something are in it: a way out, a crossing, a climb, a sweep of the
 *    ground, a trail. A missed strike is not a settled question — a fight where
 *    one bad roll permanently removes an enemy from the things you may attack is
 *    not a fight — and neither is a conversation that went badly. Those stay
 *    exactly as repeatable as they always were.
 * 2. IT IS KEYED TO THE THING. `flee` into the shelving closes the shelving, not
 *    fleeing: every other way out of the room is still on the table, which is
 *    what keeps the ≥2-legal-cards invariant safe without having to think about
 *    it. A beat aimed at nothing in particular is keyed to the SCENE — reading
 *    this ground is one question about this ground, and it has been answered —
 *    and never to the tale.
 *
 * Only an outright FAILURE closes a route. A partial already moves the player
 * (the resolver reads it as through-but-battered), and a route closing on a
 * success would be a punishment for winning.
 *
 * Note on the quiet verbs: `examine` and `inspect` never roll (Odds::QUIET), so
 * they can never fail and are deliberately absent — a rule the dice can never
 * trigger is a rule nobody can read.
 *
 * Two things are deliberately NOT here any more, both for the same reason: a
 * refusal has to be an answer about the world, not an accident of one die.
 *
 *  - `cross` is gone entirely. A doorway is never a settled question. Walking
 *    out of a room was a coin flip whose failure burned the turn AND sealed
 *    that exact way for the rest of the scene, and a player who tried the only
 *    two doors was simply held in the room. An uncontested way now casts no die
 *    at all (Odds::certain), and a contested one is a fight worth trying twice.
 *  - `scout` needs TWO misses. One bad look at the ground is a bad look, not
 *    the ground refusing to be read — so the first failure is written down and
 *    closes nothing, and only the second says the reading is finished.
 */
class Attempts
{
    /**
     * The closed list. A failure on one of these takes that exact card off the
     * table for the rest of the scene.
     */
    public const CLOSING = [
        'flee', 'ascend', 'scout', 'track',
    ];

    /**
     * Verbs that take two failures to settle, and where the first one is
     * remembered. A single miss on these is a bad attempt rather than an
     * answer about the ground.
     */
    public const TWO_STRIKE = ['scout'];

    /** Where the scene keeps them. */
    private const KEY = 'spent_attempts';

    /** Where the scene keeps the first miss of a two-strike verb. */
    private const MISSED_KEY = 'missed_attempts';

    public static function closes(string $verb): bool
    {
        return in_array($verb, self::CLOSING, true);
    }

    /**
     * One route's identity: the verb and the thing it was aimed at. The NAME
     * stands in when there is no id (a scouted exit, a zone), and the scene
     * itself stands in when the beat is aimed at no one thing.
     *
     * @param  array{type?:string,id?:int|null,name?:string}|null  $target
     */
    public static function key(string $verb, ?array $target, Scene $scene): string
    {
        if ($target === null) {
            return $verb.':scene:'.$scene->id;
        }

        $id = $target['id'] ?? null;
        $handle = $id === null ? trim((string) ($target['name'] ?? '')) : (string) $id;

        if ($handle === '') {
            return $verb.':scene:'.$scene->id;
        }

        return $verb.':'.($target['type'] ?? '-').':'.$handle;
    }

    /**
     * Write down that this way did not work. Called once per resolved beat from
     * the resolver, after the die has already been read — nothing here is ever
     * an input to a roll.
     */
    public static function record(Scene $scene, BeatOutcome $outcome): void
    {
        if ($outcome->skipped || $outcome->degree !== BeatOutcome::FAILURE || ! self::closes($outcome->verb)) {
            return;
        }

        $key = self::key($outcome->verb, $outcome->target, $scene);
        $spent = self::spent($scene);

        if (in_array($key, $spent, true)) {
            return;
        }

        // The first miss of a two-strike verb is remembered and nothing else.
        // The card stays on the table, because one failed sweep of a room is a
        // failed sweep and not the room's final answer.
        if (in_array($outcome->verb, self::TWO_STRIKE, true)) {
            $missed = self::missed($scene);

            if (! in_array($key, $missed, true)) {
                $missed[] = $key;
                $scene->update(['state' => array_merge($scene->state ?? [], [self::MISSED_KEY => $missed])]);

                return;
            }
        }

        $spent[] = $key;

        $scene->update(['state' => array_merge($scene->state ?? [], [self::KEY => $spent])]);
    }

    /** @return list<string> */
    public static function spent(Scene $scene): array
    {
        return self::keysUnder($scene, self::KEY);
    }

    /** The first misses, still open. Read by nothing but the second miss. @return list<string> */
    public static function missed(Scene $scene): array
    {
        return self::keysUnder($scene, self::MISSED_KEY);
    }

    /** @return list<string> */
    private static function keysUnder(Scene $scene, string $key): array
    {
        return array_values(array_filter(
            (array) ($scene->state[$key] ?? []),
            fn ($entry) => is_string($entry),
        ));
    }

    /**
     * Whether this card is a road already walked into a wall.
     *
     * Read by the composer as it builds, so a closed route never reaches the
     * player's screen at all — the alternative is a card offered and then
     * refused at resolution, which is a dead choice wearing a live one's
     * clothes.
     */
    public static function isSpent(Scene $scene, ActionCard $card): bool
    {
        return self::closes($card->verb)
            && in_array(self::key($card->verb, $card->target, $scene), self::spent($scene), true);
    }

    /**
     * What this scene has already answered, in plain words, for the board.
     *
     * A card that silently stopped existing reads as a bug; the same card gone
     * with one line saying why reads as the world. No mechanics language, and
     * nothing that rolls ever reads this back.
     *
     * @return list<string>
     */
    public static function boardLines(Scene $scene): array
    {
        $lines = [];

        foreach (self::spent($scene) as $entry) {
            $parts = explode(':', $entry, 3);

            if (count($parts) < 3) {
                continue;
            }

            [$verb, $type, $handle] = $parts;
            $name = self::nameOf($scene, $type, $handle);

            $line = match (Verb::tryFrom($verb)) {
                Verb::Flee => $name === null ? null : "{$name} is no way out of here — you have tried it.",
                Verb::Ascend => $name === null ? null : "{$name} will not be climbed — you have tried it.",
                Verb::Scout => 'You have read this ground as closely as it can be read.',
                Verb::Track => $name === null ? null : "{$name}'s trail is cold for good.",
                default => null,
            };

            if ($line !== null) {
                $lines[] = $line;
            }
        }

        return $lines;
    }

    /** The thing a stored key was aimed at, named the way the scene names it. */
    private static function nameOf(Scene $scene, string $type, string $handle): ?string
    {
        if (! ctype_digit($handle)) {
            return $type === 'scene' ? null : $handle;
        }

        return match ($type) {
            'feature' => $scene->allFeatures()->firstWhere('id', (int) $handle)?->name,
            'actor' => $scene->actors()->find((int) $handle)?->name,
            default => null,
        };
    }
}
