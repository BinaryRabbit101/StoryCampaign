# Implementation brief: Mending — binding wounds is an attempt, not a faucet

Instructions for a future session. Read `CLAUDE.md` and
`design/DESIGN_BIBLE.md` first; every invariant there still binds. Sibling
briefs shipping alongside this one: `TONGUES.md`, `GROWTH_LEDGER.md`. Backlog
context: `PLAYTEST_FINDINGS_2026-07-30.md`.

**One sentence:** binding wounds heals by how well the roll went — nothing on
a failure — and a turn may tend the body at most once, because ONE LIST
copying the card into all three slots must never mean three heals a turn.

## The wound this heals

`TurnResolver`'s `Verb::Bandage` arm heals unconditionally:

```php
case Verb::Bandage:
    $heal = $degree === BeatOutcome::STRONG ? 3 : 2;
    Meters::heal($character, $heal);
```

No `$succeeded` guard — every neighbouring arm (`Ride`, `Recover`, `Lift`)
branches on it; this one forgot to. A natural 1 still heals 2. And because
`CardComposer::unify` copies every composed card into pre/main/post (the ONE
LIST invariant) and `illegalReason` never asks "did this chain already do
this beat", bandage ×3 is a guaranteed +6 health per turn against enemy
output of ~1–2 into a 10-point pool. A player who spends beats on healing is
functionally unkillable — which quietly starves the scar system, the
health-danger branch trigger, and the finale's scar signal. The whole stakes
ladder hangs off this one missing guard.

## Design

1. **The heal rides the degree.** Strong 3, success 2, partial 1, failure 0.
   Partial healing 1 keeps the beat from being a coin-flip nothing — a
   half-dressed wound is still dressed — and failure finally means what it
   means everywhere else. Fact strings in the same plain register the arm
   already uses ("They bound their wounds (+2 health)" / on failure,
   something like "The dressing would not hold.").
2. **Once per turn for tending the body.** A once-per-chain guard in
   `illegalReason`: if an earlier beat in THIS turn's chain already resolved
   `Verb::Bandage`, a later bandage in the same chain is illegal with a plain
   reason ("They have already seen to their wounds this turn."). Skipped via
   the existing `BeatOutcome::skipped` path — never silently dropped.
   - Scope the guard to bandage (the tend-the-body verb). Do NOT build a
     generic per-verb dedup across the whole list: repeating a strike or a
     lift is legitimate play. If a clean seam exists, keep the guard's verb
     list a small closed constant so a future recovery verb can join it.
   - The guard reads what the chain has RESOLVED, not what was submitted —
     a bandage that was itself skipped as illegal must not burn the slot.
3. **The card stays offered in all three slots.** ONE LIST is untouched;
   position stays the player's information. Choosing bandage twice is a
   legal submission that resolves once and skips once with the reason shown —
   the same shape as any other legality re-check at resolution time.

## What must NOT change

- `Meters::heal` clamping and everything else in the arm's neighbourhood.
- The composer's offer condition (`health.current < health.max`) — an
  unwounded body already gets no card.
- No new config. The numbers are the degree ladder, stated above.
- Forecast honesty: bandage is a rolled verb and its forecast already prints
  DC/band/modifier from `Odds`. Nothing here touches the ladder.

## Tests

- Failure heals 0; partial 1; success 2; strong 3 (drive degree through a
  seeded `Dice` or by constructing conditions, matching existing resolver
  test patterns).
- A chain submitting bandage in pre AND main resolves the first and skips
  the second with the plain reason; health moves once.
- A bandage skipped for another reason (e.g. dead meter cost) does not
  consume the once-per-turn guard.
- The card is still composed for all three slots when wounded, and not
  composed at full health.
