<?php

namespace App\Game\Engine;

use App\Models\Campaign;
use Carbon\CarbonInterface;

/**
 * The hour: the wheel the wait turns.
 *
 * Turns are real-time and the idle wait is this game's defining rhythm, yet
 * nothing in the fiction used to move with it — a player who submitted at
 * midnight and came back after breakfast re-entered a world standing at
 * whatever o'clock the last chapter happened to improvise. So the wheel: four
 * phases, advanced a step at a time by the turns played and by the REAL
 * minutes of the absence, so coming back hours later means coming back to
 * changed light.
 *
 * Ambient is the prior art and the shape is deliberately identical: one
 * abstract engine key, fixed rules about where it may touch the arithmetic,
 * and a narrator who translates it through the land. The difference is only
 * that the air is rolled once per scene and holds, while the light keeps
 * turning inside a scene — the air holds, the light moves.
 *
 * The vocabulary is ABSTRACT for the same reason ambient's is. A derelict
 * station has no sun; the engine never says sunrise, it says the light is
 * coming back, and the narrator decides whether that is a horizon, a shift
 * change, or a deck coming up out of its dimmed cycle.
 *
 * Nothing here reads the campaign's genre, drive, tech level, or land. The
 * wheel advances uniformly for every tale in the game.
 *
 * The MECHANICAL half lives in Odds::HOURS — one ladder, two readers, so a
 * card's forecast and the die it is measured against can never disagree. This
 * class owns the wheel, the campaign's memory of it, and the words.
 */
class Hours
{
    /** The light coming back. */
    public const DAWN = 'dawn';

    /** The baseline, and the only phase that emits nothing anywhere. */
    public const DAY = 'day';

    /** The light going. */
    public const DUSK = 'dusk';

    /** The light long gone. */
    public const NIGHT = 'night';

    /**
     * The wheel, in the only order it ever turns. Past the end it comes round
     * again — which is why a very long absence lands where it started rather
     * than somewhere a modulo picked.
     *
     * @var list<string>
     */
    public const WHEEL = [self::DAWN, self::DAY, self::DUSK, self::NIGHT];

    /**
     * The situation-board line, folded into the same group the air already
     * uses. Phrased as a fact about the light and nothing else: no clock-face
     * time, no word a land might not own, and no hint of the odds it moves —
     * the cards carry those, itemized, where the player can price them.
     */
    private const BOARD = [
        self::DAWN => 'The light is coming up.',
        self::DUSK => 'The light is failing.',
        self::NIGHT => 'It is deep in the dark hours.',
    ];

    /** The one fact the narrator is handed, to be rendered in the land's own idiom. */
    private const NARRATOR = [
        self::DAWN => 'This is the stretch when the light first comes back to this place.',
        self::DUSK => 'This is the stretch when the light here is going, and what is left of it is thin.',
        self::NIGHT => 'This is the dark stretch of the cycle here, and the light has been gone a long while.',
    ];

    /**
     * What the resolution says when a step lands mid-play. A plain fact string
     * in the same register as everything else the world does on its own — and
     * absent for `day`, because day is the baseline and a world that remarks on
     * the ordinary has nothing left to remark with.
     */
    private const CHANGED = [
        self::DAWN => 'The dark gave way to first light while this was going on.',
        self::DUSK => 'The light began to fail while this was going on.',
        self::NIGHT => 'The last of the light went out of this place while this was going on.',
    ];

    /**
     * Where this tale stands. A campaign from before the wheel existed — or
     * none at all — reads as day, which costs nothing anywhere, so every legacy
     * save keeps playing exactly the numbers it was playing yesterday.
     */
    public static function of(?Campaign $campaign): string
    {
        $phase = $campaign?->hour_phase;

        return in_array($phase, self::WHEEL, true) ? $phase : self::DAY;
    }

    /** One step around. Anything the wheel does not recognise is treated as day. */
    public static function next(string $phase): string
    {
        $at = array_search($phase, self::WHEEL, true);
        $at = $at === false ? (int) array_search(self::DAY, self::WHEEL, true) : $at;

        return self::WHEEL[($at + 1) % count(self::WHEEL)];
    }

    /**
     * Turn the wheel for the wait that just ended and the turn about to
     * resolve, and return the plain fact when the light actually moved.
     *
     * Called beside Meters::regenerate, because both read the same real clock —
     * and that is the ONLY thing the two share. Downtime reads those minutes
     * too and neither touches the other: rest that worked better at night would
     * be a parallel buff wearing a nightcap, and the wheel would stop being
     * scenery and start being something to farm.
     *
     * The wait comes first (it happened first), and it lands at the top of
     * whatever phase it crossed into — arriving three-quarters of the way
     * through a light you have only just reached is arithmetic nobody could
     * read. Then the turn itself adds its one step of progress.
     *
     * Deterministic arithmetic throughout: nothing here rolls, so there is
     * nothing here to seed.
     *
     * @param  CarbonInterface|null  $since  When the wait began — the moment the
     *                                       turn opened, which is the moment the
     *                                       previous resolution ended. Null skips
     *                                       the wait entirely.
     */
    public static function advance(Campaign $campaign, ?CarbonInterface $since = null, ?CarbonInterface $now = null): ?string
    {
        $before = self::of($campaign);
        $phase = $before;
        $progress = max(0, (int) $campaign->hour_progress);

        $waited = self::waitSteps($since, $now ?? now());
        if ($waited > 0) {
            for ($step = 0; $step < $waited; $step++) {
                $phase = self::next($phase);
            }
            $progress = 0;
        }

        $perPhase = max(1, (int) config('game.hours.turns_per_phase', 3));
        $progress++;
        while ($progress >= $perPhase) {
            $progress -= $perPhase;
            $phase = self::next($phase);
        }

        $campaign->forceFill(['hour_phase' => $phase, 'hour_progress' => $progress])->save();

        return $phase === $before ? null : self::changed($phase);
    }

    /**
     * How many phases the absence itself is worth.
     *
     * Capped at one full turn of the wheel, which lands back where it started:
     * a week away is a day later, not a random modulo. Without the cap the
     * light would become a lottery on the length of a holiday.
     */
    private static function waitSteps(?CarbonInterface $since, CarbonInterface $now): int
    {
        if ($since === null) {
            return 0;
        }

        $minutes = max(0, (int) floor($since->diffInMinutes($now)));
        $perPhase = max(1, (int) config('game.hours.minutes_per_phase', 240));

        return min(intdiv($minutes, $perPhase), count(self::WHEEL));
    }

    /** The board line, or null when the light says nothing (the item is then simply absent). */
    public static function line(string $phase): ?string
    {
        return self::BOARD[$phase] ?? null;
    }

    /** The narrator's fixed fact, or null when there is nothing to tell. */
    public static function fact(string $phase): ?string
    {
        return self::NARRATOR[$phase] ?? null;
    }

    /** What the resolution records about a step that landed mid-play, or null. */
    public static function changed(string $phase): ?string
    {
        return self::CHANGED[$phase] ?? null;
    }
}
