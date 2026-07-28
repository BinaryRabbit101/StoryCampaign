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

        // The frontier: once the world has forged the next zone, the way
        // out of this one stands open as a real, named choice.
        $frontier = $scene->campaign?->nextZone;
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

        $cards = $this->offerBargains($cards, $character, $scene, $stream);

        // The endeavor, after the deals: it is roll-free and never carries a
        // load, so nothing above bears on it — and drawing it last keeps the
        // bargain pass reading exactly the stream it always has.
        foreach (Clocks::cards($scene, $stream) as $card) {
            $cards[$card->slot->value][] = $card;
        }

        $composed = array_map(
            fn (array $slotCards) => array_map(fn (ActionCard $c) => $c->toArray($conditions), $slotCards),
            $cards,
        );

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
     * @param  array{pre: list<ActionCard>, main: list<ActionCard>, post: list<ActionCard>}  $cards
     * @return array{pre: list<ActionCard>, main: list<ActionCard>, post: list<ActionCard>}
     */
    private function offerBargains(array $cards, Character $character, Scene $scene, Dice $dice): array
    {
        $cap = (int) config('game.bargains.per_turn', 1);
        $chance = (float) config('game.bargains.chance', 0);

        if ($cap < 1 || $chance <= 0) {
            return $cards;
        }

        $state = Bargains::sceneState($character, $scene, $cards['pre']);

        $candidates = [];
        foreach (['pre', 'main', 'post'] as $slot) {
            foreach ($cards[$slot] as $index => $card) {
                foreach (Bargains::keysFor($card, $state) as $key) {
                    $candidates[] = ['slot' => $slot, 'index' => $index, 'key' => $key];
                }
            }
        }

        // Nothing eligible costs no die: a turn with no honest deal in it must
        // not shift the stream for the turns that follow.
        if ($candidates === [] || ! $dice->chance($chance)) {
            return $cards;
        }

        $chosen = [];
        for ($taken = 0; $taken < $cap && $candidates !== []; $taken++) {
            $pick = $candidates[$dice->between(1, count($candidates)) - 1];
            $chosen[$pick['slot']][$pick['index']] = $pick['key'];

            // Never two deals on one card: the player would be reading three
            // versions of the same beat and pricing none of them.
            $candidates = array_values(array_filter(
                $candidates,
                fn (array $c) => $c['slot'] !== $pick['slot'] || $c['index'] !== $pick['index'],
            ));
        }

        foreach ($chosen as $slot => $picks) {
            $rebuilt = [];
            foreach ($cards[$slot] as $index => $card) {
                $rebuilt[] = $card;
                if (isset($picks[$index])) {
                    $rebuilt[] = Bargains::offer($card, $picks[$index]);
                }
            }
            $cards[$slot] = $rebuilt;
        }

        return $cards;
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

        // reachable_via — vertical traversal (pre slot: positioning/setup)
        foreach ($affordances['reachable_via'] ?? [] as $via) {
            $card = $this->traversalCard($capabilities, $via, $feature, $target, 'Reach', $affordances['height'] ?? null);
            if ($card !== null) {
                $cards[] = $card;
            }
        }

        // crossable_via — horizontal traversal (main slot: consequential move)
        foreach ($affordances['crossable_via'] ?? [] as $via) {
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

        // hideable — concealment
        if (($affordances['hideable'] ?? false) === true && isset($capabilities['conceal'])) {
            $blocked = isset($affordances['max_size'])
                && (self::SIZES[$capabilities['squeeze']->grade ?? 'medium'] ?? 2) > (self::SIZES[$affordances['max_size']] ?? 3);
            if (! $blocked) {
                $cards[] = new ActionCard(
                    slot: TurnSlot::Pre,
                    verb: Verb::Hide->value,
                    label: "Take cover behind {$feature->name}",
                    description: "Conceal yourself using {$feature->name} before acting.",
                    target: $target,
                    capability: 'conceal',
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

        // breakable / liftable — brute manipulation
        if (isset($affordances['breakable']) && isset($capabilities['break'])) {
            $cards[] = new ActionCard(
                slot: TurnSlot::Main,
                verb: Verb::Break->value,
                label: "Break {$feature->name}",
                description: "Force {$feature->name} open or apart.",
                target: $target,
                capability: 'break',
                risk: 'risky',
                modifiers: [$this->approachModifier(Verb::Break)],
            );
        }

        // Lifting ends with the thing IN THEIR HANDS, not merely moved: the
        // card has to promise that, and it has to say what holding it costs,
        // because the hands it fills are the same hands the next card wants.
        if (isset($affordances['lift_weight']) && isset($capabilities['lift'])) {
            $weight = (int) $affordances['lift_weight'];
            $mag = $capabilities['lift']->magnitude ?? 0;
            $hands = Hands::handsFor($weight);
            $grip = $hands >= 2 ? 'It needs both hands.' : 'One hand stays on it.';
            if ($mag >= $weight * 0.75 && Hands::free($character) >= $hands) {
                $cards[] = new ActionCard(
                    slot: TurnSlot::Main,
                    verb: Verb::Lift->value,
                    label: "Take up {$feature->name}",
                    description: $mag >= $weight
                        ? "Heave {$feature->name} up and hold it. {$grip}"
                        : "{$feature->name} is heavier than anything you've lifted — you might get it up, straining. {$grip}",
                    target: $target,
                    capability: 'lift',
                    risk: $mag >= $weight ? 'safe' : 'degraded',
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
            $asked = $tags['offering'] === Companions::STRAY
                ? "{$actor->name} has kept near you long enough to ask outright."
                : "{$actor->name} owes you their skin, and has asked to walk with you.";

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
        }

        if (($tags['intimidatable'] ?? false) && isset($capabilities['intimidate'])) {
            $scope = $capabilities['intimidate']->scope['vs'] ?? null;
            // Scoped presence: intimidate(vs: regular) does not flatten
            // tougher encounters.
            if ($scope === null || $actor->tier === $scope) {
                $cards[] = new ActionCard(
                    slot: TurnSlot::Main,
                    verb: Verb::Intimidate->value,
                    label: "Loom over {$actor->name}",
                    description: "Let your presence do the work — drive {$actor->name} back without a blow.",
                    target: $target,
                    capability: 'intimidate',
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
                    description: "Work on {$actor->name} with words.",
                    target: $target,
                    capability: $social,
                );
            }
        }

        // A trained tongue is a capability; plain conversation is not. Anyone
        // the player is not fighting can be spoken to — no gift required, and
        // no better than the trained verbs it stands in for.
        if (! $hostile && ! $spokenTo) {
            $cards[] = new ActionCard(
                slot: TurnSlot::Main,
                verb: Verb::Speak->value,
                label: "Speak with {$actor->name}",
                description: "Open a plain conversation with {$actor->name} — no craft behind it, just what you have to say.",
                target: $target,
            );
        }

        // Recruitment grows out of the social verbs: a willing soul, or one
        // already swayed or calmed, can be asked to come along.
        if (! $hostile && $actor->kind !== 'companion'
            && (($tags['companionable'] ?? false) || in_array($tags['disposition'] ?? null, ['swayed', 'calmed'], true))) {
            $cards[] = new ActionCard(
                slot: TurnSlot::Main,
                verb: Verb::Recruit->value,
                label: "Ask {$actor->name} to come along",
                description: "Invite {$actor->name} to walk this tale beside you. They decide.",
                target: $target,
            );
        }

        if (($tags['restrainable'] ?? $hostile) && isset($capabilities['restrain'])) {
            $cards[] = new ActionCard(
                slot: TurnSlot::Main,
                verb: Verb::Restrain->value,
                label: "Restrain {$actor->name}",
                description: "Bind {$actor->name} rather than harm them.",
                target: $target,
                capability: 'restrain',
                risk: 'risky',
                modifiers: [$this->approachModifier(Verb::Restrain)],
            );

            // Composition, not enumeration: restrain + swing + carry_extra
            // = haul an enemy up with you. Never hand-authored.
            if (isset($capabilities['swing'], $capabilities['carry_extra'])
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

        if (isset($capabilities['scout'])) {
            $hiddenRemains = $scene->allFeatures()->contains(
                fn (SceneFeature $f) => ($f->state['hidden'] ?? false) && ! ($f->state['destroyed'] ?? false),
            );
            if ($hiddenRemains || ! ($scene->state['exit_scouted'] ?? false)) {
                $cards[] = new ActionCard(
                    slot: TurnSlot::Pre,
                    verb: Verb::Scout->value,
                    label: 'Read the ground',
                    description: 'Sweep the scene for what others miss — hidden ways, overlooked cover, a route out.',
                    capability: 'scout',
                );
            }
        }

        if (isset($capabilities['detect'])
            && $scene->activeActors()->contains(fn (Actor $a) => $a->tags['lurking'] ?? false)) {
            $cards[] = new ActionCard(
                slot: TurnSlot::Pre,
                verb: Verb::Detect->value,
                label: 'Something is wrong — find it',
                description: 'The scene is off in a way you can almost name. Hunt the source before it moves first.',
                capability: 'detect',
            );
        }

        if (isset($capabilities['track'])) {
            foreach ($scene->actors()->where('status', 'fled')->get() as $quarry) {
                $cards[] = new ActionCard(
                    slot: TurnSlot::Main,
                    verb: Verb::Track->value,
                    label: "Follow {$quarry->name}'s trail",
                    description: "{$quarry->name} ran. Their trail is still warm — follow it out of this place.",
                    target: ['type' => 'actor', 'id' => $quarry->id, 'name' => $quarry->name],
                    capability: 'track',
                    modifiers: [$this->approachModifier(Verb::Track)],
                );
            }
        }

        if (isset($capabilities['command'])
            && $actors->contains(fn (Actor $a) => $a->kind === 'companion')) {
            $cards[] = new ActionCard(
                slot: TurnSlot::Pre,
                verb: Verb::Command->value,
                label: 'Call the play',
                description: "Direct your companions with a commander's precision — every request lands sharper this turn.",
                capability: 'command',
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
                ['value' => 'balanced', 'label' => $balanced],
                ['value' => 'cautious', 'label' => $cautious, 'fact' => $cautiousFact],
                ['value' => 'bold', 'label' => $bold, 'fact' => $boldFact],
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
