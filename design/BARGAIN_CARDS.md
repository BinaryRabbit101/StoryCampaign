# Implementation brief: Bargain Cards — complications with a price tag

Instructions for a future session. Read `CLAUDE.md` and `design/DESIGN_BIBLE.md`
first; every invariant there still binds. Sibling briefs: `GRUDGES_AND_CLOCKS.md`,
`DOWNTIME_STANCE.md`, `SCARS.md`, `WEATHER_AND_HOUR.md`, `MEMENTOS.md`.

**One sentence:** occasionally a card offers a stronger version of itself with
the complication stated up front — "Wrench the gate open — loud; the district
will hear" — trading a named, engine-applied cost for a named, engine-applied
edge, both quoted before the commit.

## Why this shape

- Direct extension of the `Odds` philosophy ("a card the player cannot price
  is a card they cannot choose") and the no-dead-choices rule: the tradeoff
  IS the card. The stance economy prices risk on the roll; bargains price a
  *consequence in the world*, which is a different and juicier decision.
- Every currency needed already exists engine-side: the alarm counter,
  `Hands::releaseAll`, the `concealed` condition, enemy `intent` tags, tempo
  pools. No new resource systems.

## Invariants

- **Price-up-front, always-paid.** The complication happens whether the roll
  succeeds or not (wrenching the gate is loud even when it works). This keeps
  the card fully priceable at choose-time and leaves failure meaning exactly
  what it already means. Never build "complication only on failure" — that is
  the existing `risky` stance's territory and would blur the two.
- Complications come from a CLOSED engine list; the edge comes from existing
  vocabulary (`Odds` bands/conditions). Claude never invents either — it only
  narrates the noise, the drop, the exposure.
- A bargain never appears alone: it is offered *beside* its plain sibling
  card (same verb/target), so taking the deal is always a choice against the
  honest version. The ≥2-legal-cards invariant is untouched.
- At most ONE bargain offer per turn (seeded roll in the composer), and none
  on `improvise` (improvise must never be better than an enumerated option)
  or on quiet no-roll verbs (`Odds::QUIET` — nothing to sweeten).
- The per-beat note and `method` modifier rules are unchanged: narration
  colour only.

## Design: the bargain table

Each entry pairs an edge with a complication, both with player-facing labels.
Suggested starting set (keep ~5; every pair must make physical sense for the
verbs it can attach to):

| key         | edge (quoted in forecast)            | complication (engine effect)                          | attaches to |
|-------------|--------------------------------------|-------------------------------------------------------|-------------|
| `loud`      | difficulty one band down             | alarm +1 (`scene->state['alarm']`)                     | break, lift, haul, climb |
| `reckless`  | +2 on the roll                       | you are seen: lose `concealed`, lurkers may mark you   | strike, cross, ascend |
| `two_hands` | difficulty one band down             | everything held drops (`Hands::releaseAll`)            | climb, restrain, break |
| `provoking` | +2 on the roll                       | target's next intent set to `press` (they come at you) | strike, speak-family taunt |
| `burning`   | effect upgraded (e.g. damage +1)     | costs 1 extra tempo from the powering pool             | capability-backed cards only |

`config/game.php` → `bargains` block: offer chance, per-turn cap.

## Engine changes

1. `ActionCard`: add `public readonly ?array $bargain = null`
   (`{key, edge_label, complication_label}`) — include the key in `id()`'s
   hash so the bargain and plain siblings get distinct ids, and surface both
   labels + the adjusted forecast in `toArray()`. The forecast must quote the
   edge in the same itemized terms `Odds` uses ("−1 band — you stop being
   careful about the noise").
2. `CardComposer`: after composing a turn's cards, one seeded pass — pick at
   most one eligible card, clone it with a bargain from the table (respecting
   the attach list and current state: no `two_hands` bargain when hands are
   already empty, no `reckless` when not concealed and no lurkers — a
   complication that costs nothing is a free lunch and violates the whole
   point). Offer beside the original.
3. `TurnResolver`: on resolving a bargained card, apply the edge to the
   `Odds` arithmetic (as an itemized part, label = edge_label) and apply the
   complication immediately after the beat resolves, emitting a fact string
   for the narrator ("the shriek of the hinges rolled down the street").
   Complications route through the EXISTING mechanisms only: the alarm
   int, `Hands`, condition clears, intent tag writes, `Meters::spend`.
4. Frontend (`SlotPicker.vue` / card display): bargains render with both
   lines visible — the sweetener and the price, equal visual weight. Never
   style the bargain as the "better" card.

## Tests

- Bargain id differs from sibling; submission validates against stored offer
  (both `validateChoice` and the resolver's own check).
- Edge appears as an itemized odds part; the DC the forecast quoted is the
  DC the roll is measured against (the `Odds` one-ladder guarantee).
- Complication fires on success AND on failure; each of the five keys routes
  to its real mechanism (alarm incremented, hands emptied, concealment
  cleared, intent written, tempo spent).
- Eligibility gates: no bargain on improvise/quiet verbs; no free-lunch
  offers (empty hands / nothing to be seen by); at most one per turn; seeded
  determinism.
- A denied tempo spend (`burning` with an empty pool) is never offered.

## Sequencing

Standalone. Composes well with `WEATHER_AND_HOUR.md` (ambient conditions can
gate bargains later — `loud` matters more in still air) but do not build that
coupling now.
