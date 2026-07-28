<?php

namespace App\Game;

use App\Models\Character;
use Illuminate\Support\Carbon;

/**
 * Meter handling. Decided defaults for open thread #1: tempo charges
 * regenerate in real time across the idle wait (the cadence itself refills
 * them); health only recovers through recovery beats or narrated downtime.
 */
class Meters
{
    public static function default(): array
    {
        return [
            'health' => ['current' => 10, 'max' => 10],
            'tempo' => [], // per-capability charge pools, e.g. "time_slow" => ['current'=>2,'max'=>3]
        ];
    }

    /** Apply real-time tempo regen for the elapsed idle window. */
    public static function regenerate(Character $character, ?Carbon $now = null): void
    {
        $now = $now ?? now();
        $since = $character->meters_regenerated_at ?? $character->created_at;
        $minutes = max(0, $since->diffInMinutes($now));
        $rate = (float) config('game.meters.tempo_regen_per_minute');
        $gain = (int) floor($minutes * $rate);

        $meters = $character->meters;
        if ($gain > 0) {
            foreach ($meters['tempo'] ?? [] as $name => $pool) {
                $meters['tempo'][$name]['current'] = min($pool['max'], $pool['current'] + $gain);
            }
        }

        // Only advance the anchor by the minutes actually converted into
        // charges, so fractional progress is never silently discarded.
        $consumedMinutes = $rate > 0 ? (int) floor($gain / $rate) : $minutes;

        $character->forceFill([
            'meters' => $meters,
            'meters_regenerated_at' => $gain > 0 ? $since->copy()->addMinutes($consumedMinutes) : $since,
        ])->save();
    }

    public static function spend(Character $character, string $meter, int $amount): bool
    {
        $meters = $character->meters;

        if ($meter === 'health') {
            return false; // health is never a spendable cost
        }

        $pool = $meters['tempo'][$meter] ?? null;
        if ($pool === null || $pool['current'] < $amount) {
            return false;
        }

        $meters['tempo'][$meter]['current'] -= $amount;
        $character->forceFill(['meters' => $meters])->save();

        return true;
    }

    public static function damage(Character $character, int $amount): void
    {
        $meters = $character->meters;
        $meters['health']['current'] = max(0, $meters['health']['current'] - $amount);
        $character->forceFill(['meters' => $meters])->save();

        // Zero writes `downed` and nothing more — deliberately. What FOLLOWS a
        // fall (the scar, the waking, the end of the tale) is the resolver's
        // business at the end of the turn, in App\Game\Engine\Scars: damage is
        // applied from a dozen places mid-chain, and rolling a permanent mark
        // from inside one of them would fire before the turn had finished
        // happening.
        if ($meters['health']['current'] === 0) {
            $character->forceFill(['status' => 'downed'])->save();
        }
    }

    public static function heal(Character $character, int $amount): void
    {
        $meters = $character->meters;
        $meters['health']['current'] = min($meters['health']['max'], $meters['health']['current'] + $amount);
        $character->forceFill(['meters' => $meters])->save();
    }

    public static function healthInDangerBand(Character $character): bool
    {
        $health = $character->meters['health'];

        return $health['current'] <= (int) ceil($health['max'] * (float) config('game.meters.health_danger_fraction'));
    }

    public static function charges(Character $character, string $meter): int
    {
        return $character->meters['tempo'][$meter]['current'] ?? 0;
    }
}
