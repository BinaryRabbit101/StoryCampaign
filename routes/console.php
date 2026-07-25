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
//
// The run ends in a push, so the hour below is when the player's phone
// buzzes, not merely when the work happens. It is anchored to an explicit
// timezone: Laravel schedules in `app.timezone` (normally UTC), so a bare
// '07:30' is 07:30 UTC — which is how this once arrived at 11:30 PM local,
// under a notification titled "The world changed overnight".
$evolutionCadence = config('game.evolution_schedule');
if (in_array($evolutionCadence, ['daily', 'weekly'], true)) {
    $schedule = Schedule::command("game:evolve {$evolutionCadence}")
        ->withoutOverlapping()
        ->timezone(config('game.schedule_timezone'))
        ->when(fn () => Turn::where(
            'resolved_at', '>=', $evolutionCadence === 'daily' ? now()->subDay() : now()->subWeek(),
        )->exists());
    $evolutionCadence === 'daily'
        ? $schedule->dailyAt(config('game.evolution_at'))
        : $schedule->weeklyOn(1, config('game.evolution_at'));
}
