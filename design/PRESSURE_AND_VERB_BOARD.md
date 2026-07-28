# Implementation brief: Pressure & the Verb Board — the world moves when you don't, and choosing becomes grammar

Instructions for a future session. Read `CLAUDE.md` and `design/DESIGN_BIBLE.md`
first; every invariant there still binds. Sibling briefs: `BARGAIN_CARDS.md`,
`GRUDGES_AND_CLOCKS.md`, `DOWNTIME_STANCE.md`, `SCARS.md`, `WEATHER_AND_HOUR.md`,
`MEMENTOS.md`, `COMPANION_BONDS.md`.

**One sentence (Feature 1):** a stall counter on the scene makes the world act
when the player keeps ceding the initiative — wait twice and something HAPPENS
(an arrival, an accident, a reveal, an old enemy), rolled from a closed table
with seeded dice and routed only through mechanisms that already exist.

**One sentence (Feature 2):** the card list becomes a Shadowgate-style grammar —
a stable board of verbs (LOOK, GO, TAKE, FIGHT, SPEAK, HIDE, TEND, WAIT, DO),
then WHAT, then HOW — a pure lens over the cards the composer already offers,
with pre/post beats re-presented as riders on the chosen act instead of two
parallel forms nobody opens.

---

## Feature 1 — Pressure: the world moves when you don't

### The wound this heals

A player on a ship whose crew "has a bad feeling" waits. Nothing happens. They
wait again. Nothing happens again. Today that is exactly what the engine does:
the `wait` card is mechanically inert (`TurnResolver::quietBeat` emits one fact
and writes nothing), the alarm clock lives only while an enemy is active and
hard-resets to zero every quiet turn, and the only thing that can reach a
passive out-of-combat turn is the 5% wandering-threat roll. `SoftTimeout`
already NAMES the concept — "the moment settles; time passes" — and does
nothing with it. The player is waiting on the game while the game waits on the
player. A mutual standoff is the deadest choice of all: it punishes the player
for believing the fiction's own telegraph.

### Why this shape

- **Waiting must be a real choice.** The no-dead-choices rule says every card
  needs a tradeoff; `wait`'s card already promises "let the scene move first."
  Pressure makes that promise true: waiting cedes the initiative, and the
  world SPENDS it. Wait stops being the null action and becomes the "your
  move" action.
- **The scene arrives thin — this is where the withheld world pays out.** The
  thin-arrival invariant only works if company genuinely shows up later. The
  alarm clock covers combat; pressure covers the quiet, which is where players
  actually stall.
- **Everything the beat can do already exists.** Like bargains, pressure buys
  no new economy: it forces the wandering-threat draw, springs an engine
  reveal, opens the grudge-return door, or changes the ground — all existing
  machinery with existing clamps.

### Invariants

- **Pressure is combat-silent.** The stall counter ticks only when NO enemy is
  active. In combat, the alarm clock owns escalation — never build a second
  combat escalator; two clocks on one fight is pressure creep in a costume.
- **Telegraphed, never a surprise.** From the first tick, the situation board
  carries a pressure line (the same pattern as the alarm's "noise is drawing
  attention" line at 2+), and the `wait` card's description says the scene is
  ready to move when one more wait would fire the beat. The player must be
  able to read that waiting again means the world acts — that is what makes
  the second wait a choice instead of an ambush.
- **The beat routes only through existing mechanisms.** Forced
  `maybeIntroduceThreat`, engine reveal of `hidden`/`lurking` (the sanctioned
  reveal path — this is an engine event, not ambient, so the ambient
  never-reveals rule is untouched), `Grudges::maybeReturn` with every one of
  its clamps intact (heat odds, chapter floor, one per scene), feature
  `destroyed` state, actor status. No new resource, no new actor source.
- **Never the player's body, never a companion.** A mishap may wreck the
  ground or hurt a bystander (non-companion, non-enemy actor — the deckhand,
  the porter); it never deals the player damage directly (arrivals fight
  through the ordinary reaction next turn, which is threat enough) and never
  touches a companion (companion harm is bond territory, `COMPANION_BONDS.md`).
- **Facts, not mechanics, reach the narrator.** The beat lands in the
  resolution as a `world` key (the established `downtime` / `fall` /
  `companions` pattern: null on turns it didn't happen), plain-worded, and the
  narration prompt renders it as the scene acting on its own. No stall
  numbers, no table keys.
- **This is not the endeavor-clock system** (`GRUDGES_AND_CLOCKS.md` Feature 2,
  still dormant). Clocks are promises the PLAYER made ticking toward a
  deadline; pressure is the WORLD's impatience with stillness. When clocks
  arrive they coexist — do not fold one into the other.

### Design: the stall counter

`scene->state['stall']`, an int beside `alarm`, tuned by a `pressure` block in
`config/game.php`:

- A turn is **quiet** when no beat cast a die, the player didn't move
  (`$moved` false), and no enemy is active.
- Quiet turn → `stall + quiet_weight` (default 1). Choosing `wait` as the main
  → `stall + wait_weight` (default 2) — an explicit wait is an invitation, and
  it should out-pace idle poking.
- Any non-quiet turn, and any scene transition, resets stall to 0.
- When `stall >= threshold` (default 3): fire ONE world beat, reset to 0. So:
  wait twice → fires on the second wait. Poke around aimlessly → fires on the
  third quiet turn. Exactly the cadence the complaint asked for.
- While `0 < stall < threshold`, the resolution carries a one-fact omen
  ("the quiet is thinning") so the narration builds instead of flat-lining,
  and the board shows the pressure line.

### Design: the world-beat table

Closed engine list, seeded roll, filtered by applicability before weighting —
a beat that cannot cost or change anything here is not in the pool (the
free-lunch rule, inverted: a world beat that cannot LAND is a blank).

| key       | requires                                    | what happens (existing machinery only)                                                                 |
|-----------|---------------------------------------------|--------------------------------------------------------------------------------------------------------|
| `arrival` | a zone spawn template exists                | `maybeIntroduceThreat(forced: true)`, never lurking — the point is that something visibly HAPPENS       |
| `reveal`  | a `hidden` feature or `lurking` actor here  | the engine exposes it: the lurker steps out telegraphing, or the ground gives the feature up            |
| `grudge`  | a simmering grudge on the campaign          | `Grudges::maybeReturn` called outside a transition — same clamps; pressure adds a doorway, not an economy |
| `mishap`  | a visible, non-stage feature to break       | the accident: one feature goes `destroyed`/transformed; seeded coin may hurt a bystander actor (status, never the player, never a companion) — next turn's aid/rescue material |

If the filtered pool is somehow empty (bare scene, no templates, no grudges),
the beat re-arms: stall holds at threshold and fires on the next turn something
qualifies. Never invent content to fill the gap — that is the forge's job.

### Engine changes

1. `config/game.php`: `pressure` block — `quiet_weight`, `wait_weight`,
   `threshold`, per-key beat weights.
2. `app/Game/Engine/Pressure.php`: `tick()` (quietness → stall arithmetic) and
   `fire()` (filter, weight, roll, execute, return fact strings). Seeded
   `Dice` throughout.
3. `TurnResolver::resolve`: call beside the alarm block (after
   `recordFlights`, before `evaluateTrigger` — an `arrival` must be visible to
   the trigger ladder so the turn classifies as `NewThreat`, not
   `SoftTimeout`). Write the `world` key into the resolution.
4. `SituationBoard`: the pressure line while stall > 0 (scene group — this IS
   scene state, unlike scars).
5. `Narrator` prompt: render `world` facts as the scene moving on its own,
   once, in the land's own terms.
6. `CardComposer`: `wait`'s description becomes state-aware — when one more
   wait would fire, it says so in plain words ("the stillness is about to
   break").

### Tests

- Two consecutive `wait` mains fire a beat on the second; three quiet
  non-wait turns fire on the third; any die-casting or moving turn resets.
- Stall never ticks while an enemy is active (alarm's territory).
- Each beat key routes to its real mechanism; `reveal` never fires with
  nothing hidden; `mishap` never touches player health or a companion;
  `grudge` respects the chapter floor and one-per-scene.
- Empty pool re-arms instead of inventing.
- Seeded determinism; `world` key null on turns without a beat; board line
  present from first tick.

---

## Feature 2 — The verb board: choosing becomes grammar

### The wound this heals

The main slot today is a flat list of pre-baked composites — "attack enemy /
grab enemy / heal wounds" — that can run 8–20 big cards, and the pre/post
slots are two more parallel forms that go unread because they are presented as
separate, equal decisions when they are actually subordinate ones. The player
is choosing between finished sentences when what they want to do is SAY a
sentence: pick a verb, point at a thing, choose the manner. Shadowgate had
this right in 1987 — a stable board of verbs builds muscle memory, and the
scene supplies the nouns.

### Why this shape

- **The engine already speaks this grammar.** Every `ActionCard` is literally
  verb × target × how (`verb`, `target: {type,id,name}`, `capability` +
  `modifiers`), and `SlotPicker.vue` already half-does the collapse (rows
  keyed `verb:capability:bargain`, targets as a chip strip). The redesign is
  overwhelmingly PRESENTATION — the composer, resolver, odds, and submission
  payload do not change shape.
- **A lens, never a new composition path.** The board lights only verbs with
  ≥1 offered card; every selection terminates in an offered card id. "Never
  resolve a card the engine didn't offer" holds by construction.
- **Pre/post become visible exactly when they matter.** A setup card's whole
  value is its grant to the main act — so show it AS that: a one-line rider on
  the chosen act quoting its computed delta ("Steady yourself first — +2 to
  the climb"), not a third form to scroll past. The engine slot chain is
  untouched; only the framing moves.

### Invariants

- **The verb vocabulary becomes first-class.** ~48 verb strings currently live
  implicitly in three drift-prone places (composer construction sites, the
  resolver's switch arms, `SlotPicker.vue`'s hardcoded families). New
  `app/Game/Verb.php` catalog: every verb declares its label and family; a
  test asserts every composed and every resolved verb exists in the catalog.
  Engine verbs are NOT renamed — families are a presentation layer over them.
- **≥2 verbs always lit.** LOOK (`examine`), WAIT, and DO (`improvise`) are
  unconditional today; the board inherits the ≥2-legal-cards guarantee.
  Unlit verbs render dim, not hidden — "nothing here to TAKE" is information
  about the scene, and the stable board is the muscle memory. A dim verb is
  grammar, not a dead choice: it is never pickable, so it prices nothing.
- **Odds visible at every step.** Target chips carry their DC (they already
  do); the HOW step shows the full itemized forecast per stance; the bargain
  twin appears under HOW as "the loud way" with gain and cost at equal
  weight, never styled as the better path. Nothing about the redesign may
  hide a number the flat list showed — `+0` included.
- **DO is still improvise.** Base stats, no bonus, risky — never better than
  an enumerated option, no bargains on it.
- **The submission payload does not change.** Still
  `{pre?, main, post?, companions}` of card ids + clamped modifiers;
  `validateChoice` and the resolver's legality re-check are untouched.

### Design: the verb families

The stable top row, every scene, in this order. Second level = the engine
verbs beneath, shown only when offered:

| board verb | engine verbs it opens                                                        | always lit? |
|------------|------------------------------------------------------------------------------|-------------|
| LOOK       | `examine`, `inspect`, `scout`, `detect`, `track`                             | yes (`examine`) |
| GO         | `cross`, `venture`, `flee`, `ascend`, `ride`, `reposition`                   | no |
| TAKE       | `lift`, `loot`, `haul`, `drop`, `hurl`                                       | no |
| FIGHT      | `strike`, `interrupt`, `restrain`, `brace`, `shield`                         | no |
| SPEAK      | `speak`, `persuade`, `deceive`, `calm`, `intimidate`, `command`, `recruit`, `bargain`, `companion_*` welcomes/requests | no |
| HIDE       | `hide`, `quiet_move`                                                         | no |
| TEND       | `bandage`, `catch_breath`, `recover`, `ready`, `time_slow`, `haste`          | no |
| WAIT       | `wait`                                                                       | yes |
| DO         | `improvise`                                                                  | yes |

Family assignment lives on the `Verb` catalog case, replacing
`SlotPicker.vue`'s `FAMILIES` array. Exact membership is the implementing
session's call — the rule is that every catalog verb has exactly one family.

### Design: the three steps and the riders

1. **VERB** — the board. Tap a lit verb; it opens its offered engine verbs
   (collapsed when there's only one, which is the common case).
2. **WHAT** — the target chips for that verb, each with its DC. Untargeted
   verbs (wait, catch_breath) skip this step.
3. **HOW** — one panel for the chosen card: capability when more than one
   body serves the verb (swing up vs. climb up — today these render as two
   separate rows; here they become one verb, two manners), stance chips with
   DC deltas, `method` chips, the bargain twin, the note box. The full
   itemized forecast lives here.

Then the riders, drawn from the pre/post slots the engine already composed:

- **"First…"** riders (pre): shown AFTER the main act is chosen, each as one
  line quoting its computed effect against that act (the `setupGrants` math
  the play screen already does): "Steady yourself — +2 to this", "Brace — the
  next blow answers the windup". Offer at most the few that actually bear on
  the chosen act or a standing threat; a rider with nothing to grant here is
  noise, not choice.
- **"Afterward…"** riders (post): same shape, after the main. Bandage only
  when hurt, loot only when there's a body — the composer's existing gates.

The turn reads as one sentence the player assembled: *"First steady yourself —
then CLIMB the mast, carefully — afterward, catch your breath."* One form, one
commit, exactly as before.

### UI changes

1. `VerbBoard.vue` (the stable row + engine-verb drawer), `TargetStrip.vue`
   (extracted from the existing chip strip), `HowPanel.vue` (forecast ledger,
   stance/method/bargain/note — most of current `SlotPicker.vue` row
   internals), `RiderList.vue` (pre/post one-liners).
2. `Play.vue`: one picker instead of four `SlotPicker` stacks; companions keep
   their own compact request row (their slot is parallel, not part of the
   sentence). `DowntimePicker` unchanged.
3. Delete `SlotPicker.vue`'s `FAMILIES` and `GROUPING_THRESHOLD` — the board
   replaces both.

### Engine changes

1. `app/Game/Verb.php`: the catalog (case per verb string, `label()`,
   `family()`), adopted at every composer construction site and resolver
   switch arm. The drift test.
2. `ActionCard::toArray`: include `family` so the client never re-derives it.
3. No change to `Odds`, `TurnResolver` flow, slots, or the payload.

### Tests

- Catalog completeness: every verb the composer emits and every verb the
  resolver switches on exists in `Verb`; every verb has exactly one family.
- Board floor: LOOK/WAIT/DO lit in an empty undressed scene (≥2 invariant).
- A submission assembled through the new flow round-trips `validateChoice`
  byte-identically to one assembled from the old flat list.
- Rider math: the quoted delta equals the grant the resolver actually applies
  (the one-ladder guarantee, extended to riders).

---

## Sequencing for the implementing session

Feature 1 first — it is small (one class, one config block, a handful of
resolver lines), standalone, and it fixes the live complaint: a player is
stalled TODAY. Feature 2 is UI-heavy and independent; build it second, and
land the `Verb` catalog as its own commit before touching the Vue side (the
catalog + drift test is pure hardening even if the board slips). Neither
feature depends on the other, but Feature 2's WAIT verb should quote the
pressure state Feature 1 creates ("the stillness is about to break") once both
exist.
