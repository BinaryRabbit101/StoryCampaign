<?php

namespace App\Console\Commands;

use App\Services\Claude\WorldEvolver;
use Illuminate\Console\Command;

class EvolveWorld extends Command
{
    protected $signature = 'game:evolve {kind=daily : daily, weekly, or manual}';

    protected $description = 'Evolve each recently-played campaign\'s world (Claude proposes, engine clamps, Chronicle narrates)';

    public function handle(WorldEvolver $evolver): int
    {
        $runs = $evolver->evolve($this->argument('kind'));

        if ($runs === []) {
            $this->info('No world was walked in the window — nothing to evolve.');

            return self::SUCCESS;
        }

        $failed = false;
        foreach ($runs as $run) {
            $campaign = $run->changes['campaign']['name'] ?? '?';
            $this->info("Evolution run {$run->id} [{$run->kind}] for \"{$campaign}\": {$run->status}.");
            if ($run->chronicle) {
                $this->line($run->chronicle);
            }
            $failed = $failed || $run->status !== 'complete';
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
