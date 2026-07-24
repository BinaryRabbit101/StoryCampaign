<?php

use App\Models\Turn;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Turn resolution/narration. Each Claude CLI run is stateless — it reads
// persistent world state + logs, does its work, writes back.
Schedule::command('game:resolve-due')->everyFiveMinutes()->withoutOverlapping();

// World evolution: the variety engine. Scheduled per config, but
// activity-gated so an idle world never burns a Claude call — the run only
// fires when someone actually played inside the window.
$evolutionCadence = config('game.evolution_schedule');
if (in_array($evolutionCadence, ['daily', 'weekly'], true)) {
    $schedule = Schedule::command("game:evolve {$evolutionCadence}")
        ->withoutOverlapping()
        ->when(fn () => Turn::where(
            'resolved_at', '>=', $evolutionCadence === 'daily' ? now()->subDay() : now()->subWeek(),
        )->exists());
    $evolutionCadence === 'daily'
        ? $schedule->dailyAt('04:30')
        : $schedule->weeklyOn(1, '04:30');
}
