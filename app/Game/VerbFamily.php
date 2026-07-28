<?php

namespace App\Game;

/**
 * The nine words the board is built from.
 *
 * A family is PRESENTATION over the engine's own verbs — it never reaches a
 * die, a difficulty, or a legality check, and no card is ever composed from
 * one. It exists so the player learns a fixed vocabulary instead of re-reading
 * a list of finished sentences every turn: the row is the same nine words on
 * every ground, and what changes underneath it is what the scene affords.
 *
 * Membership lives on the verb (App\Game\Verb::family()), not here, so a verb
 * added tomorrow declares its own place and cannot land in two families at
 * once. This end only names them and can list what fell into each.
 */
enum VerbFamily: string
{
    case Look = 'look';
    case Go = 'go';
    case Take = 'take';
    case Fight = 'fight';
    case Speak = 'speak';
    case Hide = 'hide';
    case Tend = 'tend';
    case Wait = 'wait';
    case Do = 'do';

    public function label(): string
    {
        return match ($this) {
            self::Look => 'Look',
            self::Go => 'Go',
            self::Take => 'Take',
            self::Fight => 'Fight',
            self::Speak => 'Speak',
            self::Hide => 'Hide',
            self::Tend => 'Tend',
            self::Wait => 'Wait',
            self::Do => 'Do',
        };
    }

    /**
     * The verbs that declared themselves part of this family.
     *
     * @return list<Verb>
     */
    public function verbs(): array
    {
        return array_values(array_filter(
            Verb::cases(),
            fn (Verb $verb) => $verb->family() === $this,
        ));
    }
}
