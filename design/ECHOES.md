# Implementation brief: Echoes — the shelf becomes one library

Instructions for a future session. Read `CLAUDE.md` and `design/DESIGN_BIBLE.md`
first; every invariant there still binds. Sibling briefs: `BARGAIN_CARDS.md`,
`GRUDGES_AND_CLOCKS.md`, `DOWNTIME_STANCE.md`, `SCARS.md`, `WEATHER_AND_HOUR.md`,
`MEMENTOS.md`, `COMPANION_BONDS.md`, `PRESSURE_AND_VERB_BOARD.md`, `RUMORS.md`,
`HOUR_AND_RECAP.md`, `STANDING.md`, `SIDE_THREADS.md`, `FINALE.md`.

**One sentence:** when a moment in the current tale RHYMES with a moment a
finished tale already preserved — a scar taken where a scar was once taken, a
rival settled, familiar land underfoot, an ending gathering — the engine may
surface one quoted line from that closed book as a memory, narration-only,
rare, and always traceable to something the player actually lived.

## Why this shape

- The player's campaigns already share a history (`WorldFlavor` refuses their
  last three lands; the shelf of compiled books accumulates per user), but the
  books never speak to each other — every new tale begins in total amnesia. A
  single remembered line ("you carried a mark like this once, in another
  life") makes the shelf one library without touching a single mechanic.
- Every source already exists and is already clamped: memento lines are
  engine-templated and narrator-reworded under the ≤-20-words rule; chapter
  `intent_line`s are persisted verbatim. Echoes are quotation, not generation
  — the `Recap` philosophy pointed backward across the shelf.
- Rhyme is what keeps it from being noise. A random old quote is trivia; the
  SAME KIND of moment recurring is memory. The trigger table below is
  engine-detectable resonance, nothing more.

## Invariants

- **Narration colour ONLY.** An echo appears in no card, no odds part, no
  board group, no resolver path; it reveals nothing, spawns nothing, and
  moves no number. It is the memento contract pointed at the past.
- **Quotation, never invention.** Every echo row stores the source campaign,
  source type (`memento` | `chapter`), source id, and the verbatim line.
  Claude may reword only the engine's FRAME (the "a memory surfaces" wrapper,
  mementos-style clamp — violation and the engine's words stand); the quoted
  line itself is carried verbatim. An empty shelf yields silence — the first
  campaign simply never echoes, and Claude is never asked to remember on the
  player's behalf.
- **Only closed books echo.** Sources come exclusively from the SAME user's
  ENDED campaigns (book compiled). A running sibling tale never leaks — its
  moments are not memories yet.
- **The seal on worlds holds.** An echo QUOTES; it never instantiates. A past
  companion's name may be spoken as memory; no actor, zone, item, grudge, or
  feature ever crosses campaigns through this system. (Items already have
  their own sanctioned door; echoes are not a second one.)
- **Rare, capped, and found — never announced.** Seeded chance per qualifying
  rhyme, a per-campaign cap, a chapter cooldown, and each source line echoes
  at most once per new campaign. No push. Delivered exactly like a rumor: the
  `echo` resolution key (nullable pattern), one narrator block rendered as
  remembering, one quiet line on the resolved-turn view.
- **Direction rule.** Nothing under `app/Game/` imports the echo model or the
  memento model (the existing sweep extends). The resolver detects the RHYME
  from facts it already has and hands the pick outward to a service.

## Design: the rhyme table

Checked after resolution facts are fixed; at most one echo per turn; each
rhyme draws only from its own column. Closed list:

| rhyme          | fires when (this tale, this turn)                          | draws from (finished tales)                    |
|----------------|-------------------------------------------------------------|------------------------------------------------|
| `the_mark`     | a `scar_taken` memento was minted                            | a `scar_taken` memento line                    |
| `the_rival`    | a `rival_settled` memento was minted                         | a `rival_settled` memento line                 |
| `the_company`  | a companion reached `sworn`, or `companion_lost` minted      | a companion-subject memento line               |
| `old_ground`   | a scene opened in a land a finished tale was set in          | that tale's `first_ground` memento line, else its first chapter's `intent_line` |
| `the_gathering`| the finale ARMED this turn                                   | the closing chapter's `intent_line` of a finished tale |

The frame is engine-templated per rhyme in TWO registers, and which one
speaks is a claim about the world the engine only makes when it can back it:

- **Memory** (the source tale stood on a different land): "Another life of
  theirs came away marked like this: «line» — from the tale of <title>." A
  half-remembered other life owes nobody any geography, so wildly different
  lands and tech levels never collide.
- **Legend** (the two tales share their `world_flavor`): "This land still
  tells of one who came away marked like this…" — the shared universe made
  audible exactly where it demonstrably IS shared. Past protagonists get
  their afterlife as figures the ground still tells of; the `old_ground`
  rhyme lands in this register by construction. The narrator guidance shifts
  with it (a story the character may have heard told, rather than a private
  remembering) while every other rule holds: telling, not happening — nobody
  from that tale is present.

The register is DERIVED from the two campaigns' stored lands, never stored —
like the quote itself, it cannot drift. The quote stays verbatim, the source
title named. When several sources qualify, the pick is seeded; when none
does, the rhyme is silent.

## Data model

Table `echoes`: `campaign_id`, `source_campaign_id`, `source_type`,
`source_id`, `rhyme`, `line` (the assembled frame + quote), `turn_id`,
`chapter_id` nullable, timestamps. Config `echoes` block: `chance`,
`campaign_cap` (default 4), `cooldown_chapters` (default 3).

## Engine changes

1. Migration + `App\Models\Echo` (mind the PHP reserved-ish name — pick
   `EchoLine` if `Echo` collides with anything); `echoes` block in
   `config/game.php`.
2. `app/Services/Echoes.php`: rhyme → source query (ended campaigns of the
   same user, unused sources, seeded pick) → row + assembled line; the
   narrator frame-reword clamp (mementos pattern).
3. `TurnResolver`: rhyme detection from facts already fixed (the minted
   memento kind, the bond event, the scene's land at transition, the finale
   arming), handed outward; `echo` resolution key.
4. `Narrator`: the remembering block (render once, as memory, in the land's
   own voice — the past tale's land may differ; the frame already carries its
   title) + the frame reword clamp stamping `chapter_id`.
5. UI: the quiet line on the resolved-turn view beside the rumor line — same
   register, no badge.

## Tests

- First campaign: silence everywhere. Running sibling campaigns never source.
- Each rhyme fires only on its trigger and draws only from its column;
  verbatim quote preserved through the narrator reword (frame may move, quote
  may not); clamp violation leaves the engine line standing.
- Caps: per-campaign cap, chapter cooldown, once-per-source-per-campaign; at
  most one echo per turn; seeded determinism.
- Traceability: every row resolves to a real persisted memento/chapter of an
  ended campaign owned by the same user; the direction sweep extends to the
  echo model.
- An echo never appears in cards, odds parts, or board groups, and never
  mutates the source campaign.

## Sequencing

Standalone; it reads mementos, chapters, campaigns, and the finale's armed
flag but writes none of them. Seed test shelves with synthetic ended
campaigns rather than compiling real books.
