<?php

namespace App\Game\Engine;

/**
 * The engine's record of one resolved beat: what was attempted, how the
 * dice fell, and plain-language facts for Claude to weave into the chapter.
 * Claude colors HOW it happened; these facts fix WHETHER it happened.
 */
class BeatOutcome
{
    public const STRONG = 'strong';

    public const SUCCESS = 'success';

    public const PARTIAL = 'partial';

    public const FAILURE = 'failure';

    /** The natural extremes, read off the die face before any modifier. */
    public const CRIT_SUCCESS = 'success';

    public const CRIT_FAILURE = 'failure';

    /**
     * @param  list<string>  $facts
     * @param  string|null  $note  The player's own words for this beat —
     *                             narration color, read only after every roll
     *                             is already cast. Never a mechanical input.
     * @param  string|null  $crit  'success' on a natural 20, 'failure' on a
     *                             natural 1, null otherwise. The face of the
     *                             die, not the margin: a natural 20 is a crit
     *                             even against a difficulty it would have
     *                             cleared anyway, and a natural 1 is a fumble
     *                             even when the bonuses would have carried it.
     */
    public function __construct(
        public readonly string $slot,
        public readonly string $verb,
        public readonly ?array $target,
        public readonly string $degree,
        public readonly int $roll,
        public readonly int $total,
        public readonly int $difficulty,
        public readonly array $facts = [],
        public readonly bool $skipped = false,
        public readonly ?string $note = null,
        public readonly ?string $crit = null,
    ) {}

    public function succeeded(): bool
    {
        return in_array($this->degree, [self::STRONG, self::SUCCESS], true);
    }

    public function toArray(): array
    {
        return [
            'slot' => $this->slot,
            'verb' => $this->verb,
            'target' => $this->target,
            'degree' => $this->degree,
            'roll' => $this->roll,
            'total' => $this->total,
            'difficulty' => $this->difficulty,
            'facts' => $this->facts,
            'skipped' => $this->skipped,
            'note' => $this->note,
            'crit' => $this->crit,
        ];
    }

    /** The face of the die, if it was one of the two that speak for themselves. */
    public static function critFor(int $roll): ?string
    {
        return match ($roll) {
            20 => self::CRIT_SUCCESS,
            1 => self::CRIT_FAILURE,
            default => null,
        };
    }

    public static function skipped(string $slot, string $verb, ?array $target, string $reason, ?string $note = null): self
    {
        return new self($slot, $verb, $target, self::FAILURE, 0, 0, 0, [$reason], skipped: true, note: $note);
    }
}
