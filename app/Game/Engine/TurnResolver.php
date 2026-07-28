<?php

namespace App\Game\Engine;

use App\Game\BranchTrigger;
use App\Game\Hands;
use App\Game\Meters;
use App\Models\Actor;
use App\Models\Character;
use App\Models\Scene;
use App\Models\Turn;
use App\Models\Zone;
use Illuminate\Support\Facades\DB;

/**
 * Resolves a locked turn: the pre/main/post slot chain in order (conditional
 * chain with legality-driven abort), the scene's reaction, branch-trigger
 * evaluation, and generation of the next turn's cards. The engine owns all
 * dice and outcomes; the resolution it writes is handed to Claude to narrate.
 */
class TurnResolver
{
    public function __construct(
        private readonly CardComposer $composer,
        private readonly SceneDresser $dresser,
    ) {}

    /** Resolve the turn and create its successor. Returns the next turn. */
    public function resolve(Turn $turn): Turn
    {
        return DB::transaction(function () use ($turn) {
            $turn->update(['status' => Turn::STATUS_RESOLVING]);

            $campaign = $turn->campaign;
            $character = $campaign->character;
            $scene = $turn->scene()->first() ?? $campaign->activeScene;

            Meters::regenerate($character);

            $dice = new Dice($turn->id * 2654435761 % PHP_INT_MAX);
            $submission = $turn->submission ?? [];
            $offered = collect($turn->cards)->only(['pre', 'main', 'post'])->flatMap(fn ($cards) => $cards)->keyBy('id');

            $conditions = [
                'elevated' => (bool) ($scene->state['elevated'] ?? false),
                // The air this ground stands in, fixed when it was dressed.
                // The cards were priced against it a turn ago; the dice read
                // the same key off the same scene, so the quoted DC is paid.
                'ambient' => Ambient::of($scene),
                'concealed' => false,
                'time_slowed' => false,
                'hastened' => false,
                'readied' => false,
                'shielded' => false,
                'shield_actor_id' => null,
                'flanked' => false,
                'blocked' => null,
                'braced' => false,
                'commanded' => false,
                'prior_failure' => false,
            ];

            // The wait, spent. Whatever the player chose to do with the idle
            // stretch before this turn pays out here, beside the tempo regen
            // it rides along with: engine-priced from the real elapsed minutes,
            // clamped both ways, and stamped so one wait is never spent twice.
            // No pick means today's behavior exactly — regen and nothing else.
            $lurkingBefore = Downtime::lurkingIds($scene);
            $downtime = Downtime::apply($turn, $character, $conditions);

            // Captives held before this turn may struggle free at its end —
            // a fresh grip always survives the turn it was won.
            $heldBefore = $scene->actors()->where('status', 'restrained')->pluck('id')->all();

            // Whoever was already gone when the turn began: only a flight
            // that happens THIS turn writes into the tale's memory.
            $fledBefore = $scene->actors()->where('status', 'fled')->pluck('id')->all();

            $outcomes = [];
            $trigger = null;
            $moved = false;
            $wasInDanger = Meters::healthInDangerBand($character);

            foreach (['pre', 'companion', 'main', 'post'] as $slot) {
                // Companions act from their own slot, between the player's
                // set-up and act: support requests (block, flank) shape the
                // act they support, and never consume the player's chain.
                if ($slot === 'companion') {
                    foreach ($this->companionBeats($turn, $submission, $character, $scene, $dice, $conditions) as $outcome) {
                        $outcomes[] = $outcome;
                    }

                    continue;
                }

                $choice = $submission[$slot] ?? null;
                if ($choice === null) {
                    continue;
                }

                $card = $offered->get($choice['card_id'] ?? '');
                if ($card === null || $card['slot'] !== $slot) {
                    continue; // tampered or stale submission entry — never resolve it
                }

                // Post is contingent: if the main went badly enough, the
                // planned postaction does not execute.
                if ($slot === 'post' && ($character->fresh()->status !== 'alive' || $trigger !== null)) {
                    $outcomes[] = BeatOutcome::skipped($slot, $card['verb'], $card['target'],
                        'The planned follow-up never came — the situation had already turned.');
                    break;
                }

                // Legality re-check against the ACTUAL current state.
                $illegalReason = $this->illegalReason($card, $scene, $character, $conditions);
                if ($illegalReason !== null) {
                    if ($conditions['prior_failure']) {
                        // No longer legal after an earlier failure: abort the
                        // chain and re-card (branch trigger #5, failure pivot).
                        $outcomes[] = BeatOutcome::skipped($slot, $card['verb'], $card['target'], $illegalReason);
                        $trigger = BranchTrigger::FailurePivot;
                        break;
                    }
                    $outcomes[] = BeatOutcome::skipped($slot, $card['verb'], $card['target'], $illegalReason);

                    continue;
                }

                $outcome = $this->resolveBeat($card, $choice, $character, $scene, $dice, $conditions, $turn);
                $outcomes[] = $outcome;

                if (! $outcome->succeeded()) {
                    $conditions['prior_failure'] = true;
                }

                // Partial counts: the facts say they got through (battered),
                // so the scene must actually change under them.
                if (in_array($card['verb'], ['flee', 'cross', 'track', 'venture'], true)
                    && $outcome->degree !== BeatOutcome::FAILURE) {
                    $moved = true;
                }
            }

            // An ambush laid on an earlier turn springs before the scene
            // answers — unless detect already dragged it into the light.
            [$sprung, $springFacts] = $this->springAmbushes($scene, $turn);

            // The scene answers: active enemies react to the player's beats,
            // and long-held captives strain against the grip.
            // The scene's dice are kept as data as well as prose: the dice
            // table shows the player what the world rolled against them, and
            // a die nobody can see is a die the player has to take on faith.
            $reactionRolls = [];
            $enemyFacts = array_merge($springFacts, $this->enemyReaction($character, $scene, $dice, $conditions, $moved, $reactionRolls));
            $enemyFacts = array_merge($enemyFacts, $this->captiveStruggle($scene, $dice, $heldBefore, $reactionRolls));

            // The tale remembers: an enemy who newly broke and ran this turn
            // becomes a grudge — the world's memory against the player.
            Grudges::recordFlights($scene, $turn, $fledBefore);

            // The alarm clock: every turn spent toe-to-toe in the same place
            // raises the odds the district answers. At three, it does.
            $alarm = (int) ($scene->state['alarm'] ?? 0);
            $hostilities = $scene->actors()->where('status', 'active')->where('kind', 'enemy')->exists();
            $alarm = (! $moved && $hostilities) ? $alarm + 1 : 0;
            $forced = $alarm >= 3;
            if ($forced) {
                $alarm = 0;
            }
            $scene->update(['state' => array_merge($scene->state ?? [], ['alarm' => $alarm])]);

            // Living-world texture: a zone-level actor may join mid-scene —
            // in the open, or lurking until detect or its own moment.
            $newcomer = null;
            if (! $moved && ($trigger === null || $forced)) {
                $newcomer = $this->maybeIntroduceThreat($scene, $dice, $turn, $forced);
            }

            // A wait spent watching is cashed in before anyone reads the
            // scene: what slipped in during it stands in the open instead, so
            // the branch trigger, the cards, and the chapter all see the same
            // arrival the player paid to see.
            if ($downtime['watchful']) {
                Downtime::revealNewArrivals($lurkingBefore, $scene);
                $newcomer = $newcomer?->fresh();
            }

            // A lurker is not yet a fact the player (or narrator) may know.
            $newThreat = $sprung ?? (($newcomer !== null && ! ($newcomer->tags['lurking'] ?? false)) ? $newcomer : null);

            $character->refresh();
            $scene->refresh();

            $trigger ??= $this->evaluateTrigger($character, $scene, $outcomes, $moved, $newThreat, $wasInDanger);

            $sceneBefore = $scene;
            if ($moved) {
                $scene = $this->transitionScene($scene, $dice, $turn);
            } else {
                $this->rollEnemyIntents($scene, $dice);
            }

            // New ground the transition just dressed can arrive with its own
            // hidden company. A watch kept through the wait reads that entry
            // too — it is still the entry path, not a standing ambush.
            if ($downtime['watchful']) {
                Downtime::revealNewArrivals($lurkingBefore, $scene);
            }

            // Settle every returning grudge against what this turn actually
            // did to them: killed or kept closes the score; a player who
            // walked away leaves it simmering for another day.
            Grudges::settle($sceneBefore, $scene, $moved, $turn);

            $turn->update([
                'status' => Turn::STATUS_COMPLETE,
                'resolution' => [
                    'beats' => array_map(fn (BeatOutcome $o) => $o->toArray(), $outcomes),
                    'scene_reaction' => $enemyFacts,
                    'reaction_rolls' => $reactionRolls,
                    'new_threat' => $newThreat?->only(['id', 'name', 'kind', 'tier']),
                    'conditions' => $conditions,
                    // One plain sentence about how the wait before this
                    // vignette passed — colour for the narrator, and the only
                    // thing about downtime that ever reaches a prompt. Null
                    // when the wait bought nothing, so an ordinary turn
                    // carries no instructions about a night that did not matter.
                    'downtime' => $downtime['fact'],
                ],
                'branch_trigger' => $trigger->value,
                'meters_snapshot' => $character->meters,
                'resolved_at' => now(),
            ]);

            return $this->openNextTurn($turn, $character, $scene, $trigger);
        });
    }

    /**
     * Resolve each companion's own request. Requests are validated against
     * the cards offered for that specific companion; a companion no longer
     * able to act skips theirs without touching the player's chain, and a
     * companion's failure never counts as the player's prior failure.
     *
     * @return list<BeatOutcome>
     */
    private function companionBeats(Turn $turn, array $submission, Character $character, Scene $scene, Dice $dice, array &$conditions): array
    {
        $outcomes = [];
        $offered = collect($turn->cards['companions'] ?? [])->keyBy('id');

        foreach ($submission['companions'] ?? [] as $companionId => $choice) {
            $entry = $offered->get((int) $companionId);
            if ($entry === null || $choice === null) {
                continue;
            }

            $card = collect($entry['cards'])->firstWhere('id', $choice['card_id'] ?? '');
            if ($card === null) {
                continue; // tampered or stale submission entry — never resolve it
            }

            $companion = Actor::find($entry['id']);
            if ($companion === null || $companion->status !== 'active' || $companion->kind !== 'companion') {
                $outcomes[] = BeatOutcome::skipped('companion', $card['verb'], $card['target'],
                    "{$entry['name']} was in no state to answer the request.");

                continue;
            }

            $playerFailure = $conditions['prior_failure'];
            $outcomes[] = $this->resolveBeat($card, $choice, $character, $scene, $dice, $conditions, $turn);
            $conditions['prior_failure'] = $playerFailure;
        }

        return $outcomes;
    }

    private function illegalReason(array $card, Scene $scene, Character $character, array $conditions): ?string
    {
        $target = $card['target'] ?? null;

        if (($target['type'] ?? null) === 'actor') {
            $actor = Actor::find($target['id']);
            // Restrained is a live state: captive-leverage cards stay legal.
            // Track is the exception that WANTS a fled target — that is the trail.
            $legal = $card['verb'] === 'track' ? ['fled'] : ['active', 'restrained'];
            if ($actor === null || ! in_array($actor->status, $legal, true)) {
                return "{$target['name']} is no longer a factor.";
            }
        }

        if (($target['type'] ?? null) === 'feature') {
            $feature = $scene->allFeatures()->firstWhere('id', $target['id']);
            if ($feature === null || ($feature->state['destroyed'] ?? false)) {
                return "{$target['name']} is gone.";
            }
        }

        // A thing they were holding when the card was offered and are not
        // holding now: an earlier beat in this same chain threw it or set it
        // down, and the follow-up has nothing left to act on.
        if (($target['type'] ?? null) === 'carried'
            && ! Hands::isHolding($character, $target['id'] ?? null)) {
            return "{$target['name']} is no longer in their hands.";
        }

        // Lifting wants hands the chain may have filled since the offer.
        if ($card['verb'] === 'lift' && Hands::free($character) < 1) {
            return 'Their hands are already full.';
        }

        // A venture card is only legal toward the campaign's own pre-forged
        // frontier zone — never an arbitrary zone id.
        if (($target['type'] ?? null) === 'zone'
            && $scene->campaign?->next_zone_id !== ($target['id'] ?? null)) {
            return 'The way to '.($target['name'] ?? 'new ground').' has closed.';
        }

        foreach ($card['cost'] ?? [] as $cost) {
            if (Meters::charges($character, $cost['meter']) < $cost['amount']) {
                return 'The '.str_replace('_', ' ', $cost['meter']).' is spent dry.';
            }
        }

        return null;
    }

    private function resolveBeat(array $card, array $choice, Character $character, Scene $scene, Dice $dice, array &$conditions, Turn $turn): BeatOutcome
    {
        $verb = $card['verb'];
        $approach = $choice['modifiers']['approach'] ?? 'balanced';

        foreach ($card['cost'] ?? [] as $cost) {
            Meters::spend($character, $cost['meter'], $cost['amount']);
        }

        // Tempo and quiet beats auto-succeed; everything else rolls.
        if (! Odds::rolls($verb)) {
            return $this->quietBeat($card, $character, $scene, $conditions, $turn, $this->note($choice));
        }

        // The ledger, not a second copy of it. The card the player committed
        // to printed a difficulty and a bonus from Odds; this is the same
        // call, so the number they were shown is the number they are measured
        // against. Two copies of this arithmetic is exactly how a card would
        // start promising a DC the dice do not honor.
        $difficultyLedger = Odds::difficulty($card, $approach, $conditions);
        $bonusLedger = Odds::bonus($conditions, $verb, $card['slot']);
        $difficulty = $difficultyLedger['value'];
        $bonus = $bonusLedger['value'];

        $roll = $dice->d20();
        $total = $roll + $bonus;

        $degree = match (true) {
            $total >= $difficulty + 5 => BeatOutcome::STRONG,
            $total >= $difficulty => BeatOutcome::SUCCESS,
            $total >= $difficulty - 4 => BeatOutcome::PARTIAL,
            default => BeatOutcome::FAILURE,
        };

        // The stance economy — every stance a live choice, none dominant.
        // Caution already bought a surer roll above; the price is paid here:
        // it never yields more than a plain success. Boldness paid a harder
        // roll for a wilder die (see critFor); balanced holds the middle.
        if ($approach === 'cautious' && $degree === BeatOutcome::STRONG) {
            $degree = BeatOutcome::SUCCESS;
        }

        // The faces that overrule the arithmetic. A crit success is the best
        // the beat could have gone no matter how steep the difficulty; a
        // crit failure the worst no matter how generous the bonuses. The
        // margin still decides everything in between — and the stance decides
        // which faces speak at all.
        $crit = BeatOutcome::critFor($roll, $approach);
        if ($crit === BeatOutcome::CRIT_SUCCESS) {
            $degree = BeatOutcome::STRONG;
        } elseif ($crit === BeatOutcome::CRIT_FAILURE) {
            $degree = BeatOutcome::FAILURE;
        }

        $facts = $this->applyBeatEffects($verb, $card, $degree, $approach, $character, $scene, $conditions, $dice, $crit);

        if ($crit !== null) {
            $facts = array_merge(
                CritConsequence::apply($crit, $verb, $card, $character, $scene, $conditions),
                $facts,
            );
        }

        // The chosen attack form is narration color only: it never touched
        // the roll above. It rides along as a resolved fact so the narrator
        // (and the reader's event panel) know HOW the blow took shape.
        $method = $choice['modifiers']['method'] ?? null;
        if ($verb === 'strike' && is_string($method) && $method !== '' && $method !== 'unspecified') {
            array_unshift($facts, "The attack came as {$method}.");
        }

        // The stance is already spent — it moved the difficulty and the die's
        // faces above. What rides along here is only its telling, authored on
        // the card beside the label, so the narrator learns HOW they carried
        // the beat. Appended, never unshifted: the first fact is the dice
        // table's outcome line and must stay the thing that actually changed.
        if ($approach !== 'balanced') {
            $stance = collect($card['modifiers'] ?? [])->firstWhere('key', 'approach');
            $telling = collect($stance['options'] ?? [])->firstWhere('value', $approach)['fact'] ?? null;
            if (is_string($telling) && $telling !== '') {
                $facts[] = $telling;
            }
        }

        return new BeatOutcome($card['slot'], $verb, $card['target'] ?? null, $degree, $roll, $total, $difficulty, $facts,
            note: $this->note($choice), crit: $crit,
            difficultyParts: $difficultyLedger['parts'], bonusParts: $bonusLedger['parts']);
    }

    /**
     * The player's own words for this beat. Same class as the old whole-turn
     * intent line: it colors how the narrator tells the beat and never enters
     * the mechanics path — it is read after every roll is already cast.
     */
    private function note(array $choice): ?string
    {
        $note = trim((string) ($choice['note'] ?? ''));

        return $note === '' ? null : $note;
    }

    private function quietBeat(array $card, Character $character, Scene $scene, array &$conditions, Turn $turn, ?string $note = null): BeatOutcome
    {
        $facts = [];

        switch ($card['verb']) {
            case 'bargain':
                // Taking a returned grudge's terms. Roll-free — the deal was
                // theirs to offer — and its whole mechanical content is the
                // closed list the engine picked at their return.
                $facts = Grudges::strikeBargain($card, $scene, $turn);
                break;
            case 'time_slow':
                $conditions['time_slowed'] = true;
                $facts[] = 'A time-slow charge was spent; the coming moments stretch in their favor.';
                break;
            case 'haste':
                $conditions['hastened'] = true;
                $facts[] = 'A haste charge was spent; they move ahead of the world.';
                break;
            case 'ready':
                $conditions['readied'] = true;
                $facts[] = 'They set themselves, waiting for the precise instant.';
                break;
            case 'examine':
                // Examination has teeth: it can surface what the scene hides,
                // or read an enemy's next move — plain summary only when
                // there is genuinely nothing left to find.
                $hidden = $scene->features()->get()->first(
                    fn ($f) => ($f->state['hidden'] ?? false) && ! ($f->state['destroyed'] ?? false),
                );
                $telegraphing = $scene->activeActors()->first(
                    fn ($a) => $a->kind === 'enemy' && in_array($a->tags['intent'] ?? null, ['windup', 'guard', 'circle'], true),
                );
                if ($hidden !== null) {
                    $hidden->update(['state' => array_merge($hidden->state ?? [], ['hidden' => false])]);
                    $facts[] = "Their study paid off: they found {$hidden->name}, unnoticed until now.";
                } elseif ($telegraphing !== null) {
                    $facts[] = "They read the fight: {$telegraphing->name} is ".match ($telegraphing->tags['intent']) {
                        'windup' => 'gathering for one heavy blow',
                        'guard' => 'settled behind a tight guard',
                        default => 'circling for a better angle',
                    }.'.';
                } else {
                    $facts[] = 'They studied the scene: '.$this->sceneSummary($scene);
                }
                break;
            case 'inspect':
                // Reading one thing properly, rather than the whole scene:
                // what it is and what it would give, in plain sight-language.
                $feature = $scene->allFeatures()->firstWhere('id', $card['target']['id'] ?? 0);
                $facts[] = $feature === null
                    ? 'What they went to look at was no longer there to look at.'
                    : "They read {$feature->name} closely. ".implode(' ', $feature->readings());
                break;
            case 'wait':
                $facts[] = 'They held still and let the scene move first.';
                break;
            case 'catch_breath':
                $facts[] = 'They took a slow breath and steadied themselves.';
                break;
            case 'reposition':
                // Moving denies the angle any circling enemy was hunting.
                $denied = false;
                foreach ($scene->actors()->where('status', 'active')->get() as $mover) {
                    if ($mover->tags['angle'] ?? false) {
                        $tags = $mover->tags;
                        unset($tags['angle']);
                        $mover->update(['tags' => $tags]);
                        $denied = true;
                    }
                }
                $facts[] = $denied
                    ? 'They shifted footing and broke the angle being worked against them.'
                    : 'They shifted to safer footing.';
                break;
            case 'brace':
                $conditions['braced'] = true;
                $facts[] = 'They set themselves against the blow they saw coming.';
                break;
            case 'command':
                $conditions['commanded'] = true;
                $facts[] = 'They called the play, and their companions moved with borrowed precision.';
                break;
            case 'shield':
                $conditions['shielded'] = true;
                $conditions['shield_actor_id'] = $card['target']['id'] ?? null;
                $facts[] = 'They kept their captive between themselves and the danger.';
                break;
            case 'drop':
                // Putting a thing down is never in doubt, and it must never
                // be: a player who picks something up has to be able to get
                // their hands back without asking the dice for permission.
                $put = Hands::release($character, $card['target']['id'] ?? null);
                $facts[] = $put === null
                    ? 'Their hands were already empty.'
                    : "They set {$put['name']} down and had their hands back.";
                break;
        }

        return new BeatOutcome($card['slot'], $card['verb'], $card['target'] ?? null,
            BeatOutcome::SUCCESS, 0, 0, 0, $facts, note: $note);
    }

    /**
     * Throwing what you are holding.
     *
     * The object leaves their hands however the roll goes — that is the whole
     * bargain of picking something up and committing it. A hit hurts; a miss
     * costs them the thing and leaves them empty-handed and open, which is
     * exactly the risk that makes it a real choice rather than a free attack.
     *
     * @return list<string>
     */
    private function hurlCarried(array $card, Character $character, Scene $scene, string $degree, bool $succeeded): array
    {
        $held = Hands::held($character)[0] ?? null;
        if ($held === null) {
            return ['There was nothing in their hands to throw.'];
        }

        Hands::release($character, $held['feature_id'] ?? null);

        // It landed somewhere: the ground keeps it, out of the scene's way.
        $feature = $scene->allFeatures()->firstWhere('id', $held['feature_id'] ?? 0);
        $feature?->update(['state' => array_merge($feature->state ?? [], ['destroyed' => true])]);

        $actor = Actor::find($card['target']['id'] ?? 0);
        if (! $succeeded || $actor === null) {
            return [$degree === BeatOutcome::PARTIAL
                ? "{$held['name']} went wide of ".($actor?->name ?? 'the target').' and struck nothing but ground — and their hands are empty now.'
                : "The throw was clumsy: {$held['name']} left their hands, hit nothing, and is no longer theirs to use."];
        }

        $damage = $degree === BeatOutcome::STRONG ? 3 : 2;
        $stats = $actor->stats;
        $stats['health']['current'] = max(0, $stats['health']['current'] - $damage);
        $actor->update(['stats' => $stats]);

        if ($stats['health']['current'] === 0) {
            $actor->update(['status' => 'defeated']);

            return ["{$held['name']} caught {$actor->name} full on, and {$actor->name} went down under it."];
        }

        return ["{$held['name']} slammed into {$actor->name} ({$stats['health']['current']}/{$stats['health']['max']} left). Their hands are empty now."];
    }

    /**
     * @param  string|null  $crit  The natural face, when it was 20 or 1. The
     *                             degree above already carries the crit's
     *                             verdict; this only sharpens how hard the
     *                             verb's own effect lands.
     * @return list<string>
     */
    private function applyBeatEffects(string $verb, array $card, string $degree, string $approach, Character $character, Scene $scene, array &$conditions, Dice $dice, ?string $crit = null): array
    {
        $facts = [];
        $targetName = $card['target']['name'] ?? 'the scene';
        $succeeded = in_array($degree, [BeatOutcome::STRONG, BeatOutcome::SUCCESS], true);

        switch ($verb) {
            case 'ascend':
                if ($succeeded) {
                    $conditions['elevated'] = true;
                    $scene->update(['state' => array_merge($scene->state ?? [], ['elevated' => true, 'position' => $targetName])]);
                    $facts[] = "They gained the height of {$targetName} — the fight below is now at their feet.";
                } elseif ($degree === BeatOutcome::PARTIAL) {
                    $facts[] = "They made {$targetName}, but gracelessly — exposed for a raw moment on the way up.";
                    $conditions['elevated'] = true;
                    $scene->update(['state' => array_merge($scene->state ?? [], ['elevated' => true, 'position' => $targetName])]);
                } else {
                    $facts[] = "The attempt on {$targetName} failed — they are still on the ground, and the moment is spent.";
                }
                break;

            case 'strike':
                $actor = Actor::find($card['target']['id']);
                $damage = match ($degree) {
                    BeatOutcome::STRONG => 3,
                    BeatOutcome::SUCCESS => 2,
                    BeatOutcome::PARTIAL => 1,
                    default => 0,
                };
                if ($approach === 'bold' && $damage > 0) {
                    $damage++;
                }
                // A natural 20 doesn't wound — it opens something up.
                if ($crit === BeatOutcome::CRIT_SUCCESS) {
                    $damage *= 2;
                }
                if ($damage > 0 && $actor !== null) {
                    $stats = $actor->stats;
                    $stats['health']['current'] = max(0, $stats['health']['current'] - $damage);
                    $actor->update(['stats' => $stats]);
                    if ($stats['health']['current'] === 0) {
                        $actor->update(['status' => 'defeated']);
                        $facts[] = "The blow felled {$actor->name}.";
                    } else {
                        $facts[] = "The strike wounded {$actor->name} ({$stats['health']['current']}/{$stats['health']['max']} left).";
                    }
                } else {
                    $facts[] = "The strike at {$targetName} went wide.";
                }
                break;

            case 'intimidate':
                $actor = Actor::find($card['target']['id']);
                if ($succeeded && $actor !== null) {
                    // How they were driven off travels with them: the flights
                    // sweep reads it to color a grudge's disposition.
                    $actor->update(['status' => 'fled', 'tags' => array_merge($actor->tags ?? [], ['fled_how' => 'intimidated'])]);
                    $facts[] = "{$actor->name} broke and fled before them.";
                } elseif ($degree === BeatOutcome::PARTIAL && $actor !== null) {
                    $facts[] = "{$actor->name} faltered, shaken but standing.";
                    $tags = $actor->tags ?? [];
                    $tags['shaken'] = true;
                    $actor->update(['tags' => $tags]);
                } else {
                    $facts[] = "{$targetName} did not blink.";
                }
                break;

            case 'restrain':
            case 'haul':
                $actor = Actor::find($card['target']['id']);
                if ($succeeded && $actor !== null) {
                    $actor->update(['status' => 'restrained']);
                    $facts[] = $verb === 'haul'
                        ? "They seized {$actor->name} and swung skyward, hauling their captive with them."
                        : "{$actor->name} is bound and out of the fight.";
                    if ($verb === 'haul') {
                        $conditions['elevated'] = true;
                        $scene->update(['state' => array_merge($scene->state ?? [], ['elevated' => true])]);
                    }
                } elseif ($degree === BeatOutcome::PARTIAL && $actor !== null) {
                    $facts[] = "They grappled {$actor->name} but couldn't finish the hold — the struggle continues.";
                } else {
                    $facts[] = "{$targetName} slipped the grapple entirely.";
                }
                break;

            case 'persuade':
            case 'deceive':
            case 'calm':
                $actor = Actor::find($card['target']['id']);
                if ($succeeded && $actor !== null) {
                    $tags = $actor->tags ?? [];
                    $tags['disposition'] = $verb === 'calm' ? 'calmed' : 'swayed';
                    if ($actor->kind === 'enemy') {
                        $tags['fled_how'] = 'talked';
                    }
                    $actor->update(['tags' => $tags, 'status' => $actor->kind === 'enemy' ? 'fled' : $actor->status]);
                    $facts[] = "Words landed: {$actor->name} was ".($verb === 'calm' ? 'calmed' : 'won over').'.';
                } else {
                    $facts[] = "The words found no purchase on {$targetName}.";
                }
                break;

            case 'speak':
                // Plain conversation: no trained tongue behind it, so it
                // opens a door at best — it never routs anyone the way the
                // capability verbs can.
                $actor = Actor::find($card['target']['id']);
                if ($succeeded && $actor !== null) {
                    $tags = $actor->tags ?? [];
                    $tags['disposition'] = 'swayed';
                    $tags['talkable'] = true;
                    $actor->update(['tags' => $tags]);
                    $facts[] = "{$actor->name} heard them out and warmed to them — the conversation is open now.";
                } elseif ($degree === BeatOutcome::PARTIAL) {
                    $facts[] = "{$targetName} listened, said little, and gave away less.";
                } else {
                    $facts[] = "{$targetName} had no interest in talking.";
                }
                break;

            case 'hide':
                if ($succeeded) {
                    $conditions['concealed'] = true;
                    $facts[] = "They folded into the cover of {$targetName}, unseen.";
                } else {
                    $facts[] = "The cover of {$targetName} was poorer than it looked — they are spotted.";
                }
                break;

            case 'scout':
                $hidden = $scene->features()->get()->first(
                    fn ($f) => ($f->state['hidden'] ?? false) && ! ($f->state['destroyed'] ?? false),
                );
                if ($succeeded && $hidden !== null) {
                    $hidden->update(['state' => array_merge($hidden->state ?? [], ['hidden' => false])]);
                    $facts[] = "The sweep paid off: they marked {$hidden->name}, hidden from plainer eyes.";
                } elseif ($succeeded) {
                    $scene->update(['state' => array_merge($scene->state ?? [], ['exit_scouted' => true])]);
                    $facts[] = 'They read the ground and marked a clean way out. It holds until the scene turns.';
                } elseif ($degree === BeatOutcome::PARTIAL) {
                    $facts[] = 'Something about the scene nags at them — there is more here than shows, but it kept its shape.';
                } else {
                    $facts[] = 'The sweep turned up nothing they did not already know.';
                }
                break;

            case 'detect':
                $lurker = $scene->actors()->where('status', 'active')->get()
                    ->first(fn (Actor $a) => $a->tags['lurking'] ?? false);
                if ($succeeded && $lurker !== null) {
                    $tags = $lurker->tags;
                    unset($tags['lurking'], $tags['lurking_since']);
                    $tags['shaken'] = true;
                    $lurker->update(['tags' => $tags]);
                    $facts[] = "{$lurker->name}, set to spring from hiding, stands exposed instead — the ambush died unsprung.";
                } elseif ($lurker !== null) {
                    $facts[] = 'The wrongness in the scene would not resolve into a shape.';
                } else {
                    $facts[] = 'Whatever set their senses on edge has moved on.';
                }
                break;

            case 'track':
                $quarry = Actor::find($card['target']['id']);
                if ($quarry === null || $quarry->status !== 'fled') {
                    $facts[] = 'The trail was already cold.';
                    break;
                }
                if ($degree !== BeatOutcome::FAILURE) {
                    // The trail is a doorway: the transition carries the
                    // quarry into the next scene, cornered.
                    $scene->update(['state' => array_merge($scene->state ?? [], ['pursuit_actor_id' => $quarry->id])]);
                    if ($degree === BeatOutcome::PARTIAL) {
                        $tags = $quarry->tags ?? [];
                        $tags['intent'] = 'guard';
                        $quarry->update(['tags' => $tags]);
                        $facts[] = "They followed {$quarry->name}'s trail out of this place — but not quietly. The quarry knows they are coming.";
                    } else {
                        $facts[] = "They took {$quarry->name}'s trail while it was warm, and it led them on — the quarry has no idea.";
                    }
                } else {
                    $facts[] = "The trail of {$quarry->name} frayed into cross-tracks and died.";
                }
                break;

            case 'interrupt':
                $actor = Actor::find($card['target']['id']);
                if ($actor === null || ($actor->tags['intent'] ?? null) !== 'windup') {
                    $facts[] = 'The moment to break it had already passed.';
                    break;
                }
                if ($succeeded) {
                    $tags = $actor->tags;
                    $tags['intent'] = 'press';
                    $tags['shaken'] = true;
                    $actor->update(['tags' => $tags]);
                    $damage = $degree === BeatOutcome::STRONG ? 2 : 1;
                    $stats = $actor->stats;
                    $stats['health']['current'] = max(0, $stats['health']['current'] - $damage);
                    $actor->update(['stats' => $stats]);
                    if ($stats['health']['current'] === 0) {
                        $actor->update(['status' => 'defeated']);
                        $facts[] = "They broke {$actor->name}'s windup and {$actor->name} with it — down before the blow ever formed.";
                    } else {
                        $facts[] = "They got inside {$actor->name}'s windup and broke it — the heavy blow died half-made ({$stats['health']['max']} health, {$stats['health']['current']} left).";
                    }
                } elseif ($degree === BeatOutcome::PARTIAL) {
                    $stats = $actor->stats;
                    $stats['health']['current'] = max(0, $stats['health']['current'] - 1);
                    $actor->update(['stats' => $stats]);
                    if ($stats['health']['current'] === 0) {
                        $actor->update(['status' => 'defeated']);
                        $facts[] = "The cut meant to stagger {$actor->name} finished them instead.";
                    } else {
                        $facts[] = "They cut {$actor->name} on the way in, but the windup held — the blow is still coming.";
                    }
                } else {
                    $facts[] = "{$actor->name}'s windup could not be broken — it is coming.";
                }
                break;

            case 'venture':
                // Crossing the frontier: the whole zone changes underfoot.
                if ($degree !== BeatOutcome::FAILURE) {
                    $scene->update(['state' => array_merge($scene->state ?? [], ['venture_zone_id' => $card['target']['id']])]);
                    $facts[] = $degree === BeatOutcome::PARTIAL
                        ? "They pushed past the edge of the known ground into {$targetName} — a harder crossing than hoped, and it cost them."
                        : "They left the old ground behind and crossed into {$targetName}.";
                    if ($degree === BeatOutcome::PARTIAL) {
                        Meters::damage($character, 1);
                    }
                } else {
                    $facts[] = "The way into {$targetName} defeated them this time — they remain where they were.";
                }
                break;

            case 'flee':
            case 'cross':
                if ($succeeded) {
                    $facts[] = $verb === 'flee'
                        ? "They made their escape through {$targetName}."
                        : "They crossed {$targetName} and left the old ground behind.";
                } elseif ($degree === BeatOutcome::PARTIAL) {
                    $facts[] = "They got through {$targetName}, but it cost them — battered and slowed on the far side.";
                    Meters::damage($character, 1);
                } else {
                    $facts[] = "The way through {$targetName} defeated them — they are still here.";
                }
                break;

            case 'break':
                $feature = $scene->allFeatures()->firstWhere('id', $card['target']['id']);
                if ($succeeded && $feature !== null) {
                    $feature->update(['state' => array_merge($feature->state ?? [], ['destroyed' => true])]);
                    $facts[] = "{$feature->name} gave way with a crack.";
                } else {
                    $facts[] = "{$targetName} held against the attempt.";
                }
                break;

            case 'lift':
                // A lift that lands ends with the thing HELD. It stops being
                // ground the moment it leaves the floor: the composer will
                // not offer it as scenery again until it is set down.
                $feature = $scene->allFeatures()->firstWhere('id', $card['target']['id'] ?? 0);
                $hands = Hands::handsFor((int) ($feature?->affordances['lift_weight'] ?? 0));
                if ($succeeded && $feature !== null && Hands::take($character, $feature->name, $feature->id, $hands)) {
                    $facts[] = $hands >= 2
                        ? "They got {$feature->name} up off the ground and held it, both arms full."
                        : "They took {$feature->name} up one-handed and kept hold of it.";
                } elseif ($succeeded) {
                    $facts[] = "They shifted {$targetName}, but had no hand free to keep hold of it.";
                } else {
                    $facts[] = "{$targetName} would not move.";
                }
                break;

            case 'ride':
                $facts[] = $succeeded
                    ? "They committed to {$targetName} and were carried clean across the scene."
                    : "{$targetName} bucked them off early — a hard landing, but a survivable one.";
                if (! $succeeded) {
                    Meters::damage($character, 1);
                }
                break;

            case 'bandage':
                $heal = $degree === BeatOutcome::STRONG ? 3 : 2;
                Meters::heal($character, $heal);
                $facts[] = "They bound their wounds (+{$heal} health).";
                break;

            case 'loot':
                $defeated = $scene->actors()->whereIn('status', ['defeated', 'dead'])->count();
                $facts[] = $defeated > 0
                    ? 'They searched the fallen and took what was worth taking.'
                    : 'There was no one left worth searching.';
                break;

            case 'recover':
                // Going back for what a fumble tore out of their hands. The
                // item's granted capabilities come back with it, so the form
                // itself widens again the moment they have it.
                $feature = $scene->allFeatures()->firstWhere('id', $card['target']['id'] ?? 0);
                $dropped = $feature?->affordances['dropped_item'] ?? null;
                if ($dropped === null) {
                    $facts[] = 'There was nothing of theirs there to take up.';
                    break;
                }
                if ($succeeded) {
                    $character->items()->updateExistingPivot($dropped['id'], ['equipped' => true]);
                    $feature->update(['state' => array_merge($feature->state ?? [], ['destroyed' => true])]);
                    $facts[] = "They got {$dropped['name']} back in hand — everything it gives them is theirs again.";
                } elseif ($degree === BeatOutcome::PARTIAL) {
                    $facts[] = "Their fingers found {$dropped['name']} and lost it again — it is still out there, still not theirs.";
                } else {
                    $facts[] = "{$dropped['name']} stayed exactly where it fell, out of reach.";
                }
                break;

            case 'recruit':
                $actor = Actor::find($card['target']['id']);
                if ($succeeded && $actor !== null) {
                    $tags = ($actor->tags ?? []) + ['loyalty' => 1];
                    $actor->update(['kind' => 'companion', 'tags' => $tags]);
                    $facts[] = "{$actor->name} fell in beside them — a companion now, walking the same tale.";
                } elseif ($degree === BeatOutcome::PARTIAL && $actor !== null) {
                    $tags = $actor->tags ?? [];
                    $tags['disposition'] = 'swayed';
                    $actor->update(['tags' => $tags]);
                    $facts[] = "{$actor->name} wavered — not yet, but the door is open.";
                } else {
                    $facts[] = "{$targetName} would not be drawn into it.";
                }
                break;

            case 'companion_block':
                $companion = Actor::find($card['target']['id']);
                $threat = $scene->visibleActors()->first(fn (Actor $a) => $a->kind === 'enemy');
                if ($companion === null || $threat === null) {
                    $facts[] = 'There was no one left to hold back.';
                    break;
                }
                if ($succeeded) {
                    $conditions['blocked'] = ['id' => $threat->id, 'full' => true];
                    $facts[] = "{$companion->name} planted themselves in {$threat->name}'s path — the way is held.";
                } elseif ($degree === BeatOutcome::PARTIAL) {
                    $conditions['blocked'] = ['id' => $threat->id, 'full' => false];
                    $facts[] = "{$companion->name} slowed {$threat->name}, but couldn't hold the line clean.";
                } else {
                    // The failed block costs the companion, not the player.
                    $stats = $companion->stats;
                    $stats['health']['current'] = max(0, ($stats['health']['current'] ?? 1) - 1);
                    $companion->update(['stats' => $stats]);
                    if ($stats['health']['current'] === 0) {
                        $companion->update(['status' => 'downed']);
                        $facts[] = "{$threat->name} went through {$companion->name} — the companion is down and out of the fight.";
                    } else {
                        $facts[] = "{$threat->name} shoved through {$companion->name}, who took the worst of it.";
                    }
                }
                break;

            case 'companion_flank':
                $companion = Actor::find($card['target']['id']);
                if ($succeeded && $companion !== null) {
                    $conditions['flanked'] = true;
                    $facts[] = "{$companion->name} circled wide — the threat now looks two ways.";
                } else {
                    $facts[] = ($companion?->name ?? 'The companion')." couldn't find the angle.";
                }
                break;

            case 'companion_strike':
                $companion = Actor::find($card['target']['id']);
                $enemy = $scene->visibleActors()->first(fn (Actor $a) => $a->kind === 'enemy');
                if ($companion === null || $enemy === null) {
                    $facts[] = 'There was no one left to fight.';
                    break;
                }
                $damage = match ($degree) {
                    BeatOutcome::STRONG => max(1, (int) ($companion->stats['attack'] ?? 1)) + 1,
                    BeatOutcome::SUCCESS => max(1, (int) ($companion->stats['attack'] ?? 1)),
                    BeatOutcome::PARTIAL => 1,
                    default => 0,
                };
                if ($damage > 0) {
                    $stats = $enemy->stats;
                    $stats['health']['current'] = max(0, $stats['health']['current'] - $damage);
                    $enemy->update(['stats' => $stats]);
                    if ($stats['health']['current'] === 0) {
                        $enemy->update(['status' => 'defeated']);
                        $facts[] = "{$companion->name}'s blow felled {$enemy->name}.";
                    } else {
                        $facts[] = "{$companion->name} wounded {$enemy->name} ({$stats['health']['current']}/{$stats['health']['max']} left).";
                    }
                } else {
                    // The failed attack costs the companion, not the player.
                    $stats = $companion->stats;
                    $stats['health']['current'] = max(0, ($stats['health']['current'] ?? 1) - 1);
                    $companion->update(['stats' => $stats]);
                    if ($stats['health']['current'] === 0) {
                        $companion->update(['status' => 'downed']);
                        $facts[] = "{$enemy->name} turned the attack back on {$companion->name} — the companion is down and out of the fight.";
                    } else {
                        $facts[] = "{$companion->name}'s attack went wide, and {$enemy->name}'s answer cut them.";
                    }
                }
                break;

            case 'companion_scout':
                $companion = Actor::find($card['target']['id']);
                if ($succeeded && $companion !== null) {
                    $scene->update(['state' => array_merge($scene->state ?? [], ['exit_scouted' => true])]);
                    $facts[] = "{$companion->name} found a way out — narrow, but real. It holds until the scene turns.";
                } else {
                    $facts[] = ($companion?->name ?? 'The companion').' searched but found no clean way out — yet.';
                }
                break;

            case 'hurl':
                // Two different things leave your hands this way, and the
                // TARGET says which. A held captive is thrown as a captive;
                // anything else aimed at while carrying something is the
                // carried thing going through the air. Reading the hands
                // first would have quietly turned every captive-throw into a
                // crate-throw the moment the player happened to be holding one.
                $captive = Actor::find($card['target']['id'] ?? 0);
                if (($captive === null || $captive->status !== 'restrained')
                    && Hands::held($character) !== []) {
                    $facts = array_merge($facts, $this->hurlCarried($card, $character, $scene, $degree, $succeeded));
                    break;
                }

                // Spending the captive as a weapon: however it lands, the
                // hold is over — success downs them, failure frees them.
                $other = $captive === null ? null : $scene->actors()
                    ->where('status', 'active')->where('kind', 'enemy')
                    ->where('id', '!=', $captive->id)->first();
                if ($succeeded && $captive !== null) {
                    $captive->update(['status' => 'defeated']);
                    if ($other !== null) {
                        $damage = $degree === BeatOutcome::STRONG ? 3 : 2;
                        $stats = $other->stats;
                        $stats['health']['current'] = max(0, $stats['health']['current'] - $damage);
                        $other->update(['stats' => $stats]);
                        if ($stats['health']['current'] === 0) {
                            $other->update(['status' => 'defeated']);
                            $facts[] = "They hurled {$captive->name} into {$other->name} — both went down and stayed down.";
                        } else {
                            $facts[] = "They hurled {$captive->name} into {$other->name}; the captive did not rise, and {$other->name} staggered ({$stats['health']['current']}/{$stats['health']['max']} left).";
                        }
                    } else {
                        $facts[] = "They hurled {$captive->name} aside — the captive went down hard and did not rise.";
                    }
                } elseif ($captive !== null) {
                    $captive->update(['status' => 'active']);
                    $facts[] = $degree === BeatOutcome::PARTIAL
                        ? "The throw went wrong — {$captive->name} twisted loose mid-swing and landed free."
                        : "{$captive->name} wrenched out of the grip as the throw began — the hold is lost.";
                }
                break;

            case 'improvise':
                // Base stats, no special bonus — never better than a real
                // enumerated option, so there's no incentive to game it. The
                // target only says what the gambit was aimed at; it buys the
                // attempt nothing.
                $aimed = ($card['target'] ?? null) !== null;
                $facts[] = match (true) {
                    $succeeded && $aimed => "The improvised gambit against {$targetName} worked — barely, and only through nerve.",
                    $succeeded => 'The improvised gambit worked — barely, and only through nerve.',
                    $aimed => "The improvisation against {$targetName} fell apart; the moment reasserted itself.",
                    default => 'The improvisation fell apart; the moment reasserted itself.',
                };
                break;

            default:
                $facts[] = $succeeded ? 'It worked.' : 'It failed.';
        }

        return $facts;
    }

    /**
     * @param  list<array>  $rolls  Out: one structured record per die the
     *                              scene actually cast, so the dice table can
     *                              show the enemy's roll instead of leaving
     *                              the player to infer it from the prose.
     * @return list<string>
     */
    private function enemyReaction(Character $character, Scene $scene, Dice $dice, array $conditions, bool $playerEscaped, array &$rolls): array
    {
        if ($playerEscaped || $character->fresh()->status !== 'alive') {
            return [];
        }

        $facts = [];
        $enemies = $scene->actors()->where('status', 'active')->where('kind', 'enemy')->get();

        foreach ($enemies as $enemy) {
            $tags = $enemy->tags ?? [];

            // A lurker hasn't entered the fight yet; it waits for its spring.
            if ($tags['lurking'] ?? false) {
                continue;
            }

            // A grudge under truce came to talk, not to swing. The truce
            // holds until it is answered — or until blood breaks it.
            if ($tags['truce'] ?? false) {
                $facts[] = "{$enemy->name} held to the truce, waiting on an answer.";

                continue;
            }

            $intent = $tags['intent'] ?? 'press';

            // Guard and circle are turns the enemy spends NOT attacking —
            // combat state that isn't hit points.
            if ($intent === 'guard') {
                $facts[] = "{$enemy->name} stayed behind their guard, giving nothing away.";

                continue;
            }
            if ($intent === 'circle') {
                $tags['angle'] = true;
                $enemy->update(['tags' => $tags]);
                $facts[] = "{$enemy->name} circled wide, working for an angle — and found one.";

                continue;
            }

            $blocked = $conditions['blocked'] ?? null;
            if ($blocked !== null && $blocked['id'] === $enemy->id && $blocked['full']) {
                $facts[] = "{$enemy->name} was held at bay and never reached them.";

                continue;
            }

            // What the enemy has to beat is the player's own footing, and the
            // player should be able to read which of their choices bought
            // which point of it — same itemized ledger the player's own beats
            // carry, from the other side of the swing.
            $dodgeParts = [['label' => 'Your footing', 'amount' => 12]];
            if ($conditions['elevated']) {
                $dodgeParts[] = ['label' => 'You hold the high ground', 'amount' => 3];
            }
            if ($conditions['concealed']) {
                $dodgeParts[] = ['label' => 'They cannot see you clearly', 'amount' => 3];
            }
            if ($conditions['readied']) {
                $dodgeParts[] = ['label' => 'You were set for it', 'amount' => 2];
            }
            if ($conditions['shielded']) {
                $dodgeParts[] = ['label' => 'Something is between you', 'amount' => 2];
            }
            if ($blocked !== null && $blocked['id'] === $enemy->id) {
                $dodgeParts[] = ['label' => 'Your companion is in their way', 'amount' => 3];
            }
            $dodge = array_sum(array_column($dodgeParts, 'amount'));

            $roll = $dice->d20();
            if ($conditions['time_slowed']) {
                $roll = min($roll, $dice->d20()); // the slowed world blunts them
            }
            $attackParts = [['label' => "{$enemy->name}'s reach", 'amount' => (int) ($enemy->stats['attack'] ?? 1)]];
            if ($tags['angle'] ?? false) {
                $attackParts[] = ['label' => 'They took their angle', 'amount' => 2];
            }
            if ($tags['ambush'] ?? false) {
                $attackParts[] = ['label' => 'Out of nowhere', 'amount' => 4];
            }
            $bonus = array_sum(array_column($attackParts, 'amount'));
            $attack = $roll + $bonus;

            // The scene rolls under the same two rules the player does: the
            // natural extremes overrule the arithmetic in both directions.
            $crit = BeatOutcome::critFor($roll);
            $hit = $crit !== null ? $crit === BeatOutcome::CRIT_SUCCESS : $attack >= $dodge;

            // The angle and the ambush spring are spent in the using.
            if (($tags['angle'] ?? false) || ($tags['ambush'] ?? false)) {
                unset($tags['angle'], $tags['ambush']);
                $enemy->update(['tags' => $tags]);
            }

            $record = [
                'actor' => $enemy->name,
                'kind' => 'enemy',
                'verb' => 'attack',
                'label' => match ($intent) {
                    'windup' => 'The heavy blow',
                    default => 'Presses the attack',
                },
                'roll' => $roll,
                'total' => $attack,
                'difficulty' => $dodge,
                'crit' => $crit,
                'difficulty_parts' => $dodgeParts,
                'bonus_parts' => $attackParts,
            ];

            if ($hit) {
                $damage = max(1, (int) ($enemy->stats['attack'] ?? 1));
                if ($intent === 'windup') {
                    $damage += 2; // the heavy blow the telegraph promised
                }
                if ($conditions['braced']) {
                    $damage = max(0, $damage - 2);
                }
                if ($crit === BeatOutcome::CRIT_SUCCESS) {
                    // Nothing brushes off a natural 20 — the brace bends but
                    // it does not stop this one.
                    $damage = max(2, $damage * 2);
                }
                if ($damage === 0) {
                    $facts[] = "{$enemy->name}'s blow landed on braced guard — nothing got through.";
                    $rolls[] = $record + ['degree' => BeatOutcome::PARTIAL, 'outcome' => 'Braced — nothing got through'];

                    continue;
                }
                $crown = $crit === BeatOutcome::CRIT_SUCCESS ? 'CRITICAL HIT: ' : '';
                $shield = $conditions['shielded'] ? Actor::find($conditions['shield_actor_id'] ?? 0) : null;
                if ($shield !== null && $shield->status === 'restrained') {
                    // The captive absorbs the blow meant for the player.
                    $stats = $shield->stats;
                    $stats['health']['current'] = max(0, $stats['health']['current'] - $damage);
                    $shield->update(['stats' => $stats]);
                    if ($stats['health']['current'] === 0) {
                        $shield->update(['status' => 'defeated']);
                        $facts[] = "{$crown}{$enemy->name} struck — but the blow found {$shield->name}, held in the way, and the captive crumpled.";
                    } else {
                        $facts[] = "{$crown}{$enemy->name} struck — {$shield->name}, held in the way, took the blow meant for them.";
                    }
                    $rolls[] = $record + ['degree' => BeatOutcome::SUCCESS, 'outcome' => "{$shield->name} took the blow — {$damage} damage"];
                } else {
                    Meters::damage($character, $damage);
                    $facts[] = "{$crown}{$enemy->name} answered and drew blood ({$damage} damage).";
                    $rolls[] = $record + ['degree' => BeatOutcome::STRONG, 'outcome' => "Drew blood — {$damage} damage"];
                }
            } elseif ($crit === BeatOutcome::CRIT_FAILURE) {
                $facts[] = "CRITICAL MISS: {$enemy->name} committed everything to the blow, missed by a mile, and left themselves wide open.";
                // The overreach is the player's opening: the enemy spends the
                // next turn recovering, and the cards will read that windup.
                $tags['intent'] = 'windup';
                $tags['overreached'] = true;
                $enemy->update(['tags' => $tags]);
                $rolls[] = $record + ['degree' => BeatOutcome::FAILURE, 'outcome' => 'Overreached and left themselves open'];
            } else {
                $facts[] = "{$enemy->name} pressed in but found nothing to hit.";
                $rolls[] = $record + ['degree' => BeatOutcome::FAILURE, 'outcome' => 'Found nothing to hit'];
            }
        }

        return $facts;
    }

    /**
     * The grapple clock: captives held since before this turn strain against
     * the hold, and sometimes wrench loose. Elites escape more easily.
     *
     * @param  list<int>  $heldBefore
     * @param  list<array>  $rolls  Out: the struggle's die, for the dice table.
     * @return list<string>
     */
    private function captiveStruggle(Scene $scene, Dice $dice, array $heldBefore, array &$rolls): array
    {
        $facts = [];

        $captives = $scene->actors()->where('status', 'restrained')->whereIn('id', $heldBefore)->get();
        foreach ($captives as $captive) {
            // A hold won on a natural 20 is absolute. It spends itself here:
            // they get one clean turn out of it, not a permanent grip.
            if ($captive->tags['pinned'] ?? false) {
                $tags = $captive->tags;
                unset($tags['pinned']);
                $captive->update(['tags' => $tags]);
                $facts[] = "{$captive->name} strained against the hold and found no give in it at all.";

                continue;
            }

            $roll = $dice->d20();
            $total = $roll + ($captive->tier === 'elite' ? 4 : 0);
            $crit = BeatOutcome::critFor($roll);
            $free = $crit !== null ? $crit === BeatOutcome::CRIT_SUCCESS : $total >= 16;

            $record = [
                'actor' => $captive->name,
                'kind' => 'captive',
                'verb' => 'struggle',
                'label' => 'Strains against the hold',
                'roll' => $roll,
                'total' => $total,
                'difficulty' => 16,
                'crit' => $crit,
            ];

            if ($free) {
                $captive->update(['status' => 'active']);
                $crown = $crit === BeatOutcome::CRIT_SUCCESS ? 'CRITICAL BREAK: ' : '';
                $facts[] = "{$crown}{$captive->name} wrenched free of the hold and is loose again.";
                $rolls[] = $record + ['degree' => BeatOutcome::STRONG, 'outcome' => 'Wrenched free — loose again'];
            } else {
                if ($crit === BeatOutcome::CRIT_FAILURE) {
                    $facts[] = "CRITICAL FUMBLE: {$captive->name} threw everything into breaking the hold, and the grip only closed tighter for it.";
                }
                $rolls[] = $record + ['degree' => BeatOutcome::FAILURE, 'outcome' => 'The hold held'];
            }
        }

        return $facts;
    }

    /**
     * Ambushes laid on an earlier turn spring now: the lurker steps out with
     * the drop (+4 on its opening attack) unless detect already exposed it.
     * A fresh lurker always survives the turn it slipped in — the dread has
     * a beat to breathe.
     *
     * @return array{0: ?Actor, 1: list<string>}
     */
    private function springAmbushes(Scene $scene, Turn $turn): array
    {
        $facts = [];
        $sprung = null;

        $lurkers = $scene->actors()->where('status', 'active')->get()
            ->filter(fn (Actor $a) => ($a->tags['lurking'] ?? false) && ($a->tags['lurking_since'] ?? 0) < $turn->number);

        foreach ($lurkers as $lurker) {
            $tags = $lurker->tags;
            unset($tags['lurking'], $tags['lurking_since']);
            $tags['ambush'] = true;
            $lurker->update(['tags' => $tags]);
            $facts[] = "{$lurker->name} burst from hiding — the ambush is sprung.";
            $sprung ??= $lurker;
        }

        return [$sprung, $facts];
    }

    /**
     * Telegraphs for the coming turn: each active enemy commits to an intent
     * the player will see on their cards — press, a heavy windup, a tight
     * guard, or circling for an angle. Combat state that is not hit points.
     */
    private function rollEnemyIntents(Scene $scene, Dice $dice): void
    {
        $enemies = $scene->actors()->where('status', 'active')->where('kind', 'enemy')->get();

        foreach ($enemies as $enemy) {
            $tags = $enemy->tags ?? [];
            if (($tags['lurking'] ?? false) || ($tags['truce'] ?? false)) {
                continue;
            }

            // An enemy who fumbled their swing spends the next beat hauling
            // themselves back upright — the opening they left is real, and
            // the cards will read it as the windup it is.
            if ($tags['overreached'] ?? false) {
                unset($tags['overreached']);
                $tags['intent'] = 'windup';
                $enemy->update(['tags' => $tags]);

                continue;
            }

            // A won angle is pressed home, not squandered on a new feint.
            $tags['intent'] = ($tags['angle'] ?? false) ? 'press' : match ($dice->between(1, 6)) {
                4 => 'windup',
                5 => 'guard',
                6 => 'circle',
                default => 'press',
            };
            $enemy->update(['tags' => $tags]);
        }
    }

    private function maybeIntroduceThreat(Scene $scene, Dice $dice, Turn $turn, bool $forced = false): ?Actor
    {
        $hostilities = $scene->actors()->where('status', 'active')->where('kind', 'enemy')->exists();
        $chance = $hostilities ? 0.12 : 0.05;

        if (! $forced && ! $dice->chance($chance)) {
            return null;
        }

        $template = Actor::whereNull('scene_id')
            ->where('zone_id', $scene->zone_id)
            ->where('status', 'active')
            ->inRandomOrder()
            ->first();

        if ($template === null) {
            return null;
        }

        // An alarm answered arrives in the open, shouting. An unforced
        // arrival sometimes slips in unseen instead — an ambush in the
        // making, invisible to cards and narration until it springs.
        $tags = $template->tags ?? [];
        if (! $forced && $template->kind === 'enemy' && $dice->chance(0.4)) {
            $tags['lurking'] = true;
            $tags['lurking_since'] = $turn->number;
        }

        return Actor::create([
            'scene_id' => $scene->id,
            'zone_id' => $scene->zone_id,
            'name' => $template->name,
            'kind' => $template->kind,
            'tier' => $template->tier,
            'stats' => $template->stats,
            'tags' => $tags,
            'status' => 'active',
            'source' => $template->source,
            'evolution_run_id' => $template->evolution_run_id,
        ]);
    }

    private function evaluateTrigger(Character $character, Scene $scene, array $outcomes, bool $moved, ?Actor $newThreat, bool $wasInDanger): BranchTrigger
    {
        if ($newThreat !== null) {
            return BranchTrigger::NewThreat;
        }

        if ($character->status !== 'alive' || (! $wasInDanger && Meters::healthInDangerBand($character))) {
            return BranchTrigger::ResourceThreshold;
        }

        if ($moved) {
            return BranchTrigger::SceneTransition;
        }

        $hadEnemies = $scene->actors()->where('kind', 'enemy')->exists();
        $enemiesLeft = $scene->actors()->where('kind', 'enemy')->where('status', 'active')->exists();

        if ($hadEnemies && ! $enemiesLeft) {
            return BranchTrigger::IntentComplete;
        }

        if ($enemiesLeft) {
            return BranchTrigger::MeaningfulFork; // combat continues: the fork is real
        }

        $anyFailure = collect($outcomes)->contains(fn (BeatOutcome $o) => ! $o->skipped && ! $o->succeeded());

        return $anyFailure ? BranchTrigger::FailurePivot : BranchTrigger::SoftTimeout;
    }

    /**
     * Movement earns real novelty: the next scene is a named locale dressed
     * with its own draw of the zone's features (some hidden, waiting to be
     * found) and its own inhabitants — never a copy of the ground just left.
     */
    private function transitionScene(Scene $scene, Dice $dice, Turn $turn): Scene
    {
        $scene->update(['status' => 'past']);

        // A venture crosses into the pre-forged frontier zone; ordinary
        // movement stays inside the current one.
        $zone = $scene->zone;
        $ventureZone = Zone::find($scene->state['venture_zone_id'] ?? 0);
        if ($ventureZone !== null && $scene->campaign?->next_zone_id === $ventureZone->id) {
            $zone = $ventureZone;
            $scene->campaign->update(['next_zone_id' => null]);
        }

        $locale = $this->dresser->locale($zone, $dice, exclude: $scene->title);

        $next = Scene::create([
            'campaign_id' => $scene->campaign_id,
            'zone_id' => $zone->id,
            'title' => $locale['title'],
            'description' => $locale['description'],
            'status' => 'active',
            'state' => ['dressed' => true],
        ]);

        // Thin on purpose. Ground that arrives already crowded with five
        // props and two strangers reads as a set rather than a place, and it
        // leaves the world nowhere to go: things should be able to turn up
        // over time, which they cannot if everything is there on arrival.
        $this->dresser->instantiateFeatures($next, $dice, 1, 3);

        // A pursuit arrives where the trail ends: the tracked quarry stands
        // cornered in the new scene. Otherwise the ground rolls its own
        // inhabitants — most often nobody at all. An empty room is a real
        // reading of a place, and the alarm clock and the wandering-threat
        // roll both still bring company when the scene earns it.
        $quarry = Actor::find($scene->state['pursuit_actor_id'] ?? 0);
        if ($quarry !== null && $quarry->status === 'fled') {
            $tags = $quarry->tags ?? [];
            $tags['cornered'] = true;
            $quarry->update(['scene_id' => $next->id, 'status' => 'active', 'tags' => $tags]);
            // A cornered quarry with a name the tale remembers IS the grudge
            // returning — by the player's hand instead of the dice's.
            Grudges::recordCornered($quarry, $next, $turn);
        } else {
            if ($dice->chance(0.55)) {
                $this->dresser->spawnActors($next, $dice, 1, 2);
            }

            // The tale's memory rolls for its moment: a simmering grudge may
            // walk back in — vengeful in the open, wary from hiding, or
            // scheming under truce. At most one per scene, and never on the
            // heels of a pursuit that already delivered its figure.
            if ($next->campaign !== null) {
                Grudges::maybeReturn($next, $next->campaign, $dice, $turn);
            }
        }

        // Companions walk the tale, not the scene: they come along.
        $scene->actors()->where('kind', 'companion')->where('status', 'active')
            ->update(['scene_id' => $next->id]);

        // New ground, new air — one roll, kept for as long as this scene lasts.
        // Last, so the draws above keep the exact stream they have always had.
        $this->dresser->rollAmbient($next, $dice);

        return $next;
    }

    private function openNextTurn(Turn $turn, Character $character, Scene $scene, BranchTrigger $trigger): Turn
    {
        $character = $character->fresh();
        $scene = $scene->fresh();
        $cards = $this->composer->compose($character, $scene);

        // One board, two readings: the grouped bullets the player reads and
        // the paragraph the narrator reads are compiled from the same facts,
        // so the page and the chapter can never disagree about who is here.
        $board = SituationBoard::for($character, $scene, $trigger);

        return Turn::create([
            'campaign_id' => $turn->campaign_id,
            'scene_id' => $scene->id,
            'number' => $turn->number + 1,
            'status' => Turn::STATUS_AWAITING,
            'situation' => SituationBoard::prose($board),
            'situation_board' => $board,
            'cards' => $cards,
            // How the wait ahead may be spent. Offered here and chosen after,
            // on the resolved-turn screen: the pick can never gate or delay a
            // resolution, because the turn it belongs to is already open.
            'downtime' => Downtime::offer($scene),
        ]);
    }

    private function sceneSummary(Scene $scene): string
    {
        $features = $scene->visibleFeatures()->pluck('name')->join(', ');
        $actors = $scene->visibleActors()->pluck('name')->join(', ');

        return trim(($features !== '' ? "Nearby: {$features}." : '').' '.($actors !== '' ? "Present: {$actors}." : ''));
    }
}
