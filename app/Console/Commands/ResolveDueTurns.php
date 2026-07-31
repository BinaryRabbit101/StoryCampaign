<?php

namespace App\Console\Commands;

use App\Game\Engine\TurnResolver;
use App\Models\Turn;
use App\Services\Claude\ClaudeAuthException;
use App\Services\Claude\Narrator;
use Illuminate\Console\Command;
use Throwable;

/**
 * The safety net, not the main path.
 *
 * Turns resolve inline on submit and narrate immediately after the response
 * is flushed, so in the ordinary course this sweep finds nothing. It exists
 * for the two ways that can fail: a request that died between locking a turn
 * and resolving it, and a Claude call that fell over after the resolution was
 * already safe on disk. Narration is retryable by design — a failed call
 * never costs the resolution behind it.
 */
class ResolveDueTurns extends Command
{
    protected $signature = 'game:resolve-due {--force : Resolve every locked turn and narrate every unwritten one, ignoring the abandonment and grace windows}';

    protected $description = 'Recover abandoned turns and narrate any chapter still unwritten';

    public function handle(TurnResolver $resolver, Narrator $narrator): int
    {
        $locked = Turn::where('status', Turn::STATUS_LOCKED)->get()
            ->filter(fn (Turn $turn) => $this->option('force') || $turn->isAbandoned());

        foreach ($locked as $turn) {
            try {
                $resolver->resolve($turn);
                $this->info("Resolved turn {$turn->number} of campaign {$turn->campaign_id}.");
            } catch (Throwable $e) {
                $turn->update(['status' => Turn::STATUS_LOCKED]);
                $this->error("Turn {$turn->id} failed to resolve: {$e->getMessage()}");
                report($e);
            }
        }

        // The grace window. A turn resolved seconds ago is being narrated
        // right now by the request that resolved it — the Claude call runs
        // well past the minute this sweep fires on, so picking it up here is
        // not recovery, it is a second author. The claim in Narrator::narrate
        // catches what slips through; this keeps the ordinary turn from ever
        // reaching that contest in the first place.
        $grace = now()->subMinutes((int) config('game.narration_grace_minutes', 5));

        $unnarrated = Turn::where('status', Turn::STATUS_COMPLETE)
            ->whereNull('narrated_at')
            ->whereNotNull('resolution')
            ->when(! $this->option('force'), fn ($q) => $q->where(
                fn ($w) => $w->whereNull('resolved_at')->orWhere('resolved_at', '<=', $grace),
            ))
            ->get();

        foreach ($unnarrated as $turn) {
            try {
                $narrator->narrate($turn);
                $this->info("Narrated turn {$turn->number} of campaign {$turn->campaign_id}.");
            } catch (ClaudeAuthException $e) {
                // A refused credential is not this turn's problem, and every
                // other turn in the queue is about to fail the same way. Stop,
                // and say the one thing that is actually wrong — rather than
                // filing a stack trace per turn per minute, which is how a
                // dead token produced 151 identical reports and no signal.
                $this->error('Claude rejected the credential — narration is down until it is replaced. '.$e->getMessage());

                return self::FAILURE;
            } catch (Throwable $e) {
                $this->error("Turn {$turn->id} failed to narrate (will retry next sweep): {$e->getMessage()}");
                report($e);
            }
        }

        return self::SUCCESS;
    }
}
