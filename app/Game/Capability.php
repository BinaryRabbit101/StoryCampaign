<?php

namespace App\Game;

/**
 * The shared capability vocabulary. Abilities PROVIDE these verbs;
 * scene affordances REQUIRE them. Options emerge from the intersection —
 * they are never hand-listed per scene. The vocabulary may grow via
 * world evolution (new affordance types), but the grammar stays constant.
 */
enum Capability: string
{
    // Traversal
    case Climb = 'climb';
    case Swing = 'swing';
    case Leap = 'leap';
    case Descend = 'descend';
    case Squeeze = 'squeeze';
    case Swim = 'swim';
    case Burrow = 'burrow';
    case Glide = 'glide';

    // Manipulation
    case Grapple = 'grapple';
    case Restrain = 'restrain';
    case Pull = 'pull';
    case Throw = 'throw';
    case CarryExtra = 'carry_extra';
    case Reach = 'reach';
    case Break = 'break';
    case Lift = 'lift';

    // Stealth & perception
    case Conceal = 'conceal';
    case QuietMove = 'quiet_move';
    case Scout = 'scout';
    case Track = 'track';
    case Detect = 'detect';

    // Social & presence
    case Intimidate = 'intimidate';
    case Persuade = 'persuade';
    case Deceive = 'deceive';
    case Calm = 'calm';
    case Command = 'command';

    // Tempo (modify rolls/initiative rather than unlock destinations)
    case TimeSlow = 'time_slow';
    case Haste = 'haste';
    case Delay = 'delay';
    case Ready = 'ready';

    public function group(): CapabilityGroup
    {
        return match ($this) {
            self::Climb, self::Swing, self::Leap, self::Descend,
            self::Squeeze, self::Swim, self::Burrow, self::Glide => CapabilityGroup::Traversal,

            self::Grapple, self::Restrain, self::Pull, self::Throw,
            self::CarryExtra, self::Reach, self::Break, self::Lift => CapabilityGroup::Manipulation,

            self::Conceal, self::QuietMove, self::Scout,
            self::Track, self::Detect => CapabilityGroup::Stealth,

            self::Intimidate, self::Persuade, self::Deceive,
            self::Calm, self::Command => CapabilityGroup::Social,

            self::TimeSlow, self::Haste, self::Delay, self::Ready => CapabilityGroup::Tempo,
        };
    }

    /** Capabilities whose magnitude matters, e.g. reach(12), lift(200). */
    public function parameterized(): bool
    {
        return in_array($this, [self::Reach, self::Lift, self::Leap, self::CarryExtra, self::Squeeze], true);
    }

    /** Tempo capabilities spend from a metered pool. */
    public function metered(): bool
    {
        return $this->group() === CapabilityGroup::Tempo && $this !== self::Ready;
    }

    public function label(): string
    {
        return str_replace('_', ' ', ucfirst($this->value));
    }
}
