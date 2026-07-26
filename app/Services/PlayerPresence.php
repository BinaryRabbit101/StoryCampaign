<?php

namespace App\Services;

use App\Models\Campaign;
use Illuminate\Support\Facades\Cache;

/**
 * Is the player looking at this tale right now?
 *
 * The turn-ready push exists for the player who walked away — the whole point
 * of an idle RPG is that the chapter finds you. It is noise for the player
 * sitting on the play page watching the dots, and a session of back-to-back
 * turns is exactly when that noise arrives thickest: six turns in twenty
 * minutes earned six buzzes for six chapters already on screen.
 *
 * The play page polls every 2.5s while it waits, so a recent request from it
 * is a reliable signal of presence. Cached rather than stored on the campaign:
 * this is ephemeral, it must be visible across php-fpm and cron alike (the
 * database cache store is), and it should expire on its own.
 */
class PlayerPresence
{
    /**
     * How recently the play page must have been loaded to count as watching.
     * Comfortably above the page's 2.5s poll so one dropped request does not
     * read as the player having left, and short enough that a closed tab
     * stops suppressing pushes almost immediately.
     */
    private const WINDOW_SECONDS = 20;

    public static function mark(Campaign $campaign): void
    {
        Cache::put(self::key($campaign), true, self::WINDOW_SECONDS);
    }

    public static function isWatching(Campaign $campaign): bool
    {
        return (bool) Cache::get(self::key($campaign), false);
    }

    private static function key(Campaign $campaign): string
    {
        return "play-presence:{$campaign->id}";
    }
}
