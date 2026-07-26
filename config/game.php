<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Abandoned-turn recovery
    |--------------------------------------------------------------------------
    | Turns resolve inline the moment they are submitted — there is no waiting
    | window. A turn can only be left sitting in `locked` if the request that
    | was resolving it died mid-flight, so the sweep picks up anything locked
    | for longer than this. Keep it comfortably above the worst honest
    | resolution time, or the sweep will race a request that is still working.
    */

    'abandoned_turn_minutes' => env('GAME_ABANDONED_TURN_MINUTES', 2),

    /*
    |--------------------------------------------------------------------------
    | Claude CLI
    |--------------------------------------------------------------------------
    | The narration and evolution jobs shell out to the Claude CLI. Each run
    | is stateless: it is handed world state + logs and writes back through
    | the engine. Claude never decides outcomes — only words.
    */

    'claude' => [
        'binary' => env('CLAUDE_BINARY', 'claude'),
        'model' => env('CLAUDE_MODEL', 'claude-sonnet-5'),
        'timeout' => env('CLAUDE_TIMEOUT', 600),
        // HOME to expose to the CLI process so it can find ~/.claude auth.
        // Needed when the invoker (php-fpm, cron) runs without the login
        // user's HOME; null inherits the current environment untouched.
        'home' => env('CLAUDE_HOME'),
        // Long-lived subscription token from `claude setup-token`, passed to
        // the CLI as CLAUDE_CODE_OAUTH_TOKEN. Alternative to on-disk
        // ~/.claude credentials; null omits it.
        'oauth_token' => env('CLAUDE_OAUTH_TOKEN'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Design bible
    |--------------------------------------------------------------------------
    | Read-only guardrails for the evolution job and the interview limiter.
    | The markdown file carries tone/theme; the numeric bounds here are the
    | hard clamps the engine enforces regardless of what Claude proposes.
    */

    'design_bible_path' => base_path('design/DESIGN_BIBLE.md'),

    /*
    |--------------------------------------------------------------------------
    | World evolution cadence
    |--------------------------------------------------------------------------
    | 'daily', 'weekly', or 'off'. Scheduled runs are activity-gated: the
    | Claude call only fires if some player resolved a turn since the last
    | window, so an idle world never burns a run. Manual runs
    | (`php artisan game:evolve manual`) work regardless.
    */

    'evolution_schedule' => env('GAME_EVOLVE_SCHEDULE', 'daily'),

    /*
    |--------------------------------------------------------------------------
    | When the world evolves — and therefore when the player's phone buzzes
    |--------------------------------------------------------------------------
    | The evolution run ends by pushing a chronicle ("The world changed
    | overnight"), so this hour is not really a compute window: it is the hour
    | the notification lands. Pick a time the player would want to read it.
    |
    | The timezone MUST be stated. Laravel schedules in `app.timezone`, which
    | is normally UTC — an unanchored '07:30' silently becomes the small hours
    | somewhere, which is exactly how this once fired at 11:30 PM local.
    */

    'evolution_at' => env('GAME_EVOLVE_AT', '07:30'),

    'schedule_timezone' => env('GAME_SCHEDULE_TIMEZONE', env('APP_TIMEZONE', 'UTC')),

    'bounds' => [
        // Absolute magnitude clamps per parameterized capability.
        'capability_magnitudes' => [
            'reach' => ['min' => 1, 'max' => 20],
            'lift' => ['min' => 10, 'max' => 400],
            'leap' => ['min' => 1, 'max' => 3],       // 1 short, 2 medium, 3 far
            'carry_extra' => ['min' => 0, 'max' => 2],
        ],
        // Growth thresholds that re-couple a constraint (open thread #2:
        // decided — strong magnitudes drag new liabilities with them).
        'recoupling' => [
            'reach' => ['at' => 16, 'constraint' => 'unwieldy'],
            'lift' => ['at' => 300, 'constraint' => 'ponderous'],
        ],
        // Per-evolution-run budget: hard caps on how much a single run
        // may add, so the world never sprawls overnight.
        'evolution_budget' => [
            'daily' => ['zones' => 0, 'features' => 3, 'actors' => 2, 'items' => 1, 'affordance_types' => 1],
            'weekly' => ['zones' => 1, 'features' => 6, 'actors' => 4, 'items' => 2, 'affordance_types' => 2],
        ],
        'max_item_power' => 5,
        'max_actor_tier' => 'elite', // evolution may not mint bosses without a weekly run
        // Stage budget: how much scene-scoped content a campaign's opening
        // may build outward from the player's stage. Never items — those
        // enter only through evolution.
        'stage_budget' => ['features' => 4, 'actors' => 3],
        // Zone forge budget: hard caps on a single forged zone (a campaign's
        // starting world or a frontier zone), whatever the LLM proposes.
        'zone_forge' => ['features' => 8, 'actors' => 5, 'locales' => 6],
    ],

    /*
    |--------------------------------------------------------------------------
    | Frontier
    |--------------------------------------------------------------------------
    | After this many scenes played in a zone, the world pre-forges the next
    | zone (during the narration run — never on the player's clock) and a
    | "press on" card opens the way out.
    */

    'frontier_scenes' => 4,

    /*
    |--------------------------------------------------------------------------
    | Creation point-buy
    |--------------------------------------------------------------------------
    | Starting allowance for the trait-catalog path through character
    | creation: gifts cost points, burdens refund them, and the build must
    | at least break even. Prices live in App\Game\TraitCatalog.
    */

    'creation_points' => 3,

    /*
    |--------------------------------------------------------------------------
    | Meters (open thread #1: decided defaults)
    |--------------------------------------------------------------------------
    | Tempo meters regenerate in real time across the wait between turns —
    | the idle cadence itself refills them. Health only recovers through
    | recovery beats (post-slot bandage/rest) or narrated downtime.
    */

    'meters' => [
        'tempo_regen_per_minute' => 1 / 15,   // one charge per 15 idle minutes
        'health_danger_fraction' => 0.25,     // branch trigger #4 threshold
    ],

    /*
    |--------------------------------------------------------------------------
    | Vignette shape
    |--------------------------------------------------------------------------
    */

    'vignette' => [
        'soft_timeout_beats' => 8,   // branch trigger #8: stop a quiet vignette after this many beats
        'min_cards_per_stop' => 2,   // every stop must offer >= 2 legal cards
    ],
];
