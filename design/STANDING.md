# Implementation brief: Standing — places remember what you did there

Instructions for a future session. Read `CLAUDE.md` and `design/DESIGN_BIBLE.md`
first; every invariant there still binds. Sibling briefs: `BARGAIN_CARDS.md`,
`GRUDGES_AND_CLOCKS.md`, `DOWNTIME_STANCE.md`, `SCARS.md`, `WEATHER_AND_HOUR.md`,
`MEMENTOS.md`, `COMPANION_BONDS.md`, `PRESSURE_AND_VERB_BOARD.md`, `RUMORS.md`,
`HOUR_AND_RECAP.md`, `SIDE_THREADS.md`, `FINALE.md`.

**One sentence:** a campaign-scoped ledger per zone — clamped ±3, moved ONLY by
a closed table of facts the resolver already fixed — that tiers into plain
words the board and narrator carry, prices the social verbs by one small
itemized part, and biases how arriving enemies first carry themselves.

## Why this shape

- Grudges made INDIVIDUALS remember the player; nothing makes GROUND remember
  them. A zone where you freed captives and a zone where you wrecked the well
  greet you identically. Standing is the communal mirror of the grudge —
  fact-driven like bonds, clamped and append-only like heat.
- Every input already exists: the resolver already fixes captives freed,
  elites beaten, grudges born and settled, features destroyed, the alarm
  forcing reinforcements. Standing is a listener, not a new event source.
- It answers "story facts never move numbers" correctly: standing IS an
  engine fact (like a condition, like heat), earned by resolved beats. What
  stays forbidden is notes, genre, land, or narration moving it.

## Invariants

- **Moved only by the closed event table below.** Notes, genre, drive, land,
  narration, and the evolver never move it — the evolver must NOT tend
  standing this round (that coupling is a later decision, taken deliberately
  or not at all). History is append-only (grudge pattern); the narrator may
  quote it.
- **Zero emits nothing anywhere** — no board line, no odds part, no fact. An
  unknown is unknown.
- **One itemized part, social verbs only, ±1.** `Odds::STANDING` joins the
  same conditions array forecast and die both read (the one-ladder guarantee),
  under a plain label ("your name carries here" / "your name is spat here").
  It touches the social/presence family only — stealth, steel, and stone do
  not care what the town thinks.
- **Never a dead choice.** Negative standing degrades social cards; it never
  removes them. A shunned zone still offers speak.
- **Arrival bias routes through the EXISTING intent machinery only.** In
  negative ground, an arriving enemy's first telegraph leans `press`; in
  positive ground it leans `guard`/`circle` (they hesitate). Standing never
  spawns, removes, or converts an actor, and never touches `hidden`/`lurking`
  — it colors how company arrives, not whether.
- **Facts, not mechanics, reach the narrator** — resolution key `standing`
  (the established nullable pattern) carries a plain shift fact the turn it
  moves; the board carries the tier line while ≠ 0.

## Design: the event table

Detected once per resolution from facts already fixed; each event shifts ±1;
score clamps to ±3. All events apply to the zone the scene stands in.

| event (already in the resolver's facts)             | shift |
|------------------------------------------------------|-------|
| a captive was freed                                   | +1 |
| an elite was beaten                                   | +1 |
| a grudge was settled by sparing (bargain / restraint) | +1 |
| a grudge was born here (an enemy fled swearing)       | −1 |
| a player beat destroyed a feature (once per scene)    | −1 |
| the alarm forced reinforcements                       | −1 |

Tiers (board/narrator wording, plain): −3/−2 hostile ("your name is spat
here"), −1 wary, 0 silent, +1 known, +2/+3 welcome ("doors open at your
name").

## Data model

Table `standings`: `campaign_id`, `zone_id`, `score` (int, clamped),
`history` (json, append-only `{turn_id, event, shift}`), timestamps. Config
`standing` block: clamp, the per-scene destruction cap.

## Engine changes

1. Migration + model; `standing` block in `config/game.php`.
2. `app/Game/Engine/Standings.php`: the listener (facts → events → clamped
   shift + history) and the tier wording. Grudges/Bargains are the prior art.
3. `TurnResolver`: detection beside the `Grudges::settle` region (after all
   facts are fixed); the `standing` resolution key; the current tier joins
   the conditions array at compose AND roll time; the arrival-intent bias in
   the existing seeded intent roll for NEWLY arriving enemies.
4. `Odds::STANDING` — the ±1 social part, itemized.
5. `SituationBoard`: `standing` group while ≠ 0. `Narrator`: one plain line.

## Tests

- Each table event shifts exactly ±1 and appends history; clamp holds; the
  destruction event caps once per scene; zero is silent everywhere.
- The forecast part equals the resolved part (one ladder); the part touches
  social verbs only.
- Arrival bias moves ONLY the first intent of a newly arrived enemy; existing
  enemies re-roll intents unbiased; nothing hidden/lurking is touched.
- Notes and per-beat text never move standing; seeded determinism; a zone
  from campaign A never carries standing into campaign B.

## Sequencing

Standalone. Build no coupling to `SIDE_THREADS.md` or `FINALE.md` this round
even where it tempts (a thread payoff nudging standing, ripeness reading it —
both are later decisions).
