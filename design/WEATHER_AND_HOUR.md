# Implementation brief: Weather & Hour — ambient conditions that touch the odds

Instructions for a future session. Read `CLAUDE.md` and `design/DESIGN_BIBLE.md`
first; every invariant there still binds. Sibling briefs: `GRUDGES_AND_CLOCKS.md`,
`DOWNTIME_STANCE.md`, `SCARS.md`, `BARGAIN_CARDS.md`, `MEMENTOS.md`.

**One sentence:** `SceneDresser` rolls one ambient condition per dressed scene
— gloom, haze, squall, or clear — that shows as a situation-board line, prices
into the odds ledger as itemized parts, and gives the narrator one true fact
about the air, so the same locale plays differently on a second visit.

## Why this shape

- The anti-repetition mission is engine-side variety; ambient conditions are
  the cheapest lever not yet pulled. Two visits to the same dressed locale
  currently differ by feature draw and actors only.
- It deepens existing choices rather than adding new ones: concealment,
  ranged strikes, climbs, and tracking all shift value under different air,
  which re-ranks cards the player already knows.

## Invariants

- **Abstract keys, not weather words.** The engine's vocabulary must work on
  the ash steppe, the canopy town, AND the derelict station: `gloom` (light
  is low — night, dead lamps, emergency lighting), `haze` (air is obscured —
  fog, dust, steam, smoke), `squall` (air is violent — wind, rain, venting
  pressure), `clear`. The NARRATOR translates the key to the land's idiom via
  the world brief; the engine never says "rain". This is the same discipline
  as the cold-forge kits: mechanics identical everywhere, only fiction moves.
- Genre/drive/tech never influence WHICH ambient rolls (StoryAspects are
  colour only). The land doesn't either — keep the roll uniform; a station's
  "squall" is venting atmosphere, which is exactly the point.
- Weight toward `clear` (suggest ~50%): ambient is seasoning; a world that is
  always dramatic is never dramatic.
- Every mechanical effect is an ITEMIZED `Odds` part with a plain label —
  never a hidden modifier — and effects are two-sided where possible (each
  ambient helps something and hinders something; single-sided ambients are
  difficulty creep wearing a costume).
- Hidden/lurking rules unchanged: ambient may make *detection* harder or
  easier via the odds, but never reveals or conceals anything by itself.

## Design: the ambient table

Lives beside `Odds::CONDITIONS` as `Odds::AMBIENT` (or its own class if it
grows), read by both forecast and roll — one ladder, two readers:

| key      | helps (odds parts)                                  | hinders                                            |
|----------|-----------------------------------------------------|----------------------------------------------------|
| `gloom`  | −2 to be noticed: sneak-family, gaining `concealed` | +2 examine/inspect/detect; +1 ranged strike DC     |
| `haze`   | −2 gaining `concealed`; flee/disengage easier (−1)  | +2 ranged strike; +2 detect at range               |
| `squall` | +? — tracks/trails: `track` easier (−2)             | +2 climb/ascend/swing/leap; ranged strike +2       |
| `clear`  | (no parts — the baseline)                           | (no parts)                                         |

Numbers are suggestions; keep every magnitude within the existing ±4 spread
of `Odds::CONDITIONS` so ambient never outweighs a player-built advantage.

## Engine changes

1. `SceneDresser`: roll ambient with the scene's seeded `Dice` at dress time,
   store on `scene->state['ambient']`. Legacy undressed scenes: treat absent
   as `clear`. (Optional, cheap: `squall` may not persist — on scene entry
   after N turns elsewhere, re-roll with the same seed policy? SKIP for v1;
   ambient is fixed for the scene's life. Simple and re-readable.)
2. `Odds`: difficulty and bonus tallies read `state['ambient']` and emit the
   table's parts for the verb in play, labels in plain words ("The air is
   thick — a shot must guess"). The card forecast picks these up for free if
   the table is read by both halves (mirror how `CONDITIONS` works).
3. `SituationBoard`: one line in a new `sky` group (title "The air", tone
   `ground`) when ambient ≠ clear, phrased abstractly ("Light is low here").
   Empty-group-absent rule applies — clear says nothing.
4. Narration: the situation prose already flows to the narrator; additionally
   pass the ambient key with an instruction to render it in the land's own
   weather (one mention, `ProseStyle` register — not a recurring weather
   report).
5. `DiceTable.vue` / odds display: ambient parts render like any other part —
   no special UI.

## Tests

- Dressed scene rolls exactly one ambient, seeded-deterministic; legacy
  scenes read as clear.
- Each non-clear ambient emits its parts for the right verbs in BOTH the
  card forecast and the resolved roll (the quoted DC is the paid DC).
- Clear emits nothing anywhere (no board line, no parts).
- Ambient never flips `hidden`/`lurking` visibility by itself.
- Distribution over many seeds honors the clear-weighted config.

## Tuning knob

`config/game.php` → `ambient` block: weights per key. Set squall rarest —
it is the most intrusive.

## Sequencing

Standalone. If `DOWNTIME_STANCE.md` landed, the wait's narration line may
mention the ambient the player returns to (colour only). If
`BARGAIN_CARDS.md` landed, a later pass may gate bargains on ambient
(`loud` in still air) — not in v1.
