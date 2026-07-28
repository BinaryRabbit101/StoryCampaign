<?php

namespace App\Game;

/**
 * The verb vocabulary, first-class at last.
 *
 * Every beat the engine can resolve is a verb, and until now those ~48 strings
 * lived implicitly in three drift-prone places at once: the composer's
 * construction sites, the resolver's switch arms, and a hand-kept family table
 * in the play screen. Three copies of one vocabulary is how a verb gets added
 * in the composer, never resolved by the resolver, and quietly filed under
 * "everything else" on screen — each half correct, the whole broken.
 *
 * So the catalog is the vocabulary now, and the other three read it.
 *
 * Two rules hold this in place:
 *
 *  - The STRING never changes. These are the values stored on every turn ever
 *    played, submitted from the client, and switched on at resolution. The enum
 *    is a name for a string that already existed; renaming a case's value would
 *    silently invalidate saved turns. Add cases freely; never rewrite one.
 *  - The FAMILY is presentation and nothing else. It groups verbs into the nine
 *    words of the board (App\Game\VerbFamily) so the player learns a grammar
 *    rather than a list. It never reaches difficulty, dice, legality, or the
 *    affordance grammar — story flexible, rules fixed — and every verb has
 *    exactly one, so a card can never appear twice on the board or nowhere on
 *    it (there is a test).
 */
enum Verb: string
{
    // ---- Look: reading the ground, and what is on it ----
    case Examine = 'examine';
    case Inspect = 'inspect';
    case Scout = 'scout';
    case Detect = 'detect';
    case Track = 'track';

    // ---- Go: putting the body somewhere else ----
    case Cross = 'cross';
    case Venture = 'venture';
    case Flee = 'flee';
    case Ascend = 'ascend';
    case Ride = 'ride';
    case Reposition = 'reposition';

    // ---- Take: hands on the world ----
    case Lift = 'lift';
    case Loot = 'loot';
    case Haul = 'haul';
    case Drop = 'drop';
    case Hurl = 'hurl';
    case Recover = 'recover';
    case Break = 'break';

    // ---- Fight: what is aimed at someone ----
    case Strike = 'strike';
    case Interrupt = 'interrupt';
    case Restrain = 'restrain';
    case Brace = 'brace';
    case Shield = 'shield';

    // ---- Speak: everything settled with words, including every request
    //      made of somebody walking beside you ----
    case Speak = 'speak';
    case Persuade = 'persuade';
    case Deceive = 'deceive';
    case Calm = 'calm';
    case Intimidate = 'intimidate';
    case Command = 'command';
    case Recruit = 'recruit';
    case Bargain = 'bargain';
    case CompanionWelcome = 'companion_welcome';
    case CompanionDismiss = 'companion_dismiss';
    case CompanionBlock = 'companion_block';
    case CompanionFlank = 'companion_flank';
    case CompanionStrike = 'companion_strike';
    case CompanionScout = 'companion_scout';
    case CompanionHarry = 'companion_harry';
    case CompanionDistract = 'companion_distract';
    case CompanionForage = 'companion_forage';

    // ---- Hide: not being where they are looking ----
    case Hide = 'hide';

    // ---- Tend: the body, the gear, the tempo ----
    case Bandage = 'bandage';
    case CatchBreath = 'catch_breath';
    case Ready = 'ready';
    case TimeSlow = 'time_slow';
    case Haste = 'haste';

    // ---- Wait: ceding the initiative on purpose ----
    case Wait = 'wait';

    // ---- Do: the one card with no training behind it ----
    case Improvise = 'improvise';

    /**
     * The board word this verb sits under. Exactly one, always — a verb in two
     * families would offer the same card twice, and a verb in none would
     * vanish off a board that is supposed to be the whole vocabulary.
     */
    public function family(): VerbFamily
    {
        return match ($this) {
            self::Examine, self::Inspect, self::Scout,
            self::Detect, self::Track => VerbFamily::Look,

            self::Cross, self::Venture, self::Flee,
            self::Ascend, self::Ride, self::Reposition => VerbFamily::Go,

            // `recover` is going back for gear a fumble tore out of your hands
            // and getting it up off the floor — hands on the world, not tending
            // a body, whatever its name suggests.
            self::Lift, self::Loot, self::Haul, self::Drop,
            self::Hurl, self::Recover, self::Break => VerbFamily::Take,

            self::Strike, self::Interrupt, self::Restrain,
            self::Brace, self::Shield => VerbFamily::Fight,

            self::Speak, self::Persuade, self::Deceive, self::Calm,
            self::Intimidate, self::Command, self::Recruit, self::Bargain,
            self::CompanionWelcome, self::CompanionDismiss,
            self::CompanionBlock, self::CompanionFlank, self::CompanionStrike,
            self::CompanionScout, self::CompanionHarry,
            self::CompanionDistract, self::CompanionForage => VerbFamily::Speak,

            self::Hide => VerbFamily::Hide,

            self::Bandage, self::CatchBreath, self::Ready,
            self::TimeSlow, self::Haste => VerbFamily::Tend,

            self::Wait => VerbFamily::Wait,

            self::Improvise => VerbFamily::Do,
        };
    }

    /**
     * What this verb is called when the board opens its family.
     *
     * Short and imperative: the card's own label already says the whole
     * sentence ("Strike at the dockside tough"), so this only has to name the
     * kind of thing being done. Never mechanics, and never the raw key —
     * `catch_breath` on screen is a leak, not a word.
     */
    public function label(): string
    {
        return match ($this) {
            self::Examine => 'Examine',
            self::Inspect => 'Look closer',
            self::Scout => 'Read the ground',
            self::Detect => 'Sense trouble',
            self::Track => 'Track',

            self::Cross => 'Cross',
            self::Venture => 'Press on',
            self::Flee => 'Get out',
            self::Ascend => 'Get up there',
            self::Ride => 'Ride',
            self::Reposition => 'Reposition',

            self::Lift => 'Pick up',
            self::Loot => 'Search the fallen',
            self::Haul => 'Haul',
            self::Drop => 'Set down',
            self::Hurl => 'Throw',
            self::Recover => 'Take it back',
            self::Break => 'Break',

            self::Strike => 'Strike',
            self::Interrupt => 'Interrupt',
            self::Restrain => 'Restrain',
            self::Brace => 'Brace',
            self::Shield => 'Use as cover',

            self::Speak => 'Speak',
            self::Persuade => 'Persuade',
            self::Deceive => 'Deceive',
            self::Calm => 'Calm',
            self::Intimidate => 'Intimidate',
            self::Command => 'Call the play',
            self::Recruit => 'Ask them along',
            self::Bargain => 'Hear their terms',
            self::CompanionWelcome => 'Welcome them',
            self::CompanionDismiss => 'Send them on',
            self::CompanionBlock => 'Hold the line',
            self::CompanionFlank => 'Flank',
            self::CompanionStrike => 'Take the fight',
            self::CompanionScout => 'Find a way out',
            self::CompanionHarry => 'Harry',
            self::CompanionDistract => 'Pull their attention',
            self::CompanionForage => 'Walk the ground',

            self::Hide => 'Take cover',

            self::Bandage => 'Bind wounds',
            self::CatchBreath => 'Catch breath',
            self::Ready => 'Ready yourself',
            self::TimeSlow => 'Slow time',
            self::Haste => 'Hasten',

            self::Wait => 'Wait',

            self::Improvise => 'Improvise',
        };
    }

    /**
     * The family of a stored verb string.
     *
     * A turn written before a verb joined the catalog still has to render, so
     * an unknown word falls into DO rather than off the board entirely — the
     * board is a lens, and a lens that drops a card the engine offered would
     * break the one rule that matters.
     */
    public static function familyOf(string $verb): VerbFamily
    {
        return self::tryFrom($verb)?->family() ?? VerbFamily::Do;
    }

    /** The player-facing word for a stored verb string, catalogued or not. */
    public static function labelOf(string $verb): string
    {
        return self::tryFrom($verb)?->label()
            ?? ucfirst(str_replace('_', ' ', $verb));
    }
}
