<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Turn resolution/narration. Each Claude CLI run is stateless — it reads
// persistent world state + logs, does its work, writes back. World evolution
// is not scheduled (it burned a daily Claude call regardless of player
// activity); run `php artisan game:evolve manual` by hand if ever wanted.
Schedule::command('game:resolve-due')->everyFiveMinutes()->withoutOverlapping();
