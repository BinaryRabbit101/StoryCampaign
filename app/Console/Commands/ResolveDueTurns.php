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
    protected $signature = 'game:resolve-due {--force : Resolve every locked turn, ignoring the abandonment window}';

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

        $unnarrated = Turn::where('status', Turn::STATUS_COMPLETE)
            ->whereNull('narrated_at')
            ->whereNotNull('resolution')
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
