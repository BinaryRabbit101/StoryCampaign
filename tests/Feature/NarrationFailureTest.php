<?php

namespace Tests\Feature;

use App\Game\Meters;
use App\Models\Campaign;
use App\Models\Chapter;
use App\Models\Character;
use App\Models\Turn;
use App\Models\User;
use App\Notifications\TurnReadyNotification;
use App\Services\Claude\ClaudeCli;
use App\Services\Claude\Narrator;
use App\Services\PlayerPresence;
use App\Services\TurnStarter;
use Database\Seeders\WorldSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * What the game does when the narrator stops answering, and who it interrupts
 * when it does answer.
 *
 * Narration is the one part of a turn that can fail without costing the player
 * anything mechanical — the dice are already cast and on disk. Everything here
 * is about that failure staying honest: visible when it happens, silent when
 * the player is already watching, and never hiding the story they own.
 */
class NarrationFailureTest extends TestCase
{
    use RefreshDatabase;

    private function activeCampaign(): Campaign
    {
        $this->seed(WorldSeeder::class);

        $this->mock(ClaudeCli::class, function ($mock) {
            $mock->shouldReceive('promptForJson')->andThrow(new \RuntimeException('offline'))->byDefault();
            $mock->shouldReceive('prompt')->andReturn('A tale begins.')->byDefault();
        });

        $campaign = Campaign::create([
            'user_id' => User::factory()->create()->id,
            'name' => 'Test Tale',
            'world_flavor' => 'harbor-city',
            'status' => 'active',
            'started_at' => now(),
        ]);

        Character::create([
            'campaign_id' => $campaign->id,
            'name' => 'The Cat',
            'description' => 'A striking black cat.',
            'meters' => Meters::default(),
            'status' => 'alive',
            'meters_regenerated_at' => now(),
        ]);

        return $campaign;
    }

    /** A resolved turn with no chapter, stamped as of the given moment. */
    private function unnarratedTurn(Campaign $campaign, \DateTimeInterface $resolvedAt): Turn
    {
        $turn = app(TurnStarter::class)->openFirstTurn($campaign);
        $turn->update([
            'status' => Turn::STATUS_COMPLETE,
            'branch_trigger' => 'decision_point',
            'resolution' => ['beats' => [], 'scene_reaction' => [], 'reaction_rolls' => [], 'new_threat' => null],
            'resolved_at' => $resolvedAt,
        ]);

        return $turn->fresh();
    }

    public function test_a_fresh_narration_is_a_wait_and_a_stale_one_is_a_failure(): void
    {
        $campaign = $this->activeCampaign();

        $turn = $this->unnarratedTurn($campaign, now());
        $this->assertFalse($turn->narrationIsLate(), 'A chapter seconds old is still being written.');

        $turn->update(['resolved_at' => now()->subMinutes((int) config('game.narration_late_minutes') + 1)]);
        $this->assertTrue($turn->fresh()->narrationIsLate());

        // A narrated turn is never late, however long it took.
        $turn->update(['narrated_at' => now()]);
        $this->assertFalse($turn->fresh()->narrationIsLate());
    }

    public function test_the_play_page_stops_calling_a_failure_a_wait_and_gives_the_chapter_back(): void
    {
        $campaign = $this->activeCampaign();
        Chapter::create([
            'campaign_id' => $campaign->id,
            'turn_id' => null,
            'number' => 1,
            'kind' => 'prologue',
            'body' => 'The prologue the player already owns.',
        ]);

        // Still plausibly in flight: the wait is honest, the chapter is held
        // back so the previous one is not mistaken for the new one.
        $turn = $this->unnarratedTurn($campaign, now());
        $this->actingAs($campaign->user)->get(route('play.show', $campaign))
            ->assertInertia(fn ($page) => $page
                ->where('narrating', true)
                ->where('narrationStalled', false));

        // Past the window it is a failure. The breathing panel would lie, and
        // withholding the prologue on top of it leaves the player with an
        // empty screen and no story at all.
        $turn->update(['resolved_at' => now()->subMinutes((int) config('game.narration_late_minutes') + 1)]);
        $this->actingAs($campaign->user)->get(route('play.show', $campaign))
            ->assertInertia(fn ($page) => $page
                ->where('narrating', false)
                ->where('narrationStalled', true)
                ->where('latestChapter.body', 'The prologue the player already owns.'));
    }

    public function test_the_push_skips_the_player_who_is_already_watching(): void
    {
        Notification::fake();
        $campaign = $this->activeCampaign();
        $turn = $this->unnarratedTurn($campaign, now());

        // Loading the play page IS the poll the waiting player is running.
        $this->actingAs($campaign->user)->get(route('play.show', $campaign));
        $this->assertTrue(PlayerPresence::isWatching($campaign));

        $this->mock(ClaudeCli::class, function ($mock) {
            $mock->shouldReceive('promptForJson')->andReturn([
                'intent_line' => 'The cat chose the rooftops.',
                'chapter' => 'A chapter.',
            ]);
        });
        app(Narrator::class)->narrate($turn);

        Notification::assertNothingSent();
        $this->assertSame(1, $campaign->chapters()->count(), 'The chapter is still written and kept.');
    }

    public function test_the_push_still_finds_the_player_who_walked_away(): void
    {
        Notification::fake();
        $campaign = $this->activeCampaign();
        $turn = $this->unnarratedTurn($campaign, now());

        $this->assertFalse(PlayerPresence::isWatching($campaign));

        $this->mock(ClaudeCli::class, function ($mock) {
            $mock->shouldReceive('promptForJson')->andReturn([
                'intent_line' => 'The cat chose the rooftops.',
                'chapter' => 'A chapter.',
            ]);
        });
        app(Narrator::class)->narrate($turn);

        Notification::assertSentTo($campaign->user, TurnReadyNotification::class);
    }
}
