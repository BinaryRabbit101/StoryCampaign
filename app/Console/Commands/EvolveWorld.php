<?php

namespace App\Console\Commands;

use App\Services\Claude\WorldEvolver;
use Illuminate\Console\Command;

class EvolveWorld extends Command
{
    protected $signature = 'game:evolve {kind=daily : daily, weekly, or manual}';

    protected $description = 'Run a scheduled world-evolution pass (Claude proposes, engine clamps, Chronicle narrates)';

    public function handle(WorldEvolver $evolver): int
    {
        $run = $evolver->evolve($this->argument('kind'));

        $this->info("Evolution run {$run->id} [{$run->kind}] {$run->status}.");
        if ($run->chronicle) {
            $this->line($run->chronicle);
        }

        return $run->status === 'complete' ? self::SUCCESS : self::FAILURE;
    }
}
