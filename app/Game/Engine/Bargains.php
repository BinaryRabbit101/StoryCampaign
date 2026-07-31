<?php

namespace App\Game\Engine;

use App\Game\Hands;
use App\Game\Meters;
use App\Models\Actor;
use App\Models\Character;
use App\Models\Scene;

/**
 * Bargain cards: a complication with a price tag.
 *
 * Occasionally a card is offered twice — the honest version, and a version of
 * the same beat that trades a named consequence in the world for a named edge
 * on the arithmetic. "Wrench the gate open — loud; it will be heard." Both
 * halves are printed before the commit, because a card the player cannot price
 * is a card they cannot choose.
 *
 * This is the sibling of the stance economy rather than a copy of it. A stance
 * prices RISK on the roll: bold buys a wilder die at a harder number. A bargain
 * prices a CONSEQUENCE, and it is always paid — the hinges shriek whether or
 * not the gate opens. That is the whole reason it can be priced up front, and
 * it is why "complication only on failure" is deliberately not built here: that
 * is the `risky` stance's ground, and blurring the two would leave the player
 * unable to read either.
 *
 * Two closed lists, and no third one anywhere:
 *  - the EDGE lives in Odds::BARGAINS, with every other number the dice honor;
 *  - the COMPLICATION lives here, and it routes only through mechanisms the
 *    engine already had — the scene's alarm counter, Hands, the concealed
 *    condition, an enemy's intent tag, a tempo pool. Nothing here invents a
 *    resource, and Claude invents neither half: it is handed the fact and
 *    narrates the noise, the drop, the exposure.
 *
 * The gates matter as much as the table. A complication that cannot cost
 * anything is a free lunch wearing a warning label, and one free lunch teaches
 * the player that the whole mechanic is a strictly better button.
 */
class Bargains
{
    /** Force it without care for the noise. The district hears. */
    public const LOUD = 'loud';

    /** Nothing covered. You are seen, and what was waiting comes now. */
    public const RECKLESS = 'reckless';

    /** Both hands on it — so everything you were holding hits the ground. */
    public const TWO_HANDS = 'two_hands';

    /** Make it personal. They come straight at you next. */
    public const PROVOKING = 'provoking';

    /** Spend the gift harder than it wants to be spent. It costs a charge. */
    public const BURNING = 'burning';

    /**
     * The closed table. `verbs` null means any verb the common gates allow —
     * only `burning` reads that way, because what it attaches to is a property
     * of the CARD (it must be capability-backed) rather than of the verb.
     *
     * `price` is the tail of the card's label, so a bargain announces itself in
     * the list rather than in a detail panel the player may never open.
     */
    private const TABLE = [
        self::LOUD => [
            'verbs' => ['break', 'lift', 'haul', 'ascend'],
            'price' => 'loud; it will be heard',
            'complication' => 'Whatever answers noise in a place like this comes a step sooner.',
        ],
        self::RECKLESS => [
            'verbs' => ['strike', 'cross', 'ascend'],
            'price' => 'seen; nothing covered',
            'complication' => 'You are seen doing it: cover is lost, and anything waiting out of sight comes now.',
        ],
        self::TWO_HANDS => [
            'verbs' => ['ascend', 'restrain', 'break'],
            'price' => 'both hands; you drop what you hold',
            'complication' => 'Everything in your hands goes on the ground.',
        ],
        self::PROVOKING => [
            'verbs' => ['strike', 'intimidate', 'speak'],
            'price' => 'provoking; they turn on you',
            'complication' => 'They answer it personally: their next move comes straight at you.',
        ],
        self::BURNING => [
            'verbs' => null,
            'price' => 'burning; it costs a charge',
            'complication' => 'One charge of the reserve that powers your gifts burns away with it.',
        ],
    ];

    /**
     * Both halves in the player's own words, keyed for storage on the card.
     * The edge wording is Odds' — never re-typed here, or the card and the
     * ledger would eventually say different things about the same deal.
     *
     * @return array{key:string,edge_label:string,complication_label:string}
     */
    public static function describe(string $key): array
    {
        return [
            'key' => $key,
            'edge_label' => (string) Odds::bargainLabel($key),
            'complication_label' => self::TABLE[$key]['complication'],
        ];
    }

    /**
     * What is true of this scene right now, read once for the whole pass.
     *
     * The gates below all read from here rather than from the card, because
     * every one of them is asking the same question: would this complication
     * actually cost the player anything, here, tonight?
     *
     * @param  list<ActionCard>  $offered  Every beat composed for this turn —
     *                                     where concealment being buyable at all is read from.
     * @return array<string,mixed>
     */
    public static function sceneState(Character $character, Scene $scene, array $offered): array
    {
        $active = $scene->activeActors();

        return [
            // An alarm raised where nobody is fighting is wiped at the end of
            // the same turn (the resolver clears the clock the moment hostilities
            // stop), so `loud` would cost exactly nothing.
            'hostilities' => $active->contains(fn (Actor $a) => $a->kind === 'enemy' && ! ($a->tags['lurking'] ?? false)),
            // Whether a step out of here is a roll at all. A deal buys an edge
            // on a die: offered against a beat that casts none, it would be a
            // complication with nothing on the other side of it — the free
            // lunch in reverse, and just as unreadable.
            'contested' => Odds::contestedGround($scene),
            'lurkers' => $active->contains(fn (Actor $a) => $a->tags['lurking'] ?? false),
            // Concealment is a within-turn condition — it never survives a
            // resolution — so "are you concealed?" cannot be asked at compose
            // time. What can be asked is whether cover is IN PLAY: a hide card
            // among this turn's beats is concealment the player may be holding
            // by the time the reckless one lands.
            'cover' => collect($offered)->contains(fn (ActionCard $c) => $c->verb === 'hide'),
            'holding' => Hands::used($character) > 0,
            'reserve' => self::reserve($character),
            'provokable' => $active
                ->filter(fn (Actor $a) => $a->kind === 'enemy'
                    && ! ($a->tags['lurking'] ?? false)
                    && ! ($a->tags['truce'] ?? false)
                    && ($a->tags['intent'] ?? null) !== 'press')
                ->pluck('id')->all(),
        ];
    }

    /**
     * Every deal this card could honestly be offered, in table order.
     *
     * @param  array<string,mixed>  $state
     * @return list<string>
     */
    public static function keysFor(ActionCard $card, array $state): array
    {
        // Already a deal, or a beat there is nothing to sweeten — including the
        // beat that is certain only here and now, which Odds answers off the
        // card rather than off the verb.
        if ($card->bargain !== null || Odds::certain($card->oddsCard(), $state)) {
            return [];
        }

        // A named way out is never a deal, even when it does roll. Whether that
        // step casts a die at all depends on what is standing in the room and
        // what the air is doing, so a bargain hung on it would appear and
        // vanish with the weather — and the air may move what a card COSTS,
        // never what is on the menu.
        if ($card->verb === 'cross' && ($card->target['type'] ?? null) === 'exit') {
            return [];
        }

        // Improvise resolves against base stats with no bonus and must never
        // be better than a real enumerated option. A bargained improvise would
        // be exactly that.
        //
        // And the ending is not a thing to be sweetened. `face` casts no die,
        // so the gate above already refuses it — this names it anyway, because
        // the day somebody gives that beat a roll, the tale's one irreversible
        // choice must not quietly become available at a discount.
        if (in_array($card->verb, ['improvise', 'face'], true)) {
            return [];
        }

        $keys = [];

        foreach (self::TABLE as $key => $rule) {
            if ($rule['verbs'] !== null && ! in_array($card->verb, $rule['verbs'], true)) {
                continue;
            }
            if (self::costsSomething($key, $card, $state)) {
                $keys[] = $key;
            }
        }

        return $keys;
    }

    /**
     * The free-lunch gate, one clause per key. Each asks whether the price is
     * real HERE — not whether the mechanism exists.
     *
     * @param  array<string,mixed>  $state
     */
    private static function costsSomething(string $key, ActionCard $card, array $state): bool
    {
        return match ($key) {
            self::LOUD => (bool) $state['hostilities'],
            self::RECKLESS => (bool) $state['lurkers'] || (bool) $state['cover'],
            self::TWO_HANDS => (bool) $state['holding'],
            self::PROVOKING => ($card->target['type'] ?? null) === 'actor'
                && in_array($card->target['id'] ?? null, $state['provokable'], true),
            // A tempo spend that would be denied is never offered: a card that
            // promises a cost it cannot charge is a card that quietly gives its
            // edge away.
            self::BURNING => $card->capability !== null && $state['reserve'] !== null,
            default => false,
        };
    }

    /** The plain card, offered again as a deal. Same beat, same target, different id. */
    public static function offer(ActionCard $card, string $key): ActionCard
    {
        $deal = self::describe($key);

        return new ActionCard(
            slot: $card->slot,
            verb: $card->verb,
            label: $card->label.' — '.self::TABLE[$key]['price'],
            description: rtrim($card->description, ' ')
                .' The same beat, taken as a deal: '.lcfirst($deal['edge_label'])
                .'. The price is paid either way — '.lcfirst($deal['complication_label']),
            target: $card->target,
            capability: $card->capability,
            risk: $card->risk,
            cost: $card->cost,
            modifiers: $card->modifiers,
            composed: $card->composed,
            bargain: $deal,
        );
    }

    /**
     * Pay the price, the instant the beat is done — win or lose.
     *
     * Called after the verb's own effects have landed, so nothing here can
     * change what the beat did; it only changes what the world does next. Every
     * branch writes through a mechanism that already existed, and every branch
     * returns a plain fact for the narrator to render in this land's own terms.
     *
     * @param  array<string,mixed>  $card  The stored card, as the resolver reads it.
     * @param  array<string,mixed>  $conditions
     * @return list<string>
     */
    public static function pay(array $card, Character $character, Scene $scene, array &$conditions): array
    {
        $key = $card['bargain']['key'] ?? null;

        return match ($key) {
            self::LOUD => self::payLoud($scene),
            self::RECKLESS => self::payReckless($scene, $conditions),
            self::TWO_HANDS => self::payTwoHands($character),
            self::PROVOKING => self::payProvoking($card),
            self::BURNING => self::payBurning($character),
            default => [],
        };
    }

    /** @return list<string> */
    private static function payLoud(Scene $scene): array
    {
        $state = $scene->state ?? [];
        $scene->update(['state' => array_merge($state, ['alarm' => (int) ($state['alarm'] ?? 0) + 1])]);

        return ['They took it loudly, and the noise carried well past this place.'];
    }

    /**
     * Being seen, in the two ways the engine already understands it: the cover
     * condition goes, and anything holding still out of sight stops holding
     * still. Bringing a lurker's clock forward is the existing ambush spring,
     * not a new one — the resolver springs it later in this same resolution.
     *
     * @return list<string>
     */
    private static function payReckless(Scene $scene, array &$conditions): array
    {
        $conditions['concealed'] = false;
        $facts = ['They did it in the open, and were seen doing it.'];

        $lurkers = $scene->actors()->where('status', 'active')->get()
            ->filter(fn (Actor $a) => $a->tags['lurking'] ?? false);

        foreach ($lurkers as $lurker) {
            $lurker->update(['tags' => array_merge($lurker->tags ?? [], ['lurking_since' => 0])]);
        }

        if ($lurkers->isNotEmpty()) {
            $facts[] = 'Whatever had been waiting out of sight had no reason to wait any longer.';
        }

        return $facts;
    }

    /** @return list<string> */
    private static function payTwoHands(Character $character): array
    {
        $dropped = Hands::releaseAll($character);

        if ($dropped === []) {
            return ['They put both hands into it, with nothing in either to begin with.'];
        }

        return ['They needed both hands for it, so '.implode(' and ', array_column($dropped, 'name')).' went on the ground.'];
    }

    /** @return list<string> */
    private static function payProvoking(array $card): array
    {
        $actor = Actor::find($card['target']['id'] ?? 0);

        if ($actor === null || $actor->status !== 'active') {
            return ['They made it personal, and there was no one left to take it personally.'];
        }

        $actor->update(['tags' => array_merge($actor->tags ?? [], ['intent' => 'press'])]);

        return ["They made it personal, and {$actor->name} is coming straight at them for it."];
    }

    /** @return list<string> */
    private static function payBurning(Character $character): array
    {
        $pool = self::reserve($character);

        if ($pool === null || ! Meters::spend($character, $pool, 1)) {
            return ['They reached for the reserve to force it, and found it already dry.'];
        }

        return ['They burned a charge of their '.str_replace('_', ' ', $pool).' to force it.'];
    }

    /**
     * The pool a burning bargain spends from.
     *
     * The brief calls this "the powering pool". Only tempo capabilities carry a
     * metered pool, and every tempo VERB is a quiet no-roll beat — so a pool
     * keyed to the card's own capability would make this key unreachable. It is
     * the character's reserve instead: the charges that power what they can do
     * at all, spent to force a gift past what it comfortably gives. Deepest
     * pool first, so the deal never quietly empties the last charge of a
     * different one the player was saving.
     */
    public static function reserve(Character $character): ?string
    {
        $pools = [];

        foreach ($character->meters['tempo'] ?? [] as $name => $pool) {
            if ((int) ($pool['current'] ?? 0) >= 1) {
                $pools[$name] = (int) $pool['current'];
            }
        }

        if ($pools === []) {
            return null;
        }

        // PHP's sorts are stable, so name order decides ties and the pick is
        // the same on every machine that reads the same sheet.
        ksort($pools);
        arsort($pools);

        return (string) array_key_first($pools);
    }
}
