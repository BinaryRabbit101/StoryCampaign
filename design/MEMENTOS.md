# Implementation brief: Mementos — a trophy shelf that compiles into the book

Instructions for a future session. Read `CLAUDE.md` and `design/DESIGN_BIBLE.md`
first; every invariant there still binds. Sibling briefs: `GRUDGES_AND_CLOCKS.md`,
`DOWNTIME_STANCE.md`, `SCARS.md`, `BARGAIN_CARDS.md`, `WEATHER_AND_HOUR.md`.

**One sentence:** notable resolved moments leave behind a mechanically-inert
keepsake object — the broken blade of a beaten rival, a token from a freed
captive — collected on a shelf in the campaign view and compiled into the
finished book as a closing appendix: *what you carried home*.

## Why this shape

- The keepsake book is the game's actual product; mementos give it a second
  axis of sentiment beyond prose. A shelf slowly filling is also a quiet
  between-session pull (the collection instinct) with zero balance cost.
- Items deliberately enter only through evolution (invariant). Mementos slot
  UNDER that rule, not around it: they are not items, grant nothing, and can
  therefore be minted by play itself.

## Invariants

- **Mechanically inert, forever.** A memento is not an `Item`, grants no
  capability, occupies no hands (`Hands` is scene matter; mementos are
  memory), never appears in cards, odds, or any resolver path. Enforce by
  type: its own table, no `grants`, nothing in `app/Game/` ever reads it.
- **The engine mints them.** A CLOSED trigger list decides when (below);
  Claude may only word the memento's name/line, and even that has an
  engine-templated fallback so mementos exist when Claude is unavailable.
- Sparse by design: config cap (suggest 1 per chapter, ~12 per campaign). A
  shelf of forty trinkets is an inventory; a shelf of nine is a life.
- Append-only, like chapters: once minted, a memento is never edited or
  deleted; each carries `{turn_id, chapter_id}` provenance so the book can
  cite its chapter.
- No mechanics language in the shelf UI or book appendix — a memento line is
  in-world prose ("A hinge-pin from the harbor gate, bent double"), not a
  achievement toast. No push notification for minting; the player finds it.

## Data model

Table `mementos`: `campaign_id`, `turn_id`, `chapter_id`, `trigger` (enum
below), `name` (short), `line` (one sentence), `created_at`.
`config/game.php` → `mementos` block: per-chapter cap, campaign cap.

## Trigger list (closed; engine-detected in `TurnResolver`'s post-beat facts)

- `rival_settled` — a grudge reaches `resolved` (requires `GRUDGES_AND_CLOCKS.md`;
  omit the trigger cleanly if grudges are not yet in).
- `elite_beaten` — an elite-tier enemy killed or restrained.
- `captive_freed` — a restrained/held captive released to safety.
- `first_ground` — first scene ever entered in a newly forged frontier zone
  (a pressed flower from new country; fires once per zone).
- `endeavor_filled` — a clock fills (same conditional dependency as grudges).
- `scar_taken` — the fall that scarred them left something behind (pairs
  with `SCARS.md`; the object that did it, or what was in their hand).

When multiple triggers fire in one chapter, mint the rarest one only
(priority: rival_settled > scar_taken > endeavor_filled > elite_beaten >
captive_freed > first_ground).

## Engine & service changes

1. `TurnResolver`: after a turn's facts are final, detect triggers, respect
   caps, and mint with the TEMPLATED name (engine-side patterns per trigger,
   seeded pick, using the involved actor/feature name — `NameForge` may help
   with texture). Store immediately: the memento must exist even if
   narration later fails.
2. `Narrator`: when this chapter minted a memento, the prompt gains one line
   inviting a better name/line for it within strict bounds (≤ 8 words name,
   ≤ 20 words line, must reference the same subject). Engine clamps: on any
   violation or Claude failure, the templated words stand. Update the row
   with the improved wording only.
3. `BookCompiler::compile`: append a final section "What you carried home" —
   each memento's name, line, and a chapter reference ("— chapter 9"). No new
   generation; it is compilation, same as the rest of the book. The optional
   coda prompt may receive the memento list as context.
4. UI: a shelf strip on the campaign page (names + lines, chapter links).
   Widget: optionally the latest memento name as flavor.

## Tests

- Each trigger mints with correct provenance; caps enforced; priority rule
  picks one when several fire.
- Claude unavailable → templated memento exists and compiles.
- Narrator rewording is clamped (length/subject) and never blocks or edits
  anything but name/line.
- Mementos never appear in card composition, odds, hands, or any engine
  query (a grep-level assertion: nothing under `app/Game/` imports the
  model).
- Book appendix lists all mementos in chapter order; empty shelf → no
  appendix section at all (empty-group-absent, same as the board).

## Sequencing

Standalone with two optional triggers that light up when grudges/clocks/scars
land. Implement the trigger enum tolerantly so those arrive as pure
additions.
