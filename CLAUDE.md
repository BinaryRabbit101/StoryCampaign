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
  `CapabilityGroup` (slot scoping), `TurnSlot` (pre/main/post — the RESOLUTION
  order of the player's three beats, and nothing else: every one of the three
  offers the same composed list, so position no longer decides what may stand in
  it. See `CardComposer::unify` and the ONE LIST invariant), `BranchTrigger`
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
  `resolved` and terminal), `Attempts` (a way already tried and refused: a
  FAILURE on the short closed list `flee`/`cross`/`ascend`/`scout`/`track`,
  keyed to the thing it was aimed at (or to the scene, for a beat aimed at no
  one thing), closes that exact card for the rest of that scene — written by the
  resolver after the die is read, filtered by the composer before the card is
  ever offered, and stated on the board in plain words so an absence never reads
  as a bug. Only a failure closes anything, only that thing, and only there),
  `Downtime` (the idle wait as a choice — CURRENTLY UNOFFERED: the engine half
  stands (a closed set of stances written to `turns.downtime` when a turn opens,
  paid out at the top of the next resolution from the REAL elapsed minutes,
  clamped by `config/game.php` `downtime`) but no screen shows the picker any
  more, so the wait passes as it did before the feature existed — tempo regen
  and nothing else), `Ambient` (the air a scene stands in: ONE abstract key —
  `gloom` low light / `haze` obscured air / `squall` violent air / `clear` —
  rolled by `SceneDresser` from the scene's seeded dice when the ground is
  dressed, stored on `scenes.state.ambient` and fixed for that scene's life,
  weighted toward clear by `config/game.php` `ambient`; it reaches the
  arithmetic only through `Odds::AMBIENT`, shows as the board's `sky` line,
  and reaches Claude as one fact to render in the land's own weather),
  `Hours` (the wheel the wait turns: ONE abstract phase on the campaign —
  `dawn` / `day` / `dusk` / `night`, stored on `campaigns.hour_phase` +
  `hour_progress` — stepped by the turns played (`turns_per_phase`, default 3)
  and by the REAL minutes of the idle wait (`minutes_per_phase`, default 240),
  capped at one full turn of the wheel per absence so a week away is a day
  later. Same contract as ambient on a wheel: it reaches the arithmetic only
  through `Odds::HOURS`, two-sided per phase and itemized BESIDE the air's
  parts, joins the board's `sky` line, and reaches Claude as one plain fact to
  render in whatever way the land keeps time. Where the air holds for a scene's
  life, the light keeps moving inside it — so the step lands at the END of a
  resolution, after the beats the cards were priced for, and the next turn's
  cards are composed under the new phase. `config/game.php` `hours`),
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
  `Scars` (going down marks you instead of erasing you: health at 0 keeps
  writing `downed`, and the resolver's turn-end path then rolls a permanent
  burden from the closed `App\Game\ScarCatalog` table with seeded dice, keyed
  to how they went down — appended through the ORDINARY constraint path with
  `source: 'scar'` and a `{turn_id, chapter_id}` stamp, re-coupled through
  `CapabilityClamp`, priced by `Odds::SCARS` under a plain label, and refunding
  nothing. They wake at `config/game.php` `scars.wake_health_fraction` on safe
  adjacent ground — dragged clear if a companion still stood, left where they
  fell otherwise — and the fall past `scars.max_before_end` ends the tale
  through `BookCompiler::close`'s coda path instead, closed by the Narrator
  behind the fall's own chapter),
  `Companions` (what accumulates between you and whoever walks beside you: a
  `tags.bond` int 0–6 on the companion's own actor row deriving
  `stranger`/`fellow`/`sworn`, moved ONLY by engine events the resolver already
  rolled — a request that landed, a block that turned a blow, a fire shared, a
  border crossed together; −1 when a request got them hurt, never below 0, and
  sworn is sticky. Fellow earns a SIGNATURE: one more request card in the
  companion's own slot, engine-picked once with seeded dice from a closed table
  keyed to what they were (`companion_harry` / `companion_distract` /
  `companion_forage`). Sworn earns the INTERCEPTION: once per chapter, unbidden
  and engine-triggered, a blow that would drop the player to 0 lands on them
  instead — it fires inside the reaction, ahead of the scar path, so there is no
  fall left to roll against. The world may also offer companions the player
  never asked for (the grateful, after a rescue in the turn's own facts; the
  stray, a rare `SceneDresser` spawn that keeps to the edge and walks
  transitions), and both end in a consensual pair of ordinary main-slot cards.
  Loss is decided at scene exit against the tier and mints the `companion_lost`
  memento; the name never respawns. `config/game.php` `companions`),
  `Standings` (places remember what you did there: a campaign-scoped `standings`
  row per zone — clamped ±3, append-only `{turn_id, event, shift}` history —
  moved ONLY by a closed table of facts the resolver already fixed (captive
  freed / elite beaten / rival spared +1; grudge born / ground wrecked, once
  per scene / alarm answered −1). It tiers into plain words
  (hostile/wary/silent/known/welcome) the board and narrator carry, prices the
  SOCIAL verbs alone by one itemized `Odds::STANDING` point in either direction,
  and biases a newly arrived enemy's FIRST telegraph through the existing seeded
  intent roll. Zero emits nothing anywhere; the evolver never touches it.
  `config/game.php` `standing`),
  `Threads` (someone else's small story: a non-hostile actor the dressing spawns
  may carry a WANT — a 2–4 segment arc from a closed kind table (`seeking` on
  hidden-rich ground / `mending` on a standing breakable / `road` once a
  frontier zone exists), seeded off the actor's own id, ONE active per campaign.
  It is DORMANT until a ≥-partial social or inspect beat lands on that person:
  before the reveal it reaches no card, no board group and no prompt, which is
  hidden-is-hidden applied to story. After it, ordinary offered beats in the
  kind's advance class move it (a search needs a beat that actually EXPOSED
  something; a mending only counts work on the named feature; the road fills at
  scene transitions), at most one step per chapter. Payoffs route through
  machinery that already existed — `revelation` unhides a feature or sets
  `exit_scouted`, `told_tale` writes one `rumors` row with source `thread`, and
  `companionship` is the third path `COMPANION_BONDS.md` promised: the existing
  consensual welcome/decline pair, silent at the party cap, with no new bond
  mover. Neglect is real and only fictional — a rooted want dies at the border,
  a walking one at `threads.expiry_chapters`, and the bad end costs the player
  nothing at all),
  `Finale` (a tale that ends on a peak: RIPENESS read once per resolution from
  closed engine facts and `config/game.php` `finale` weights — a chapter floor
  AND enough among {a grudge at max heat 2, each filled clock 1, each zone
  beyond the first 1, each scar 1, every 4 keepsakes 1} — ARMS an ending on
  `campaigns.finale` json, which puts one board line and one recurring main-slot
  `face` card on the table and changes nothing else. Declining is free forever.
  Taking it pins the target — the hottest simmering grudge by heat then age,
  else the engine-owned portable `reckoning` clock whose advance verbs are drawn
  from the campaign's own most-used verb families — and UNDERWAY the pinned
  score is forced back at the next transition (or this scene, if nobody is
  standing in the open) through `Grudges::forceReturn`, telegraphing, the one
  place the chapter floor and one-per-scene rule are waived. Ventures are
  suppressed; everything else keeps running. Completion is an engine condition —
  the score resolved or the reckoning filled — and closes the book through
  `BookCompiler::close` exactly as the scar cap does),
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
  `TurnStarter`, `BookCompiler` (compilation, not generation; coda on early end),
  `Mementos` (the trophy shelf: a closed, engine-detected trigger list
  (`rival_settled` > `scar_taken` > `endeavor_filled` > `elite_beaten` >
  `captive_freed` > `first_ground` — that order IS the priority rule) mints at
  most one keepsake per chapter and ~12 per tale (`config/game.php`
  `mementos`), with engine-templated words written the instant the turn
  resolves; the narrator may only reword name/line inside a clamp, and the
  shelf compiles into the book's closing section),
  `Echoes` (the shelf of finished books becomes one library: when a moment in
  the present tale RHYMES with a moment a CLOSED book already preserved, one
  line out of that book surfaces as a memory. Five rhymes, closed and in
  priority order — `the_mark` (a `scar_taken` keepsake minted) / `the_rival`
  (`rival_settled`) / `the_company` (a bond crossed into sworn, or
  `companion_lost`) / `old_ground` (a scene opened in a land another tale of
  theirs was set in) / `the_gathering` (the finale ARMED this turn) — each
  drawing only from its own column: a keepsake of the matching trigger, or a
  chapter's own `intent_line`. Sources are exclusively the SAME user's ENDED
  campaigns (`status = completed`); a first tale is silent forever, and Claude
  is never asked to remember on the player's behalf. The row on `echoes` stores
  the source campaign, type, and id, so the quote is re-derived from the real
  persisted row rather than copied — the narrator may reword only the FRAME
  around it. The frame speaks in two registers, derived (never stored) from
  the two tales' lands: MEMORY ("another life") when the lands differ, LEGEND
  ("this land still tells of…") when they match — the shared universe made
  audible only where the ground can back the claim, `old_ground` always
  legend by construction. Rare by four caps in `config/game.php` `echoes`: a seeded chance,
  `campaign_cap` 4, `cooldown_chapters` 3 (counted in turns — a chapter is one
  turn's telling), one per turn, and each source line once per campaign. No
  push; it lands as the `echo` resolution key, one narrator block, and one
  quiet line beside the rumor line on the resolved-turn screen. The model is
  `App\Models\EchoLine`, not `Echo`: `echo` is a PHP language construct and
  `class Echo` will not parse).
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
- The creation interview leans with the bargain, and the ENGINE decides which
  way. The narrator prompt carries the RUNNING balance (`Interviewer::
  balanceSection`, off the same `draftLedger` the screen reads), never just the
  starting allowance: a narrator that cannot tell whether a sheet is overspent
  hedges the only safe way it can — by asking what everything costs, every
  time — and the interview then answers "what else can you do?" with a fourth
  burden for a character with points still in hand. Points unspent leans the
  question and ≥3 of 4 offered answers POSITIVE; even offers both; overspent
  leans to the price but must still hand back at least one way FORWARD (trade
  a gift down, set one aside) rather than four ways to be worse. Claude writes
  the words and may never override the lean; there is no text classifier
  deciding what counts as positive, and there should never be one.
- Nothing about a card's odds may be a surprise. Turns commit on submit — the
  player cannot go back and re-pick — so every card carries a `forecast` (DC
  per stance, the bonuses already standing, and what a set-up beat grants the
  beats after it), and every resolved roll carries `difficulty_parts` /
  `bonus_parts`. Both come from `Odds` and nowhere else: a second copy of that
  ladder is how a card starts promising a DC the dice do not honor. The
  modifier is always displayed, `+0` included — hiding it when it happens to
  be zero is exactly when its absence is worth stating. The TOTAL is what must
  never be hidden; the itemization is a reading aid, so the constant every roll
  in the game shares (`Base difficulty`) is not printed as a line item — a
  number that never varies teaches nobody anything and buried the parts that do.
  And a two-sided trade must show BOTH sides where the number is: a stance chip
  prints its difficulty delta AND its terms (`terms` on the modifier option),
  because "creep −2 DC / dash +2 DC" on its own reads as the engine claiming
  running is harder than tiptoeing. It is not a movement rule — it is the same
  trade on every card: caution buys a surer roll and spends the top of the result
  (never better than plainly working, no wild faces), boldness pays for the
  reverse.
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
- Downtime is no longer offered anywhere. The picker was removed from both the
  dice table and the play page: it asked the player to price four abstractions
  against time they had not spent yet, and it read as noise between the dice and
  the chapter. Everything under it still works — the offer is still written to
  `turns.downtime`, a recorded stance is still paid out, the widget still reports
  one — so putting it back is a UI job, not a rebuild. What holds now is what
  held before the feature existed: a player who submits and closes the app gets
  tempo regen and nothing else. If it does come back, the old terms come back
  with it (the pick happens AFTER a turn resolves and never gates resolution; a
  wait under the floor pays nothing and one past the cap pays no more; each
  stance costs the other three; narration gets one plain sentence about the wait,
  no numbers and no stance names).
- ONE LIST, THREE POSITIONS. The player's turn is still a chain that resolves
  pre → companions → main → post, and order is still the whole point of a set-up
  beat — but every one of the three picks offers the SAME composed list, and the
  player decides what belongs where. Composed once and copied (`ActionCard::
  withSlot`), so a beat can never be priced differently depending on which pick
  reached it; the slot rides in `id()`, so the three copies are three distinct
  cards a submission points at individually and "never resolve a card the engine
  didn't offer" still holds by construction. Only `main` is required. Position
  must never become a second gate on what a beat may be: a card that only makes
  sense before the act says so in its own words and in what it grants, which is
  the player's information to act on rather than the form's to enforce.
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
- The hour is the same contract as the air, rolled along a wheel. Phases are
  engine vocabulary that must work on an ash steppe and aboard a derelict
  station alike — the engine never says sunrise and never names a clock time;
  the narrator translates the phase through `worldBrief()`, once per chapter.
  Genre, drive, tech level, and the land never influence the wheel: it advances
  uniformly for every tale, by deterministic arithmetic with nothing to seed.
  `day` emits nothing anywhere (the `clear` analogue). Every effect is an
  itemized `Odds::HOURS` part, two-sided per phase, magnitudes smaller than
  ambient's because the two STACK — a gloomy night must be darker than either
  alone while the pair stays inside the ±4 spread of `Odds::CONDITIONS` — and
  itemized separately from the air, so the player reads two named parts rather
  than one number. The wheel moves the ODDS of detection only: it may never
  reveal or conceal a `hidden` feature or a `lurking` actor by itself. It shares
  the real clock with `Downtime` and nothing else: neither modifies the other,
  and rest never works better in the dark. And because a turn's cards were
  priced under the light they were offered in, the step lands AFTER the beats
  resolve — a wheel that turned before the dice would charge a number the card
  never quoted.
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
- A scar is a real burden, and the engine alone hands it out. Claude never
  chooses or invents one — it is handed the finished facts (where they fell,
  what it cost in plain words, where they wake) and writes the waking. A scar
  prices by its NAME through the same `Odds` ladder a creation-time burden
  does, itemized where the player can read it: there is no harsher table for
  injuries, because how you came by a burden is a story fact and story facts
  never move numbers. It refunds NOTHING (the sanctioned relief valve is the
  growth interview acknowledging it, never points), it never lands on a
  companion (a downed companion is the existing actor status and stays there),
  and it never becomes a `SituationBoard` group — the board is for the scene.
  Rest still never lifts a downed character off the floor; only the fall's own
  recovery beat does, and it charges a permanent mark to do it.
- A memento is memory, never mechanics. It is not an `Item`, grants nothing,
  occupies no hands, and never appears in a card, an odds part, or a resolver
  path — which is precisely why play itself may mint one while items still
  enter only through evolution. Enforced by direction as well as by type:
  nothing under `app/Game/` may import `App\Models\Memento` (there is a test),
  so the resolver only ever detects the MOMENT from facts it already has and
  hands the minting outward. Append-only like chapters: once minted, the only
  writes are the narrator's clamped rewording of name/line (≤ 8 words, ≤ 20
  words, still about the same subject — any violation and the engine's words
  stand) and the chapter stamp that completes its provenance. No push fires
  for one; the player finds it. And no mechanics language reaches the shelf,
  the book, or the widget.
- Companions are coordinated, never controlled — at EVERY bond tier. Requests
  are cards in each companion's own slot (never the player's pre/main/post), the
  engine rolls the companion's attempt, and failure can cost the companion. A
  deeper bond widens what may be ASKED (the fellow's signature is one more
  request card) and adds THEIR OWN INITIATIVE (the sworn interception takes no
  card and no player input); it never hands the player authorship of a
  companion's beat. Bond movement is fact-driven only — notes, narration, genre,
  and land never move it — and Claude is told the tier in plain words and never
  the number. Joining is consensual on both sides: every path the world
  initiates ends in an engine-offered accept/decline pair, sending someone on
  their way is never a dead choice, and a third candidate's offer simply does
  not fire while the party is at its cap of two. Asking somebody along is not a
  card of its own: it is an engine-offered `intent` chip on the plain
  conversation with them (`speak`, offered only where recruiting is actually
  legal), because talking to somebody is one act as the player reads it. The chip
  is validated like every modifier and both readings price identically — and the
  note box still cannot recruit anyone, because a note colours the telling and
  never reaches a mechanic. A companion may also be bought AT CREATION
  (`Companions::plant`, `joined_via` origin, planted before the first turn's
  cards are composed so their request slot exists on turn one) — priced on the
  creation ledger at `companions.creation_cost` like any other gift, capped at
  `companions.creation_cap` (below the party cap, so the world still has room to
  offer the grateful and the stray), and starting at bond ZERO like everybody
  else: a long history is a story fact, and story facts never move numbers.
- A standing is earned in front of the people who hold it, and zero says
  nothing. It moves ONLY through the closed event table — notes, genre, drive,
  land, narration, and the evolver never touch it, and the history is
  append-only like a grudge's. It reaches the arithmetic exactly once, through
  `Odds::STANDING`, as ONE point on the social/presence verbs however far the
  score has run: a ladder that grew with the number would turn a memory into a
  stat to farm, and steel and stone do not care what the town thinks. It
  DEGRADES social cards and never removes one — a shunned zone still offers
  speak. The arrival bias bends the draw the existing intent machinery already
  cast, for a newcomer's first telegraph only; standing never spawns, removes,
  or converts an actor, and never touches `hidden`/`lurking`. At zero there is
  no board line, no odds part, no fact, and no row: an unknown is unknown.
- A side thread is a window, not a hook with a barb. It is DORMANT until the
  player discovers it — an undiscovered want is engine state the cards, the
  board, and the narrator never see, and it may not even accrue progress, or a
  payoff could fire for a player who never met the person. Ignoring one must
  stay legitimate: the cost of walking past lands entirely on the NPC (their
  want ends badly, one plain fact says so) and NEVER on the player's sheet —
  no odds part, no standing, no scar, no memento. The engine authors every want
  from the closed kind table and Claude never invents one; every payoff routes
  through machinery that already existed, and the `companionship` one adds no
  bond mover and yields to the party cap in silence.
- The ending is armed, never forced, and it curates rather than invents. Ripeness
  only puts a card on the table; declining costs nothing FOREVER (the offer
  recurs unchanged, nothing escalates behind it), and the scar cap stays the only
  unchosen ending in the game. Ripeness reads closed engine facts and config
  weights alone — genre, drive, land, tech level, notes, and narration move none
  of it, and the chapter floor gates a short tale on its own. While underway the
  finale adds no mechanics: no finale-only bonus, no finale-only penalty, one
  ladder, ordinary cards. Its content routes through machinery that already
  existed — the forced grudge return (the ONLY place the chapter floor and the
  one-per-scene rule are waived, reachable only through `Grudges::forceReturn`'s
  explicit parameter) and the existing clock — and the only thing suppressed is
  the venture card, because the player chose this ground. Pressure, the hour, the
  air, companions and the rest keep running: a world that holds its breath is a
  cutscene. Completion is an engine CONDITION and never a judgement, the close is
  the existing `BookCompiler::close` (coda, flourish, compilation) fired exactly
  once behind the chapter that tells it, and a fall past the scar cap mid-finale
  ends the tale through the scar path with no special casing anywhere.

- An echo is QUOTATION, never invention, and never a second door between
  worlds. It quotes a line the player really lived and instantiates NOTHING —
  no actor, zone, item, grudge, or feature ever crosses campaigns through it,
  and a past companion's name is spoken as memory and nothing else. Only CLOSED
  books of the SAME user may speak: a sibling tale still being played is not a
  memory yet, another player's tales are not this player's life, and an empty
  shelf is silence with no fallback, because every fallback is a fabrication.
  The verbatim quote survives any rewording (the clamp checks it against the
  SOURCE row, not a copy, and refuses the whole proposal otherwise — the
  wrapper is all Claude may move); the engine's words stand on any violation.
  It is colour in every direction — no card, no odds part, no board group, no
  reveal, no number, no push — enforced by direction as well as by type, in the
  same `app/Game` sweep the shelf and the queue live under. And it is FOUND
  rather than announced: a seeded roll, a cap on the tale, a cooldown between
  them, one per turn, and one visit per source line. A memory that arrives on
  schedule has stopped being a memory.

## Widget

`GET /api/widget/status?token=…` (token from `POST /widget/token`) serves the
Scriptable iPhone widget — see `scriptable/StoryCampaignWidget.js`. Snapshot +
deep link only; iOS controls refresh timing.
