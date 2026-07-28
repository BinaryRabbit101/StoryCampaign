# Implementation brief: Rumors — the world's news reaches the fireside

Instructions for a future session. Read `CLAUDE.md` and `design/DESIGN_BIBLE.md`
first; every invariant there still binds. Sibling briefs: `BARGAIN_CARDS.md`,
`GRUDGES_AND_CLOCKS.md`, `DOWNTIME_STANCE.md`, `SCARS.md`, `WEATHER_AND_HOUR.md`,
`MEMENTOS.md`, `COMPANION_BONDS.md`, `PRESSURE_AND_VERB_BOARD.md`.

**One sentence:** the changes the evolver and the forge make off-screen become a
campaign-scoped queue of engine-templated hearsay that reaches the CHARACTER
through channels that already exist — a talk by the fire, the ground walked
during the wait, the road into a new zone — one at a time, true, and never
invented.

## Why this shape

- **The living world is the game's premise, and today it is invisible in
  play.** `WorldEvolver` tends each active campaign's world and publishes a
  Chronicle chapter with a push — the READER is told. But the character never
  hears a word of it: no scene, no conversation, no crossing ever references
  what changed. The idle game's best feature happens entirely off-stage.
- **The supply already exists.** Every evolution run logs its structured
  `changes` array on `evolution_runs` (append-only), and every frontier
  pre-forge knows what it just built. Rumors are an extraction and a delivery
  schedule, not a new content generator.
- **It pays out the quiet verbs.** SPEAK with a friendly actor and the
  walk-the-ground downtime stance are the least mechanically rewarded acts in
  the game. News is the natural yield: talking to people is how you hear
  things.

## Invariants

- **Rumors are narration colour and NOTHING else.** A rumor never appears in a
  card, an odds part, or a resolver path; it grants nothing, reveals nothing,
  and moves no number. Specifically it may never expose a `hidden` feature or
  `lurking` actor in the CURRENT scene — rumors are news about ELSEWHERE.
  Word of a grudge simmering is telling the player what the board's grudge
  line already implies; it never changes the return roll.
- **True, engine-templated, never invented.** Every rumor row derives from a
  real logged fact (`evolution_runs.changes`, a frontier pre-forge, a grudge
  the evolver tended). The line the player reads is templated by the engine
  the moment the source fact is logged; the narrator may only reword it inside
  the mementos-style clamp (≤ 20 words, same subject — violation and the
  engine's words stand). An empty queue yields silence, never fabrication —
  Claude is never asked "make up some news."
- **This is not the Chronicle.** The Chronicle chapter remains the reader's
  omniscient digest and keeps its push. Rumors are diegetic: what the
  character plausibly heard, delivered in-scene, days late and one at a time.
  Do not gate, rewrite, or deduplicate the Chronicle against the rumor queue —
  hearing an echo in play of something the reader already knows is the point
  (dramatic irony, not redundancy).
- **Delivery rides existing channels only.** No new card, no new stance, no
  push notification. A rumor lands as a `rumor` key on the resolution (the
  `downtime` / `fall` / `world` pattern — null on turns without one), at most
  one per chapter, only through the closed channel list below.
- **Direction rule, same as mementos.** Nothing under `app/Game/` may import
  the rumor model (extend the existing direction test). The resolver detects
  the delivery MOMENT from facts it already has and hands the pick outward to
  a service; producers (`WorldEvolver`, `ZoneForge`) write candidates from
  their own side.

## Design: the queue

Table `rumors` (campaign-scoped, append-only in spirit): `campaign_id`,
`source` (`evolution` | `forge` | `grudge`), `subject` (nullable refs —
`zone_id`, `actor name`), `line` (the engine-templated sentence),
`heard_turn_id` / `heard_chapter_id` (null until delivered — the only
mutation), timestamps. Producers:

- **`WorldEvolver`** — after clamping a run's changes, template one candidate
  per rumor-worthy change (a new actor seeded, an item placed, a zone
  reshaped, a grudge tended: "someone has been asking after you by name").
  Cap per run (config) — an evolution run is a night's gossip, not a gazette.
- **`ZoneForge` frontier pre-forge** — one candidate about the zone just
  built, so the venture card's destination has a voice before the crossing
  ("travellers speak of <the place> ahead").

Queue discipline: oldest first, undelivered only; a rumor about a zone the
campaign has since entered is stale — skip and mark heard (the character can
see it themselves now). Config `rumors` block: per-run candidate cap, queue
cap (drop oldest beyond it), per-chapter delivery cap (1).

## Design: the channels (closed list)

Checked in this order, first that qualifies delivers; all are moments the
turn already produced — the resolver adds no new mechanics to serve them:

| channel     | qualifies when                                                              | flavour of delivery |
|-------------|------------------------------------------------------------------------------|---------------------|
| `crossing`  | the turn transitioned scenes or ventured into a new zone                     | met on the road; zone-subject rumors preferred, the next zone's above all |
| `talk`      | a social-family beat (speak / persuade / calm / recruit …) resolved ≥ partial against a non-hostile, non-truce actor | they pass on what they heard |
| `fireside`  | the downtime stance was walk-the-ground or keep-watch and paid out           | overheard, found posted, read in the ashes of another camp |

No channel on a turn with active combat — nobody trades news mid-fight. The
companion variant (a `sworn` companion volunteering a rumor unprompted) is
deliberately NOT built now: bond initiative belongs to `COMPANION_BONDS.md`
and should wait until there is a reason to widen it.

## Engine changes

1. Migration + `App\Models\Rumor`; `rumors` block in `config/game.php`.
2. `app/Services/Rumors.php`: `offer()` (producer-side templating + caps) and
   `deliver()` (channel test → oldest-first pick → stamp `heard_*`). Seeded
   `Dice` only where a choice is rolled; delivery order itself is
   deterministic.
3. `WorldEvolver`: candidate extraction after the clamp, before the Chronicle
   publish. `ZoneForge`: the frontier candidate.
4. `TurnResolver`: detect the channel moment from the resolved facts, hand
   outward (mementos pattern), write the `rumor` key. No import of the model
   under `app/Game/` — extend the direction test.
5. `Narrator` prompt: a fixed-facts block — render the line as something
   heard, once, in the land's own voice; the clamped reword rule.
6. UI: the resolved-turn view shows the heard line with the same quiet weight
   as a downtime sentence — no badge, no push; the player finds it, like a
   memento.

## Tests

- Producers: an evolution run yields ≤ cap candidates, each traceable to a
  logged change; a frontier pre-forge yields its zone rumor; queue cap drops
  oldest.
- Delivery: each channel fires only under its qualifying facts; combat
  silences all three; one per chapter; oldest-first; stale zone rumors
  skipped and marked; `rumor` key null otherwise.
- Clamps: narrator reword outside the clamp → engine line stands; a rumor
  never appears in cards, odds parts, or board groups; the direction test.
- Empty queue: channels qualify, nothing delivers, nothing is invented.

## Sequencing

Standalone — no dependency on Endeavor Clocks, though they land well together
(a filled clock is itself rumor-worthy material for OTHER systems later; do
not build that coupling now). The evolver runs on its own schedule, so seed
test campaigns with synthetic `evolution_runs` rows rather than live LLM runs.
