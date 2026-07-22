<?php

namespace App\Console\Commands;

use App\Game\Engine\TurnResolver;
use App\Models\Turn;
use App\Services\Claude\Narrator;
use Illuminate\Console\Command;
use Throwable;

/**
 * The turn-resolution sweep: resolves locked turns whose cadence window has
 * passed, then narrates any resolved-but-unnarrated turns (narration is
 * retryable — a failed Claude call never loses the resolution).
 */
class ResolveDueTurns extends Command
{
    protected $signature = 'game:resolve-due {--force : Ignore the cadence window and resolve immediately}';

    protected $description = 'Resolve due turns and narrate their chapters';

    public function handle(TurnResolver $resolver, Narrator $narrator): int
    {
        $locked = Turn::where('status', Turn::STATUS_LOCKED)->get()
            ->filter(fn (Turn $turn) => $this->option('force') || $turn->isDueForResolution());

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
            } catch (Throwable $e) {
                $this->error("Turn {$turn->id} failed to narrate (will retry next sweep): {$e->getMessage()}");
                report($e);
            }
        }

        return self::SUCCESS;
    }
}
