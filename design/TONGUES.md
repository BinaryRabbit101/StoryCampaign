# Implementation brief: Tongues — words carry the same weight as steel

Instructions for a future session. Read `CLAUDE.md` and
`design/DESIGN_BIBLE.md` first; every invariant there still binds. Sibling
briefs shipping alongside this one: `MENDING.md`, `GROWTH_LEDGER.md`. Backlog
context: `PLAYTEST_FINDINGS_2026-07-30.md`.

**One sentence:** talking an enemy out of a fight prices like the gamble it
is and scales with who is standing across from you — a regular can be talked
off the field, an elite can only be made to hold their hand — so the tongues
stop being a strictly better strike.

## The wound this heals

The trained tongues (`persuade`/`deceive`/`calm`) are composed with no
`risk:` argument, so they default to `safe` → DC 10, while the strike is
`risky` → DC 13. And on success the resolver deletes the enemy outright —
any tier — with zero cost on failure:

```php
$actor->update(['tags' => $tags, 'status' => $actor->kind === 'enemy' ? 'fled' : $actor->status]);
```

DC 10 (~55%) to remove an enemy in one beat, against DC 13 for 2 damage into
a 4–9 HP pool. Wherever `talkable` is tagged, combat has a dominant
non-combat answer; a `talkable` elite folds on a 10. `intimidate` is already
tier-scoped to regulars — the tongues have no scope at all.

## Design

1. **Risk.** Against a HOSTILE target, the trained tongues compose
   `risky` — the same gamble the strike takes, priced on the card by the one
   `Odds` ladder as always. Against a non-hostile (plain conversation,
   recruitment, thread work), they stay as they are: talking to someone who
   is not fighting you is not a gamble. The untrained parley is already
   `degraded` and stays untouched — it must remain never-better than the
   bought craft.
2. **Tier scopes the outcome, not the DC.** Keep the ladder blind to tier for
   now (an `Odds::TIERS` part is backlog, and it belongs to the strike
   family). What moves is what a success BUYS:
   - **Regular, success or strong:** fled, exactly as today (`fled_how:
     'talked'`, disposition written, grudge machinery untouched).
   - **Elite (and any tier above, if the vocabulary has one), strong:** fled,
     same as a regular — a perfect word at the perfect moment still ends a
     fight.
   - **Elite, plain success:** they hold their hand — the words land but the
     will doesn't break. Write the disposition tag (`swayed`/`calmed`) and
     clear/suppress their reaction for THIS turn through the existing intent
     and reaction machinery (the same class of effect an interrupt already
     has). They remain `active`. The fact says so plainly ("The words
     reached {name}; the blade did not fall — but they did not leave.").
   - **Partial:** unchanged (no purchase).
3. **Failure has a price a card can quote.** A failed trained tongue against
   a hostile sets that enemy's intent to `press` — you talked, they closed
   the distance. Routes through the EXISTING intent tag machinery (the same
   write the `provoking` bargain already does). The forecast/description
   should say it in the card's own words ("get it wrong and they come at
   you"), because nothing about a card's odds may be a surprise.
   - Only against hostiles, and only the trained tongues. The untrained
     parley stays consequence-free on failure: it is already degraded and
     bonusless, and stacking a price on it makes the floor a trap.
4. **Verify tier vocabulary in code first** (`Actor::$tier` values in
   seeders, `ZoneForge`, `WorldEvolver` — the config names `elite` and
   implies a tier above it may exist). Whatever the set is, the rule is:
   regulars can be talked off the field; anything above a regular needs a
   STRONG to leave, and a plain success buys one turn of held hands.

## What must NOT change

- The recruitment path (`canAsk`, the speak chip), thread advancement, and
  every non-hostile use of the tongues — this brief prices the COMBAT use
  only.
- `intimidate`'s existing tier scope and outcomes.
- Grudges: a flee born from a strong-success tongue still writes `fled_how:
  'talked'` and feeds the grudge/disposition machinery exactly as today.
- The one-ladder invariant: no new DC table, no tier term in `Odds` in this
  brief. Risk is per-card composition, which is already the ladder's input.
- `validateChoice`/stored-card ids: composition changes card contents, not
  the offer/submission contract.

## Tests

- A trained tongue card targeting a hostile carries `risk: 'risky'` and its
  forecast DC matches what the resolver rolls against (one-ladder check).
- The same verb against a non-hostile stays safe.
- Regular + success → fled. Elite + success → still active, disposition
  written, no reaction from them this turn. Elite + strong → fled.
- Failure against a hostile writes `intent: press`; failure against a
  non-hostile writes nothing.
- Untrained parley: still degraded, still no failure price, still never
  offered beside a trained tongue at the same target.
