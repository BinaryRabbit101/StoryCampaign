# Implementation brief: Scars — going down marks you instead of erasing you

Instructions for a future session. Read `CLAUDE.md` and `design/DESIGN_BIBLE.md`
first; every invariant there still binds. Sibling briefs: `GRUDGES_AND_CLOCKS.md`,
`DOWNTIME_STANCE.md`, `BARGAIN_CARDS.md`, `WEATHER_AND_HOUR.md`, `MEMENTOS.md`.

**One sentence:** health 0 no longer just sets `status = 'downed'` — the
character survives the fall, wakes carrying an engine-rolled permanent scar
(mechanically a `TraitCatalog` burden, narratively a fact the book carries
forever), and the tale bends around the recovery instead of ending.

## Why this shape

- `Meters::damage` currently writes `status = 'downed'` and nothing follows —
  the game's highest-stakes moment is mechanically a dead end. Permadeath
  would fight the keepsake-book identity; a consequence-free faint would gut
  the stakes. Scars are the third option: you can lose something *real* and
  keep playing.
- The pricing machinery already exists: `TraitCatalog::negatives()` defines
  engine-priced burdens and `CapabilityClamp` re-couples constraints. A scar
  is a burden acquired mid-tale instead of at creation. The growth-interview
  path (which just gained per-answer verdicts) is the natural later home for
  a scar being addressed, worked around, or accepted.

## Invariants

- **The engine rolls the scar.** Seeded `Dice`, from a small closed scar
  table keyed to how the character went down (what the resolver already
  knows: the verb/actor of the finishing beat). Claude narrates the waking;
  it never chooses or invents the scar.
- A scar is a REAL burden — it must price into cards and odds exactly as a
  creation-time constraint does (through the existing constraint path, not a
  parallel system), and it appears as an itemized `Odds` part with a plain
  label ("The old wound in your knee"), because a cost the player cannot see
  teaches nothing.
- No refund. Creation-time burdens buy points; a scar buys nothing — it is
  the price of falling, not a currency. (If playtesting shows downs feel
  purely punitive, the sanctioned relief valve is the growth interview
  acknowledging the scar — never points.)
- Cap of 2 scars. The THIRD fall ends the tale: `BookCompiler::close` with
  the coda path — the tale of someone who spent everything. This keeps
  stakes real without a death spiral of stacking burdens making the third
  fall inevitable-and-miserable. Surface the count plainly on the character
  strip after the first scar.
- Genre/tech never touch which scar or its price; only the narration of it.
- The waking is a recovery beat, not a skipped scene: the resolver composes
  the wake-up turn in a safe adjacent position (enemies won: they've taken
  what they wanted and moved on, or the companion dragged you clear — the
  engine picks from fixed outcomes; companions present make the second
  option available). Never respawn-at-full: wake at ~half health.

## Data model

- Scars live in the existing constraints structure on the character sheet
  (same shape `TraitCatalog::compile` produces) with `source: 'scar'` and a
  `{turn_id, chapter_id}` provenance stamp so the book can cite the chapter
  it happened in.
- Scar table: a new small catalog in `TraitCatalog` (or `ScarCatalog` beside
  it) mapping finishing-context → 2–3 candidate burdens, each an EXISTING
  negative entry or a new one priced by the same rules. Suggested seeds: a
  marked limp (climb/cross harder), a guarded side (brace weaker), a dimmed
  eye (examine/ranged strike harder), a tremor in the hands (interacts with
  `Hands`-hungry verbs). Keep to ~6 total; distinct enough that two scars
  never read as the same injury twice.
- `config/game.php`: `scars` block — `max_before_end` (2), `wake_health_fraction`.

## Engine changes

1. `Meters::damage` reaching 0: keep writing `downed`, but the resolver's
   turn-end path now branches: roll the scar, append it through the
   constraint path, run `CapabilityClamp` re-coupling, half-heal, compose the
   wake-up turn, and hand the narrator the facts (where they fell, what the
   scar is in plain words, where they wake). On the third fall, close the
   campaign through `BookCompiler` instead.
2. `Odds`: scar constraints must flow through the existing constraint
   pricing so the itemized parts appear with the scar's label — verify, and
   add a part label pass if constraint parts are currently generic.
3. Narration: the wake-up chapter prompt carries the scar as a fact
   ("her right knee will not fully bend again") with the standing rule that
   later chapters may reference it; `SituationBoard` does NOT get a
   permanent scar group (the sheet and odds parts carry it — the board is
   for the scene).
4. Growth interviews (`Interviewer`): include current scars in the sheet
   context it already sends, so a player may talk about one; grants stay
   clamped exactly as today.
5. `BookCompiler`: no structural change (chapters carry the story), but the
   optional coda prompt should receive the scar list — a body that shows
   where the tale has been is the point.

## Tests

- Down → scar appended via constraint path, clamp re-coupled, provenance
  stamped, wake turn composed at configured health, seeded-deterministic.
- Scar prices cards/odds identically to the same burden taken at creation.
- Two distinct falls yield distinct scars; third fall closes the campaign
  through the early-end/coda path.
- Scar facts reach the narrator prompt; no mechanics language does.
- A downed companion (if companion damage can down them) does NOT enter this
  path — scars are the player character's only.

## Sequencing

Standalone. If `GRUDGES_AND_CLOCKS.md` is implemented, a fall against a
grudge should append to that grudge's history (`event: 'downed_you'`) — one
line in the grudge hook, high payoff when they meet again.
