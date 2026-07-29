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
    | Late narration
    |--------------------------------------------------------------------------
    | A resolved turn whose chapter has not been written this long is no
    | longer "being written" — something is broken, and the play page says so
    | instead of breathing at the player forever. The sweep keeps retrying
    | regardless; this only governs when the wait stops being presented as
    | normal.
    */

    'narration_late_minutes' => env('GAME_NARRATION_LATE_MINUTES', 5),

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
        // Preferred over the literal above: a path to a file holding nothing
        // but the token, READ FRESH ON EVERY RUN.
        //
        // Two failures this exists to end. A token pasted into .env is baked
        // into the config cache, so the value the app actually uses drifts
        // away from the value in .env the moment one is edited without the
        // other — narration once ran for hours on a cached token that .env
        // had already replaced, and nothing on the box agreed about which
        // credential was live. And a token per site means rotating it is a
        // sweep across every .env on the machine, where the one you forget
        // fails silently. One file, shared, re-read per call: rotation is a
        // single edit that takes effect immediately and cannot leave a site
        // behind.
        'oauth_token_file' => env('CLAUDE_OAUTH_TOKEN_FILE'),
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
            'daily' => ['zones' => 0, 'features' => 3, 'actors' => 2, 'items' => 1, 'affordance_types' => 1, 'grudges' => 2],
            'weekly' => ['zones' => 1, 'features' => 6, 'actors' => 4, 'items' => 2, 'affordance_types' => 2, 'grudges' => 3],
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
    | Downtime (the idle wait, spent)
    |--------------------------------------------------------------------------
    | Closing the app is a move. When a turn resolves the engine offers a small
    | closed set of stances for the wait ahead, and the pick pays out from the
    | REAL elapsed minutes when the player comes back.
    |
    | The two bounds are the whole economy. The floor stops benefit-farming by
    | rapid re-submits: a wait shorter than this was not a wait, and pays
    | nothing. The cap stops the game rewarding absence: past it, nothing more
    | accrues, so an idle game never punishes coming back sooner. One health an
    | hour means a night's sleep restores most of the default ten-point pool
    | and a coffee break restores none of it.
    */

    'downtime' => [
        'floor_minutes' => 20,
        'cap_minutes' => 480,
        'rest_heal_per_hour' => 1,
    ],

    /*
    |--------------------------------------------------------------------------
    | Ambient conditions (the air a scene stands in)
    |--------------------------------------------------------------------------
    | One weighted roll per dressed scene, from the scene's own seeded dice,
    | fixed for that scene's life. The keys are abstract because the engine has
    | to speak them on an ash steppe and aboard a derelict station alike:
    | `gloom` is low light, `haze` is obscured air, `squall` is violent air,
    | `clear` is the baseline. The narrator turns the key into this land's own
    | weather; the engine never says "rain".
    |
    | Weighted hard toward clear on purpose — ambient is seasoning, and a world
    | that is always dramatic is never dramatic. Squall is rarest because it is
    | the most intrusive: it is the one that closes off the high ground.
    |
    | Prices for each key live in App\Game\Engine\Odds::AMBIENT. This block only
    | decides how often the world reaches for one.
    */

    'ambient' => [
        'weights' => [
            'clear' => 50,
            'gloom' => 22,
            'haze' => 18,
            'squall' => 10,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | The hour (the wheel the wait turns)
    |--------------------------------------------------------------------------
    | A four-phase wheel on the campaign — dawn, day, dusk, night — advanced by
    | the turns played and by the REAL minutes of the idle wait. The keys are
    | abstract for the same reason ambient's are: a derelict station has no sun,
    | so the engine never says sunrise and the narrator renders the phase in
    | whatever way this land keeps time.
    |
    | `turns_per_phase` is the played rhythm: three beats of story and the light
    | has moved on. `minutes_per_phase` is the real one — four hours, so a lunch
    | break changes nothing and a genuine night away comes back to changed
    | light. A single wait can never carry the wheel more than one full turn
    | around, which is why a week away is a day later rather than a modulo.
    |
    | Prices for each phase live in App\Game\Engine\Odds::HOURS with every other
    | number the dice honor. This block only decides how fast the wheel turns.
    */

    'hours' => [
        'turns_per_phase' => 3,
        'minutes_per_phase' => 240,
    ],

    /*
    |--------------------------------------------------------------------------
    | Bargain cards (a complication with a price tag)
    |--------------------------------------------------------------------------
    | Sometimes a card is offered twice: the honest version, and the same beat
    | with a named consequence traded for a named edge — both quoted before the
    | commit, and the consequence paid whether the roll lands or not.
    |
    | `chance` is how often a turn that HAS an honest deal available actually
    | puts one on the table. Deliberately occasional: a deal every turn is not
    | a decision, it is a tax on reading the list. `per_turn` caps how many may
    | be offered at once — one, so the turn still has a shape.
    |
    | The deals themselves are two closed lists and neither is tunable here:
    | the edge lives in App\Game\Engine\Odds::BARGAINS with every other number
    | the dice honor, and the complication in App\Game\Engine\Bargains.
    */

    'bargains' => [
        'chance' => 0.35,
        'per_turn' => 1,
    ],

    /*
    |--------------------------------------------------------------------------
    | Fortune (the world's weather on the beats that never roll)
    |--------------------------------------------------------------------------
    | Quiet beats cast a fortune d20 beside their certain outcome: faces at or
    | above lucky_from break the moment their way, faces at or below unlucky_to
    | break it against them, and the wide middle is silence. The beat's own
    | promise is untouched either way.
    */

    'fortune' => [
        'lucky_from' => 18,
        'unlucky_to' => 2,
    ],

    /*
    |--------------------------------------------------------------------------
    | Scars (going down marks you instead of erasing you)
    |--------------------------------------------------------------------------
    | Health reaching zero used to be a dead end: `status = 'downed'` and
    | nothing followed. Permadeath would fight the keepsake-book identity and a
    | consequence-free faint would gut the stakes, so a fall is the third thing:
    | the character survives it carrying an engine-rolled permanent burden, and
    | the tale bends around the recovery.
    |
    | `max_before_end` is how many scars a body may carry. The fall AFTER that
    | closes the campaign through the book's early-end/coda path — the tale of
    | someone who spent everything. The cap is what stops a death spiral: two
    | stacked burdens make the third fall likelier, and a third stacked burden
    | would make it inevitable and miserable.
    |
    | `wake_health_fraction` is what they wake with. Never respawn-at-full: the
    | fall has to still be standing there in the numbers when they come round.
    |
    | Which scar lands is NOT tunable here. That table is App\Game\ScarCatalog,
    | and its prices live in App\Game\Engine\Odds::SCARS with every other number
    | the dice honor.
    */

    'scars' => [
        'max_before_end' => 2,
        'wake_health_fraction' => 0.5,
    ],

    /*
    |--------------------------------------------------------------------------
    | Mementos (what you carried home)
    |--------------------------------------------------------------------------
    | Notable resolved moments leave a keepsake behind, and the shelf compiles
    | into the finished book as its closing section. They grant nothing, ever —
    | which is why play may mint them at all, where items still enter a world
    | only through evolution.
    |
    | Both numbers exist to keep the shelf SPARSE. A chapter may leave at most
    | one thing behind (when several moments qualify, the rarest wins — the
    | priority order is the trigger list in App\Services\Mementos), and a tale
    | leaves about a dozen. Forty trinkets is an inventory; nine is a life, and
    | a keepsake that turns up every turn stops being a keepsake by the third one.
    |
    | WHICH moments qualify is not tunable here: that is the closed trigger
    | list, detected by the resolver from facts it has already fixed.
    */

    'mementos' => [
        'per_chapter' => 1,
        'per_campaign' => 12,
    ],

    /*
    |--------------------------------------------------------------------------
    | Companions (what accumulates between you and whoever walks beside you)
    |--------------------------------------------------------------------------
    | A bond is one small integer on the companion's own actor row, moved ONLY
    | by engine events the resolver already rolled — a request that landed, a
    | block that took the blow meant for the player, a night at a fire, a border
    | crossed together. Narration never moves it, and Claude is only ever told
    | the tier in plain words.
    |
    | The thresholds are the whole shape of the arc. `fellow` at two means the
    | signature request arrives inside the first fight or two — early enough to
    | feel like the companion becoming themselves rather than a reward unlocked.
    | `sworn` at five is deliberately several chapters out: the interception is
    | the emotional payload of the feature and it has to be earned, or the fall
    | that did not happen means nothing.
    |
    | `cap` is two because coordinating a third is a spreadsheet, not a party —
    | while full, a candidate's offer simply never fires.
    |
    | The two finding odds are the world's side of it. `grateful` is generous
    | (a rescue is rare and the offer is refusable) and `stray` is deliberately
    | tiny: it fires per spawned soul, and a scene where every bystander tags
    | along is a scene with no bystanders in it.
    |
    | `sworn_final` is the one place a companion can be lost for good to an
    | interception. Rare, and only in a fight the player did not win.
    */

    /*
    | `creation_cost` is what somebody already at your side costs at creation,
    | in the same coin every gift is priced in. A companion is mechanical power
    | — a whole extra beat every turn, in their own slot — so a crew, a friend,
    | or a pet that walked out of the interview for free would be the one gift
    | in the game nobody paid for. Priced, it is an ordinary choice on the
    | ledger the player can read while they talk.
    |
    | `creation_cap` is one, not two: the world still has to have somewhere to
    | put the grateful and the stray, and a party that arrives full has closed
    | every door the companion system opens.
    */

    'companions' => [
        'cap' => 2,
        'creation_cost' => 2,
        'creation_cap' => 1,
        'bond_max' => 6,
        'tiers' => ['fellow' => 2, 'sworn' => 5],
        'grateful_chance' => 0.40,
        'stray_chance' => 0.08,
        // Scenes a stray must walk before it will ask to stay.
        'stray_scenes' => 2,
        'sworn_final_chance' => 0.25,
    ],

    /*
    |--------------------------------------------------------------------------
    | Rumors (the world's news, reaching the character)
    |--------------------------------------------------------------------------
    | The evolver tends each tale's world overnight and the Chronicle tells the
    | READER about it. These are the same facts reaching the CHARACTER — days
    | late, one at a time, through channels the turn already produced.
    |
    | All three numbers exist to keep it RARE. `per_run` is how many pieces of
    | gossip one night's tending is worth: a run is a night in a tavern, not a
    | gazette. `queue` is how much unheard news the world will hold on to
    | before the oldest of it simply goes stale — without it the character
    | would still be hearing about last month at the end of the tale.
    | `per_chapter` is one, and a chapter is one turn's telling: two pieces of
    | news in a page is a briefing, not a conversation.
    |
    | WHICH moments deliver is not tunable: that is the closed channel list in
    | App\Services\Rumors, detected by the resolver from facts it has already
    | fixed. And nothing here can make one up — an empty queue is silence.
    */

    'rumors' => [
        'per_run' => 3,
        'queue' => 12,
        'per_chapter' => 1,
    ],

    /*
    |--------------------------------------------------------------------------
    | Endeavor clocks (the goal the player is working toward)
    |--------------------------------------------------------------------------
    | The alarm clock above counts up AGAINST the player while they stand and
    | fight. An endeavor is the same clock turned around: a multi-turn goal the
    | engine offered, the player spent a whole beat committing to, and ordinary
    | qualifying beats fill.
    |
    | The segment band is what makes one feel like a commitment rather than a
    | chore. Under four and it fills inside one scene, which is not a goal, it
    | is a delay; over six and it can never be finished before the ground
    | changes underneath it.
    |
    | `offer_chance` is how often a scene that COULD support an endeavor
    | actually puts one on the table. Deliberately occasional — the harder caps
    | are structural and not tunable here: one endeavor per tale at a time, and
    | at most one ever proposed per scene however that one ended.
    |
    | WHICH endeavors exist, which verbs move them, and what filling one pays
    | are not tunable either: that is the closed table in
    | App\Game\Engine\Clocks, and its payoffs route through machinery that
    | already existed — the engine's own reveal, a feature's destroyed state,
    | and one existing condition out of App\Game\Engine\Odds::CONDITIONS.
    */

    'clocks' => [
        'min_segments' => 4,
        'max_segments' => 6,
        'offer_chance' => 0.5,
    ],

    /*
    |--------------------------------------------------------------------------
    | Side threads (someone else's small story)
    |--------------------------------------------------------------------------
    | A non-hostile soul the dressing put on the ground may be in the middle of
    | something of their own: a short engine-authored arc the player discovers by
    | talking to them, helps through cards they were already being offered, or
    | ignores at a real cost to that person and none at all to themselves.
    |
    | `offer_chance` is deliberately tiny. It fires per spawned soul, and a scene
    | where every bystander needs something is a quest board, not a place. `active`
    | is one for the same reason the endeavor cap is one: two small stories running
    | at once is a subplot ledger, and neither of them gets noticed.
    |
    | `expiry_chapters` only ever binds the walking kind — the rooted kinds run out
    | of GROUND first, at the border, because a want about this place cannot follow
    | the tale off it. It is the patience of somebody who is not the protagonist:
    | long enough to be answerable, short enough that ignoring them means something.
    |
    | WHICH wants exist, what moves them, and what seeing one through pays are not
    | tunable: that is the closed kind table in App\Game\Engine\Threads, and every
    | payoff routes through machinery that already existed — the engine's own
    | reveal, one rumors row, and the consensual welcome/decline pair Companions
    | already owns. Nothing here can cost the player a number, and nothing ever may.
    */

    'threads' => [
        'offer_chance' => 0.12,
        'active' => 1,
        'expiry_chapters' => 8,
    ],

    /*
    |--------------------------------------------------------------------------
    | Pressure (the world moves when you don't)
    |--------------------------------------------------------------------------
    | A stall counter on the scene fills while a turn casts no die, the player
    | stays put, and nobody is fighting. At the threshold the world spends the
    | initiative it was handed: one beat from a closed table, rolled with the
    | turn's own seeded dice and executed through machinery that already existed.
    |
    | The two weights ARE the cadence, and the cadence is the whole feature.
    | An explicit wait is an invitation and out-paces idle poking, so two waits
    | reach the threshold and three quiet turns of anything else also reach it.
    | Raising the threshold does not make the world calmer — it makes waiting a
    | lie again, which is the wound this heals.
    |
    | Pressure is combat-silent by construction: while an enemy stands in the
    | open the alarm clock above owns escalation, and a second escalator on the
    | same fight would be difficulty creep in a costume.
    |
    | The beat weights only decide how often the world reaches for each kind of
    | move. WHICH beats are even eligible is not tunable: a beat that could not
    | cost or change anything here is filtered out of the pool before any of
    | these numbers are read, and an empty pool holds the counter rather than
    | inventing content. That table is App\Game\Engine\Pressure.
    */

    'pressure' => [
        'quiet_weight' => 1,
        'wait_weight' => 2,
        'threshold' => 3,
        'beats' => [
            'arrival' => 30,
            'reveal' => 25,
            'grudge' => 15,
            'mishap' => 30,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Standing (places remember what you did there)
    |--------------------------------------------------------------------------
    | A campaign-scoped ledger per zone, moved ONLY by the closed event table in
    | App\Game\Engine\Standings — facts the resolver had already fixed. WHICH
    | events move it is not tunable here, and neither is what it is worth: one
    | itemized point on the social verbs, no matter how far the score has run.
    | The evolver never touches it.
    |
    | `clamp` is the whole shape of the feature. Three either way is a place
    | that greets you differently; ten would be a reputation stat to farm.
    | Raising it does not make standing matter more — it only makes it take
    | longer to come back from, in a game where coming back is the point.
    |
    | `wrecks_per_scene` keeps one bad afternoon from being ten of them. A
    | player who takes four crates apart in one room took one room apart.
    */

    'standing' => [
        'clamp' => 3,
        'wrecks_per_scene' => 1,
    ],

    /*
    |--------------------------------------------------------------------------
    | The finale (a tale that ends on a peak)
    |--------------------------------------------------------------------------
    | Tales used to STOP rather than conclude: the scar cap ended them and
    | nothing else did. Every system above now produces climax material — a
    | nemesis, unfinished business, permanent cost, a shelf of keepsakes — and
    | nothing spent it. The finale is a curator: it reads how much a tale has
    | accumulated, ARMS an ending it offers and never forces, and stages the
    | last of it out of what the tale itself built.
    |
    | `chapter_floor` is the whole guard against a short tale being offered an
    | ending it has not earned. Signals alone cannot arm it: a campaign that
    | took two scars in its first three chapters is a rough opening, not a
    | third act.
    |
    | `arm_threshold` is how much standing debt makes an ending honest. The
    | weights are the shape of that judgement: a nemesis at full heat is worth
    | two because a tale with one already has its last scene written; the rest
    | are worth one apiece, and keepsakes are counted in fours because the shelf
    | fills on its own and would otherwise arm every tale by attrition.
    |
    | Nothing here can force an ending. What these numbers decide is when a card
    | APPEARS; declining it costs nothing, forever.
    */

    'finale' => [
        'chapter_floor' => 8,
        'arm_threshold' => 4,
        'weights' => [
            'max_heat_grudge' => 2,
            'clock_filled' => 1,
            'zone_beyond_first' => 1,
            'scar' => 1,
            'mementos' => 1,
        ],
        // How many keepsakes are worth one signal.
        'mementos_per_signal' => 4,
    ],

    /*
    |--------------------------------------------------------------------------
    | Previously On (the returning-player recap)
    |--------------------------------------------------------------------------
    | A short, dismissible panel above the form, compiled from strings the
    | engine already persisted — never generated, never a Claude call.
    |
    | `absence_hours` is the whole gate: below it the player has not been away
    | long enough to have lost the thread, and a recap of a turn they took over
    | breakfast is noise. Twelve hours is one night, which is the absence this
    | game is actually built around.
    |
    | `fact_lines` keeps the middle section skimmable. Four lines is a glance;
    | ten is the chapter again, badly, and a player who has to read the recap
    | properly would rather have read the chapter.
    */

    'recap' => [
        'absence_hours' => 12,
        'fact_lines' => 4,
    ],

    /*
    |--------------------------------------------------------------------------
    | Echoes (the shelf of finished books becomes one library)
    |--------------------------------------------------------------------------
    | Every tale used to begin in total amnesia. When a moment in the current
    | one rhymes with a moment a CLOSED book already preserved — a mark taken
    | where a mark was once taken, a score settled, familiar land underfoot —
    | the engine may surface that preserved line as a memory. Quotation, never
    | invention: an empty shelf of finished tales is simply silence, and Claude
    | is never asked to remember on the player's behalf.
    |
    | All three numbers exist to keep it FOUND rather than announced. `chance`
    | is how often a qualifying rhyme actually speaks up — a memory that
    | surfaces every time the trigger fires is a notification, not a memory.
    | `campaign_cap` is the ceiling on a whole tale: four is a handful of
    | moments where the shelf leans in, and a fifth would start reading as a
    | mechanic. `cooldown_chapters` keeps two of them out of the same stretch;
    | a chapter is one turn's telling, so the wait is counted in turns.
    |
    | WHICH moments rhyme and WHICH column each draws from are not tunable
    | here: that is the closed rhyme table in App\Services\Echoes, detected by
    | the resolver from facts it has already fixed.
    */

    'echoes' => [
        'chance' => 0.25,
        'campaign_cap' => 4,
        'cooldown_chapters' => 3,
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
