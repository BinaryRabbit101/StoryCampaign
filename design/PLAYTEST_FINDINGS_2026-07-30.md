# Playtest findings — 2026-07-30 code survey

A three-way code survey (engine mechanics, player-facing surface, long-arc
progression) of the game as it stands at `913095b`. The top three findings
have their own implementation briefs (`MENDING.md`, `TONGUES.md`,
`GROWTH_LEDGER.md`); everything else here is backlog, ordered by how much it
distorts actual play. Line numbers are from the survey date and will drift.

## What is strong (do not break while fixing the rest)

The turn-composition core — verb board → target strip → sentence panel,
itemized dice table after the fact, the situation board — is the best part of
the product. The board genuinely surfaces grudges, standing, threads, finale
arming, the sky, and attempts. The bargain/notes explanations in `HowPanel`
are exemplary. Most weaknesses are *around* this core, not in it.

## Tier 1 — briefed (see sibling files)

1. **Bandage is a guaranteed, stackable heal** → `MENDING.md`
2. **Talking is strictly better than fighting** → `TONGUES.md`
3. **Growth is an unpriced power faucet; the sheet never reaches the
   ladder** → `GROWTH_LEDGER.md`

## Tier 2 — engine backlog

- **Five purchasable capabilities grant nothing.** `quiet_move` (Odds entries
  unreachable — hide composes on `conceal`), `grapple` (half the Grappler
  gift, zero reads), `pull`, `throw` (Hurl spends `lift`), `delay` (metered;
  buying it mints a pool no card spends). All priced in
  `TraitCatalog::capabilityCost`. Also a dead match arm: `Scars.php` matches
  verb `'grapple'`, which does not exist in `Verb`. Either delete from the
  catalog or give each one card (`quiet_move` → a pre-beat granting
  `concealed`; `throw` → own the Hurl card; `delay` → a real tempo verb).
- **Enemy tier never reaches the difficulty ladder.** An elite is the same 13
  to hit as a regular; a boss fight is a longer fight, not a harder one.
  Direction: one itemized `Odds::TIERS` part (+2 elite / +3 boss on
  strike/interrupt/restrain), inside the existing ±4 CONDITIONS spread.
- **No player combat scaling anywhere.** Strike damage fixed 3/2/1, dodge a
  flat 12; nothing on the sheet, no item, no growth moves either. Chapter 20
  rolls the same numbers as chapter 1.
- **Brace grants `braced`, which is not in `Odds::CONDITIONS`/`GRANTS`** — the
  one card whose purpose is a forecastable set-up cannot print what it buys.
  Add it to the table so the forecast can quote it.
- **"Catch your breath" does nothing** but buy a fortune lottery ticket, and
  is offered every turn on all three slots.
- **`Attempts` closes `scout` on one failed roll** at untrained DC 15 (~70%
  failure) for the whole scene — a look that found nothing is not a settled
  question. Exempt awareness verbs or require a second failure.
- **Telegraphs yield a real decision ~1 turn in 6.** Intent draw is d6 with
  50% `press`; only `windup` opens answering cards — `guard` and `circle`
  open nothing.
- **Standing's arithmetic is ±1 in a zone the frontier abandons every 4
  scenes.** Either accept it as pure fiction or let it carry across zones.

## Tier 2 — pacing and long arc

- **The finale arms on a calendar, not on play.** `Finale::ripeness()` counts
  ALL chapters — the evolver's nightly chronicle chapters and the prologue
  included — so the floor of 8 is met by one played turn plus a week of real
  time. And max-heat grudges (weight 2 of threshold 4) are realistically
  reached only by the evolver's +1/night; in play, heat rises only on a
  re-flee. A binge player may never be offered an ending. Fix: count only
  turn-backed chapters; add one in-play heat source (e.g. a grudge surviving
  a forced return).
- **The idle layer no longer exists.** Turns resolve inline, downtime is
  unoffered, and `TurnReadyNotification` only fires when the player is NOT
  watching — which they always are for their own turn. Only the daily
  chronicle push reliably lands. The dead downtime config also silently
  killed the campfire bond mover and `rest_heal_per_hour`, the only non-beat
  healing. Cheapest fix: re-surface the downtime picker post-resolution — the
  server half is fully wired.
- **Statistically invisible subsystems.** Side threads: 0.12 per spawned
  non-hostile actor in scenes that "arrive thin" — roughly a
  one-in-twenty-campaigns feature. Stray companions (0.08/spawn) similar.
  Tempo (+4, the biggest bonus in the game) is priced out at creation
  (`time_slow` costs 5 against 3 points), which also starves the `burning`
  bargain and Fortune's unlucky branch. The memento cap of 12 is unreachable
  (~5–8 realistic), so the finale's memento signal is calibrated against a
  ceiling that does not exist.
- **`returnCharacter` punishes its users**: every scar carried at full price,
  no refund — "bring your hero back" means "bring their injuries back". Let a
  returning hero shed one scar per closed book. Separate bug: the picker
  offers characters from ACTIVE campaigns (`CampaignController::index`
  filters ownership only) — a live character can be forked mid-tale.
- **Items are dead content.** The only `->attach()` in the app is
  `returnCharacter`; nothing in play ever hands one out, yet evolution burns
  budget minting them and equip machinery hangs off an empty relation. Also
  `items` has no `campaign_id` and a globally unique slug — campaign A's
  forged item blocks campaign B's, against the campaign-scoped-worlds
  invariant. Either let the dresser place evolution items as takeable scene
  features, or drop the item pillar and reclaim the budget.
- **Evolution lands where the player no longer is.** The evolver tends every
  walked zone (including abandoned ground) as zone-level templates that only
  surface at the next dressing there; with `frontier_scenes: 4` the change is
  often never seen. Scope it to the active zone + `next_zone_id`.
- **Chronicle chapters pad the book**: a 20-turn tale played over 20 days
  ships a book that is half world-tending digest. Give them their own section
  or exclude them from numbering.

## Tier 3 — player-facing shell

- **Chapters render as an unspaced blob.** `space-y-3` is inert in both
  `Play.vue` and `Book/Reader.vue` (no block children; body is one
  `whitespace-pre-wrap` text node). Split on `\n\n` into real `<p>` elements.
  The reader also needs a chapter index / prev-next; reaching it takes two
  taps through the collapsed character sheet.
- **The shell is stock starter kit.** `Welcome.vue` pitches Laravel and links
  Laracasts; the sidebar's one nav item is "Dashboard" with laravel.com
  footer links. Replace with a real front door and game nav (this tale / the
  book / all tales / settings).
- **Irreversibility is guarded backwards.** Ending a tale and deleting a
  campaign get confirm dialogs; the finale card, venture, and abandon go on
  one tap in a game where turns commit on submit. Add an engine-set
  `irreversible` flag on `ActionCard`, gate submit behind the existing
  confirm pattern.
- **`downed` reaches the page and is never rendered** — at the exact moment
  the player needs to know only a recovery beat (at the price of a permanent
  scar) gets them up.
- **No ledger of the campaign's relationships.** Grudges, standings, threads
  surface only while co-located; nothing lists who holds a score with you or
  which zones remember you — though the finale pins its target off "the
  hottest grudge". A "what this tale holds" panel beside the memento shelf,
  compiled from rows the engine already appends.
- **Smaller frictions:** the dice table is unskippable (add an auto-cast
  preference; always show "Throw them all"), and it blocks the recap a
  returning player needs; push permission fires cold in `onMounted` on the
  first screen (a denial is ~permanent on iOS) — gate behind an in-app
  affordance after the first chapter; the memento shelf renders uncollapsed
  ABOVE the chapter; `AmbientBackdrop` ignores the engine's actual
  ambient/hour (not even in the page payload) — drive hue/motes off them,
  zero mechanics, all payoff; tempo charges, bands, endeavors, and the scar
  rules are never explained anywhere in-app.
- **Dead surfaces sweep:** downtime route (live, validated, unreachable),
  `intent_text` (superseded by notes), `stanceDelta` in `lib/odds.ts` (dead
  code), `card.composed` (declared, never rendered).
