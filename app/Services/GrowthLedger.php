<?php

namespace App\Services;

use App\Game\TraitCatalog;
use App\Models\Character;
use App\Models\GrowthEntry;
use App\Models\Turn;

/**
 * The tale pays for what the sheet learns.
 *
 * Creation is priced to the point — gifts cost, burdens refund, the build must
 * break even — and then the growth interview handed out capabilities for
 * nothing at all. The long-arc character economy was "how persuasively you talk
 * to an LLM, how often", which is the one place this game let Claude decide
 * whether a player may have a power. This is the missing currency.
 *
 * Three rules hold it up, and they are the ones the shelf lives by.
 *
 * 1. The ENGINE alone mints. The earn table below is closed — five moments, all
 *    of them facts a resolution has ALREADY fixed — and the resolver hands them
 *    outward here the way it hands out keepsakes. Genre, drive, land, notes, and
 *    narration mint nothing; Claude mints nothing. A scar mints nothing either:
 *    a scar refunds nothing in any coin, and the sanctioned relief valve stays
 *    the interview acknowledging it in words.
 * 2. The ENGINE alone prices. A grant costs what the same capability costs at
 *    creation (App\Game\TraitCatalog), and if the ledger cannot afford it the
 *    grant is refused however Claude answered. Claude labels; the engine
 *    decides — the same split as everywhere else.
 * 3. The balance is DERIVED. Earns minus spends, summed from rows, never stored.
 *
 * A moment may leave BOTH a keepsake and an earn. They are different registers
 * — memory and coin — and neither caps the other.
 */
class GrowthLedger
{
    /**
     * The closed earn table. Which moments qualify is NOT tunable: config only
     * carries what each is worth. Every key here is a trigger the resolver
     * already detects for the shelf, read off facts the turn has fixed.
     *
     * `scar_taken` and `companion_lost` are deliberately absent. The first
     * refunds nothing by invariant; the second is a cost, and paying a player
     * for losing somebody is the wrong thing to say.
     *
     * @var list<string>
     */
    public const EARNS = [
        'elite_beaten',
        'endeavor_filled',
        'rival_settled',
        'first_ground',
        'captive_freed',
    ];

    /**
     * What each is worth when nothing overrides it. The config block is the
     * tunable copy; this is what holds if somebody empties it.
     *
     * @var array<string, int>
     */
    private const POINTS = [
        'elite_beaten' => 2,
        'endeavor_filled' => 2,
        'rival_settled' => 2,
        'first_ground' => 1,
        'captive_freed' => 1,
    ];

    /**
     * Paid ONCE for a given subject, however often the tale comes back to it —
     * the memento rule for new country, in coin. Crossing back over old ground
     * is not the first time you ever saw it, and a border you can walk in a
     * circle would be a point printer.
     *
     * Everything else is guarded only against writing the same turn twice, so a
     * re-resolved turn cannot pay out again while two different elites in two
     * different scenes still both count.
     *
     * @var list<string>
     */
    private const ONCE_PER_TALE = ['first_ground'];

    /**
     * What one STEP of a magnitude is, per parameterized capability.
     *
     * Deepening something already held costs a point per step, which is why the
     * step has to mean something different for a reach measured in feet than for
     * a leap graded one to three. These are the granularities the catalog's own
     * tiers move in — the gap between `long-reach` and `prehensile-grip`, or
     * between `strong-back` and `titan-strength` — so a growth path and a
     * creation path arrive at the same sheet for comparable coin.
     *
     * @var array<string, int>
     */
    private const MAGNITUDE_STEPS = [
        'reach' => 4,
        'lift' => 40,
        'leap' => 1,
        'carry_extra' => 1,
    ];

    /**
     * Plain, setting-neutral words for each earn. The ledger is read by the
     * player, so it says what happened rather than what fired — and it has to
     * read honestly on an ash steppe and aboard a derelict station alike.
     *
     * @var array<string, string>
     */
    private const LABELS = [
        'elite_beaten' => '{subject}, brought down',
        'endeavor_filled' => '{subject}, seen all the way through',
        'rival_settled' => 'the score with {subject}, closed',
        'first_ground' => 'first ground in {subject}',
        'captive_freed' => '{subject}, out of the grip',
    ];

    /**
     * Pay the tale's own moments into the ledger.
     *
     * Called from the resolver with the SAME candidate list the shelf is handed
     * — the moments are already detected, and detecting them twice is how two
     * readings of one turn start to disagree. Everything not on the closed earn
     * table above is ignored rather than trusted.
     *
     * @param  list<array{trigger:string, subject:string, place:string}>  $moments
     * @return int The points written, which is 0 on most turns.
     */
    public static function earn(Turn $turn, array $moments): int
    {
        $character = $turn->campaign?->character;

        if ($turn->campaign_id === null || $character === null || $moments === []) {
            return 0;
        }

        $written = 0;

        foreach ($moments as $moment) {
            $event = (string) ($moment['trigger'] ?? '');
            $points = self::pointsFor($event);

            if ($points <= 0) {
                continue;
            }

            $label = self::label($event, (string) ($moment['subject'] ?? 'something'));

            if (self::alreadyPaid($turn, $event, $label)) {
                continue;
            }

            GrowthEntry::create([
                'campaign_id' => $turn->campaign_id,
                'character_id' => $character->id,
                'turn_id' => $turn->id,
                'kind' => GrowthEntry::EARN,
                'points' => $points,
                'event' => $event,
                'label' => $label,
            ]);

            $written += $points;
        }

        return $written;
    }

    /** Earns minus spends. The only balance there is; nothing caches it. */
    public static function balance(Character $character): int
    {
        $rows = GrowthEntry::where('character_id', $character->id)
            ->selectRaw('kind, SUM(points) as total')
            ->groupBy('kind')
            ->pluck('total', 'kind');

        return (int) ($rows[GrowthEntry::EARN] ?? 0) - (int) ($rows[GrowthEntry::SPEND] ?? 0);
    }

    /**
     * What a granted change costs, in the same coin creation charged.
     *
     * A capability the sheet does not carry yet costs its catalog price. One it
     * already carries costs a point per step of deepening, which is what makes
     * "a longer reach" cheaper than "a whole new limb" — the thing the growth
     * interview has always said in words and never enforced.
     *
     * Never negative. A burden arriving through growth (a large frame prices
     * below zero at creation) pays back NOTHING: refunding here would make
     * asking to be worse a way to shop, which is a farming vector wearing a
     * character-development costume.
     *
     * @param  array{capability?:string, magnitude?:int|null, grade?:string|null, scope?:array|null}  $entry
     */
    public static function price(Character $character, array $entry): int
    {
        $name = (string) ($entry['capability'] ?? '');
        $existing = $character->capabilities()->where('capability', $name)->first();

        if ($existing === null) {
            return max(0, TraitCatalog::capabilityCost($entry));
        }

        $before = $existing->magnitude;
        $after = $entry['magnitude'] ?? null;

        // A real deepening: one point per step, rounded up, and never free
        // while the number actually moved.
        if ($before !== null && $after !== null && $after > $before) {
            $step = self::MAGNITUDE_STEPS[$name] ?? 1;

            return max(1, (int) ceil(($after - $before) / $step));
        }

        // Not a magnitude — a grade or a scope moving, or a magnitude that
        // stayed put or came back smaller. Priced by what the catalog says the
        // two versions are worth, floored at nothing gained, nothing owed.
        return max(0, TraitCatalog::capabilityCost($entry) - TraitCatalog::capabilityCost([
            'capability' => $name,
            'magnitude' => $before,
            'grade' => $existing->grade,
        ]));
    }

    /**
     * The whole ask, priced together. All of it or none of it: a half-granted
     * change would leave the player paying for a sheet they cannot read back
     * off the reply they were given.
     *
     * @param  list<array>  $entries
     */
    public static function priceAll(Character $character, array $entries): int
    {
        return array_sum(array_map(fn (array $entry) => self::price($character, $entry), $entries));
    }

    /** Write the spend. One row per granted ask, naming what it bought. */
    public static function spend(Character $character, int $points, string $label, ?Turn $turn = null): ?GrowthEntry
    {
        if ($points <= 0) {
            return null;
        }

        return GrowthEntry::create([
            'campaign_id' => $character->campaign_id,
            'character_id' => $character->id,
            'turn_id' => $turn?->id,
            'kind' => GrowthEntry::SPEND,
            'points' => $points,
            'event' => 'growth_granted',
            'label' => mb_substr($label === '' ? 'what the world granted' : $label, 0, 180),
        ]);
    }

    /**
     * The balance in plain words — for the growth panel and for the growth
     * prompt, which are the only two places it is ever spoken.
     *
     * A stated zero, never a hidden panel: "nothing in hand yet" is information,
     * and a player who cannot see an empty purse thinks the world is refusing
     * them for some other reason.
     */
    public static function balanceLine(Character $character): string
    {
        $balance = self::balance($character);

        return match (true) {
            $balance <= 0 => 'Nothing in hand yet — what a tale teaches is earned inside it first.',
            $balance === 1 => '1 point in hand, earned in this tale.',
            default => "{$balance} points in hand, earned in this tale.",
        };
    }

    /**
     * What the play page shows beside the growth conversation. Always present,
     * always a number: the panel states a zero rather than hiding itself.
     *
     * @return array{balance: int, line: string}
     */
    public static function panel(Character $character): array
    {
        return [
            'balance' => self::balance($character),
            'line' => self::balanceLine($character),
        ];
    }

    /** What one earn is worth, off config with the closed table as the floor. */
    private static function pointsFor(string $event): int
    {
        if (! in_array($event, self::EARNS, true)) {
            return 0;
        }

        return max(0, (int) config("game.growth.earn.{$event}", self::POINTS[$event]));
    }

    private static function label(string $event, string $subject): string
    {
        return mb_substr(strtr(self::LABELS[$event] ?? '{subject}', ['{subject}' => $subject]), 0, 180);
    }

    /**
     * Has this exact moment already been paid for?
     *
     * New country is paid once per tale, keyed to the ground itself. Everything
     * else only guards its own turn, so a resolution that runs twice cannot
     * double-pay while two separate elites still earn separately.
     */
    private static function alreadyPaid(Turn $turn, string $event, string $label): bool
    {
        $query = GrowthEntry::where('campaign_id', $turn->campaign_id)
            ->where('kind', GrowthEntry::EARN)
            ->where('event', $event)
            ->where('label', $label);

        if (! in_array($event, self::ONCE_PER_TALE, true)) {
            $query->where('turn_id', $turn->id);
        }

        return $query->exists();
    }
}
