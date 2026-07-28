# Implementation brief: The Finale — a tale that ends on a peak

Instructions for a future session. Read `CLAUDE.md` and `design/DESIGN_BIBLE.md`
first; every invariant there still binds. Sibling briefs: `BARGAIN_CARDS.md`,
`GRUDGES_AND_CLOCKS.md`, `DOWNTIME_STANCE.md`, `SCARS.md`, `WEATHER_AND_HOUR.md`,
`MEMENTOS.md`, `COMPANION_BONDS.md`, `PRESSURE_AND_VERB_BOARD.md`, `RUMORS.md`,
`HOUR_AND_RECAP.md`, `STANDING.md`, `SIDE_THREADS.md`.

**One sentence:** the engine reads a campaign's RIPENESS from facts it already
holds, ARMS an ending it offers but never forces, stages the last ground out
of what the tale itself built — the hottest grudge arriving for the last
time — and closes the book through the coda path that already exists.

## Why this shape

- Tales currently STOP rather than CONCLUDE: the scar cap ends them and
  nothing else does. A keepsake book whose last chapter is just the most
  recent chapter is a story that trails off mid-sentence.
- Every system now generates climax material — grudges carry a nemesis,
  clocks carry unfinished business, scars carry cost, mementos carry weight —
  and nothing spends it. The finale is a curator, not a content generator:
  it composes an ending out of standing debts.
- The ending must be chosen. This game is built on consent at every fork
  (companions, bargains, downtime); the largest fork of all cannot be the
  one exception.

## Invariants

- **Armed, never forced.** Ripeness arms the finale; arming OFFERS a card and
  changes nothing else. Declining (not picking it) costs nothing, forever —
  the offer simply recurs. The scar cap remains the only unchosen ending.
- **Ripeness reads closed engine facts only,** config-weighted: a chapter
  floor AND enough among {a grudge at max heat, clocks filled, zones entered,
  scars carried, mementos minted}. Genre, drive, land, tech level, notes, and
  narration never move ripeness.
- **Telegraphed like everything.** While armed: one board line in plain words
  ("the tale is gathering toward its end") and the finale card states plainly
  that taking it begins the ending and there is no stepping back from it —
  priced up front, the bargain rule applied to structure.
- **The finale curates; it never invents mechanics.** Ordinary cards,
  ordinary odds, one ladder, no finale-only bonuses or penalties. Its content
  routes through existing machinery only: the hottest simmering grudge
  returns through the sanctioned `Grudges` return path (forced and
  telegraphing — the chapter floor and one-per-scene rule are waived HERE
  and only here, by the engine, as the point of the whole system); venture
  cards are simply not offered while underway (you chose this ground).
- **Resolution is an engine condition, not a judgment:** the finale completes
  when the target grudge resolves (killed / kept / bargained), or — for a
  campaign with no simmering grudge — when the engine-committed `reckoning`
  clock fills (existing clocks machinery, portable, engine-owned; it does
  not count against the player's one-active rule, and the brief's authors
  accept the small exception because the alternative is a finale a player
  clock can deadlock).
- **The close is the existing close.** On completion (or on a fall that ends
  the tale mid-finale — the ordinary scar rules apply unchanged, no special
  case), `BookCompiler::close` runs exactly as it does today: coda, title
  flourish, compilation, campaign complete. The finale adds narration
  GUIDANCE while underway ("this is the closing movement; write toward rest")
  and nothing else to the book pipeline.
- **Chapters append-only; no mechanics in narration; `finale` resolution key**
  (nullable pattern) carries the plain facts (the arrival, the turn of the
  tide, the end).

## Design: ripeness and the stages

`campaigns.finale` json: `{state: null|armed|underway, signals, grudge_name?,
clock_id?}` — engine-written only.

- **Ripeness** (checked once per resolution, cheap): `chapters >=
  chapter_floor` AND weighted signals `>= arm_threshold`. Suggested weights
  (config): grudge at max heat 2, each filled clock 1, each zone beyond the
  first 1, each scar 1, every 4 mementos 1.
- **Armed:** board line appears; `CardComposer` offers the finale card (main
  slot, new verb `face`, catalog + family placement per the drift test —
  FIGHT if the target is a grudge, otherwise DO; the label names what has
  been building in plain words). The card recurs while armed; it may not be
  bargained.
- **Underway (the card resolved ≥ partial):** the target is pinned — the
  hottest simmering grudge (by heat, then age) returns next scene transition
  or, if the scene allows, this one, telegraphing; no ventures are offered;
  pressure, hour, ambient, companions, threads all keep running (the world
  does not hold its breath — that is what makes it an ending and not a
  cutscene). With no simmering grudge, the `reckoning` clock is minted
  instead (advance verbs drawn from the campaign's own most-used verb
  families — the ending should rhyme with how this player played).
- **Complete:** the condition lands → the `finale` facts are written → the
  chapter narrates → `BookCompiler::close`.

## Engine changes

1. Migration (`campaigns.finale` json, default null); `finale` block in
   `config/game.php` (chapter_floor, arm_threshold, weights).
2. `app/Game/Engine/Finale.php`: ripeness, arm, pin, resolution check,
   wording. Grudges and Clocks are the prior art.
3. `CardComposer`: the `face` card while armed (verb into the catalog).
4. `TurnResolver`: the per-resolution check (after facts fix, before
   `evaluateTrigger` so the ending classifies as itself, not `SoftTimeout`);
   the venture suppression while underway; the completion handoff to
   `BookCompiler::close` following the scar-cap end's exact path.
5. `Grudges`: the finale-scoped forced return (explicit parameter, never
   reachable from ordinary play). `Clocks`: the `reckoning` kind.
6. `SituationBoard`: the armed/underway line. `Narrator`: the closing-
   movement guidance while underway, plain words.

## Tests

- Ripeness math from seeded facts; the chapter floor gates alone; genre and
  friends never move it; arming emits the board line and the card.
- Declining forever is free (armed persists, nothing escalates); the card
  cannot be bargained; venture suppression only while underway.
- The forced return happens only via the finale parameter, telegraphing;
  ordinary `maybeReturn` clamps unchanged everywhere else.
- Completion by grudge resolution and by reckoning fill both close the book
  through the existing coda path exactly once; a mid-finale scar-cap fall
  still ends the tale through its own path with no special casing.
- The `reckoning` clock is exempt from one-active without freeing the player
  slot; seeded determinism throughout.

## Sequencing

Requires grudges and clocks (both shipped). Build no coupling to
`STANDING.md` or `SIDE_THREADS.md` this round: ripeness does not read
standing, and an open side thread neither blocks nor feeds the finale (its
expiry rules already handle a tale that ends around it).
