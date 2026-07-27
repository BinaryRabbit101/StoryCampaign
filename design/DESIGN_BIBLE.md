# Design Bible — StoryCampaign

> Read-only guardrails for the world-evolution job and the interview limiter.
> The evolution process must honor this document absolutely. Numeric bounds
> are duplicated in `config/game.php` where the engine enforces them as hard
> clamps — this file is where the authors stay in control despite live updates.

## Tone — NOT setting, NOT genre

This document fixes VOICE and BOUNDS. It does not fix a place or a genre.
Both belong to the campaign and arrive in the prompt:

- The **land** is rolled by the engine at creation from `App\Game\WorldFlavor`
  — ash steppe, canopy town, derelict station, neon sprawl, boomtown, walled
  town, and two dozen more.
- The **genre**, what **drives** the tale, and the **magic and machinery** in
  it come from `App\Game\StoryAspects`, either picked by the player or typed
  in their own words.

Whatever a campaign was handed outranks every example in this file. Never
carry a setting or genre across from an example: if it is not named in the
prompt, it is not in that world. A tale may be a western, a horror, a
starfaring one, or a modern day with nothing uncanny in it at all — this
document does not get a vote on which.

Melancholy warmth is the house voice: danger is real, death is rare, wonder is
common. The world is old and keeps its own counsel; changes overnight should
feel like weather, rumor, and slow geology, not patch notes.

Narration voice: third-person past tense, close to the character. Concrete
and sensory over abstract. Plain and literal: say what happened in direct
words. At most one image per chapter, and only one that earns its place by
revealing a concrete fact; decorative comparisons ("the way a man watches
weather") are banned — write what he actually did. People talk: when
characters face each other, they get direct speech in quotes, not a
described conversation. A chapter is made of three
materials — what people say, what they do, and what is concretely there.
Action-forward: what people do carries every chapter, and description rides
inside the motion — never standalone scene-painting. Never mention
mechanics, dice, cards, meters, or systems.

## Guardrails (these hold in every genre)

- Violence lands at "adventure novel," not gore. No cruelty to the helpless.
  A horror campaign gets its dread from what is withheld, not from what is
  described happening to someone.
- Creatures, technology, and powers must fit the campaign's own genre and
  stated magic level — and must never grant the character anything. Powers
  come from the sheet and from items, both engine-priced.
- NPCs have lives and memory; recurring faces are encouraged.
- Themes travel; their dressing does not. Pursuit across difficult ground,
  contraband, rival factions, frontier country, abandoned places — each in
  the campaign's own land and genre. The chase is the theme; the rooftops
  are not.
- The story is flexible; the rules are not. Nothing in the fiction may bend
  what the engine resolved, and no genre earns different numbers.

## Hard bounds (mirrored in config/game.php — the engine clamps regardless)

- Capability magnitudes: reach 1–20, lift 10–400 lbs, leap 1–3, carry_extra 0–2.
- Re-coupling: reach ≥ 16 attaches `unwieldy`; lift ≥ 300 attaches `ponderous`.
- Item power 1–5. Evolution may not mint items above power 5, ever.
- Actor tiers from evolution: regular or elite only. Bosses are authored by hand.
- Per-run budget: daily = 3 features / 2 actors / 1 item / 0 zones;
  weekly adds 1 zone and doubles most caps. A quiet night is a legitimate run.
- Stage budget (campaign openings): at most 4 scene features and 3 actors,
  all scene-scoped to that campaign's opening scene. The stage never creates
  zones, zone-level templates, or items — those belong to evolution.
- Difficulty must not ratchet: never respond to player success by inflating
  enemy stats across the board. Add *situations*, not bigger numbers.

## Evolution guidance

- Prefer new **affordance types** over new enemies: something to swim, wind
  to ride, verticality to climb, surfaces that break. The capability grammar
  is fixed; grow its vocabulary of scene features, in the land's own terms.
- Reward invested capabilities retroactively: if some player learned `glide`,
  a wind current appearing three days later is the world winking at them.
- Chronicle entries are story beats. "The northern mines collapsed overnight,
  and something new stirs below" — never "added 2 enemies to zone 3."
- Do not contradict or duplicate the evolution log. Do not undo another
  run's changes. The world accretes; it does not churn.
