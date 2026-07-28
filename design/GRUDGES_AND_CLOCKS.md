# Implementation brief: Grudges (nemesis system) & Endeavor Clocks

Instructions for a future session. Read `CLAUDE.md` and `design/DESIGN_BIBLE.md`
first; every invariant there still binds. This doc adds two mechanics designed
2026-07-27. They are independent — ship Grudges first (bigger win, less UI),
Clocks second, in separate commits.

The shared gap they close: every existing tension system (telegraphs, angles,
alarm, lurkers, pursuit) is scene-local and resets when the scene ends. Nothing
persists that the player is fighting *for* or *against* across chapters, so
there is no long-arc stake pulling the player back through the idle wait.
Grudges give the world memory against the player; Clocks give the player
committed goals inside the world.

---

## Feature 1 — Grudges: the tale's enemies remember you

**One sentence:** an enemy who flees a resolved turn becomes a campaign-scoped
grudge that `WorldEvolver` tends and `SceneDresser` can bring back — changed,
scarred, and carrying history — instead of despawning into nothing.

### Why this shape

- `TurnResolver` already produces the dramatic asset: actors get
  `status = 'fled'` when broken or intimidated (see the `fled` writes around
  `TurnResolver.php:584` and `:622`). Today only the *player* can act on a
  fled enemy (`track`, `TurnResolver.php:689`); the enemy never acts on the
  player. Grudges fix that asymmetry.
- The payoff compounds through existing systems for free: chapters are
  append-only, so a recurring name threads through the keepsake book; the
  Chronicle narrates their off-screen movements; "the tale remembers" becomes
  mechanically true.

### Invariants this must honor (beyond the global ones)

- **Engine decides WHETHER and WHEN a grudge returns.** Claude (via the
  evolver) may only propose *how they've changed within clamps* and narrate.
  Never let the LLM decide that a grudge appears in a scene.
- Grudges are **campaign-scoped** (they live in one tale's private world) and
  **genre-agnostic** (no mechanic may read genre/drive/tech).
- Stat growth is clamped: a returning grudge may never exceed the evolver's
  existing actor stat bounds (`config/game.php` `bounds`), and tier stays
  `regular|elite` — a grudge is a story thread, not a difficulty spiral.
- A returned grudge is a normal actor to every downstream system: cards,
  intents, lurking, situation board all work unchanged. No special-case
  combat logic.
- Hidden is hidden: a grudge returning `lurking` must not reach cards,
  situation, or narration until revealed — reuse `visibleActors()` paths.

### Data model

New table `grudges` (one migration; note uncommitted migration
`2026_07_27_180000_add_board_hands_and_growth_verdicts.php` exists on this
branch — date any new migration after whatever is current when you start):

- `campaign_id` (FK), `actor_name` (string — the identity; actor rows are
  scene-scoped copies, so the *name* is the durable identity, matching how
  `ChapterEntities` treats named actors)
- `stats` json, `tags` json, `tier` (snapshot at flee time, the base the
  evolver mutates within clamps)
- `history` json — append-only list of entries
  `{turn_id, chapter_id, event, detail}` where `event` is one of
  `fled|returned|escaped_again|deal_offered|resolved`. This is what the
  narrator prompt quotes.
- `heat` tinyint (0–3): how ready they are to return. Starts at 1 on flee.
- `disposition` string: `vengeful|wary|scheming` — engine-rolled at creation
  from the flee circumstances (wounded → vengeful, intimidated → wary,
  untouched → scheming). Colors the return mode; never read by difficulty.
- `status`: `simmering|returning|resolved`. `resolved` is terminal (killed,
  restrained-and-kept, or bargained with on return).
- `last_seen_chapter_id` nullable FK.

### Engine changes

1. **Creation** — in `TurnResolver`, at both `fled` write sites: if the actor
   is `kind === 'enemy'` and has a name that isn't a generic template dupe,
   upsert a grudge (unique per campaign+name; a re-flee bumps `heat` and
   appends history instead of duplicating). Also append history when a `track`
   pursuit corners them and when they're killed/restrained (→ `resolved`).
2. **Return decision** — engine-side, in the scene-transition path (the same
   place pursuit delivers a cornered quarry, `TurnResolver.php:~1402`, and/or
   `SceneDresser::spawnActors`): when dressing a new scene, roll on the seeded
   `Dice` against `heat` (suggested: return chance `heat * 15%`, hard floor of
   2 chapters since `last_seen_chapter_id` so returns never feel instant).
   On a hit, spawn the grudge's actor into the scene from its stored
   stats/tags, mark grudge `returning`, append history. Disposition picks the
   entry mode: `vengeful` → active with an aggressive intent telegraphed,
   `wary` → `lurking` (existing ambush machinery), `scheming` → non-hostile
   approach carrying a deal (an engine-composed `speak`-family card — reuse
   the parley path; the deal's mechanical content is engine-picked from a
   small closed list, e.g. calls off reinforcements / reveals a hidden
   feature / leaves forever, so Claude never invents outcomes).
   At most ONE grudge may return per scene.
3. **Evolver tending** — in `WorldEvolver::buildPrompt`, list the campaign's
   `simmering` grudges (name, disposition, history digest) and allow the plan
   JSON an optional `grudges` array: per grudge, a short `development`
   (chronicle-facing prose) and an optional stat/tag delta. In
   `WorldEvolver::apply`, clamp deltas to the actor bounds, bump `heat` by at
   most 1 per run, cap tended grudges per run (add to
   `bounds.evolution_budget`, suggest `'grudges' => 2`). The chronicle prose
   naturally carries their off-screen movements.
4. **Narration** — when a returned grudge is in the scene, the narrator prompt
   gets a `## Returning figure` block: name + history entries (already prose
   facts, no mechanics language) + disposition word. `Narrator` and the
   situation path must treat them as any visible actor otherwise. Add a
   situation-board group (`SituationBoard`): key `grudge`, title "An old
   score", tone `foe`, one line ("{name} — you have met before; they fled you
   at {place}").
5. **Book** — no compiler changes needed (chapters already carry the story),
   but verify `BookCompiler` doesn't dedupe/alias named actors in a way that
   would hide the recurrence.

### Tests (PHPUnit, seeded Dice throughout)

- Fleeing enemy creates a grudge; re-flee bumps heat, no duplicate row.
- Return roll respects the 2-chapter floor and per-scene cap of one.
- `lurking` return is invisible to cards/situation/narration until revealed.
- Evolver deltas beyond bounds are clamped; `grudges` budget respected;
  heat +1 max per run.
- Kill/restrain/bargain on return marks `resolved`; resolved grudges never
  return.
- A grudge from campaign A never appears in campaign B.

---

## Feature 2 — Endeavor Clocks: multi-turn goals the player can price

**One sentence:** a player-committed, engine-tracked progress clock (4–6
segments) for a multi-turn endeavor — a ritual, a siege, a search, a build —
that turns qualifying successes into visible progress and fills toward an
engine-resolved payoff.

### Why this shape

- The alarm counter (`scene->state['alarm']`, read in `SituationBoard`) proves
  the pattern works against the player; this is the player-owned mirror.
- It extends the `Odds` philosophy ("a card the player cannot price is a card
  they cannot choose"): a goal whose progress is invisible is a goal the
  player won't pursue. Every tick must be as legible as the odds ledger.

### Invariants

- **The engine authors the clock.** Clocks are *offered as cards* by
  `CardComposer` when the scene supports one (never free text, never
  player-named): e.g. a `hidden`-rich scene offers "Begin a sweep of the
  vault" (search clock), a breakable obstacle wall offers a demolition clock,
  a branch objective offers a preparation clock. Committing is a normal card
  submission through `validateChoice`.
- Progress is mechanical: a beat ticks the clock only if its verb is in the
  clock's engine-defined `advance_verbs` and the roll succeeded. Notes,
  genre, and narration never tick anything.
- The payoff is engine-resolved on fill (reveal all `hidden` features /
  destroy the obstacle / grant a `readied`-class condition — reuse `Odds`
  `CONDITIONS`, don't invent a parallel buff system). Claude narrates the
  fill; it never adjudicates it.
- One active clock per campaign (keeps UI and stakes readable). Abandoning
  is free but loses progress — commitment is the tradeoff, honoring the
  no-dead-choices rule (a clock card must always compete with immediate
  options that are also good).
- Clocks expire: scene-scoped clocks die on scene exit unless the clock is
  explicitly `portable` (engine-set); this prevents stale UI and impossible
  goals.

### Data model

Table `clocks`: `campaign_id`, `scene_id` nullable, `name`, `segments`
(4–6), `filled`, `advance_verbs` json, `payoff` string (closed enum),
`portable` bool, `status` (`open|filled|abandoned|expired`), timestamps.
Config: `config/game.php` → `clocks` block (max segments, offer frequency
cap so the composer doesn't offer one every scene).

### Engine changes

1. `CardComposer`: a clock-offer card family (respecting the ≥2-legal-cards
   rule and the frequency cap); when a clock is open, tag qualifying cards
   with a forecast line sourced from the clock ("advances *Sweep the vault*"),
   same pattern as `Odds` `GRANTS` forecasts.
2. `TurnResolver`: after a successful qualifying beat, tick; on fill, apply
   the payoff and emit a fact for the narrator; handle expiry on scene exit.
3. `SituationBoard`: group `endeavor`, tone `neutral`, "Sweep the vault —
   3 of 5" (plain words, no mechanics jargon beyond the count).
4. UI: a small segment display in the turn view near the meters; the clock
   name is player-facing prose the engine templated, not LLM-generated.
5. Narrator prompt: open clock rides in as a plain-language goal line
   ("they are partway through sweeping the vault"), no counts.

### Tests

- Clock offered only when scene qualifies; commit via card ids only.
- Only qualifying verb + success ticks; notes/failures never tick.
- Fill applies exactly the enum payoff; scene exit expires non-portable
  clocks; one-active-clock enforced.

---

## Sequencing for the implementing session

1. Confirm the uncommitted 2026-07-27 work (SituationBoard/Hands/Odds/growth
   verdicts) has landed; both features integrate with it (board groups, Odds
   conditions). If it hasn't, stop and ask.
2. Grudges: migration → creation hooks → return roll → evolver tending →
   narration/board → tests → `vendor/bin/pint --dirty` → `php artisan test`.
3. Clocks as a separate follow-up commit, same order.
4. Neither feature may add mechanics language to narration prompts, and both
   must keep working when Claude is unavailable (grudge return and clock
   resolution are pure engine; only *tending* and *narration* are LLM).
