# Implementation brief: The Growth Ledger — the tale pays for what the sheet learns

Instructions for a future session. Read `CLAUDE.md` and
`design/DESIGN_BIBLE.md` first; every invariant there still binds. Sibling
briefs shipping alongside this one: `MENDING.md`, `TONGUES.md`. Backlog
context: `PLAYTEST_FINDINGS_2026-07-30.md`.

**One sentence:** the growth interview spends a currency the engine alone
mints from moments the resolver already fixed — so "what did this tale teach
you" gets the same engine-priced, ledger-backed answer creation already has,
and the one ungoverned power faucet in the game becomes its missing
progression loop.

## The wound this heals

Creation is rigorously priced: `TraitCatalog::sheetBalance` must break even
against `creation_points`, the interview leans with the bargain, the engine
decides which way. Then `Interviewer::grow()` writes whatever Claude marks
`granted` — `capabilities()->updateOrCreate(...)` — with no cost, no cap, no
cooldown, and no ledger. `sheetBalance` is never called on the growth path.
The long-arc character economy is "how persuasively you talk to an LLM, how
often", which contradicts the invariant that powers come only from the
engine-priced sheet. The only limiter is `CapabilityClamp`, which bounds
magnitudes and drops unknown names — it never asks whether you can afford
what you are asking for.

## Design

### The currency

- **Append-only ledger**, house style (grudges, standings): a
  `growth_ledger` table — campaign_id, character_id, nullable turn_id,
  `kind` (`earn` | `spend`), integer `points`, a short `event` slug, plain
  `label`, timestamps. Balance is derived (sum of earns minus spends), never
  stored; expose `GrowthLedger::balance(Character)` or equivalent.
- **The engine alone mints.** A closed earn table, detected by the resolver
  from facts it has ALREADY fixed — the same discipline (and largely the
  same detection points) as `Mementos`:

  | event            | points | the moment (already detected today)            |
  |------------------|--------|------------------------------------------------|
  | `elite_beaten`   | 2      | an elite standing at the turn's top, down by its end |
  | `endeavor_filled`| 2      | an endeavor clock filled                        |
  | `rival_settled`  | 2      | a grudge resolved (killed / kept / bargained)   |
  | `first_ground`   | 1      | first scene opened in a zone new to the tale    |
  | `captive_freed`  | 1      | the existing captive-freed detection            |

  Genre, drive, land, notes, and narration mint nothing. Claude mints
  nothing. **A scar mints nothing** — a scar refunds nothing, in any coin;
  the sanctioned relief valve stays the interview acknowledging it in words.
- Config block `config/game.php` → `growth`: the earn table's point values
  and nothing else. WHICH moments qualify is the closed list above, not
  tunable.

### The spend

- `Interviewer::grow()` prices every granted change through
  `TraitCatalog::capabilityCost()`: a NEW capability costs its catalog
  price; a magnitude increase on a parameterized capability costs 1 per step
  (still clamped by `CapabilityClamp` exactly as today). The engine
  enforces: if the ledger cannot afford the total, the grant is REFUSED
  regardless of Claude's `granted` flag — Claude labels, the engine decides,
  same split as everywhere.
- **The prompt carries the running balance**, the same way
  `Interviewer::balanceSection` carries the creation ledger — a narrator that
  cannot see the balance hedges by refusing everything or granting anything.
  Tell it the points in hand in plain words and that it may propose smaller
  where the ask overreaches (trade down, name the cheaper cousin), mirroring
  the creation interview's "always hand back a way forward".
- A granted spend writes one `spend` row with the capability named in the
  label. Growth **never refunds**: taking a burden in the growth interview
  earns no points (a farming vector wearing a character-development
  costume) — burdens arriving through growth remain story facts and scars.
- Acknowledging a scar, and every pure-prose exchange, stays free and
  unchanged.

### The surface

- The growth panel shows the balance the way the creation ledger does —
  same emerald/red palette, same signed plainness. Zero points is a stated
  zero, not a hidden panel: "nothing in hand yet" is information.
- One line in the growth interview's opening message stating the balance in
  plain words. No board group, no push, no memento, no narration mechanics
  language — the chapter never says "points".

## What must NOT change

- `CapabilityClamp` still clamps every grant; the ledger is a second gate,
  not a replacement.
- Creation pricing, `creation_points`, and the interview lean are untouched.
- `returnCharacter` carries capabilities as today; it does NOT carry ledger
  balance (a new tale earns its own).
- Mementos: a moment may mint BOTH a keepsake and an earn — they are
  different registers (memory vs coin) and neither caps the other.
- No mechanics language in narration; the ledger reaches Claude only inside
  the growth interview prompt.

## Tests

- Each earn event writes exactly one row with the right points, keyed to the
  turn; the same moment never mints twice (mirror the memento dedup shape,
  e.g. `first_ground` per zone).
- A grant the balance affords: capabilities written, one spend row, balance
  falls by the catalog price. A grant it cannot afford: nothing written,
  `granted` reported false/refused to the screen regardless of Claude's
  reply.
- Magnitude step pricing: raising a clamped magnitude two steps costs 2.
- A burden proposed through growth earns 0.
- Scars mint nothing; acknowledgment path unchanged and free.
- The growth prompt contains the running balance; the panel payload exposes
  it.
