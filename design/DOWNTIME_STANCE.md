# Implementation brief: Downtime Stance — the idle wait becomes a choice

Instructions for a future session. Read `CLAUDE.md` and `design/DESIGN_BIBLE.md`
first; every invariant there still binds. Sibling briefs: `GRUDGES_AND_CLOCKS.md`,
`SCARS.md`, `BARGAIN_CARDS.md`, `WEATHER_AND_HOUR.md`, `MEMENTOS.md`.

**One sentence:** when a turn resolves, the engine offers one small choice of
how the character spends the wait — rest, keep watch, tend gear, walk the
ground — and the choice pays out from the *actual elapsed idle time* when the
player returns.

## Why this shape

- The game is an idle RPG whose idle time currently does exactly one silent
  thing: tempo regen (`Meters::regenerate`, applied at resolve time from
  `TurnResolver.php:38`, anchored on `meters_regenerated_at`). Closing the
  app should be a move, not an absence — that is the core loop trick of the
  genre and the one thing this design lacks.
- Health currently has NO passive recovery ("health only via recovery
  beats" — `Meters` doc block). Rest is the deliberate, chosen exception:
  recovery the player opts into by trading away the other stances.

## Invariants

- The downtime pick is a normal engine-offered choice: a fixed, closed set of
  stances composed engine-side, submitted by id, validated like a card. Never
  free text, never LLM-adjudicated.
- **Optional, defaulting to none.** A player who just submits and closes the
  app gets today's behavior exactly (tempo regen only). Downtime must never
  gate or delay turn resolution — turns stay REALTIME; the stance is chosen
  *after* resolution, on the resolved-turn screen, and stored for the wait.
- Payouts are engine-priced from elapsed minutes with a floor and a cap
  (config): under ~20 idle minutes nothing accrues (no benefit-farming by
  rapid re-submits), and benefits stop growing past ~8 hours (no incentive to
  stay away; an idle game must never punish coming back).
- Each stance is a real tradeoff (no-dead-choices): rest heals but leaves you
  approachable (lurking ambush odds unchanged); keep watch spends the wait on
  vigilance (no heal, but the next scene's `lurking` arrivals enter revealed);
  tend gear grants the `readied` condition from `Odds::CONDITIONS` on return
  (reuse it — do NOT invent a parallel buff); walk the ground pre-reveals one
  `hidden` feature of the next scene if any exists. One benefit each, none
  strictly best.
- Narration colour only: the narrator prompt gets a plain sentence ("the
  night passed in half-sleep by the wall"), never numbers or stance names.
  `ProseStyle::rules()` applies (see memory: plain prose register).

## Data model

- `turns.downtime` json nullable: `{stance, chosen_at, applied: bool,
  payout: {...}}` — stored on the OPEN (next) turn row the resolver already
  generates, so applying it on return is local to that turn.
- `config/game.php` → `downtime` block: `floor_minutes`, `cap_minutes`,
  `rest_heal_per_hour` (suggest 1, so a full night restores most of the
  default 10-health pool but a coffee break restores nothing).

## Engine changes

1. **Offer** — when `TurnResolver` generates the next turn, attach the stance
   list (static, engine-side; filter walk-the-ground out when the next scene
   has no hidden features — an option that can do nothing is a dead choice).
   `PlayController` exposes it with the resolved-turn payload; a small
   endpoint records the pick onto `turns.downtime` (validate: turn belongs to
   player, turn still open, stance in the offered set, only once).
2. **Payout** — at the top of the next resolution (beside the existing
   `Meters::regenerate` call), compute elapsed = `chosen_at` → now, clamp to
   floor/cap, apply the stance effect, mark `applied`, and emit one fact
   string into the resolution so the narrator can colour the wait. Keep
   watch's effect: when the scene-entry logic would spawn `lurking` arrivals,
   spawn them visible instead — touch only the entry path, not standing
   lurkers already in the scene.
3. **UI** — on the resolved-turn view (the dice-table screen the player is
   already watching while narration writes), a quiet one-line picker: "The
   next stretch of road — how do you spend it?" Show the concrete payout
   terms (the `Odds` philosophy: price it before they choose). Widget
   (`scriptable/StoryCampaignWidget.js` + `/api/widget/status`): show the
   chosen stance as flavor text if present.

## Tests

- No pick → behavior identical to today (regen only), and the turn resolves
  normally.
- Floor: 5 idle minutes pays nothing; cap: 24h pays the same as 8h.
- Rest heals through `Meters::heal` and respects max; never revives a
  `downed` character (that is `SCARS.md` territory).
- Keep watch converts entry-time lurkers to visible; does not reveal
  pre-existing ones.
- Tend gear grants exactly the `readied` condition; walk-the-ground reveals
  exactly one hidden feature and is not offered when none exist.
- Stance outside the offered set, double-submit, and picks on a resolved
  turn are all rejected.

## Sequencing

Standalone; no dependency on other briefs. If `WEATHER_AND_HOUR.md` is also
being implemented, land this first — its ambient rolls can later colour the
downtime narration line, not the other way around.
