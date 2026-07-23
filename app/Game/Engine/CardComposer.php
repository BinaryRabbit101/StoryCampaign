<?php

namespace App\Game\Engine;

use App\Game\Capability;
use App\Game\Meters;
use App\Game\TurnSlot;
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

    /** @return array{pre: list<array>, main: list<array>, post: list<array>, companions: list<array{id: int, name: string, cards: list<array>}>} */
    public function compose(Character $character, Scene $scene): array
    {
        $capabilities = $character->effectiveCapabilities();
        $features = $scene->allFeatures();
        $actors = $scene->activeActors();

        $cards = ['pre' => [], 'main' => [], 'post' => []];

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
                verb: 'flee',
                label: 'Take the scouted way out',
                description: 'Slip out through the route your companion found. It holds until the scene turns.',
                target: ['type' => 'exit', 'id' => null, 'name' => 'the scouted way out'],
                modifiers: [$this->approachModifier()],
            );
        }

        foreach ($this->tempoCards($character, $capabilities) as $card) {
            $cards[$card->slot->value][] = $card;
        }

        foreach ($this->genericCards($character, $actors) as $card) {
            $cards[$card->slot->value][] = $card;
        }

        $composed = array_map(
            fn (array $slotCards) => array_map(fn (ActionCard $c) => $c->toArray(), $slotCards),
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
                    fn (ActionCard $c) => $c->toArray(),
                    $this->companionCards($companion, $actors, $scene),
                ),
            ])
            ->values()->all();

        return $composed;
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
                verb: 'cross',
                label: ucfirst($via)." across {$feature->name}",
                description: $risk === 'degraded'
                    ? "The {$feature->name} is at the very edge of what you can clear. Risky."
                    : "Cross the {$feature->name} using {$capability->label()}.",
                target: $target,
                capability: $capability->value,
                risk: $risk,
                modifiers: [$this->approachModifier()],
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
                    verb: 'flee',
                    label: "Flee into {$feature->name}",
                    description: $tight
                        ? "You can just barely fit through {$feature->name} — a tight squeeze, and it will cost you time."
                        : "Slip away through {$feature->name}.",
                    target: $target,
                    capability: 'squeeze',
                    risk: $tight ? 'degraded' : 'safe',
                    modifiers: [$this->approachModifier()],
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
                    verb: 'hide',
                    label: "Take cover behind {$feature->name}",
                    description: "Conceal yourself using {$feature->name} before acting.",
                    target: $target,
                    capability: 'conceal',
                );
            }
        }

        // breakable / liftable — brute manipulation
        if (isset($affordances['breakable']) && isset($capabilities['break'])) {
            $cards[] = new ActionCard(
                slot: TurnSlot::Main,
                verb: 'break',
                label: "Break {$feature->name}",
                description: "Force {$feature->name} open or apart.",
                target: $target,
                capability: 'break',
                risk: 'risky',
                modifiers: [$this->approachModifier()],
            );
        }

        if (isset($affordances['lift_weight']) && isset($capabilities['lift'])) {
            $weight = (int) $affordances['lift_weight'];
            $mag = $capabilities['lift']->magnitude ?? 0;
            if ($mag >= $weight * 0.75) {
                $cards[] = new ActionCard(
                    slot: TurnSlot::Main,
                    verb: 'lift',
                    label: "Lift {$feature->name}",
                    description: $mag >= $weight
                        ? "Heave {$feature->name} aside."
                        : "{$feature->name} is heavier than anything you've lifted — you might manage it, straining.",
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
                    verb: 'ride',
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
            verb: 'ascend',
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

        if ($hostile) {
            $cards[] = new ActionCard(
                slot: TurnSlot::Main,
                verb: 'strike',
                label: "Strike at {$actor->name}",
                description: "Attack {$actor->name} directly.",
                target: $target,
                risk: 'risky',
                modifiers: [$this->approachModifier()],
            );
        }

        if (($tags['intimidatable'] ?? false) && isset($capabilities['intimidate'])) {
            $scope = $capabilities['intimidate']->scope['vs'] ?? null;
            // Scoped presence: intimidate(vs: regular) does not flatten
            // tougher encounters.
            if ($scope === null || $actor->tier === $scope) {
                $cards[] = new ActionCard(
                    slot: TurnSlot::Main,
                    verb: 'intimidate',
                    label: "Loom over {$actor->name}",
                    description: "Let your presence do the work — drive {$actor->name} back without a blow.",
                    target: $target,
                    capability: 'intimidate',
                    modifiers: [$this->approachModifier()],
                );
            }
        }

        foreach (['persuade', 'deceive', 'calm'] as $social) {
            if (($tags[$social.'able'] ?? ($tags['talkable'] ?? false)) && isset($capabilities[$social])) {
                $cards[] = new ActionCard(
                    slot: TurnSlot::Main,
                    verb: $social,
                    label: ucfirst($social)." {$actor->name}",
                    description: "Work on {$actor->name} with words.",
                    target: $target,
                    capability: $social,
                );
            }
        }

        // Recruitment grows out of the social verbs: a willing soul, or one
        // already swayed or calmed, can be asked to come along.
        if (! $hostile && $actor->kind !== 'companion'
            && (($tags['companionable'] ?? false) || in_array($tags['disposition'] ?? null, ['swayed', 'calmed'], true))) {
            $cards[] = new ActionCard(
                slot: TurnSlot::Main,
                verb: 'recruit',
                label: "Ask {$actor->name} to come along",
                description: "Invite {$actor->name} to walk this tale beside you. They decide.",
                target: $target,
            );
        }

        if (($tags['restrainable'] ?? $hostile) && isset($capabilities['restrain'])) {
            $cards[] = new ActionCard(
                slot: TurnSlot::Main,
                verb: 'restrain',
                label: "Restrain {$actor->name}",
                description: "Bind {$actor->name} rather than harm them.",
                target: $target,
                capability: 'restrain',
                risk: 'risky',
                modifiers: [$this->approachModifier()],
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
                            verb: 'haul',
                            label: "Haul {$actor->name} up to {$feature->name}",
                            description: "Snatch {$actor->name} and swing to {$feature->name}, taking them with you.",
                            target: $target,
                            capability: 'restrain',
                            risk: 'risky',
                            composed: true,
                            modifiers: [$this->approachModifier()],
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
            verb: 'shield',
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
                verb: 'hurl',
                label: "Hurl {$captive->name}".($hasOtherEnemy ? ' into the fray' : ' aside'),
                description: $hasOtherEnemy
                    ? "Send {$captive->name} crashing into their allies. The hold ends — one way or the other."
                    : "Throw {$captive->name} hard. The hold ends — one way or the other.",
                target: $target,
                capability: isset($capabilities['lift']) ? 'lift' : 'carry_extra',
                risk: 'risky',
                composed: true,
                modifiers: [$this->approachModifier()],
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
                        verb: 'haul',
                        label: "Drag {$captive->name} up to {$feature->name}",
                        description: "Take your captive with you to {$feature->name} — height, leverage, and a hostage in hand.",
                        target: $target,
                        capability: 'carry_extra',
                        risk: 'risky',
                        composed: true,
                        modifiers: [$this->approachModifier()],
                    );
                    break;
                }
            }
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
                verb: 'companion_block',
                label: "Block {$threat->name}",
                description: "{$companion->name} plants themselves in {$threat->name}'s path — held off from you, if the line holds.",
                target: $target,
                composed: true,
            );
            $cards[] = new ActionCard(
                slot: TurnSlot::Companion,
                verb: 'companion_flank',
                label: 'Flank the threat',
                description: "{$companion->name} circles wide so the threat must look two ways — your strike lands harder.",
                target: $target,
                composed: true,
            );
            $cards[] = new ActionCard(
                slot: TurnSlot::Companion,
                verb: 'companion_strike',
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
                verb: 'companion_scout',
                label: 'Find a way out',
                description: "{$companion->name} slips off to search for an exit while you hold the scene.",
                target: $target,
                composed: true,
            );
        }

        return $cards;
    }

    /** @return list<ActionCard> */
    private function tempoCards(Character $character, array $capabilities): array
    {
        $cards = [];

        foreach (['time_slow', 'haste'] as $tempo) {
            if (isset($capabilities[$tempo]) && Meters::charges($character, $tempo) >= 1) {
                $capability = Capability::from($tempo);
                $cards[] = new ActionCard(
                    slot: TurnSlot::Pre,
                    verb: $tempo,
                    label: $capability->label(),
                    description: $tempo === 'time_slow'
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
                verb: 'ready',
                label: 'Ready yourself',
                description: 'Set a stance and wait for the right instant.',
                capability: 'ready',
            );
        }

        return $cards;
    }

    /**
     * Generic fallbacks (always present) and post-slot recovery verbs.
     * Improvise resolves against base stats with no special bonus — never
     * better than a real enumerated option.
     *
     * @return list<ActionCard>
     */
    private function genericCards(Character $character, $actors): array
    {
        $cards = [
            new ActionCard(
                slot: TurnSlot::Main,
                verb: 'improvise',
                label: 'Improvise',
                description: 'Attempt something the moment suggests. Describe it in your intent — the outcome rides on plain luck and grit.',
                risk: 'risky',
            ),
            new ActionCard(
                slot: TurnSlot::Main,
                verb: 'examine',
                label: 'Examine the scene',
                description: 'Take stock: study what is around you before committing to anything.',
            ),
            new ActionCard(
                slot: TurnSlot::Main,
                verb: 'wait',
                label: 'Wait',
                description: 'Hold still and let the scene move first.',
            ),
        ];

        $health = $character->meters['health'];
        if ($health['current'] < $health['max']) {
            $cards[] = new ActionCard(
                slot: TurnSlot::Post,
                verb: 'bandage',
                label: 'Bind your wounds',
                description: 'Once the moment settles, tend to your injuries.',
            );
        }

        $cards[] = new ActionCard(
            slot: TurnSlot::Post,
            verb: 'catch_breath',
            label: 'Catch your breath',
            description: 'Recover your composure after the main effort.',
        );

        if ($actors->contains(fn (Actor $a) => $a->status === 'active' && $a->kind === 'enemy')) {
            // looting only fires if the fight actually ends this turn — the
            // resolver treats post as contingent.
            $cards[] = new ActionCard(
                slot: TurnSlot::Post,
                verb: 'loot',
                label: 'Search the fallen',
                description: 'If the fight ends in your favor, go through what they carried.',
            );
        }

        $cards[] = new ActionCard(
            slot: TurnSlot::Post,
            verb: 'reposition',
            label: 'Reposition',
            description: 'Move to safer footing once the dust settles.',
        );

        return $cards;
    }

    private function approachModifier(): array
    {
        return [
            'key' => 'approach',
            'label' => 'Approach',
            'options' => [
                ['value' => 'balanced', 'label' => 'Balanced'],
                ['value' => 'cautious', 'label' => 'Cautious — surer, gentler'],
                ['value' => 'bold', 'label' => 'Bold — harder, riskier'],
            ],
        ];
    }
}
