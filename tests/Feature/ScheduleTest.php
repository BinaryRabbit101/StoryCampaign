<?php

namespace Tests\Feature;

use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Tests\TestCase;

/**
 * The evolution run ends by pushing a chronicle, so its scheduled hour is the
 * hour a player's phone buzzes. An unanchored time is scheduled in
 * `app.timezone` — normally UTC — which once put "The world changed overnight"
 * on a Central-time phone at 11:30 PM. The timezone must always be explicit.
 */
class ScheduleTest extends TestCase
{
    private function evolveEvent(): Event
    {
        $event = collect(app(Schedule::class)->events())
            ->first(fn (Event $e) => str_contains($e->command ?? '', 'game:evolve'));

        $this->assertNotNull($event, 'the world-evolution run is not scheduled');

        return $event;
    }

    public function test_the_evolution_run_is_anchored_to_an_explicit_timezone()
    {
        config(['game.schedule_timezone' => 'America/Chicago']);
        $this->refreshApplicationWithSchedule();

        $this->assertSame('America/Chicago', (string) $this->evolveEvent()->timezone);
    }

    public function test_the_evolution_hour_is_configurable_and_lands_in_the_morning()
    {
        config(['game.evolution_at' => '07:30']);
        $this->refreshApplicationWithSchedule();

        // dailyAt('07:30') compiles to "30 7 * * *".
        $this->assertSame('30 7 * * *', $this->evolveEvent()->expression);
    }

    public function test_turn_resolution_still_sweeps_on_its_own_short_cycle()
    {
        $sweep = collect(app(Schedule::class)->events())
            ->first(fn (Event $e) => str_contains($e->command ?? '', 'game:resolve-due'));

        $this->assertNotNull($sweep);
        $this->assertSame('*/5 * * * *', $sweep->expression);
    }

    /** Re-register the console routes so config changes reach the schedule. */
    private function refreshApplicationWithSchedule(): void
    {
        $schedule = app(Schedule::class);
        (fn () => $this->events = [])->call($schedule);

        require base_path('routes/console.php');
    }
}
