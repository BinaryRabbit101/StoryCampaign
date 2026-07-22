# Design Bible — StoryCampaign

> Read-only guardrails for the world-evolution job and the interview limiter.
> The evolution process must honor this document absolutely. Numeric bounds
> are duplicated in `config/game.php` where the engine enforces them as hard
> clamps — this file is where the authors stay in control despite live updates.

## Tone & setting

A lantern-lit harbor city and the wilds pressing in around it. Grounded low
fantasy: strange gifts and stranger creatures, but no world-breaking magic.
Melancholy warmth — danger is real, death is rare, wonder is common. The
world is old and keeps its own counsel; changes overnight should feel like
weather, rumor, and slow geology, not patch notes.

Narration voice: third-person past tense, close to the character. Concrete
and sensory over abstract. Never mention mechanics, dice, cards, meters,
or systems.

## Allowed themes

- Rooftop chases, harbor smuggling, guild rivalries, wild borderlands.
- Creatures: naturalistic or folkloric. No demons-and-hellfire, no sci-fi.
- Violence lands at "adventure novel," not gore. No cruelty to the helpless.
- NPCs have lives and memory; recurring faces are encouraged.

## Hard bounds (mirrored in config/game.php — the engine clamps regardless)

- Capability magnitudes: reach 1–20, lift 10–400 lbs, leap 1–3, carry_extra 0–2.
- Re-coupling: reach ≥ 16 attaches `unwieldy`; lift ≥ 300 attaches `ponderous`.
- Item power 1–5. Evolution may not mint items above power 5, ever.
- Actor tiers from evolution: regular or elite only. Bosses are authored by hand.
- Per-run budget: daily = 3 features / 2 actors / 1 item / 0 zones;
  weekly adds 1 zone and doubles most caps. A quiet night is a legitimate run.
- Difficulty must not ratchet: never respond to player success by inflating
  enemy stats across the board. Add *situations*, not bigger numbers.

## Evolution guidance

- Prefer new **affordance types** over new enemies: water to swim, wind to
  ride, verticality to climb, surfaces that break. The capability grammar is
  fixed; grow its vocabulary of scene features.
- Reward invested capabilities retroactively: if some player learned `glide`,
  a wind current appearing three days later is the world winking at them.
- Chronicle entries are story beats. "The northern mines collapsed overnight,
  and something new stirs below" — never "added 2 enemies to zone 3."
- Do not contradict or duplicate the evolution log. Do not undo another
  run's changes. The world accretes; it does not churn.
