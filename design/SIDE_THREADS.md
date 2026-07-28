# Implementation brief: Side Threads — someone else's small story

Instructions for a future session. Read `CLAUDE.md` and `design/DESIGN_BIBLE.md`
first; every invariant there still binds. Sibling briefs: `BARGAIN_CARDS.md`,
`GRUDGES_AND_CLOCKS.md`, `DOWNTIME_STANCE.md`, `SCARS.md`, `WEATHER_AND_HOUR.md`,
`MEMENTOS.md`, `COMPANION_BONDS.md` (which promised this file),
`PRESSURE_AND_VERB_BOARD.md`, `RUMORS.md`, `HOUR_AND_RECAP.md`, `STANDING.md`,
`FINALE.md`.

**One sentence:** a non-hostile actor may carry a WANT — a short engine-authored
arc (2–4 segments) the player discovers by talking, helps through ordinary
cards, or ignores at a real cost to that person and no cost to themselves —
paying off in a revelation, a told tale, or the long-promised third road to
companionship.

## Why this shape

- `COMPANION_BONDS.md` names this file as the third companion-finding path and
  it has never existed. Clocks proved the segment-arc machinery; a side thread
  is, structurally, a clock owned by an NPC.
- Bystanders currently have no continuity — they are furniture that speaks.
  One person in the world visibly WANTING something, and visibly better or
  worse off for how the player answered, is worth more atmosphere than a
  dozen dressed features.
- Ignoring must be legitimate. The tale is the player's; a side thread is a
  window, not a hook with a barb. The cost of walking past lands on the NPC
  (their want resolves badly), never on the player's sheet.

## Invariants

- **Engine-authored from the closed kind table.** Claude narrates a want the
  engine chose and templated; it never invents one. The actor's own words
  about it are narration; the want's facts are engine rows.
- **Attaches only to non-hostile, non-companion actors; ONE active thread
  per campaign** (the clocks readability rule). A campaign at its companion
  cap can still carry a thread — the `companionship` payoff simply does not
  fire (the Companions third-candidate rule, extended).
- **Dormant until discovered.** The thread reaches the board, the forecast
  lines, and narration ONLY after a social or inspect beat against that actor
  resolves ≥ partial and reveals the want (a fact, not a roll bonus). Until
  then it is engine state the narrator never sees — the hidden-is-hidden rule
  applied to story.
- **Helping happens through ordinary offered cards.** Qualifying beats carry
  a forecast line ("helps <name>'s hope" — the clocks `advances` pattern); no
  new slot, no payload change, no new verbs.
- **Payoffs come from the closed list and existing machinery only:**
  `revelation` (an engine reveal of one hidden feature, or `exit_scouted`),
  `told_tale` (ONE `rumors` row, new source `thread` — extend the rumors
  source enum, its direction test, and its traceability test), and
  `companionship` (the actor offers to join through the EXISTING consensual
  welcome/decline pair, respecting the party cap and every bond rule —
  this is the promised third path, and it adds no new bond movers).
- **Neglect is real but only fictional.** An expired thread (scene exit for
  rooted kinds, `expiry_chapters` for walking ones) resolves the want badly
  for THEM — the actor departs or falls, one plain fact emitted — and never
  costs the player a number. No standing hit, no odds part, no scar: walking
  past is a choice about who they are, not a tax.
- **Board group `thread`** in the clocks register ("Aldan's search — 2 of 3")
  once discovered; no mechanics language in narration (the narrator hears
  "they are close to what they are looking for", never a count).

## Design: the kind table

| kind      | attaches when the scene offers                   | segments | advanced by (≥ partial)                                    | payoff |
|-----------|---------------------------------------------------|----------|-------------------------------------------------------------|--------|
| `seeking` | ≥ 1 `hidden` feature in the scene                 | 2–3      | the player's reveal-class successes (inspect/scout/detect/examine that exposed something) | `revelation` |
| `mending` | a breakable or destroyed feature stands           | 3–4      | force/tend-class successes on the NAMED feature (break/lift/bandage-adjacent work the engine tags) | `told_tale` |
| `road`    | the campaign has a `next_zone_id`                 | 2–3      | scene transitions with the actor along (the stray walking pattern from Companions) | `companionship` |

Attachment: a seeded pass when a dressed scene spawns a qualifying actor
(`config` offer chance, silent when a thread is already active). The `road`
kind gives the actor the stray's walking behavior; the other kinds root them.

## Data model

Table `threads`: `campaign_id`, `actor_id`, `actor_name`, `kind`, `segments`,
`filled`, `revealed` (bool), `status` (`open|filled|failed|expired`),
`history` (json, append-only), timestamps. Config `threads` block: offer
chance, active cap (1), `expiry_chapters`.

## Engine changes

1. Migration + model; `threads` block in `config/game.php`.
2. `app/Game/Engine/Threads.php`: attach (seeded, at scene dressing), reveal
   detection, tick detection, payoff execution, expiry, wording. Clocks and
   Companions are the prior art; follow the Mementos direction rule if the
   model would otherwise be imported under `app/Game/` — match how `Clock`
   itself resolved that tension and be consistent with it.
3. `TurnResolver`: reveal + tick + expiry detection from facts already fixed;
   resolution key `thread` (nullable pattern) for the want revealed, a tick,
   the payoff, or the bad end.
4. `CardComposer`: the forecast line on qualifying cards while revealed.
5. `SituationBoard`: `thread` group once revealed. `Narrator`: plain facts,
   no counts. `Rumors`: the `thread` source.

## Tests

- Attach only to qualifying non-hostile actors; one active per campaign;
  silent at an active thread; seeded determinism.
- Dormancy: nothing reaches board/forecast/narration before the reveal beat;
  the reveal fact fires once.
- Ticks: only the kind's advance class, only ≥ partial, only the named
  target where the kind names one; notes never tick.
- Payoffs: each routes through its existing machinery; `companionship`
  respects the party cap and the welcome/decline pair; `told_tale` rows are
  traceable and pass the extended direction test.
- Expiry: rooted threads die on scene exit, walking ones at the chapter cap;
  the bad end emits one fact and costs the player nothing mechanical.

## Sequencing

Requires clocks (shipped) and companions (shipped). Build no coupling to
`STANDING.md` or `FINALE.md` this round — a thread that moves standing or
feeds ripeness is a later decision.
