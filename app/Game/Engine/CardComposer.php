<?php

namespace App\Game\Engine;

use App\Game\Capability;
use App\Game\Hands;
use App\Game\Meters;
use App\Game\TurnSlot;
use App\Game\Verb;
use App\Models\Actor;
use App\Models\Character;
use App\Models\Scene;
use App\Models\SceneFeature;

/**
 * The intersection engine: capabilities × affordances × constraints →
 * legal action cards. Mismatched magnitudes inside tolerance produce a
 * risky/degraded card rather than a hidden one (graceful degradation);
 * multiple capabilities firing together produce composed cards that were
 * never hand-authored.
 */
class CardComposer
{
    private const SIZES = ['small' => 1, 'medium' => 2, 'large' => 3];

    private const GAPS = ['short' => 1, 'medium' => 2, 'far' => 3];

    /** The heaviest thing untrained arms may take up — one-hand territory, far below where the lift gift starts paying. */
    private const UNTRAINED_LIFT = 40;

    /** The highest an untrained scramble reaches — below where the trained climb even starts reading as risky. */
    private const UNTRAINED_CLIMB = 12;

    /** The highest an ordinary standing jump reaches, matching the trained leap's own low-height threshold. */
    private const UNTRAINED_LEAP = 6;

    /**
     * The body-plausible ways: anyone can scramble, jump, or paddle — badly.
     * Everything else in the traversal group (swing, glide, burrow, squeeze
     * grades) stays a bought power; nerve does not grow wings.
     */
    private const UNTRAINED_WAYS = [Capability::Climb, Capability::Descend, Capability::Leap, Capability::Swim];

    /**
     * @param  Dice|null  $dice  The seeded stream the bargain pass rolls on. The
     *                           resolver hands down the turn's own dice so the
     *                           offer moves from turn to turn; callers with no
     *                           stream (the first turn, a test recomposing) get
     *                           one seeded off the scene, which keeps the pass
     *                           deterministic rather than absent.
     * @return array{pre: list<array>, main: list<array>, post: list<array>, companions: list<array{id: int, name: string, cards: list<array>}>}
     */
    public function compose(Character $character, Scene $scene, ?Dice $dice = null): array
    {
        $capabilities = $character->effectiveCapabilities();
        // Only what the player can perceive makes cards: hidden features wait
        // for examine/scout, a lurking ambusher for detect (or its spring).
        // What is already in their hands is not ground any more — it comes
        // back as its own cards below, not as something to climb or hide behind.
        $features = $scene->visibleFeatures()
            ->reject(fn (SceneFeature $f) => Hands::isHolding($character, $f->id));
        $actors = $scene->visibleActors();

        $cards = ['pre' => [], 'main' => [], 'post' => []];

        foreach ($this->carriedCards($character, $actors) as $card) {
            $cards[$card->slot->value][] = $card;
        }

        foreach ($features as $feature) {
            foreach ($this->featureCards($character, $capabilities, $feature) as $card) {
                $cards[$card->slot->value][] = $card;
            }
        }

        foreach ($actors as $actor) {
            foreach ($this->actorCards($character, $capabilities, $actor, $features) as $card) {
                $cards[$card->slot->value][] = $card;
            }
        }

        // A held captive is an affordance too: the grip itself opens options.
        $captives = $scene->actors()->where('status', 'restrained')->get();
        foreach ($captives as $captive) {
            foreach ($this->captiveCards($capabilities, $captive, $actors, $features) as $card) {
                $cards[$card->slot->value][] = $card;
            }
        }

        if ($scene->state['exit_scouted'] ?? false) {
            $cards['main'][] = new ActionCard(
                slot: TurnSlot::Main,
                verb: Verb::Flee->value,
                label: 'Take the scouted way out',
                description: 'Slip out through the route your companion found. It holds until the scene turns.',
                target: ['type' => 'exit', 'id' => null, 'name' => 'the scouted way out'],
                modifiers: [$this->approachModifier(Verb::Flee)],
            );
        }

        // The ground's own ways out, headings and all. Every dressed scene
        // has at least one, no capability gates walking, and each names the
        // ground it leads toward — so leaving is always a real choice between
        // named doors, and the map the player keeps in their head has words
        // to hang on. An exit already walked is a road on the map, not a card.
        foreach ($scene->exits()->whereNull('to_scene_id')->get() as $way) {
            $cards['main'][] = new ActionCard(
                slot: TurnSlot::Main,
                verb: Verb::Cross->value,
                label: 'Head '.$way->direction.' — toward '.$way->label,
                description: "Leave this ground by the {$way->direction} way. It runs toward {$way->label}.",
                target: ['type' => 'exit', 'id' => $way->id, 'name' => 'the way '.$way->direction],
                modifiers: [$this->approachModifier(Verb::Cross)],
            );
        }

        foreach ($this->awarenessCards($capabilities, $scene, $actors) as $card) {
            $cards[$card->slot->value][] = $card;
        }

        foreach ($this->tempoCards($character, $capabilities) as $card) {
            $cards[$card->slot->value][] = $card;
        }

        foreach ($this->genericCards($character, $scene, $actors) as $card) {
            $cards[$card->slot->value][] = $card;
        }

        // The scene is never furniture: everything the player can see is
        // something they can look at or improvise against, whether or not
        // they have a capability that fits it.
        foreach ($this->groundedCards($features, $actors) as $card) {
            $cards[$card->slot->value][] = $card;
        }

        // The ending, offered and never forced. It stands on the table for as
        // long as the tale is ripe and the player has not taken it up; nothing
        // behind it escalates, and declining it is free forever.
        foreach (Finale::cards($scene->campaign) as $card) {
            $cards[$card->slot->value][] = $card;
        }

        // The frontier: once the world has forged the next zone, the way
        // out of this one stands open as a real, named choice.
        //
        // Not while the last stretch is running. A tale walking into its own
        // ending is not also being offered a whole new country to walk into
        // instead — you chose this ground, and the way on is closed until it is
        // finished. Nothing else about the world pauses.
        $frontier = Finale::isUnderway($scene->campaign) ? null : $scene->campaign?->nextZone;
        if ($frontier !== null) {
            $cards['main'][] = new ActionCard(
                slot: TurnSlot::Main,
                verb: Verb::Venture->value,
                label: "Press on toward {$frontier->name}",
                description: "Leave {$scene->zone->name} behind for good. {$frontier->description}",
                target: ['type' => 'zone', 'id' => $frontier->id, 'name' => $frontier->name],
                modifiers: [$this->approachModifier(Verb::Venture)],
            );
        }

        // What is already true as these cards are offered. Every card prices
        // itself against this, so "high ground, +2 to a strike" is on the card
        // before it is chosen rather than a surprise on the dice table.
        // The air is part of that: it was rolled once when this ground was
        // dressed and it stands here, so it prices onto the card the same way
        // high ground does — visible before the commit, never after it.
        // Old wounds are part of that too. A permanent cost the card does not
        // quote is the worst kind of surprise: it is still charging the player
        // ten chapters after they stopped thinking about it.
        $conditions = [
            'elevated' => (bool) ($scene->state['elevated'] ?? false),
            'ambient' => Ambient::of($scene),
            // Whether anything is standing against a step out of here. It
            // decides one thing only: whether walking through an open way is a
            // roll at all. Read from Odds and nowhere else, by the same call
            // the resolver makes, so a card that says "Certain" is met by a
            // resolution that agrees.
            'contested' => Odds::contestedGround($scene),
            // And the light it stands in. The air was rolled once for this
            // ground and holds; the hour keeps turning under the same scene, so
            // it is read fresh off the tale every time cards are composed —
            // and priced onto the card the same way the air is, before the
            // commit rather than after it.
            'hour' => Hours::of($scene->campaign),
            'scars' => Scars::names($character),
            // And what this ground remembers about them. It prices the social
            // cards before the commit exactly as the air prices a climb — a
            // town that spits their name is a fact the player can read off the
            // card, not a number that turns up once the die is already cast.
            'standing' => Standings::of($scene),
            // The endeavor under way, if there is one: its name and the exact
            // verbs that move it, read straight off the clock's own row. Every
            // qualifying card quotes it in its forecast, so "advances the
            // search of the long quay" is a promise the tick will honor.
            'endeavor' => Clocks::forecast($scene),
            // And somebody else's want, once the player has actually discovered
            // it. Null while it is dormant, which is the dormancy rule as the
            // cards see it: an undiscovered want prices nothing and promises
            // nothing. Read off the thread's own row, so "helps Aldan's search"
            // is quoting the very row the tick will move.
            'thread' => Threads::forecast($scene),
        ];

        // Full hands never forbid an action — a held crate that locked the
        // screen would be a punishment, not a choice — but they make the
        // hand-hungry ones harder, and the card says so in both places the
        // player can read: the wording, and the difficulty.
        $cards = array_map(
            fn (array $slotCards) => array_map(
                fn (ActionCard $c) => $this->underLoad($c, $character),
                $slotCards,
            ),
            $cards,
        );

        // The deals, last of all — so a bargain inherits whatever the load
        // already did to its sibling, and so the plain version is always the
        // one standing first in the list.
        $stream = $dice ?? new Dice($scene->id * 2654435761 % PHP_INT_MAX);

        // One list, not three.
        //
        // The turn still resolves as a chain — before, the act, after — because
        // order is what makes a set-up beat mean anything. What is gone is the
        // idea that the POSITION decides what may stand in it: bracing was
        // pre-only and looting post-only, so two of the player's three picks
        // were a short list of leftovers they learned to skip. Now every
        // position offers everything the ground offers, and the player decides
        // what belongs where.
        //
        // Composed once and copied, so a beat can never be priced differently
        // depending on which pick it was reached from. The slot rides in id(),
        // so the copies are three distinct cards the submission points at
        // individually — "never resolve a card the engine didn't offer" holds
        // exactly as it did.
        $beats = $this->unify($cards, $scene);

        $beats = $this->offerBargains($beats, $character, $scene, $stream);

        // The endeavor, after the deals: it is roll-free and never carries a
        // load, so nothing above bears on it — and drawing it last keeps the
        // bargain pass reading exactly the stream it always has.
        foreach (Clocks::cards($scene, $stream) as $card) {
            $beats[] = $card;
        }

        $composed = [];
        foreach (TurnSlot::playerSlots() as $slot) {
            $composed[$slot->value] = array_map(
                fn (ActionCard $c) => $c->withSlot($slot)->toArray($conditions),
                $beats,
            );
        }

        // Companions: coordinated, never controlled. Each companion carries
        // their own request slot — asking never spends the player's beats,
        // and the engine rolls the companion's attempt, not the player's.
        $composed['companions'] = $actors
            ->filter(fn (Actor $a) => $a->kind === 'companion')
            ->map(fn (Actor $companion) => [
                'id' => $companion->id,
                'name' => $companion->name,
                'cards' => array_map(
                    fn (ActionCard $c) => $c->toArray($conditions),
                    $this->companionCards($companion, $actors, $scene),
                ),
            ])
            ->values()->all();

        return $composed;
    }

    /**
     * What a thing in your hands lets you do.
     *
     * Lifting used to end with the object shoved aside and forgotten. Now it
     * ends with the object HELD, which is a position rather than an event —
     * so it has to keep offering choices, and one of them has to be putting
     * it down again. Setting it down is free and always available: a player
     * who picks something up must never be stuck holding it.
     *
     * @return list<ActionCard>
     */
    private function carriedCards(Character $character, $actors): array
    {
        $cards = [];

        foreach (Hands::held($character) as $entry) {
            $target = ['type' => 'carried', 'id' => $entry['feature_id'], 'name' => $entry['name']];

            $cards[] = new ActionCard(
                slot: TurnSlot::Post,
                verb: Verb::Drop->value,
                label: "Set down {$entry['name']}",
                description: 'Put it down and have your hands back.',
                target: $target,
            );

            foreach ($actors as $actor) {
                if ($actor->kind !== 'enemy') {
                    continue;
                }
                $cards[] = new ActionCard(
                    slot: TurnSlot::Main,
                    verb: Verb::Hurl->value,
                    label: "Throw {$entry['name']} at {$actor->name}",
                    description: "Everything you are holding, all at once. {$entry['name']} does not come back to your hands.",
                    target: ['type' => 'actor', 'id' => $actor->id, 'name' => $actor->name],
                    capability: 'lift',
                    risk: 'risky',
                    modifiers: [$this->approachModifier(Verb::Hurl)],
                );
            }
        }

        return $cards;
    }

    /**
     * The three slot buckets, flattened into the one list every pick offers.
     *
     * Order is preserved in the order the slots were always resolved in — the
     * set-up beats first, the acts next, the follow-ups last — so the list a
     * player reads still moves from "steady yourself" to "hit them" to "search
     * the body" rather than shuffling into a heap.
     *
     * Duplicates collapse on the identity id() is built from, so a beat the
     * composer happened to author into two buckets appears once.
     *
     * This is also where a road already walked into a wall stops being offered:
     * a route the scene has already refused is dropped here, once, for all three
     * picks — see Attempts. The generic fallbacks are never in that list, so the
     * ≥2-legal-cards invariant cannot be reached by this.
     *
     * @param  array{pre: list<ActionCard>, main: list<ActionCard>, post: list<ActionCard>}  $cards
     * @return list<ActionCard>
     */
    private function unify(array $cards, Scene $scene): array
    {
        $beats = [];
        $seen = [];

        foreach (TurnSlot::playerSlots() as $slot) {
            foreach ($cards[$slot->value] ?? [] as $card) {
                if (Attempts::isSpent($scene, $card)) {
                    continue;
                }

                // id() reads the slot, so the identity has to be taken from a
                // single reference slot or every copy would look unique.
                $identity = $card->withSlot(TurnSlot::Main)->id();

                if (isset($seen[$identity])) {
                    continue;
                }

                $seen[$identity] = true;
                $beats[] = $card;
            }
        }

        return $beats;
    }

    /**
     * One seeded pass for the deals.
     *
     * A bargain is never the whole offer and never stands alone: it is inserted
     * immediately AFTER the honest version of the same beat, so taking it is
     * always a choice against the plain card rather than instead of it. The
     * ≥2-legal-cards invariant is untouched — this only ever adds.
     *
     * At most one per turn (config), and only sometimes: a deal on the table
     * every single turn stops being a decision and becomes a tax on reading.
     * Eligibility is decided entirely by Bargains, which refuses any offer
     * whose complication could not cost the player anything here.
     *
     * It runs on the unified list, before the copies are made, so the one deal
     * on the table stands beside its plain sibling in every pick — not in
     * whichever of the three the die happened to land on.
     *
     * @param  list<ActionCard>  $beats
     * @return list<ActionCard>
     */
    private function offerBargains(array $beats, Character $character, Scene $scene, Dice $dice): array
    {
        $cap = (int) config('game.bargains.per_turn', 1);
        $chance = (float) config('game.bargains.chance', 0);

        if ($cap < 1 || $chance <= 0) {
            return $beats;
        }

        $state = Bargains::sceneState($character, $scene, $beats);

        $candidates = [];
        foreach ($beats as $index => $card) {
            foreach (Bargains::keysFor($card, $state) as $key) {
                $candidates[] = ['index' => $index, 'key' => $key];
            }
        }

        // Nothing eligible costs no die: a turn with no honest deal in it must
        // not shift the stream for the turns that follow.
        if ($candidates === [] || ! $dice->chance($chance)) {
            return $beats;
        }

        $chosen = [];
        for ($taken = 0; $taken < $cap && $candidates !== []; $taken++) {
            $pick = $candidates[$dice->between(1, count($candidates)) - 1];
            $chosen[$pick['index']] = $pick['key'];

            // Never two deals on one card: the player would be reading three
            // versions of the same beat and pricing none of them.
            $candidates = array_values(array_filter(
                $candidates,
                fn (array $c) => $c['index'] !== $pick['index'],
            ));
        }

        $rebuilt = [];
        foreach ($beats as $index => $card) {
            $rebuilt[] = $card;
            if (isset($chosen[$index])) {
                $rebuilt[] = Bargains::offer($card, $chosen[$index]);
            }
        }

        return $rebuilt;
    }

    /**
     * The price of full hands, written onto the cards that want one.
     *
     * A degraded card is still offered and still winnable — it just costs
     * five points of difficulty, which is the honest weight of trying it
     * one-handed around a crate. The card says which, so the player can put
     * the thing down first if the trade is not worth it.
     */
    private function underLoad(ActionCard $card, Character $character): ActionCard
    {
        if ($card->risk === 'degraded' || ! Hands::encumbers($character, $card->verb)) {
            return $card;
        }

        $held = Hands::summary($character);

        return new ActionCard(
            slot: $card->slot,
            verb: $card->verb,
            label: $card->label,
            description: rtrim($card->description, ' ')." Your hands are full of {$held} — this goes harder one-armed.",
            target: $card->target,
            capability: $card->capability,
            risk: 'degraded',
            cost: $card->cost,
            modifiers: $card->modifiers,
            composed: $card->composed,
            bargain: $card->bargain,
        );
    }

    /** @return list<ActionCard> */
    private function featureCards(Character $character, array $capabilities, SceneFeature $feature): array
    {
        $cards = [];
        $affordances = $feature->affordances;
        $target = ['type' => 'feature', 'id' => $feature->id, 'name' => $feature->name];

        if (($feature->state['destroyed'] ?? false) === true) {
            return [];
        }

        // reachable_via — vertical traversal (pre slot: positioning/setup).
        // The trained ways first; the untrained scramble only when NO bought
        // way up stands, so the floor is never a strictly worse twin offered
        // beside a real capability — one floor per feature is the whole offer.
        $waysUp = $affordances['reachable_via'] ?? [];
        $trainedWayUp = false;
        foreach ($waysUp as $via) {
            $card = $this->traversalCard($capabilities, $via, $feature, $target, 'Reach', $affordances['height'] ?? null);
            if ($card !== null) {
                $trainedWayUp = true;
                $cards[] = $card;
            }
        }
        if (! $trainedWayUp && ($card = $this->scrambleCard($waysUp, $feature, $target, $affordances['height'] ?? null)) !== null) {
            $cards[] = $card;
        }

        // crossable_via — horizontal traversal (main slot: consequential move).
        // Trained ways first; when none stands, the body-plausible ways have
        // an untrained floor — degraded, bonusless, a leap capped at what an
        // ordinary standing jump clears — never better than the bought
        // capability, and never offered beside one.
        $waysAcross = $affordances['crossable_via'] ?? [];
        $trainedWayAcross = false;
        foreach ($waysAcross as $via) {
            $capability = Capability::tryFrom($via);
            if ($capability === null || ! isset($capabilities[$capability->value])) {
                continue;
            }
            $risk = 'safe';
            if ($capability === Capability::Leap) {
                $gap = self::GAPS[$affordances['gap'] ?? 'medium'] ?? 2;
                $mag = $capabilities['leap']->magnitude ?? 1;
                if ($mag < $gap - 1) {
                    continue;
                }
                $risk = $mag >= $gap ? 'safe' : 'degraded';
            }
            $trainedWayAcross = true;
            $cards[] = new ActionCard(
                slot: TurnSlot::Main,
                verb: Verb::Cross->value,
                label: ucfirst($via)." across {$feature->name}",
                description: $risk === 'degraded'
                    ? "The {$feature->name} is at the very edge of what you can clear. Risky."
                    : "Cross the {$feature->name} using {$capability->label()}.",
                target: $target,
                capability: $capability->value,
                risk: $risk,
                modifiers: [$this->approachModifier(Verb::Cross)],
            );
        }
        if (! $trainedWayAcross) {
            foreach ($waysAcross as $via) {
                $capability = Capability::tryFrom($via);
                if ($capability === null || ! in_array($capability, self::UNTRAINED_WAYS, true)) {
                    continue;
                }
                if ($capability === Capability::Leap
                    && (self::GAPS[$affordances['gap'] ?? 'medium'] ?? 2) > 1) {
                    continue;
                }
                $cards[] = new ActionCard(
                    slot: TurnSlot::Main,
                    verb: Verb::Cross->value,
                    label: ucfirst($via)." across {$feature->name}",
                    description: "Cross the {$feature->name} on nothing but an ordinary body and nerve.",
                    target: $target,
                    risk: 'degraded',
                    modifiers: [$this->approachModifier(Verb::Cross)],
                );
                break;
            }
        }

        // flee_destination — escape routes, squeeze-constrained
        if (($affordances['flee_destination'] ?? false) === true) {
            $required = self::SIZES[$affordances['squeeze_required'] ?? 'large'] ?? 3;
            $grade = self::SIZES[$capabilities['squeeze']->grade ?? 'medium'] ?? 2;
            if ($grade <= $required + 1) {
                $tight = $grade > $required;
                $cards[] = new ActionCard(
                    slot: TurnSlot::Main,
                    verb: Verb::Flee->value,
                    label: "Flee into {$feature->name}",
                    description: $tight
                        ? "You can just barely fit through {$feature->name} — a tight squeeze, and it will cost you time."
                        : "Slip away through {$feature->name}.",
                    target: $target,
                    capability: 'squeeze',
                    risk: $tight ? 'degraded' : 'safe',
                    modifiers: [$this->approachModifier(Verb::Flee)],
                );
            }
        }

        // hideable — concealment. Trained cover is the conceal capability's
        // ground, but ducking behind what is actually here is something every
        // body can try: the untrained hide is the same floor improvise gives
        // DO, offered at degraded risk with no capability bonus — never better
        // than the bought gift. The size gate holds either way; no amount of
        // nerve fits a large frame into a small vent.
        if (($affordances['hideable'] ?? false) === true) {
            $blocked = isset($affordances['max_size'])
                && (self::SIZES[$capabilities['squeeze']->grade ?? 'medium'] ?? 2) > (self::SIZES[$affordances['max_size']] ?? 3);
            if (! $blocked) {
                $trained = isset($capabilities['conceal']);
                $cards[] = new ActionCard(
                    slot: TurnSlot::Pre,
                    verb: Verb::Hide->value,
                    label: "Take cover behind {$feature->name}",
                    description: $trained
                        ? "Conceal yourself using {$feature->name} before acting."
                        : "Duck behind {$feature->name} and trust it to hold you out of sight — no craft to it, just cover and stillness.",
                    target: $target,
                    capability: $trained ? 'conceal' : null,
                    risk: $trained ? 'safe' : 'degraded',
                );
            }
        }

        // Something of the character's own, lying where a fumble sent it. No
        // capability gates picking your own gear back up — but it costs a
        // whole main beat, which is the real price of having dropped it.
        if (isset($affordances['dropped_item'])) {
            $item = $affordances['dropped_item'];
            $cards[] = new ActionCard(
                slot: TurnSlot::Main,
                verb: Verb::Recover->value,
                label: "Take up {$item['name']}",
                description: "Go back for {$item['name']} and get it in your hands again. Everything it was giving you comes back with it.",
                target: $target,
                risk: 'safe',
                modifiers: [$this->approachModifier(Verb::Recover)],
            );
        }

        // breakable / liftable — brute manipulation. The trained break rides
        // the bought capability; the untrained one is bare hands and body
        // weight against the same thing, degraded and bonusless — offered
        // because a door that only trained shoulders may test reads as a
        // locked verb, not a hard door.
        if (isset($affordances['breakable'])) {
            $trainedBreak = isset($capabilities['break']);
            $cards[] = new ActionCard(
                slot: TurnSlot::Main,
                verb: Verb::Break->value,
                label: "Break {$feature->name}",
                description: $trainedBreak
                    ? "Force {$feature->name} open or apart."
                    : "Throw your weight at {$feature->name} and hope it gives before you do — no training behind it.",
                target: $target,
                capability: $trainedBreak ? 'break' : null,
                risk: $trainedBreak ? 'risky' : 'degraded',
                modifiers: [$this->approachModifier(Verb::Break)],
            );
        }

        // Lifting ends with the thing IN THEIR HANDS, not merely moved: the
        // card has to promise that, and it has to say what holding it costs,
        // because the hands it fills are the same hands the next card wants.
        if (isset($affordances['lift_weight'])) {
            $weight = (int) $affordances['lift_weight'];
            $mag = isset($capabilities['lift']) ? ($capabilities['lift']->magnitude ?? 0) : 0;
            $hands = Hands::handsFor($weight);
            $grip = $hands >= 2 ? 'It needs both hands.' : 'One hand stays on it.';
            $trainedLift = $mag >= $weight * 0.75;
            // Anything light enough for ordinary arms may be taken up without
            // the bought gift — degraded, bonusless, and capped well below
            // where trained strength starts to matter. A scene of loose small
            // things should never be dark to a character who simply never
            // bought a strong back.
            $bareHands = ! $trainedLift && $weight <= self::UNTRAINED_LIFT;
            if (($trainedLift || $bareHands) && Hands::free($character) >= $hands) {
                $cards[] = new ActionCard(
                    slot: TurnSlot::Main,
                    verb: Verb::Lift->value,
                    label: "Take up {$feature->name}",
                    description: match (true) {
                        $trainedLift && $mag >= $weight => "Heave {$feature->name} up and hold it. {$grip}",
                        $trainedLift => "{$feature->name} is heavier than anything you've lifted — you might get it up, straining. {$grip}",
                        default => "Get your arms around {$feature->name} and take it up — awkward, but within an ordinary body's power. {$grip}",
                    },
                    target: $target,
                    capability: $trainedLift ? 'lift' : null,
                    risk: $trainedLift && $mag >= $weight ? 'safe' : 'degraded',
                );
            }
        }

        // rideable_via — evolution-added affordances like wind currents
        foreach ($affordances['rideable_via'] ?? [] as $via) {
            if (isset($capabilities[$via])) {
                $cards[] = new ActionCard(
                    slot: TurnSlot::Main,
                    verb: Verb::Ride->value,
                    label: 'Ride '.$feature->name,
                    description: "Commit to the {$feature->name} and let it carry you.",
                    target: $target,
                    capability: $via,
                    risk: 'risky',
                );
            }
        }

        return $cards;
    }

    private function traversalCard(array $capabilities, string $via, SceneFeature $feature, array $target, string $intent, ?int $height): ?ActionCard
    {
        $capability = Capability::tryFrom($via);
        if ($capability === null || ! isset($capabilities[$capability->value])) {
            return null;
        }

        $risk = 'safe';
        $description = "Get atop {$feature->name} by ".strtolower($capability->label()).'.';

        // Parameterized check: swinging up needs reach >= height, with a
        // graceful-degradation band of 3 below.
        if ($capability === Capability::Swing && $height !== null) {
            $reach = $capabilities['reach']->magnitude ?? 0;
            if ($reach < $height - 3) {
                return null;
            }
            if ($reach < $height) {
                $risk = 'degraded';
                $description = "{$feature->name} sits just past your reach — the swing is possible, but barely.";
            }
        }

        if ($capability === Capability::Leap && $height !== null && $height > 6) {
            $mag = $capabilities['leap']->magnitude ?? 1;
            if ($mag < 2) {
                return null;
            }
        }

        if ($capability === Capability::Climb) {
            $risk = $height !== null && $height > 15 ? 'risky' : $risk;
        }

        return new ActionCard(
            slot: TurnSlot::Pre,
            verb: Verb::Ascend->value,
            label: ucfirst($capability->label())." up to {$feature->name}",
            description: $description,
            target: $target,
            capability: $capability->value,
            risk: $risk,
        );
    }

    /**
     * The untrained way up: when none of a feature's ways is a bought
     * capability, a body-plausible one may still be scrambled — degraded,
     * bonusless, and only within an ordinary body's reach. Height is the
     * honest gate, not training: what an unpracticed climber cannot have is
     * the tall wall, not the crate. Swing, glide, and burrow never floor;
     * nerve does not grow wings.
     *
     * @param  list<string>  $ways  The feature's reachable_via list.
     */
    private function scrambleCard(array $ways, SceneFeature $feature, array $target, ?int $height): ?ActionCard
    {
        $plausible = collect($ways)
            ->map(fn (string $via) => Capability::tryFrom($via))
            ->first(fn (?Capability $capability) => $capability !== null
                && in_array($capability, self::UNTRAINED_WAYS, true)
                && ($height === null || $height <= match ($capability) {
                    Capability::Leap => self::UNTRAINED_LEAP,
                    default => self::UNTRAINED_CLIMB,
                }));

        if ($plausible === null) {
            return null;
        }

        return new ActionCard(
            slot: TurnSlot::Pre,
            verb: Verb::Ascend->value,
            label: "Scramble up to {$feature->name}",
            description: "Get atop {$feature->name} the hard way — no craft to it, just hands, feet, and stubbornness.",
            target: $target,
            risk: 'degraded',
        );
    }

    /** @return list<ActionCard> */
    private function actorCards(Character $character, array $capabilities, Actor $actor, $features): array
    {
        $cards = [];
        $target = ['type' => 'actor', 'id' => $actor->id, 'name' => $actor->name];
        $tags = $actor->tags ?? [];
        $hostile = $actor->kind === 'enemy';

        // Someone the world sent, waiting on an answer. The pair IS the whole
        // conversation with them this turn: joining has to be consensual on
        // both sides, and burying "yes" among nine other things to do with them
        // would make the answer an accident rather than a decision. Ordinary
        // main-slot cards, validated exactly as every other card is.
        if (! $hostile && $actor->kind !== 'companion' && isset($tags['offering'])) {
            $asked = match ($tags['offering']) {
                Companions::STRAY => "{$actor->name} has kept near you long enough to ask outright.",
                Companions::THREAD => "{$actor->name} got what they came for with your help, and has asked to walk on with you.",
                default => "{$actor->name} owes you their skin, and has asked to walk with you.",
            };

            return [
                new ActionCard(
                    slot: TurnSlot::Main,
                    verb: Verb::CompanionWelcome->value,
                    label: "Welcome {$actor->name}",
                    description: "{$asked} Say yes, and they leave this place at your side.",
                    target: $target,
                ),
                new ActionCard(
                    slot: TurnSlot::Main,
                    verb: Verb::CompanionDismiss->value,
                    label: "Send {$actor->name} on their way",
                    description: "{$asked} Part well instead — nobody walks off from you with nothing.",
                    target: $target,
                ),
            ];
        }

        // A stray keeps to the edge. No recruitment, no craft worked on them —
        // just the plain word anyone in the scene gets, until they decide for
        // themselves that they want to be asked.
        if (! $hostile && ($tags['following'] ?? false)) {
            return [
                new ActionCard(
                    slot: TurnSlot::Main,
                    verb: Verb::Speak->value,
                    label: "Speak with {$actor->name}",
                    description: "{$actor->name} has been keeping near you without being asked. Say something to them.",
                    target: $target,
                ),
            ];
        }

        if ($hostile) {
            // A returned grudge under truce came carrying terms. Hearing them
            // out is a real choice standing beside the strike, not instead of
            // it: take the deal and the score dies, or answer with steel and
            // the truce dies. The deal's content was engine-picked at their
            // return — the card only quotes it.
            if (($tags['truce'] ?? false) && isset($tags['deal'])) {
                $cards[] = new ActionCard(
                    slot: TurnSlot::Main,
                    verb: Verb::Bargain->value,
                    label: "Hear {$actor->name}'s terms",
                    description: "{$actor->name} has come to settle, not to fight. ".Grudges::dealDetail($tags['deal']).' Take the terms, and the old score dies here.',
                    target: $target,
                );
            }

            // The enemy's telegraphed intent colors the strike: a windup is an
            // opening, a guard a warning. The resolver reads the same intent
            // for the actual difficulty — the card only tells the truth.
            $intent = $tags['intent'] ?? null;
            $description = "Attack {$actor->name} directly.".match ($intent) {
                'windup' => ' They are mid-windup — committed, and open.',
                'guard' => ' They have gone guarded — a hard target right now.',
                'circle' => ' They are circling, hunting an angle on you.',
                default => '',
            };

            $cards[] = new ActionCard(
                slot: TurnSlot::Main,
                verb: Verb::Strike->value,
                label: "Strike at {$actor->name}",
                description: $description,
                target: $target,
                risk: 'risky',
                modifiers: [$this->approachModifier(Verb::Strike), $this->methodModifier($character)],
            );

            if ($intent === 'windup') {
                $cards[] = new ActionCard(
                    slot: TurnSlot::Main,
                    verb: Verb::Interrupt->value,
                    label: "Break {$actor->name}'s windup",
                    description: "{$actor->name} is gathering something heavy. Get inside it before it lands.",
                    target: $target,
                    risk: 'risky',
                    modifiers: [$this->approachModifier(Verb::Interrupt)],
                );
                $cards[] = new ActionCard(
                    slot: TurnSlot::Pre,
                    verb: Verb::Brace->value,
                    label: "Brace for {$actor->name}'s blow",
                    description: 'Set your footing against what you can see coming — the blow will find less of you.',
                    target: $target,
                );
            }
        } elseif ($actor->kind !== 'companion') {
            // Anyone in reach can be fought — a beast, a bystander, a rival
            // who has not raised a hand yet. The card tells the truth about
            // what the swing buys: the target answers as an enemy from the
            // first blow on, and a person struck unprovoked is something this
            // place will hold against you. Same strike, same ladder; the only
            // thing the engine adds is the quarrel the player started.
            $cards[] = new ActionCard(
                slot: TurnSlot::Main,
                verb: Verb::Strike->value,
                label: "Turn on {$actor->name}",
                description: $actor->kind === 'npc'
                    ? "Attack {$actor->name}, who has raised no hand against you. They will fight or fall as an enemy — and this place will remember who swung first."
                    : "Attack {$actor->name}. It has offered you no violence yet; it will answer with everything it has.",
                target: $target,
                risk: 'risky',
                modifiers: [$this->approachModifier(Verb::Strike), $this->methodModifier($character)],
            );
        }

        if ($tags['intimidatable'] ?? false) {
            $trainedPresence = isset($capabilities['intimidate']);
            // Scoped presence: intimidate(vs: regular) does not flatten
            // tougher encounters. Untrained nerve carries the same restriction
            // as the narrowest bought gift — the regular tier and no further —
            // so bare bluster is never offered where a scoped gift is not.
            $scope = $trainedPresence
                ? ($capabilities['intimidate']->scope['vs'] ?? null)
                : 'regular';
            if ($scope === null || $actor->tier === $scope) {
                $cards[] = new ActionCard(
                    slot: TurnSlot::Main,
                    verb: Verb::Intimidate->value,
                    label: "Loom over {$actor->name}",
                    description: $trainedPresence
                        ? "Let your presence do the work — drive {$actor->name} back without a blow."
                        : "Square up to {$actor->name} and make yourself the worst thing here — nothing behind it but nerve.",
                    target: $target,
                    capability: $trainedPresence ? 'intimidate' : null,
                    risk: $trainedPresence ? 'safe' : 'degraded',
                    modifiers: [$this->approachModifier(Verb::Intimidate)],
                );
            }
        }

        $spokenTo = false;
        foreach ([Verb::Persuade, Verb::Deceive, Verb::Calm] as $verb) {
            // The three trained tongues share a capability name with their verb,
            // which is why one loop can serve all of them.
            $social = $verb->value;
            if (($tags[$social.'able'] ?? ($tags['talkable'] ?? false)) && isset($capabilities[$social])) {
                $spokenTo = true;
                $cards[] = new ActionCard(
                    slot: TurnSlot::Main,
                    verb: $verb->value,
                    label: ucfirst($social)." {$actor->name}",
                    // Talking somebody out of a fight is the same size of gamble
                    // as swinging at them, and it prices like one: `risky`,
                    // through the one ladder, exactly as the strike does. What
                    // the words BUY is scoped by who is standing there — only a
                    // regular can be talked off the field on a plain success —
                    // and getting it wrong brings them on. All three facts are
                    // the card's to state: nothing about a beat may be a
                    // surprise once the turn commits.
                    description: $hostile
                        ? "Work on {$actor->name} with words while the fight is on — as much of a gamble as a swing. "
                            .($actor->tier === 'regular'
                                ? 'Land it and they break off. '
                                : "Someone like {$actor->name} only leaves on a perfect word; anything less buys a moment with their hand stayed. ")
                            .'Get it wrong and they come at you.'
                        : "Work on {$actor->name} with words.",
                    target: $target,
                    capability: $social,
                    risk: $hostile ? 'risky' : 'safe',
                );
            }
        }

        // Talking a hostile down has an untrained floor too. Plain speech
        // covers everyone the player is NOT fighting, but it is never offered
        // against drawn steel — so a character who never bought a tongue had
        // no words at all once a fight started, and words are not a gift. One
        // parley card rather than three degraded twins: on a hostile the three
        // trained tongues resolve identically (they break and go), and three
        // copies of one outcome is noise, not choice. Degraded, bonusless,
        // never better than the bought craft — and never beside a truce, where
        // the terms on the table ARE the conversation.
        if ($hostile && ! $spokenTo && ! ($tags['truce'] ?? false)) {
            $parley = collect([Verb::Calm, Verb::Persuade, Verb::Deceive])->first(
                fn (Verb $tongue) => ($tags[$tongue->value.'able'] ?? ($tags['talkable'] ?? false))
                    && ! isset($capabilities[$tongue->value]),
            );
            if ($parley !== null) {
                $cards[] = new ActionCard(
                    slot: TurnSlot::Main,
                    verb: $parley->value,
                    label: "Talk {$actor->name} down",
                    description: "Try words against {$actor->name} with no craft behind them — just what is true and how you say it.",
                    target: $target,
                    risk: 'degraded',
                );
            }
        }

        // Recruitment grows out of the social verbs: a willing soul, or one
        // already swayed or calmed, can be asked to come along — and asking is
        // not its own card any more. It is what one conversation is FOR.
        //
        // It used to stand beside "Speak with them" as a second verb, which put
        // two entries under the board's SPEAK word for what the player reads as
        // one act: talking to somebody. So the ask is a chip on the conversation
        // instead, and the drawer under SPEAK closes. It stays an engine-offered,
        // engine-validated option either way — the note box cannot recruit
        // anybody, because a note colors the telling and never reaches the
        // mechanics.
        $canAsk = ! $hostile && $actor->kind !== 'companion'
            && (($tags['companionable'] ?? false) || in_array($tags['disposition'] ?? null, ['swayed', 'calmed'], true));

        // A trained tongue is a capability; plain conversation is not. Anyone
        // the player is not fighting can be spoken to — no gift required, and
        // no better than the trained verbs it stands in for. It is also where
        // the ask lives, so a silver-tongued player still has somewhere to make
        // one.
        if (! $hostile && (! $spokenTo || $canAsk)) {
            $cards[] = new ActionCard(
                slot: TurnSlot::Main,
                verb: Verb::Speak->value,
                label: "Speak with {$actor->name}",
                description: $canAsk
                    ? "Open a plain conversation with {$actor->name} — no craft behind it, just what you have to say. Say what it is for below: talk, or ask them to walk this tale beside you."
                    : "Open a plain conversation with {$actor->name} — no craft behind it, just what you have to say.",
                target: $target,
                modifiers: $canAsk ? [$this->intentModifier($actor->name)] : [],
            );
        }

        if ($tags['restrainable'] ?? $hostile) {
            // The trained hold rides the bought capability; the untrained one
            // is weight and desperation against the same body — degraded,
            // bonusless, and a harder DC than the risky trained grapple.
            $trainedGrip = isset($capabilities['restrain']);
            $cards[] = new ActionCard(
                slot: TurnSlot::Main,
                verb: Verb::Restrain->value,
                label: "Restrain {$actor->name}",
                description: $trainedGrip
                    ? "Bind {$actor->name} rather than harm them."
                    : "Wrap {$actor->name} up and hang on — no holds anyone ever taught you, just weight and grip.",
                target: $target,
                capability: $trainedGrip ? 'restrain' : null,
                risk: $trainedGrip ? 'risky' : 'degraded',
                modifiers: [$this->approachModifier(Verb::Restrain)],
            );

            // Composition, not enumeration: restrain + swing + carry_extra
            // = haul an enemy up with you. Never hand-authored — and always
            // trained: a composed card is spent through its capabilities.
            if ($trainedGrip
                && isset($capabilities['swing'], $capabilities['carry_extra'])
                && ($capabilities['carry_extra']->magnitude ?? 0) >= 1) {
                foreach ($features as $feature) {
                    $vias = $feature->affordances['reachable_via'] ?? [];
                    if (in_array('swing', $vias, true) && ! ($feature->state['destroyed'] ?? false)) {
                        $cards[] = new ActionCard(
                            slot: TurnSlot::Main,
                            verb: Verb::Haul->value,
                            label: "Haul {$actor->name} up to {$feature->name}",
                            description: "Snatch {$actor->name} and swing to {$feature->name}, taking them with you.",
                            target: $target,
                            capability: 'restrain',
                            risk: 'risky',
                            composed: true,
                            modifiers: [$this->approachModifier(Verb::Haul)],
                        );
                        break;
                    }
                }
            }
        }

        return $cards;
    }

    /**
     * Options a restrained captive affords. The grapple is a live state the
     * engine decays (they struggle free over time) — these cards are how the
     * player spends the hold before it slips.
     *
     * @return list<ActionCard>
     */
    private function captiveCards(array $capabilities, Actor $captive, $actors, $features): array
    {
        $cards = [];
        $target = ['type' => 'actor', 'id' => $captive->id, 'name' => $captive->name];

        // Pre: keep the captive between you and the danger.
        $cards[] = new ActionCard(
            slot: TurnSlot::Pre,
            verb: Verb::Shield->value,
            label: "Shield yourself with {$captive->name}",
            description: "Keep {$captive->name} between you and whatever answers — blows meant for you find your captive first.",
            target: $target,
            capability: 'restrain',
            composed: true,
        );

        // Main: spend the captive as a weapon. Either way the grip is over.
        if (isset($capabilities['lift']) || ($capabilities['carry_extra']->magnitude ?? 0) >= 1) {
            $hasOtherEnemy = $actors->contains(fn (Actor $a) => $a->kind === 'enemy' && $a->id !== $captive->id);
            $cards[] = new ActionCard(
                slot: TurnSlot::Main,
                verb: Verb::Hurl->value,
                label: "Hurl {$captive->name}".($hasOtherEnemy ? ' into the fray' : ' aside'),
                description: $hasOtherEnemy
                    ? "Send {$captive->name} crashing into their allies. The hold ends — one way or the other."
                    : "Throw {$captive->name} hard. The hold ends — one way or the other.",
                target: $target,
                capability: isset($capabilities['lift']) ? 'lift' : 'carry_extra',
                risk: 'risky',
                composed: true,
                modifiers: [$this->approachModifier(Verb::Hurl)],
            );
        }

        // Pre, composed: take the captive up with you — grip + carry + a way up.
        if (($capabilities['carry_extra']->magnitude ?? 0) >= 1) {
            foreach ($features as $feature) {
                $vias = $feature->affordances['reachable_via'] ?? [];
                $canGo = collect($vias)->contains(fn ($via) => isset($capabilities[$via]));
                if ($canGo && ! ($feature->state['destroyed'] ?? false)) {
                    $cards[] = new ActionCard(
                        slot: TurnSlot::Pre,
                        verb: Verb::Haul->value,
                        label: "Drag {$captive->name} up to {$feature->name}",
                        description: "Take your captive with you to {$feature->name} — height, leverage, and a hostage in hand.",
                        target: $target,
                        capability: 'carry_extra',
                        risk: 'risky',
                        composed: true,
                        modifiers: [$this->approachModifier(Verb::Haul)],
                    );
                    break;
                }
            }
        }

        return $cards;
    }

    /**
     * The perception-and-leadership verbs: scout finds what the scene is
     * hiding, detect hunts the thing that is hunting you, track turns a
     * fled enemy into a doorway, command sharpens every companion request.
     *
     * @return list<ActionCard>
     */
    private function awarenessCards(array $capabilities, Scene $scene, $actors): array
    {
        $cards = [];

        // Every eye can look and every voice can carry: the awareness verbs
        // have untrained floors on the same principle as hide, break, and the
        // bare-hands lift. The bought gift is safe ground with the capability
        // behind it; the floor is the same beat at degraded risk with no
        // bonus — never better than training, never a locked verb.
        $trainedEyes = isset($capabilities['scout']);
        $hiddenRemains = $scene->allFeatures()->contains(
            fn (SceneFeature $f) => ($f->state['hidden'] ?? false) && ! ($f->state['destroyed'] ?? false),
        );
        if ($hiddenRemains || ! ($scene->state['exit_scouted'] ?? false)) {
            $cards[] = new ActionCard(
                slot: TurnSlot::Pre,
                verb: Verb::Scout->value,
                label: 'Read the ground',
                description: $trainedEyes
                    ? 'Sweep the scene for what others miss — hidden ways, overlooked cover, a route out.'
                    : 'Look the place over, hard — no woodcraft to it, just patience and attention.',
                capability: $trainedEyes ? 'scout' : null,
                risk: $trainedEyes ? 'safe' : 'degraded',
            );
        }

        if ($scene->activeActors()->contains(fn (Actor $a) => $a->tags['lurking'] ?? false)) {
            $trainedSenses = isset($capabilities['detect']);
            $cards[] = new ActionCard(
                slot: TurnSlot::Pre,
                verb: Verb::Detect->value,
                label: 'Something is wrong — find it',
                description: $trainedSenses
                    ? 'The scene is off in a way you can almost name. Hunt the source before it moves first.'
                    : 'The scene is off in a way you cannot name. Stop, and stare, and hope your plain senses are enough.',
                capability: $trainedSenses ? 'detect' : null,
                risk: $trainedSenses ? 'safe' : 'degraded',
            );
        }

        $trainedTracker = isset($capabilities['track']);
        foreach ($scene->actors()->where('status', 'fled')->get() as $quarry) {
            $cards[] = new ActionCard(
                slot: TurnSlot::Main,
                verb: Verb::Track->value,
                label: "Follow {$quarry->name}'s trail",
                description: $trainedTracker
                    ? "{$quarry->name} ran. Their trail is still warm — follow it out of this place."
                    : "{$quarry->name} ran. You are no tracker, but the signs are fresh enough that stubbornness might serve.",
                target: ['type' => 'actor', 'id' => $quarry->id, 'name' => $quarry->name],
                capability: $trainedTracker ? 'track' : null,
                risk: $trainedTracker ? 'safe' : 'degraded',
                modifiers: [$this->approachModifier(Verb::Track)],
            );
        }

        if ($actors->contains(fn (Actor $a) => $a->kind === 'companion')) {
            $trainedVoice = isset($capabilities['command']);
            $cards[] = new ActionCard(
                slot: TurnSlot::Pre,
                verb: Verb::Command->value,
                label: 'Call the play',
                description: $trainedVoice
                    ? "Direct your companions with a commander's precision — every request lands sharper this turn."
                    : 'Shout the plan as you see it — no habit of command behind it, but a plan said out loud is still a plan.',
                capability: $trainedVoice ? 'command' : null,
                risk: $trainedVoice ? 'safe' : 'degraded',
            );
        }

        return $cards;
    }

    /**
     * Requests a companion can be asked to attempt, all in the companion's
     * own slot. The engine resolves the companion's try with its own roll —
     * sometimes they fail, sometimes the cost lands on them. That risk is
     * what keeps them people.
     *
     * @return list<ActionCard>
     */
    private function companionCards(Actor $companion, $actors, Scene $scene): array
    {
        $cards = [];
        $target = ['type' => 'actor', 'id' => $companion->id, 'name' => $companion->name];
        $enemies = $actors->filter(fn (Actor $a) => $a->kind === 'enemy');

        if ($enemies->isNotEmpty()) {
            $threat = $enemies->first();
            $cards[] = new ActionCard(
                slot: TurnSlot::Companion,
                verb: Verb::CompanionBlock->value,
                label: "Block {$threat->name}",
                description: "{$companion->name} plants themselves in {$threat->name}'s path — held off from you, if the line holds.",
                target: $target,
                composed: true,
            );
            $cards[] = new ActionCard(
                slot: TurnSlot::Companion,
                verb: Verb::CompanionFlank->value,
                label: 'Flank the threat',
                description: "{$companion->name} circles wide so the threat must look two ways — your strike lands harder.",
                target: $target,
                composed: true,
            );
            $cards[] = new ActionCard(
                slot: TurnSlot::Companion,
                verb: Verb::CompanionStrike->value,
                label: "Strike at {$threat->name}",
                description: "{$companion->name} takes the fight to {$threat->name} themselves — and answers for how it goes.",
                target: $target,
                risk: 'risky',
                composed: true,
            );
        }

        if (! ($scene->state['exit_scouted'] ?? false)) {
            $cards[] = new ActionCard(
                slot: TurnSlot::Companion,
                verb: Verb::CompanionScout->value,
                label: 'Find a way out',
                description: "{$companion->name} slips off to search for an exit while you hold the scene.",
                target: $target,
                composed: true,
            );
        }

        // What this one, and only this one, does. Earned at fellow, engine-
        // picked once, and still a REQUEST in their own slot — a deeper bond
        // widens what you may ask for, never what you may command.
        $signature = $this->signatureCard($companion, $enemies, $scene, $target);
        if ($signature !== null) {
            $cards[] = $signature;
        }

        return $cards;
    }

    /**
     * The fellow's signature request.
     *
     * Null while the bond is still shallow, and null when the thing it does
     * could not happen here — a card that can accomplish nothing is a dead
     * choice, and the one on a companion's short list would be conspicuous.
     */
    private function signatureCard(Actor $companion, $enemies, Scene $scene, array $target): ?ActionCard
    {
        $signature = Companions::signature($companion);
        $threat = $enemies->first();

        return match (true) {
            $signature === Companions::HARRY && $threat !== null => new ActionCard(
                slot: TurnSlot::Companion,
                verb: Verb::CompanionHarry->value,
                label: "Harry {$threat->name}",
                description: "{$companion->name} worries at {$threat->name} from the wrong side until whatever angle they were working comes off you. It puts them in reach of it.",
                target: $target,
                risk: 'risky',
                composed: true,
            ),

            $signature === Companions::DISTRACT && $threat !== null => new ActionCard(
                slot: TurnSlot::Companion,
                verb: Verb::CompanionDistract->value,
                label: "Pull {$threat->name}'s attention",
                description: "{$companion->name} gives {$threat->name} something they have to look at — whatever they were gathering for comes apart, and they go back to circling.",
                target: $target,
                composed: true,
            ),

            $signature === Companions::FORAGE && $this->sceneHides($scene) => new ActionCard(
                slot: TurnSlot::Companion,
                verb: Verb::CompanionForage->value,
                label: 'Send them over the ground',
                description: "{$companion->name} walks the place on their own legs and comes back with whatever it was keeping to itself.",
                target: $target,
                composed: true,
            ),

            default => null,
        };
    }

    private function sceneHides(Scene $scene): bool
    {
        return $scene->allFeatures()->contains(
            fn (SceneFeature $f) => ($f->state['hidden'] ?? false) && ! ($f->state['destroyed'] ?? false),
        );
    }

    /** @return list<ActionCard> */
    private function tempoCards(Character $character, array $capabilities): array
    {
        $cards = [];

        foreach ([Verb::TimeSlow, Verb::Haste] as $verb) {
            // The two tempo verbs are spent through the capability of the same
            // name, out of the meter of the same name — one word, three roles.
            $tempo = $verb->value;
            if (isset($capabilities[$tempo]) && Meters::charges($character, $tempo) >= 1) {
                $capability = Capability::from($tempo);
                $cards[] = new ActionCard(
                    slot: TurnSlot::Pre,
                    verb: $verb->value,
                    label: $capability->label(),
                    description: $verb === Verb::TimeSlow
                        ? 'The world thickens and slows; your next act lands with uncanny precision.'
                        : 'Quicken your blood; move before anyone can answer.',
                    capability: $tempo,
                    cost: [['meter' => $tempo, 'amount' => 1]],
                );
            }
        }

        if (isset($capabilities['ready'])) {
            $cards[] = new ActionCard(
                slot: TurnSlot::Pre,
                verb: Verb::Ready->value,
                label: 'Ready yourself',
                description: 'Set a stance and wait for the right instant.',
                capability: 'ready',
            );
        }

        return $cards;
    }

    /**
     * Generic fallbacks (always present) and post-slot recovery verbs.
     * Improvisation itself lives in groundedCards, where it can name what
     * it is aimed at — but it resolves the same way either place: against
     * base stats with no special bonus, never better than a real
     * enumerated option.
     *
     * @return list<ActionCard>
     */
    private function genericCards(Character $character, Scene $scene, $actors): array
    {
        $cards = [
            new ActionCard(
                slot: TurnSlot::Main,
                verb: Verb::Examine->value,
                label: 'Examine the scene',
                description: 'Take stock: study what is around you before committing to anything.',
            ),
            new ActionCard(
                slot: TurnSlot::Main,
                verb: Verb::Wait->value,
                label: 'Wait',
                // The card has always promised that the scene moves first. It
                // is only true once something is actually coming, so the card
                // says WHEN — a world beat the player could not see coming is
                // an ambush, not a consequence of their own choice, and this
                // turn commits the moment they submit it.
                description: Pressure::waitWouldBreak($scene)
                    ? 'Hold still and let the scene move first. The stillness here has stretched as far as it goes: wait again and this place moves without you.'
                    : 'Hold still and let the scene move first.',
            ),
        ];

        $health = $character->meters['health'];
        if ($health['current'] < $health['max']) {
            $cards[] = new ActionCard(
                slot: TurnSlot::Post,
                verb: Verb::Bandage->value,
                label: 'Bind your wounds',
                description: 'Once the moment settles, tend to your injuries.',
            );
        }

        $cards[] = new ActionCard(
            slot: TurnSlot::Post,
            verb: Verb::CatchBreath->value,
            label: 'Catch your breath',
            description: 'Recover your composure after the main effort.',
        );

        if ($actors->contains(fn (Actor $a) => $a->status === 'active' && $a->kind === 'enemy')) {
            // looting only fires if the fight actually ends this turn — the
            // resolver treats post as contingent.
            $cards[] = new ActionCard(
                slot: TurnSlot::Post,
                verb: Verb::Loot->value,
                label: 'Search the fallen',
                description: 'If the fight ends in your favor, go through what they carried.',
            );
        }

        $cards[] = new ActionCard(
            slot: TurnSlot::Post,
            verb: Verb::Reposition->value,
            label: 'Reposition',
            description: 'Move to safer footing once the dust settles.',
        );

        return $cards;
    }

    /**
     * Everything the player can see is something they can act on, capability
     * or not — otherwise a scene whose affordances miss the character's gifts
     * offers nothing but the same three fallbacks every turn.
     *
     * Two capability-free families, both aimed at named things:
     *  - `inspect` — read one specific thing properly (a quiet pre beat), so
     *    the scene stops being a list of names the player cannot interrogate.
     *  - `improvise` — the riskiest card in the game finally says what it is
     *    about. It still rolls against base stats with no bonus, so a
     *    grounded improvisation is never better than the enumerated option
     *    standing beside it.
     *
     * Shared verb + no capability means the picker collapses each family into
     * a single row of target chips: the form grows a choice, not a wall.
     *
     * @return list<ActionCard>
     */
    private function groundedCards($features, $actors): array
    {
        $cards = [];

        $standing = $features->reject(fn (SceneFeature $f) => $f->state['destroyed'] ?? false)->take(6);

        foreach ($standing as $feature) {
            $target = ['type' => 'feature', 'id' => $feature->id, 'name' => $feature->name];

            $cards[] = new ActionCard(
                slot: TurnSlot::Pre,
                verb: Verb::Inspect->value,
                label: "Look closer at {$feature->name}",
                description: "Read {$feature->name} properly before you commit — what it is, what it would take, and what it might give you.",
                target: $target,
            );

            $cards[] = new ActionCard(
                slot: TurnSlot::Main,
                verb: Verb::Improvise->value,
                label: "Improvise with {$feature->name}",
                description: "Turn {$feature->name} to your advantage in some way none of the offered options cover. No training behind it — say what you mean to try in your own words, and trust your nerve.",
                target: $target,
                risk: 'risky',
                modifiers: [$this->approachModifier()],
            );
        }

        foreach ($actors->reject(fn (Actor $a) => $a->kind === 'companion')->take(4) as $actor) {
            $cards[] = new ActionCard(
                slot: TurnSlot::Main,
                verb: Verb::Improvise->value,
                label: "Improvise on {$actor->name}",
                description: "Try something on {$actor->name} that none of the offered options cover. No training behind it — say what you mean to try in your own words, and trust your nerve.",
                target: ['type' => 'actor', 'id' => $actor->id, 'name' => $actor->name],
                risk: 'risky',
                modifiers: [$this->approachModifier()],
            );
        }

        $cards[] = new ActionCard(
            slot: TurnSlot::Main,
            verb: Verb::Improvise->value,
            label: 'Do something else entirely',
            description: 'Something off this list and aimed at nothing here in particular. Write what you attempt in your own words below — the outcome rides on plain luck and grit.',
            risk: 'risky',
            modifiers: [$this->approachModifier()],
        );

        return $cards;
    }

    /**
     * The stance row every consequential card carries. Mechanically it is a
     * fixed triad the resolver alone interprets — cautious buys a surer roll
     * at the price of the die's wild faces and any result past a plain
     * success; bold pays a harder roll for a wilder die and a heavier blow —
     * and that economy never varies by verb, land, or genre (story flexible,
     * rules fixed). What varies is the WORDING: each verb family speaks its
     * own stances, so fleeing offers a creep or a dash where a strike offers
     * a guard or an all-in, and each option's `fact` is the telling the
     * resolver hands the narrator when that stance was chosen. Values stay
     * `balanced|cautious|bold` so validation and the resolver never care
     * which family dressed the row.
     *
     * Each option also carries its TERMS, because the difficulty delta alone
     * reads as nonsense on half the verbs in the game: "creep, −2 DC" beside
     * "dash, +2 DC" looks like the engine claiming that running from a fight is
     * harder than tiptoeing away from one, and no amount of flavour text fixes a
     * number the player cannot account for. It is not a movement rule — it is
     * the same trade on every card in the game. Caution buys a surer roll and
     * spends the top of the result: it can never do better than plainly work,
     * and the die's wild faces go quiet. Boldness pays for the reverse. The
     * terms say so, in the same words on every card, beside the number they
     * explain.
     */
    private function approachModifier(Verb $verb = Verb::Improvise): array
    {
        [$balanced, $cautious, $bold, $cautiousFact, $boldFact] = match ($verb) {
            Verb::Flee, Verb::Venture, Verb::Cross, Verb::Track, Verb::Recover => [
                'Steady on',
                'Creep — slow, certain, nothing given away',
                'Dash — flat out, and damn the noise',
                'They crept, testing every step and giving nothing away.',
                'They went flat out, trading care for speed and letting the noise fall where it would.',
            ],
            Verb::Strike, Verb::Interrupt, Verb::Hurl => [
                'Measured',
                'Guarded — risk nothing, win nothing grand',
                'All-in — everything behind it, nothing held back',
                'They fought guarded, offering no opening and reaching for no glory.',
                'They threw everything behind it, all commitment and no guard.',
            ],
            Verb::Restrain, Verb::Haul => [
                'Firm',
                'Patient — let the grip come to you',
                'Overwhelming — take them before they can think',
                'They worked patiently, waiting for the hold to offer itself.',
                'They rushed the hold, all weight and violence at once.',
            ],
            Verb::Break => [
                'Firm',
                'Pry — work it loose a piece at a time',
                'Smash — through in one blow or not at all',
                'They pried at it patiently, working it loose piece by piece.',
                'They put everything into one breaking blow.',
            ],
            Verb::Intimidate => [
                'Level',
                'Cold — a quiet menace, slow and certain',
                'Eruptive — fill the room all at once',
                'They let the menace come on slow and cold.',
                'They erupted, filling the space between one breath and the next.',
            ],
            default => [
                'Balanced',
                'Careful — feel your way, take the sure thing',
                'Reckless — trust the leap, live with the landing',
                'They felt their way through it, taking only what was sure.',
                'They trusted the leap entirely, nerve over sense.',
            ],
        };

        return [
            'key' => 'approach',
            'label' => 'Approach',
            'options' => [
                [
                    'value' => 'balanced',
                    'label' => $balanced,
                    'terms' => 'The plain reading: every result on the table, nothing traded either way.',
                ],
                [
                    'value' => 'cautious',
                    'label' => $cautious,
                    'terms' => 'Easier to pull off, and it can never do better than simply work — no lucky break, no disaster either.',
                    'fact' => $cautiousFact,
                ],
                [
                    'value' => 'bold',
                    'label' => $bold,
                    'terms' => 'Harder to pull off, and it reaches further when it lands — the best and worst faces of the die both come into play.',
                    'fact' => $boldFact,
                ],
            ],
        ];
    }

    /**
     * What a conversation is for.
     *
     * The one place a modifier decides which of two things a beat resolves as,
     * rather than how hard it is. It exists because the player reads talking to
     * somebody as one act — and only ever appears on a card where BOTH readings
     * are legal, so neither option is ever the dead one.
     *
     * Both readings cost the same, which is the honest part: `speak` and
     * `recruit` sit on the same rungs of every table in Odds (the same standing
     * point, the same ruined-voice scar), so the DC printed on this card is the
     * DC either answer is measured against. A modifier that quietly moved the
     * number would be a card promising odds the dice do not honor.
     */
    private function intentModifier(string $name): array
    {
        return [
            'key' => 'intent',
            'label' => 'What for',
            'options' => [
                ['value' => 'talk', 'label' => 'Just talk', 'terms' => 'Words, and see where they land.'],
                [
                    'value' => 'recruit',
                    'label' => 'Ask them along',
                    'terms' => "{$name} decides for themselves — a yes and they walk this tale with you.",
                ],
            ],
        ];
    }

    /**
     * How the blow takes shape — bite, claw-rake, tail-whip — is narration
     * color only: the resolver reads just 'approach' for difficulty and
     * damage, and the chosen form travels to the narrator as a resolved
     * fact. Styles come from the interview, fitted to the character's body;
     * the fallback list is deliberately body-neutral.
     */
    private function methodModifier(Character $character): array
    {
        $styles = $character->attack_styles
            ?: ['a driving blow', 'a lunge', 'a grapple', 'a feint, then the real strike'];

        $options = [['value' => 'unspecified', 'label' => 'However the moment allows']];
        foreach ($styles as $style) {
            $options[] = ['value' => $style, 'label' => ucfirst($style)];
        }

        return [
            'key' => 'method',
            'label' => 'The shape of the attack',
            'options' => $options,
        ];
    }
}
