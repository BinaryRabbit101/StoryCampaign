<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// The living world's two scheduled roles: turn resolution/narration and
// world evolution. Each Claude CLI run is stateless — it reads persistent
// world state + logs, does its work, writes back.
Schedule::command('game:resolve-due')->everyFiveMinutes()->withoutOverlapping();
Schedule::command('game:evolve daily')->dailyAt('03:00')->withoutOverlapping();
Schedule::command('game:evolve weekly')->sundays()->at('04:00')->withoutOverlapping();
