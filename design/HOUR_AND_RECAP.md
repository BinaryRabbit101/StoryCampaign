# Implementation brief: The Hour & Previously On — time passes in the world, and the tale remembers where it was

Instructions for a future session. Read `CLAUDE.md` and `design/DESIGN_BIBLE.md`
first; every invariant there still binds. Sibling briefs: `BARGAIN_CARDS.md`,
`GRUDGES_AND_CLOCKS.md`, `DOWNTIME_STANCE.md`, `SCARS.md`, `WEATHER_AND_HOUR.md`,
`MEMENTOS.md`, `COMPANION_BONDS.md`, `PRESSURE_AND_VERB_BOARD.md`, `RUMORS.md`.

**One sentence (Feature 1):** the hour half `WEATHER_AND_HOUR.md` named and
never built — a four-phase wheel on the campaign, advanced by turns and by the
REAL minutes of the idle wait, priced exactly the way ambient is priced (one
abstract key, two-sided itemized `Odds` parts) and translated by the narrator
into each land's own version of night.

**One sentence (Feature 2):** a player returning after a real absence finds a
short, dismissible "the thread so far" panel above the form — compiled, never
generated, from fact strings and titles the engine already persisted — so
re-entry costs a glance instead of a re-read.

---

## Feature 1 — The Hour: the wheel the wait turns

### Why this shape

- Turns are real-time and the idle wait is the game's defining rhythm — yet
  nothing in the fiction moves with it. A player who submits at midnight and
  returns after breakfast re-enters a world where it is still whatever
  o'clock the narrator last improvised. The wait deserves a fictional mirror:
  come back hours later and the light has actually changed.
- Ambient proved the pattern: one abstract engine key, fixed rules about
  where it may touch the arithmetic, the narrator translating it through
  `worldBrief()`. The hour is the same shape rolled along a wheel instead of
  fixed per scene.
- It composes with what shipped: gloom at night is a different place than
  gloom at noon, a `night` crossing lands a `dawn` arrival after a long rest,
  and the downtime sentence gains a truthful clock behind it.

### Invariants

- **The hour is abstract and land-translated.** Engine keys are a closed
  wheel — `dawn → day → dusk → night` — and are engine vocabulary, never
  player-facing verbatim. A derelict station has no sun: the narrator renders
  the key through `worldBrief()` (the dimmed shift, the waking cycle), once
  per chapter, exactly as ambient's keys become the land's own weather. The
  board line stays neutral ("the light is failing", "deep in the dark hours").
- **Priced in the open, two-sided, one ladder.** Every effect is an itemized
  part in a single `Odds::HOURS` table, read by forecast and die alike —
  never a second copy. Each non-neutral phase helps something and hinders
  something; `day` is the `clear` analogue and emits nothing anywhere.
  Magnitudes stay small (±1/±2, inside the ambient spread) because hour
  STACKS with ambient — a gloomy night must be darker than either alone
  without breaking the ±4 conditions envelope. Itemized separately
  ("nightfall" beside "gloom"), so the player reads two named parts, not one
  mystery number.
- **Never a revealer, never rolled by the fiction.** Like ambient: the hour
  moves the ODDS of detection only — it may not expose or protect a `hidden`
  feature or `lurking` actor by itself. Genre, land, drive, and tech level
  never influence the wheel; it advances uniformly for every campaign.
- **The wheel is campaign state, advanced by the engine only.** Turns advance
  it by a fixed step; the REAL elapsed minutes of the wait advance it further
  (clamped — see design). Claude is told the phase in plain words and never
  advances, names, or reads a clock-face time. No card, note, or narration
  moves the wheel.
- **No new coupling to downtime's payout.** The wait already pays through
  `Downtime`; the hour reads the same elapsed minutes but the two never
  modify each other. Rest does not "work better at night" — that is a
  parallel-buff system in a nightcap.

### Design: the wheel

`campaigns.hour_phase` (enum string) + `campaigns.hour_progress` (int),
tuned by a `config/game.php` `hours` block:

- Every resolved turn: `hour_progress + 1`; at `turns_per_phase` (default 3)
  the phase steps and progress resets.
- At the top of resolution, the real minutes since the last resolution add
  `floor(minutes / minutes_per_phase)` phase steps (default 240 — a genuine
  overnight absence crosses into a new light), capped at one full cycle per
  wait so a week away is a day later, not a random modulo.
- The phase at scene entry is the phase the scene opens under; it keeps
  turning inside a long scene (unlike ambient, which is scene-fixed — the
  air holds, the light moves).

### Design: the `Odds::HOURS` table (suggested; agent refines magnitudes)

| phase  | helps (itemized part)                        | hinders (itemized part)                       |
|--------|----------------------------------------------|-----------------------------------------------|
| `dawn` | recovery beats +1 ("first light")            | concealment −1 ("the world is waking")        |
| `day`  | — (emits nothing)                            | — (emits nothing)                             |
| `dusk` | concealment +1 ("failing light")             | perception/detection −1 ("failing light")     |
| `night`| concealment +2 ("the dark")                  | perception/detection −2, precise traversal −1 ("the dark") |

Board: the hour joins the existing `sky` line (one line carries air and light
both). Narrator: one plain fact per chapter, land-translated. Forecast and
resolved rolls: the part appears by name, `+0` phases simply absent.

### Engine changes

1. Migration: `hour_phase` / `hour_progress` on `campaigns` (default `day`/0).
2. `app/Game/Engine/Hours.php`: the wheel (advance-by-turn, advance-by-wait,
   clamps) + the phase's board wording. Follow `Ambient` as prior art.
3. `Odds::HOURS` + the conditions plumbing: the phase joins the SAME
   conditions array `ActionCard::toArray` and the resolver both read, at
   compose time and at roll time — one ladder, like ambient.
4. `TurnResolver`: advance the wheel beside `Meters::regenerate` (both read
   the real clock); emit the phase-change fact when a step lands mid-play.
5. `SituationBoard`: fold into the `sky` line. `Narrator`: the plain fact.
6. NO `Play.vue` changes — the hour reaches the UI through the board line and
   the itemized forecast parts, which already render.

### Tests

- Wheel arithmetic: turn steps, real-minute steps, the one-cycle cap, phase
  order; seeded determinism where anything rolls.
- One-ladder: the forecast part equals the resolved part for every phase;
  `day` emits nothing anywhere; stacking with ambient stays inside the
  conditions envelope.
- Hour never flips `hidden`/`lurking` state; uniform advance regardless of
  genre/land; Claude prompt contains the phase word and no clock time.

---

## Feature 2 — Previously On: the thread regained

### Why this shape

- The game is built for absence — turns wait, downtime pays, evolution tends,
  rumors queue — but re-entry is cold: the player lands on the form and must
  reconstruct the stakes from the board alone. A scheduled-play game should
  greet a returning player the way a serial does: *previously, on this tale*.
- Everything needed is already persisted in plain words: chapter titles,
  resolved-turn fact strings, the downtime sentence, minted mementos, the
  Chronicle, the open clock's board group. This is `BookCompiler`'s
  philosophy at panel size — compilation, not generation.

### Invariants

- **Compiled, never generated.** No Claude call, ever. The panel is assembled
  from strings the engine already wrote and clamped: chapter titles, fact
  strings from `turns.resolution`, memento lines, the Chronicle's existence
  (title, not body). If a piece is missing the panel is simply shorter —
  nothing is summarized, paraphrased, or invented.
- **No mechanics language.** The sources already comply; the recap adds no
  framing that breaks it (no "you rolled", no counts beyond what the board
  already shows in plain words).
- **Never gates play.** The panel is informational, dismissible, and absent
  entirely below the absence threshold. It renders above the form, never as
  an interstitial the player must click through. A player who ignores it
  loses nothing.
- **Absence is read from existing timestamps** (the last resolved turn /
  last submission) — no activity-tracking columns, no beacons. Dismissal is
  client-side per turn (localStorage); the server re-offers naturally on the
  next qualifying absence.

### Design: the panel

Shown when the open turn is unsubmitted and the real time since the last
resolution ≥ `recap.absence_hours` (config, default 12). Sections, each
skipped when empty, in this order:

1. **Where the tale stands** — campaign title flourish, chapter count, the
   last chapter's title ("Chapter 11 — The Salt in the Rigging").
2. **What just happened** — up to `recap.fact_lines` (default 4) fact strings
   from the last resolved turn, favoring the memorable kinds the resolution
   already labels: the fall, an arrival, a world beat, a clock tick, a
   companion event, a memento minted.
3. **While you were away** — the wait: the downtime sentence if one was paid;
   a Chronicle published since last seen ("the world has been tended — a new
   chronicle waits in the book"); a rumor heard last turn.
4. **What stands open** — the open clock's plain line; a grudge line the
   board already carries.

### Engine changes

1. `app/Services/Recap.php`: `for(Campaign)` → nullable structured panel
   (sections of plain strings + source refs). Reads models only — no writes,
   no dice.
2. `PlayController` (or `TurnStarter`'s screen payload): attach the panel to
   the play-screen props when the threshold qualifies.
3. `Play.vue`: the dismissible panel above `DowntimePicker`, quiet styling
   (the memento register — found, not announced), localStorage dismissal
   keyed by turn id.
4. `config/game.php`: `recap` block (`absence_hours`, `fact_lines`).

### Tests

- Threshold: absent below `absence_hours`, present at it; absent once the
  turn is submitted; absent on a brand-new campaign's first turn.
- Sources: every line traceable to a persisted string (title, resolution
  fact, memento line, downtime sentence); empty sections skipped; no Claude
  invocation anywhere on the path.
- The panel never mutates state; fact selection is deterministic (no dice —
  favor-by-kind then recency).

---

## Sequencing for the implementing session

The features are independent — either lands alone. If built in parallel, the
hour must not touch `Play.vue` (the recap owns that file this round) and the
recap must not touch `TurnResolver`/`Odds` (the hour owns those); both add a
`config/game.php` block and both may brush `PlayController` — keep those
diffs small and additive. The hour's phase-change fact is recap-visible for
free (it is a resolution fact string); build no other coupling.
