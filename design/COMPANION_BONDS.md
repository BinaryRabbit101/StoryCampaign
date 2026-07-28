# Implementation brief: Companion Bonds & Campfire Beats — and companions the road provides

Instructions for a future session. Read `CLAUDE.md` and `design/DESIGN_BIBLE.md`
first; every invariant there still binds. Sibling briefs: `GRUDGES_AND_CLOCKS.md`
(grudges are implemented — see `Grudges::` calls in `TurnResolver`),
`DOWNTIME_STANCE.md`, `SCARS.md`, `BARGAIN_CARDS.md`, `WEATHER_AND_HOUR.md`,
`MEMENTOS.md`, `SIDE_THREADS.md`.

**One sentence:** something accumulates between you and a companion — a bond
that grows through shared danger and quiet campfire beats, unlocks a signature
assist, makes their loss actually cost something — and companions can now
*find the player* (the grateful offer, the stray that follows) instead of only
ever being asked.

## What exists today (build on it, don't replace it)

- Companions are coordinated, never controlled: one request per companion per
  turn in the `TurnSlot::Companion` slot (`CardComposer::companionCards` —
  block / flank / strike / scout), engine-rolled, failure can cost them.
- Recruitment is ask-only: a `recruit` card when an actor is `companionable`
  or swayed/calmed (`CardComposer.php:~521`), resolved at
  `TurnResolver.php:~866` by flipping `kind` to `companion`.
- Companions walk the tale, not the scene: `transitionScene` carries active
  companions into the next scene (`TurnResolver.php:~1452`).

## Invariants

- Coordinated-never-controlled stands, at every bond tier. A deeper bond
  sharpens *requests* and adds *their own initiative* — it never gives the
  player direct control of their beat.
- Bond movement is engine-fact-driven only: rolls the resolver already made.
  Notes, narration, and genre never move a bond. Claude is told the tier in
  plain words ("she has bled beside you"), never the number.
- Joining is always consensual on BOTH sides: every natural path ends in an
  engine-offered accept/decline card pair for the *player* ("Welcome them" /
  "Send them on their way") — nobody is saddled with a companion, and
  send-away is never a dead choice (the departing soul leaves a small fact:
  a told rumor, a shown path — colour, not mechanics).
- Cap: 2 active companions. A third candidate's offer simply doesn't fire
  while full (the stray keeps its distance; the grateful part warmly).
- Bond effects reuse existing vocabulary: odds parts, `Odds::CONDITIONS`,
  companion cards. No parallel buff system, no XP, no companion "levels".

## Data model

- On the companion actor row: `tags.bond` int (0–6), `tags.bond_tier`
  derived (`stranger` 0–1, `fellow` 2–4, `sworn` 5–6), `tags.joined_via`
  (`asked|grateful|stray`), `tags.signature` (set at fellow, see below).
- `config/game.php` → `companions` block: cap, bond thresholds, stray odds.

## Bond movement (engine events, all already visible in the resolver)

+1: companion succeeds a requested card; player's `companion_block` request
saves them from a landed hit; a campfire beat is shared (below); a scene
entered in a NEW zone together (the road itself). −1: a requested `risky`
beat gets them hurt (trust dents, it doesn't shatter). Never below 0; sworn
is sticky (once reached, dents can't drop the tier — the history happened).

## Tier effects

- **Fellow** — the companion gains a SIGNATURE: one extra request card,
  engine-picked once from a small closed table keyed to their kind/tags
  (a creature: `companion_harry` — drag a foe's angle off you; a talker:
  `companion_distract` — an enemy's intent turns from windup to circle; a
  scout-ish soul: `companion_forage` — walk-the-ground's reveal, from their
  legs not yours). Picked with seeded dice, stored in `tags.signature`,
  worded by the narrator once as a small chapter moment.
- **Sworn** — once per chapter, unbidden: if a blow would drop the player to
  0, the sworn companion intercepts — the damage lands on them instead,
  engine-triggered, no card, no player input (initiative, not control). This
  is the emotional payload of the whole system: the fall that didn't happen
  because of who was beside you. (If `SCARS.md` is in: this fires BEFORE the
  scar path, and may down the companion instead.)

## Campfire beats (requires `DOWNTIME_STANCE.md`)

A companion present adds one stance to the downtime offer: **share the
fire**. Payout: half of rest's healing, +1 bond to ONE companion
(engine-picks the lowest-bond present), and the next chapter's narration
receives a campfire fact — an engine-picked topic from a closed list seeded
per companion (where they came from; what they fear; what they make of the
player's latest scar/grudge/memento — cross-reference whichever systems are
live). The player's optional note rides along as colour, same rules as beat
notes. This is where the game earns its quiet pages; `ProseStyle` register
applies hard (one campfire beat per wait, a paragraph, no speeches).

## Companions found, not asked

Two engine paths that initiate from the world's side (`joined_via` records
which; a `SIDE_THREADS.md` payoff is a third):

1. **The grateful.** When a turn's resolved facts include a rescue — a
   captive freed, a non-hostile actor saved from a landed threat by the
   player's beat — and the survivor is not hostile, roll seeded dice
   (suggest 40%): next turn carries the offer pair ("X asks to walk with
   you"). Fires at most once per chapter.
2. **The stray.** `SceneDresser::spawnActors` may rarely (config, suggest
   ~8% of spawns) mark a spawned non-hostile creature/NPC `tags.following`:
   it keeps to the scene's edge — visible on the situation board under "Also
   here" with "— keeping near you", no companion slot, no cards but the
   capability-free trio. It walks scene transitions like a companion does.
   After it witnesses a shared success (any succeeded main beat with it
   present, 2 scenes minimum) the offer pair fires. If the player never
   engages, it simply stays a stray — ambience, not obligation.

## Loss

A companion downed by a failed request or an interception doesn't vanish
mid-scene: they are `status: 'downed'` on the board ("At your side — down,
breathing"). Scene exit decides it, engine-rolled against bond tier: a
stranger slips away when recovered (gone); fellow/sworn survive to walk on at
1 health — except a sworn interception in a lost fight, which may be final
(seeded, rare). A companion lost for good is a `MEMENTOS.md` trigger
(`companion_lost`, priority just below `rival_settled`) and a standing
narration fact. Never re-spawn the name.

## Engine changes (summary)

1. Bond bookkeeping in `TurnResolver` beside the companion-beat resolution
   and block/interception paths; tier derivation in one place.
2. `CardComposer::companionCards`: append signature card at fellow+;
   `SituationBoard` allies group gains the plain-word tier ("at your side,
   sworn") and stray line.
3. Offer pairs composed as normal main-slot cards, validated as ever.
4. Downtime integration (share the fire) per `DOWNTIME_STANCE.md`.
5. Narrator prompt: tier words, campfire facts, interception facts.

## Tests

- Bond moves only on the listed engine events; sworn stickiness; cap holds.
- Signature picked once, seeded, from the right table; card appears fellow+.
- Interception fires once per chapter, only at would-be-0, damage rerouted.
- Grateful path: only after genuine rescue facts, once per chapter, offer
  pair validates; decline leaves no companion and one colour fact.
- Stray: spawn-rate config honored; walks transitions; converts only after
  witnessed success + 2 scenes; ignored stray stays a stray.
- Loss rolls respect tier; lost companion never respawns; memento minted.
- Claude unavailable: everything above still works (all prose is additive).

## Sequencing

Bond core + signatures first; campfire beats need `DOWNTIME_STANCE.md`;
natural finding is independent of both and can ship first if desired (it is
the smallest slice: two triggers + offer pair).
