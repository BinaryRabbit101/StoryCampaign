# StoryCampaign — Living-World Idle RPG

Turn-based idle RPG: scheduled Claude CLI runs narrate turn resolutions into
chapters. Players act through one structured form per turn (contextual action
cards, never free text); the engine owns all mechanics and outcomes; Claude
owns narration. Every chapter is persisted and compiles into a keepsake book
at campaign end. Each campaign plays in its OWN forged world: `ZoneForge`
builds the starting zone at creation (colored by premise/tone) and pre-forges
each frontier zone during the narration run once the tale has ranged
`frontier_scenes` scenes in the current zone — a "Press on toward X" venture
card then crosses into it. World evolution (`game:evolve`, `WorldEvolver`,
Chronicle) tends each recently-played campaign's world on an activity-gated
schedule (`GAME_EVOLVE_SCHEDULE` = daily|weekly|off, default daily): one run
per campaign that resolved a turn inside the window, none for idle worlds.

Full design rationale: `design/DESIGN_BIBLE.md` (guardrails) and the original
spec decisions embedded as comments in the code.

## Stack

Laravel 13 + Inertia v3 + Vue 3 + TypeScript + Tailwind 4 (laravel/vue-starter-kit,
same as sibling projects). Fortify auth, Wayfinder, PHPUnit 12, SQLite, PWA
(manifest + `public/sw.js`), web push via `laravel-notification-channels/webpush`.

- Dev: `npm run dev` + `php artisan serve` (or Herd). Build: `npm run build`.
- Tests: `php artisan test`. Format: `vendor/bin/pint --dirty`.
- Turns are REALTIME: submitting resolves the turn inline (engine work only,
  milliseconds), and narration is dispatched `afterResponse()` so the player
  watches the dice table while Claude writes. There is no cadence window.
- Scheduler (prod): `php artisan schedule:work` — runs `game:resolve-due`
  every minute and the activity-gated `game:evolve` (see above).
  `game:resolve-due` is a SAFETY NET, not the main path: it recovers turns
  still locked past `GAME_ABANDONED_TURN_MINUTES` (a request that died
  mid-resolution) and narrates any resolved turn whose Claude call fell over.
- Claude CLI config: `CLAUDE_BINARY` / `CLAUDE_MODEL` in `.env`; VAPID keys
  already generated (`php artisan webpush:vapid` — needs `OPENSSL_CONF` set
  to a real openssl.cnf on Windows, e.g. Git's).

## Architecture — the load-bearing split

**Engine decides WHETHER; Claude decides HOW it's told.** Claude never
adjudicates legality or outcomes — it is only ever handed resolved facts.

- `app/Game/` — mechanics. `WorldFlavor` (the LAND catalog: ~29 settings from
  ash steppe and canopy town to derelict station, neon sprawl, and frontier
  boomtown, each tagged with the genres it can wear — rolled by the engine at
  campaign creation from the chosen genre's pool, never repeating the
  player's last three lands, stored on `campaigns.world_flavor` and fixed for
  life; also carries the cold-forge kit the engine builds zones from when
  Claude is unavailable. The roll is the default, not the rule: the player
  may name a land from the catalog, or describe their own in
  `campaigns.setting` — typed words replace the catalog brief in
  `worldBrief()` while a flavor is still rolled underneath for the kit),
  `StoryAspects` (the three player-set story axes —
  genre, what drives the tale, magic/machinery level — each a catalog pick OR
  the player's own typed words, stored on `campaigns.genre|drive|tech_level`;
  narration colour only, with ONE soft engine consequence: genre narrows the
  land pool, resolved from typed text by alias match),
  `Capability` (the shared verb vocabulary),
  `TraitCatalog` (the point-buy creation path: engine-priced gifts cost
  points, burdens refund them, builds must break even against
  `creation_points`; Claude only writes prose around the finished sheet),
  `CapabilityGroup` (slot scoping), `TurnSlot` (pre/main/post), `BranchTrigger`
  (the 8 vignette stop conditions), `Meters` (tempo regens in real time across
  the idle wait; health only via recovery beats).
- `app/Game/Engine/` — `CardComposer` (the intersection engine: capabilities ×
  affordances × constraints → cards, with graceful degradation and composed
  cards), `TurnResolver` (slot chain with legality-driven abort, seeded `Dice`,
  enemy reaction, branch triggers, next-turn generation), `SceneDresser`
  (scene generation: a seeded SUBSET of zone templates instantiated as
  scene-scoped copies + spawned actors + a zone `locales` title, marked
  `state.dressed`), `ActionCard`, `BeatOutcome`.
- Anti-repetition systems (all engine-side, no LLM): every visible feature
  and actor carries capability-free `inspect`/`improvise`/`speak` cards, so
  ground the character's gifts don't fit is still actionable and the card
  list moves with the scene instead of collapsing to fixed fallbacks;
  dressed scenes make every transition new ground; features tagged `hidden` are discovery
  content (examine/scout reveal them); enemies telegraph intents
  (press/windup/guard/circle in `tags.intent`) that composer cards and
  resolver difficulty both honor (interrupt/brace answer a windup;
  reposition denies a won angle); reinforcements may arrive `lurking`
  (invisible to cards, situation, and narration until detect exposes them
  or the ambush springs); an `alarm` clock on the scene forces reinforcements
  after 3 stationary combat turns; `track` turns a fled enemy into a pursuit
  that carries them, cornered, into the next scene.
- `app/Services/Claude/` — `ClaudeCli` (stateless CLI runs), `Narrator`
  (resolution → chapter + push, then frontier pre-forge), `ZoneForge`
  (campaign-scoped zones: Claude proposes a whole region, engine clamps to
  the `zone_forge` budget + affordance grammar; the cold-forge fallback
  builds the zone engine-side from the campaign's `WorldFlavor` kit — it must
  never clone shared ground, or every offline tale wakes in the same place),
  `WorldEvolver` (per-campaign
  budgeted evolution + Chronicle), `Interviewer` (creation/growth interviews).
- `app/Services/` — `CapabilityClamp` (bible bounds + constraint re-coupling),
  `TurnStarter`, `BookCompiler` (compilation, not generation; coda on early end).
- Affordances are JSON tags on `scene_features` (e.g.
  `{"reachable_via":["climb","swing"],"height":11}`). Zone-level features
  (`scene_id` null) are templates: dressed scenes (`state.dressed`) draw a
  subset as scene-scoped COPIES at creation (per-scene hidden/destroyed
  state never leaks back to the shared row); legacy undressed scenes still
  overlay every zone template. Zone-level actors are spawn templates.

## Invariants (do not break)

- Never resolve a card the engine didn't offer: submissions reference card ids
  stored on the turn; `PlayController::validateChoice` + `TurnResolver` both
  enforce this.
- Every turn stop must offer ≥ 2 legal cards (generic fallbacks guarantee it).
- Improvise resolves against base stats with no bonus — never better than a
  real enumerated option.
- The optional per-beat note ("in your own words", one per chosen card, stored
  as `submission.<slot>.note` and carried on `BeatOutcome::$note`) colors
  narration only; it must never reach the mechanics path — the resolver reads
  it after every roll is already cast. The strike `method` modifier (bite,
  tail-whip, …) is the same class: engine-offered and validated, carried into
  the beat's facts for the narrator, but never an input to difficulty or damage.
- Chapters are append-only, persisted before any push is sent; the book is a
  compilation (the only new generation at campaign end is the optional coda
  and title flourish).
- Evolution runs are clamped by `config/game.php` bounds no matter what the
  LLM proposes, and log to `evolution_runs` (append-only) for coherence.
- No mechanics language in any narration prompt output (no dice, cards, meters).
- Hidden is hidden from the narrator too: `hidden` features and `lurking`
  actors must never reach cards, situation text, or narration prompts until
  the engine reveals them (use `visibleFeatures()`/`visibleActors()`).
- The design bible fixes VOICE and bounds — never the setting or the genre.
  Both are per-campaign: the land from `WorldFlavor`, the genre/drive/tech
  level from `StoryAspects`. Every prompt that invents or narrates ground
  (forge, stage, interview/prologue, narration, evolution) must carry
  `Campaign::worldBrief()` (land + genre + tech) and `stageBrief()` (premise +
  drive + tone), and state they outrank anything the bible illustrates.
  Naming one setting in the bible is how every campaign ended up in the same
  harbor.
- Story flexible, rules fixed: genre, drive, and tech level are narration
  colour and NOTHING else. They never change cards, difficulty, dice, stat
  clamps, or the affordance grammar, and they may never grant a capability —
  powers come only from the engine-priced sheet and items. The cold-forge
  kits are mechanically identical across every land and genre; only the
  fiction moves.
- Worlds are campaign-scoped: `zones.campaign_id` marks a tale's private
  world; campaign_id null is the shared world (seed archetypes + evolution's
  garden). Zones enter a campaign's world ONLY through `ZoneForge` (creation
  + frontier, both engine-clamped); the player never picks or names a zone
  directly, and a venture card is legal only toward the campaign's own
  pre-forged `next_zone_id`. Items still enter only through evolution.
- The player-set stage (premise/opening/tone, all optional) colors narration,
  the forge, and seeds the opening through `StageBuilder`: scene-scoped
  features/actors (source `stage`), clamped by `config/game.php`
  `stage_budget` + the evolver's stat bounds. It must never create
  world-level content directly. `campaigns.opening` is where the player asked
  the first scene to find them — it rides in `stageBrief()`, so the stage
  builder must open ON that moment, not approach it.
- Companions are coordinated, never controlled: requests are cards in each
  companion's own slot (never the player's pre/main/post), the engine rolls
  the companion's attempt, and failure can cost the companion.

## Widget

`GET /api/widget/status?token=…` (token from `POST /widget/token`) serves the
Scriptable iPhone widget — see `scriptable/StoryCampaignWidget.js`. Snapshot +
deep link only; iOS controls refresh timing.
