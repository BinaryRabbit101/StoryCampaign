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
  the idle wait; health only via recovery beats), `Hands` (what is physically
  held: a successful `lift` puts scene matter IN the character's two hands,
  where it stops being ground, opens `drop`/`hurl`, and degrades the cards
  that want a free hand until it is set down — distinct from `items`, which
  are owned and travel between tales).
- `app/Game/Engine/` — `CardComposer` (the intersection engine: capabilities ×
  affordances × constraints → cards, with graceful degradation and composed
  cards), `Odds` (the difficulty/bonus ladder, ITEMIZED — the single source
  both the card's forecast and the resolver's roll read, so a card can never
  quote a DC the dice won't honor), `TurnResolver` (slot chain with
  legality-driven abort, seeded `Dice`, enemy reaction, branch triggers,
  next-turn generation), `SituationBoard` (the state of play as grouped
  bullets + the prose compilation the narrator prompt reads), `SceneDresser`
  (scene generation: a seeded SUBSET of zone templates instantiated as
  scene-scoped copies + spawned actors + a zone `locales` title, marked
  `state.dressed`), `Grudges` (the nemesis system: an enemy who newly flees
  becomes a campaign-scoped `grudges` row keyed by NAME — heat 0–3, a
  disposition rolled from the flee circumstances, append-only history the
  narrator quotes; the engine alone rolls returns at scene transition
  (heat×15%, 2-chapter floor, one per scene — vengeful arrives telegraphing,
  wary arrives `lurking`, scheming arrives under `truce` with an engine-picked
  deal the roll-free `bargain` verb accepts); the evolver only tends simmering
  grudges within actor clamps and +1 heat/run; killed/kept/bargained is
  `resolved` and terminal), `Downtime` (the idle wait as a choice: a closed,
  engine-composed set of stances — rest / keep watch / tend gear / walk the
  ground — offered on `turns.downtime` when a turn opens, picked on the
  resolved-turn screen, and paid out at the top of the next resolution from
  the REAL elapsed minutes, clamped by `config/game.php` `downtime`
  (floor/cap)), `Ambient` (the air a scene stands in: ONE abstract key —
  `gloom` low light / `haze` obscured air / `squall` violent air / `clear` —
  rolled by `SceneDresser` from the scene's seeded dice when the ground is
  dressed, stored on `scenes.state.ambient` and fixed for that scene's life,
  weighted toward clear by `config/game.php` `ambient`; it reaches the
  arithmetic only through `Odds::AMBIENT`, shows as the board's `sky` line,
  and reaches Claude as one fact to render in the land's own weather),
  `Bargains` (a complication with a price tag: a seeded composer pass
  occasionally clones one card into a second, bargained twin offered BESIDE
  its plain sibling — five closed keys (`loud` / `reckless` / `two_hands` /
  `provoking` / `burning`), each trading a named edge for a named consequence
  the engine applies the instant the beat resolves, win or lose. The edge is
  arithmetic and lives in `Odds::BARGAINS`; the complication lives here and
  routes only through mechanisms that already existed — the scene's `alarm`
  int, `Hands::releaseAll`, the `concealed` condition, an enemy `intent` tag,
  `Meters::spend`. Offer chance and per-turn cap in `config/game.php`
  `bargains`),
  `ActionCard`, `BeatOutcome`.
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
- Nothing about a card's odds may be a surprise. Turns commit on submit — the
  player cannot go back and re-pick — so every card carries a `forecast` (DC
  per stance, the bonuses already standing, and what a set-up beat grants the
  beats after it), and every resolved roll carries `difficulty_parts` /
  `bonus_parts`. Both come from `Odds` and nowhere else: a second copy of that
  ladder is how a card starts promising a DC the dice do not honor. The
  modifier is always displayed, `+0` included — hiding it when it happens to
  be zero is exactly when its absence is worth stating.
- The scene arrives thin. Openings and transitions draw few features and often
  no actors at all; an empty room is a legitimate reading of a place, and a
  scene that spends the whole world on arrival leaves nothing to appear later.
  The alarm clock and the wandering-threat roll are how company shows up.
- The prologue is written LAST, after the first scene and turn exist, and is
  handed that scene as the moment it must END standing in (`Interviewer::
  landing()`); the first chapter is then told it continues from it. Writing
  the prologue before the opening scene existed is what made the two read as
  different books stapled together.
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
- Downtime is optional and defaults to none: a player who submits and closes
  the app gets tempo regen and nothing else. The pick happens AFTER a turn
  resolves and never gates or delays resolution; a wait shorter than the floor
  pays nothing and one past the cap pays no more; each stance costs the other
  three (rest heals but keeps no watch; watch reveals only ENTRY-time lurkers;
  tend gear grants the existing `readied` condition from `Odds::CONDITIONS`,
  never a parallel buff; walk the ground is not offered when the scene hides
  nothing). Narration gets one plain sentence about the wait — no numbers, no
  stance names.
- The air is abstract, priced in the open, and never a revealer. Ambient keys
  are engine vocabulary that must work on an ash steppe and aboard a derelict
  station alike — the engine never says "rain"; the narrator translates the key
  through `worldBrief()`, once per chapter. Genre, drive, tech level, and the
  land never influence WHICH air rolls (the roll is uniform). Every effect is
  an itemized `Odds::AMBIENT` part, two-sided per key (each non-clear air helps
  something and hinders something — a single-sided ambient is difficulty creep
  in a costume), inside the ±4 spread of `Odds::CONDITIONS`, read by the
  forecast and the die from that one table. Clear emits nothing anywhere. And
  ambient moves the ODDS of detection only: it may never reveal or conceal a
  `hidden` feature or a `lurking` actor by itself.
- A bargain is priced up front and always paid. The complication happens
  whether the roll lands or not — wrenching a gate open is loud even when it
  works — which is what keeps the card priceable at choose-time and leaves
  failure meaning exactly what it already meant. Never build "complication only
  on failure": that is the `risky` stance's ground, and blurring the two leaves
  the player unable to read either. A bargain never stands alone (it is
  inserted directly after its plain sibling, so the deal is always a choice
  against the honest version), never lands on `improvise` or an `Odds::QUIET`
  verb, and is never offered when its complication could not cost anything
  here — no `two_hands` with empty hands, no `loud` where nobody is fighting,
  no `burning` against a pool that cannot pay. A free lunch wearing a warning
  label teaches the player the whole mechanic is a strictly better button. The
  UI gives the gain and the cost equal weight; the bargain is never styled as
  the better card.
- Companions are coordinated, never controlled: requests are cards in each
  companion's own slot (never the player's pre/main/post), the engine rolls
  the companion's attempt, and failure can cost the companion.

## Widget

`GET /api/widget/status?token=…` (token from `POST /widget/token`) serves the
Scriptable iPhone widget — see `scriptable/StoryCampaignWidget.js`. Snapshot +
deep link only; iOS controls refresh timing.
