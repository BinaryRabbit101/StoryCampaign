<?php

namespace App\Game\Engine;

use App\Game\BranchTrigger;
use App\Game\Hands;
use App\Game\Meters;
use App\Game\Verb;
use App\Models\Actor;
use App\Models\Character;
use App\Models\Scene;
use App\Models\Turn;
use App\Models\Zone;
use App\Services\Mementos;
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
                // Everywhere this body has already been. The cards were priced
                // against the same list a turn ago, so an old wound charges the
                // die exactly what the card said it would.
                'scars' => Scars::names($character),
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

            // Everything about who is walking beside them that this turn ends
            // up having to say: the fire before it, a blow somebody stepped in
            // front of, a joining, a parting, a loss. Collected as it happens
            // and handed to the narrator as plain facts — never as a number.
            $companionEvents = array_filter(['campfire' => $downtime['campfire']]);

            // Captives held before this turn may struggle free at its end —
            // a fresh grip always survives the turn it was won.
            $heldBefore = $scene->actors()->where('status', 'restrained')->pluck('id')->all();

            // Whoever was already gone when the turn began: only a flight
            // that happens THIS turn writes into the tale's memory.
            $fledBefore = $scene->actors()->where('status', 'fled')->pluck('id')->all();

            // Two of the moments the tale keeps a keepsake from are read from
            // nothing but a status that changed across this turn: the elites
            // standing at the top of it, and anyone in a grip who is not an
            // enemy — a captive, held. Both are captured here and compared at
            // the end; neither is read by anything mechanical.
            $elitesBefore = $scene->actors()->where('kind', 'enemy')->where('tier', 'elite')
                ->whereIn('status', ['active', 'fled'])->pluck('id')->all();
            $captivesBefore = $scene->actors()->where('status', 'restrained')
                ->where('kind', '!=', 'enemy')->pluck('id')->all();

            // And whoever was walking beside them when the turn began. Read the
            // same way: compared at the end, and a name that is not on their
            // feet or on the floor any more was lost for good somewhere in it.
            $companionsBefore = Companions::beside($scene)->pluck('id')->all();

            $outcomes = [];
            $trigger = null;
            $moved = false;
            $wasInDanger = Meters::healthInDangerBand($character);

            // What the player set themselves to, and what came of it. Only the
            // FINISH is collected here — a tick is the board's business and the
            // goal line covers the middle, so an endeavor half done leaves the
            // chapter carrying no instructions about it.
            $endeavor = [];
            $endeavorFilled = null;

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

                // The endeavor, moved by the beat that just landed — only if
                // the clock named this verb, and only if the die did not
                // simply fail. The clock's own row decides both, which is the
                // same row the card quoted before the commit.
                $tick = Clocks::advance($scene, $outcome, $conditions);
                $endeavor = array_merge($endeavor, $tick['facts']);
                $endeavorFilled ??= $tick['filled'];

                // Partial counts: the facts say they got through (battered),
                // so the scene must actually change under them.
                if (in_array($card['verb'], [
                    Verb::Flee->value, Verb::Cross->value,
                    Verb::Track->value, Verb::Venture->value,
                ], true)
                    && $outcome->degree !== BeatOutcome::FAILURE) {
                    $moved = true;
                }
            }

            // A soul keeping to the edge of the scene has now watched something
            // of theirs actually work. That is the only thing that turns a
            // stray into somebody who might ask to stay — a stranger who has
            // seen you fail at everything has no reason to.
            Companions::witness($scene, collect($outcomes)->contains(
                fn (BeatOutcome $o) => $o->slot === 'main' && ! $o->skipped && $o->succeeded(),
            ));

            // An ambush laid on an earlier turn springs before the scene
            // answers — unless detect already dragged it into the light.
            [$sprung, $springFacts] = $this->springAmbushes($scene, $turn);

            // The scene answers: active enemies react to the player's beats,
            // and long-held captives strain against the grip.
            // The scene's dice are kept as data as well as prose: the dice
            // table shows the player what the world rolled against them, and
            // a die nobody can see is a die the player has to take on faith.
            $reactionRolls = [];
            $enemyFacts = array_merge($springFacts, $this->enemyReaction($character, $scene, $dice, $conditions, $moved, $reactionRolls, $turn, $companionEvents));
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

            // The world's own impatience with stillness.
            //
            // The alarm above owns escalation while a fight is on; this owns the
            // quiet, which is where a player who reads a telegraph correctly and
            // holds still used to be met with nothing at all, twice running. It
            // reads the room the way the PLAYER reads it — nothing standing in
            // the open — because a lurker is not a fight, and a scene holding one
            // is exactly the withheld world that ought to be able to spend itself.
            $openThreat = $scene->visibleActors()->contains(fn (Actor $a) => $a->kind === 'enemy');
            $stall = Pressure::tick(
                $scene,
                quiet: ! $openThreat && ! $moved
                    && collect($outcomes)->every(fn (BeatOutcome $o) => $o->roll === 0),
                waited: collect($outcomes)->contains(
                    fn (BeatOutcome $o) => $o->slot === 'main' && ! $o->skipped && $o->verb === 'wait',
                ),
            );

            $omen = Pressure::omen($stall);
            $world = $omen === null ? null : [$omen];
            $pressed = null;

            if (Pressure::armed($stall)) {
                // A beat that cannot land here is never in the pool, and an empty
                // pool holds the counter where it is: the world waits for a turn
                // it has something real to spend, rather than inventing one.
                $pressed = Pressure::fire($scene, $character, $turn, $dice,
                    fn () => $this->maybeIntroduceThreat($scene, $dice, $turn, forced: true));

                if ($pressed !== null) {
                    Pressure::spend($scene);
                    $world = $pressed['facts'];
                }
            }

            // Living-world texture: a zone-level actor may join mid-scene —
            // in the open, or lurking until detect or its own moment. Whoever
            // pressure just walked through the door counts as that arrival, so
            // the trigger ladder below reads it as a new threat rather than as
            // a quiet turn that timed out.
            $newcomer = $pressed['arrival'] ?? null;
            if ($newcomer === null && ! $moved && ($trigger === null || $forced)) {
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

            // The floor.
            //
            // Health at zero used to write `downed` and stop there, which made
            // the highest-stakes moment in the game a dead end. It branches
            // here instead: the character survives it carrying an engine-rolled
            // permanent mark, wakes on safe adjacent ground at half health, and
            // the tale bends around the recovery. Past the cap, it ends — and
            // the book closes on the fall's own chapter, once that chapter has
            // been written.
            $fall = ($character->status === 'downed' && $scene->campaign_id !== null)
                ? Scars::takeFall($character, $scene, $turn, $dice, $outcomes, $reactionRolls, $this->dresser)
                : null;

            $trigger = $fall !== null
                ? BranchTrigger::ResourceThreshold
                : ($trigger ?? $this->evaluateTrigger($character, $scene, $outcomes, $moved, $newThreat, $wasInDanger));

            $sceneBefore = $scene;
            if ($fall !== null) {
                // Whatever the turn was doing, the fall is what happened. The
                // waking already moved them (or ended them); nothing else may
                // transition the scene out from under it.
                if ($fall['scene'] !== null) {
                    $scene = $fall['scene'];
                    $moved = true;
                    // The waking is a change of ground like any other, and an
                    // endeavor left behind on the floor they fell on has to be
                    // let go of here too — otherwise the one-at-a-time rule
                    // would leave the tale unable to take anything else on.
                    Clocks::onSceneExit($sceneBefore, $scene);
                }
            } elseif ($moved) {
                $scene = $this->transitionScene($scene, $dice, $turn, $companionEvents);
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

            // And the tale's memory keeps the worst of it: any old score
            // standing over them when they went down remembers doing it, and
            // says so the next time the two meet.
            if ($fall !== null) {
                Grudges::recordDowning($sceneBefore, $turn);
            }

            // Companions the road provided, rather than ones the player asked
            // for. Both paths end the same way — a consensual offer pair on the
            // next turn's cards — and both stay silent while the party is full.
            // The grateful path reads a genuine rescue out of the turn's own
            // facts; the stray path reads a soul who has now walked far enough
            // and seen enough to ask.
            Companions::maybeOfferGrateful($scene, $dice, $this->rescuedThisTurn($captivesBefore))
                ?? Companions::maybeOfferStray($scene);

            // The signature, picked once, the moment a bond first reaches
            // fellow — with this turn's own seeded stream, so a re-resolved
            // turn hands the same companion the same trick.
            foreach (Companions::present($scene) as $companion) {
                Companions::ensureSignature($companion, $dice);
            }

            // What the player's own beats settled about who walks with them.
            foreach ($outcomes as $outcome) {
                $key = match ($outcome->skipped ? null : $outcome->verb) {
                    Verb::CompanionWelcome->value => 'joined',
                    Verb::CompanionDismiss->value => 'parted',
                    default => null,
                };
                if ($key !== null) {
                    $companionEvents[$key] = array_merge($companionEvents[$key] ?? [], $outcome->facts);
                }
            }

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
                    // Where they fell, what it cost them permanently, and where
                    // they came round. Null on any turn nobody went down, so an
                    // ordinary chapter carries no instructions about a body
                    // that is fine.
                    'fall' => $fall['record'] ?? null,
                    // What passed between them and whoever walks beside them —
                    // the fire before this vignette, a blow somebody stepped in
                    // front of, a joining, a parting, a loss. Null on the many
                    // turns none of that happened, and never a number.
                    'companions' => $companionEvents === [] ? null : $companionEvents,
                    // What this place did while nobody made it do anything — an
                    // arrival, an accident, something it had been keeping back.
                    // Null on every turn the stillness was not building toward
                    // one, so an ordinary chapter carries no instructions about
                    // a world that stayed where it was.
                    'world' => $world,
                    // The endeavor the player committed to, FINISHED. Null on
                    // every turn it merely moved — the board carries the count
                    // and the narrator carries the goal, and a chapter told to
                    // announce a tally every page has stopped being a chapter.
                    'endeavor' => $endeavor === [] ? null : $endeavor,
                ],
                'branch_trigger' => $trigger->value,
                'meters_snapshot' => $character->meters,
                'resolved_at' => now(),
            ]);

            // What this turn leaves on the shelf.
            //
            // The facts are final above, so the moment is settled: the engine
            // reads the notable ones straight out of them and hands them
            // outward. It is minted NOW, not at narration, so the keepsake
            // survives an evening Claude is down — and the engine keeps no
            // reference to what came back, because a memento is memory and
            // must never be able to reach the mechanics that made it.
            Mementos::mint($turn, $this->mementoTriggers(
                $turn, $sceneBefore, $scene, $elitesBefore, $captivesBefore, $companionsBefore,
                $fall, $reactionRolls, $endeavorFilled,
            ));

            // The tale that ran out of body. No next turn is opened — there is
            // nothing left to choose — and the book closes behind the fall's
            // own chapter, in the narration run that writes it.
            if ($fall !== null && $fall['record']['final']) {
                return $turn->fresh();
            }

            return $this->openNextTurn($turn, $character, $scene, $trigger, $dice);
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
            $health = (int) ($companion->stats['health']['current'] ?? 0);

            $outcome = $this->resolveBeat($card, $choice, $character, $scene, $dice, $conditions, $turn);
            $outcomes[] = $outcome;
            $conditions['prior_failure'] = $playerFailure;

            // The bond moves on the facts this beat just fixed, and on nothing
            // else. A request that landed is one more reason to trust them; a
            // request that got them hurt is a dent, not a break — and the dent
            // is never keyed, because getting them hurt costs every time.
            $companion = $companion->fresh();
            if ((int) ($companion->stats['health']['current'] ?? 0) < $health) {
                Companions::nudge($companion, -1);
            } elseif ($outcome->succeeded()) {
                Companions::nudge($companion, 1, $turn, 'assist');
            }
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
            $legal = $card['verb'] === Verb::Track->value ? ['fled'] : ['active', 'restrained'];
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
        if ($card['verb'] === Verb::Lift->value && Hands::free($character) < 1) {
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
        $bonusLedger = Odds::bonus($conditions, $verb, $card['slot'], $card['bargain']['key'] ?? null);
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

        // The deal, settled. The edge was already spent above — it rode into
        // the difficulty and the bonus as one more itemized line — and the
        // price falls here, the instant the beat is done, whether the roll
        // landed or not. Wrenching a gate open is loud even when it works, and
        // a complication that only bites on a failure is the `risky` stance
        // wearing a different name.
        if (($card['bargain'] ?? null) !== null) {
            $facts = array_merge($facts, Bargains::pay($card, $character, $scene, $conditions));
        }

        // The chosen attack form is narration color only: it never touched
        // the roll above. It rides along as a resolved fact so the narrator
        // (and the reader's event panel) know HOW the blow took shape.
        $method = $choice['modifiers']['method'] ?? null;
        if ($verb === Verb::Strike->value && is_string($method) && $method !== '' && $method !== 'unspecified') {
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

        // The catalog, not the raw string: a verb the composer emits and this
        // switch has never heard of falls straight through to the quiet
        // default, which is the drift the Verb enum exists to make impossible.
        switch (Verb::tryFrom($card['verb'])) {
            case Verb::Bargain:
                // Taking a returned grudge's terms. Roll-free — the deal was
                // theirs to offer — and its whole mechanical content is the
                // closed list the engine picked at their return.
                $facts = Grudges::strikeBargain($card, $scene, $turn);
                break;
            case Verb::Undertake:
                // Setting yourself to a multi-turn goal. Roll-free, and the
                // whole proposal is recomputed from the scene rather than
                // carried on the card — so a submission can never smuggle in
                // terms the ground does not currently afford.
                $facts = Clocks::commit($scene);
                break;
            case Verb::Abandon:
                // Free, and never a dead choice: what it buys back is room to
                // take something else on. Everything already done is lost —
                // that loss IS the commitment the clock was worth.
                $facts = Clocks::abandon($scene, $card['target']['id'] ?? null);
                break;
            case Verb::TimeSlow:
                $conditions['time_slowed'] = true;
                $facts[] = 'A time-slow charge was spent; the coming moments stretch in their favor.';
                break;
            case Verb::Haste:
                $conditions['hastened'] = true;
                $facts[] = 'A haste charge was spent; they move ahead of the world.';
                break;
            case Verb::Ready:
                $conditions['readied'] = true;
                $facts[] = 'They set themselves, waiting for the precise instant.';
                break;
            case Verb::Examine:
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
            case Verb::Inspect:
                // Reading one thing properly, rather than the whole scene:
                // what it is and what it would give, in plain sight-language.
                $feature = $scene->allFeatures()->firstWhere('id', $card['target']['id'] ?? 0);
                $facts[] = $feature === null
                    ? 'What they went to look at was no longer there to look at.'
                    : "They read {$feature->name} closely. ".implode(' ', $feature->readings());
                break;
            case Verb::Wait:
                $facts[] = 'They held still and let the scene move first.';
                break;
            case Verb::CatchBreath:
                $facts[] = 'They took a slow breath and steadied themselves.';
                break;
            case Verb::Reposition:
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
            case Verb::Brace:
                $conditions['braced'] = true;
                $facts[] = 'They set themselves against the blow they saw coming.';
                break;
            case Verb::Command:
                $conditions['commanded'] = true;
                $facts[] = 'They called the play, and their companions moved with borrowed precision.';
                break;
            case Verb::Shield:
                $conditions['shielded'] = true;
                $conditions['shield_actor_id'] = $card['target']['id'] ?? null;
                $facts[] = 'They kept their captive between themselves and the danger.';
                break;
            case Verb::CompanionWelcome:
                // The answer to somebody who already asked. Roll-free, and it
                // has to be: the other half of the consent has been given, and
                // a die that could refuse a yes would be the engine overruling
                // both parties at once.
                $asker = Actor::find($card['target']['id'] ?? 0);
                if ($asker === null || $asker->status !== 'active' || ! isset($asker->tags['offering'])) {
                    $facts[] = 'Whoever was asking had already moved on.';
                    break;
                }
                if (Companions::atCap($scene)) {
                    $facts[] = "There was no room beside them for {$asker->name}, and both of them knew it.";
                    break;
                }
                $via = $asker->tags['offering'];
                Companions::join($asker, $via);
                $facts[] = "{$asker->name} fell in beside them — a companion now, walking the same tale.";
                break;

            case Verb::CompanionDismiss:
                // Parting is never a dead choice: they go, and they leave one
                // true thing behind. Colour, and nothing the engine will ever
                // charge or credit anyone for.
                $leaving = Actor::find($card['target']['id'] ?? 0);
                if ($leaving === null || $leaving->status !== 'active') {
                    $facts[] = 'Whoever was asking had already moved on.';
                    break;
                }
                $tags = $leaving->tags ?? [];
                unset($tags['offering'], $tags['following'], $tags['stray_scenes'], $tags['witnessed']);
                $leaving->update(['status' => 'departed', 'tags' => $tags]);
                $facts[] = "They thanked {$leaving->name} and sent them on their way.";
                $facts[] = Companions::partingGift($leaving);
                break;

            case Verb::Drop:
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

        $word = Verb::tryFrom($verb);

        switch ($word) {
            case Verb::Ascend:
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

            case Verb::Strike:
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

            case Verb::Intimidate:
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

            case Verb::Restrain:
            case Verb::Haul:
                $actor = Actor::find($card['target']['id']);
                if ($succeeded && $actor !== null) {
                    $actor->update(['status' => 'restrained']);
                    $facts[] = $word === Verb::Haul
                        ? "They seized {$actor->name} and swung skyward, hauling their captive with them."
                        : "{$actor->name} is bound and out of the fight.";
                    if ($word === Verb::Haul) {
                        $conditions['elevated'] = true;
                        $scene->update(['state' => array_merge($scene->state ?? [], ['elevated' => true])]);
                    }
                } elseif ($degree === BeatOutcome::PARTIAL && $actor !== null) {
                    $facts[] = "They grappled {$actor->name} but couldn't finish the hold — the struggle continues.";
                } else {
                    $facts[] = "{$targetName} slipped the grapple entirely.";
                }
                break;

            case Verb::Persuade:
            case Verb::Deceive:
            case Verb::Calm:
                $actor = Actor::find($card['target']['id']);
                if ($succeeded && $actor !== null) {
                    $tags = $actor->tags ?? [];
                    $tags['disposition'] = $word === Verb::Calm ? 'calmed' : 'swayed';
                    if ($actor->kind === 'enemy') {
                        $tags['fled_how'] = 'talked';
                    }
                    $actor->update(['tags' => $tags, 'status' => $actor->kind === 'enemy' ? 'fled' : $actor->status]);
                    $facts[] = "Words landed: {$actor->name} was ".($word === Verb::Calm ? 'calmed' : 'won over').'.';
                } else {
                    $facts[] = "The words found no purchase on {$targetName}.";
                }
                break;

            case Verb::Speak:
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

            case Verb::Hide:
                if ($succeeded) {
                    $conditions['concealed'] = true;
                    $facts[] = "They folded into the cover of {$targetName}, unseen.";
                } else {
                    $facts[] = "The cover of {$targetName} was poorer than it looked — they are spotted.";
                }
                break;

            case Verb::Scout:
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

            case Verb::Detect:
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

            case Verb::Track:
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

            case Verb::Interrupt:
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

            case Verb::Venture:
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

            case Verb::Flee:
            case Verb::Cross:
                if ($succeeded) {
                    $facts[] = $word === Verb::Flee
                        ? "They made their escape through {$targetName}."
                        : "They crossed {$targetName} and left the old ground behind.";
                } elseif ($degree === BeatOutcome::PARTIAL) {
                    $facts[] = "They got through {$targetName}, but it cost them — battered and slowed on the far side.";
                    Meters::damage($character, 1);
                } else {
                    $facts[] = "The way through {$targetName} defeated them — they are still here.";
                }
                break;

            case Verb::Break:
                $feature = $scene->allFeatures()->firstWhere('id', $card['target']['id']);
                if ($succeeded && $feature !== null) {
                    $feature->update(['state' => array_merge($feature->state ?? [], ['destroyed' => true])]);
                    $facts[] = "{$feature->name} gave way with a crack.";
                } else {
                    $facts[] = "{$targetName} held against the attempt.";
                }
                break;

            case Verb::Lift:
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

            case Verb::Ride:
                $facts[] = $succeeded
                    ? "They committed to {$targetName} and were carried clean across the scene."
                    : "{$targetName} bucked them off early — a hard landing, but a survivable one.";
                if (! $succeeded) {
                    Meters::damage($character, 1);
                }
                break;

            case Verb::Bandage:
                $heal = $degree === BeatOutcome::STRONG ? 3 : 2;
                Meters::heal($character, $heal);
                $facts[] = "They bound their wounds (+{$heal} health).";
                break;

            case Verb::Loot:
                $defeated = $scene->actors()->whereIn('status', ['defeated', 'dead'])->count();
                $facts[] = $defeated > 0
                    ? 'They searched the fallen and took what was worth taking.'
                    : 'There was no one left worth searching.';
                break;

            case Verb::Recover:
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

            case Verb::Recruit:
                $actor = Actor::find($card['target']['id']);
                if ($succeeded && $actor !== null && ! Companions::atCap($scene)) {
                    // The asked path into the same one door every companion
                    // comes through, so the bond ladder has exactly one entrance.
                    Companions::join($actor, Companions::ASKED);
                    $facts[] = "{$actor->name} fell in beside them — a companion now, walking the same tale.";
                } elseif ($succeeded && $actor !== null) {
                    $facts[] = "{$actor->name} was willing enough — but there was no room beside them for another, and both of them could see it.";
                } elseif ($degree === BeatOutcome::PARTIAL && $actor !== null) {
                    $tags = $actor->tags ?? [];
                    $tags['disposition'] = 'swayed';
                    $actor->update(['tags' => $tags]);
                    $facts[] = "{$actor->name} wavered — not yet, but the door is open.";
                } else {
                    $facts[] = "{$targetName} would not be drawn into it.";
                }
                break;

            case Verb::CompanionBlock:
                $companion = Actor::find($card['target']['id']);
                $threat = $scene->visibleActors()->first(fn (Actor $a) => $a->kind === 'enemy');
                if ($companion === null || $threat === null) {
                    $facts[] = 'There was no one left to hold back.';
                    break;
                }
                if ($succeeded) {
                    $conditions['blocked'] = ['id' => $threat->id, 'full' => true, 'companion_id' => $companion->id];
                    $facts[] = "{$companion->name} planted themselves in {$threat->name}'s path — the way is held.";
                } elseif ($degree === BeatOutcome::PARTIAL) {
                    $conditions['blocked'] = ['id' => $threat->id, 'full' => false, 'companion_id' => $companion->id];
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

            case Verb::CompanionFlank:
                $companion = Actor::find($card['target']['id']);
                if ($succeeded && $companion !== null) {
                    $conditions['flanked'] = true;
                    $facts[] = "{$companion->name} circled wide — the threat now looks two ways.";
                } else {
                    $facts[] = ($companion?->name ?? 'The companion')." couldn't find the angle.";
                }
                break;

            case Verb::CompanionStrike:
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

            case Verb::CompanionScout:
                $companion = Actor::find($card['target']['id']);
                if ($succeeded && $companion !== null) {
                    $scene->update(['state' => array_merge($scene->state ?? [], ['exit_scouted' => true])]);
                    $facts[] = "{$companion->name} found a way out — narrow, but real. It holds until the scene turns.";
                } else {
                    $facts[] = ($companion?->name ?? 'The companion').' searched but found no clean way out — yet.';
                }
                break;

            case Verb::CompanionHarry:
                // The fellow's own trick: drag the angle a foe was working off
                // the player and onto themselves. It reuses the block's own
                // vocabulary rather than inventing a second one — a partial
                // hold, priced by the same dodge part the block already earns.
                $companion = Actor::find($card['target']['id']);
                $quarry = $scene->visibleActors()->first(fn (Actor $a) => $a->kind === 'enemy');
                if ($companion === null || $quarry === null) {
                    $facts[] = 'There was nobody left to worry at.';
                    break;
                }
                if ($succeeded) {
                    $tags = $quarry->tags ?? [];
                    $had = (bool) ($tags['angle'] ?? false);
                    unset($tags['angle']);
                    $quarry->update(['tags' => $tags]);
                    $conditions['blocked'] = ['id' => $quarry->id, 'full' => false, 'companion_id' => $companion->id];
                    $facts[] = $had
                        ? "{$companion->name} came at {$quarry->name} from the wrong side, and the angle they had worked for came off."
                        : "{$companion->name} worried at {$quarry->name} from the wrong side, and kept them turned away.";
                } else {
                    // Getting in reach of it is the whole cost of the card.
                    $stats = $companion->stats;
                    $stats['health']['current'] = max(0, (int) ($stats['health']['current'] ?? 1) - 1);
                    $companion->update(['stats' => $stats]);
                    if ($stats['health']['current'] === 0) {
                        $companion->update(['status' => 'downed']);
                        $facts[] = "{$quarry->name} caught {$companion->name} coming in, and the companion went down under it.";
                    } else {
                        $facts[] = "{$companion->name} came in too close and {$quarry->name} made them pay for it.";
                    }
                }
                break;

            case Verb::CompanionDistract:
                // Pull the attention off whatever it was gathering for. The
                // windup dies and they go back to circling — the existing
                // telegraph vocabulary, moved, never a new state.
                $companion = Actor::find($card['target']['id']);
                $mark = $scene->visibleActors()
                    ->first(fn (Actor $a) => $a->kind === 'enemy' && ($a->tags['intent'] ?? null) === 'windup')
                    ?? $scene->visibleActors()->first(fn (Actor $a) => $a->kind === 'enemy');
                if ($companion === null || $mark === null) {
                    $facts[] = 'There was nobody left to pull off anything.';
                    break;
                }
                if ($succeeded) {
                    $tags = $mark->tags ?? [];
                    $wound = ($tags['intent'] ?? null) === 'windup';
                    $tags['intent'] = 'circle';
                    unset($tags['angle']);
                    $mark->update(['tags' => $tags]);
                    $facts[] = $wound
                        ? "{$companion->name} gave {$mark->name} something they had to answer, and the heavy blow came apart half-made."
                        : "{$companion->name} pulled {$mark->name}'s attention wide, and they went back to circling.";
                } else {
                    $facts[] = "{$mark->name} did not so much as look at {$companion->name}.";
                }
                break;

            case Verb::CompanionForage:
                // Walking the ground, on their legs instead of yours.
                $companion = Actor::find($card['target']['id']);
                $kept = $scene->features()->get()->first(
                    fn ($f) => ($f->state['hidden'] ?? false) && ! ($f->state['destroyed'] ?? false),
                );
                if ($succeeded && $kept !== null) {
                    $kept->update(['state' => array_merge($kept->state ?? [], ['hidden' => false])]);
                    $facts[] = ($companion?->name ?? 'The companion')." came back with what this place was keeping to itself: {$kept->name}.";
                } elseif ($succeeded) {
                    $facts[] = ($companion?->name ?? 'The companion').' walked the whole of it and found nothing anybody had missed.';
                } else {
                    $facts[] = ($companion?->name ?? 'The companion').' quartered the ground and came back with nothing to show for it.';
                }
                break;

            case Verb::Hurl:
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

            case Verb::Improvise:
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
     * @param  array  $companionEvents  Out: anything the people beside them did
     *                                  about the answer — a line held, a blow
     *                                  stepped in front of.
     * @return list<string>
     */
    private function enemyReaction(Character $character, Scene $scene, Dice $dice, array $conditions, bool $playerEscaped, array &$rolls, Turn $turn, array &$companionEvents): array
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

                // A line that actually held is the clearest thing a companion
                // can do for someone, and the bond reads it directly off the
                // fact rather than off the request that asked for it.
                $holder = Actor::find($conditions['blocked']['companion_id'] ?? 0);
                if ($holder !== null) {
                    Companions::nudge($holder, 1, $turn, 'assist');
                }

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

                // The fall that did not happen because of who was beside them.
                // Unbidden, engine-triggered, no card and no player input — and
                // it fires HERE, before the damage lands, which is what puts it
                // ahead of the scar path: there is no fall left for Scars to
                // roll against once the blow has been taken by somebody else.
                $intercepted = ($companionEvents['interception'] ?? null) === null
                    ? Companions::intercept($character, $scene, $turn, $damage)
                    : null;
                if ($intercepted !== null) {
                    $companionEvents['interception'] = $intercepted;
                    $facts[] = $crown.$intercepted['fact'];
                    $rolls[] = $record + [
                        'degree' => BeatOutcome::SUCCESS,
                        'outcome' => "{$intercepted['companion']} took the blow — {$damage} damage",
                    ];

                    continue;
                }

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

    /**
     * The notable moments this turn left behind, as plain candidate records.
     *
     * DETECTION ONLY. The engine never holds a memento, prices one, or reads
     * one back — nothing under app/Game may even name the model. Every
     * candidate below is read from facts this resolution has already fixed,
     * and what (if anything) becomes an object on the shelf is decided
     * entirely outside the engine, by App\Services\Mementos.
     *
     * The list is closed and its priority order lives with the service, and
     * anything later arrives the way `endeavor_filled` just did — one more
     * block in this method, and nothing else anywhere.
     *
     * @param  list<int>  $elitesBefore
     * @param  list<int>  $captivesBefore
     * @param  list<int>  $companionsBefore
     * @param  array{scene:?Scene, record:array}|null  $fall
     * @param  list<array>  $reactionRolls
     * @param  string|null  $endeavorFilled  The name of the endeavor this turn
     *                                       saw all the way through, if it saw one.
     * @return list<array{trigger:string, subject:string, place:string}>
     */
    private function mementoTriggers(Turn $turn, Scene $before, Scene $after, array $elitesBefore, array $captivesBefore, array $companionsBefore, ?array $fall, array $reactionRolls, ?string $endeavorFilled = null): array
    {
        $candidates = [];
        $place = $before->title;

        // An old score closed for good — killed, kept, or bargained out.
        foreach (Grudges::settledNames($turn) as $name) {
            $candidates[] = ['trigger' => 'rival_settled', 'subject' => $name, 'place' => $place];
        }

        // The fall that marked them keeps whatever put them there: the blow
        // that finished it if the scene threw one, and the ground itself if
        // they went down on their own.
        if (($fall['record']['scar'] ?? null) !== null) {
            $struckBy = null;
            foreach ($reactionRolls as $roll) {
                if (($roll['kind'] ?? null) === 'enemy' && str_contains((string) ($roll['outcome'] ?? ''), 'Drew blood')) {
                    $struckBy = $roll['actor'];
                }
            }
            $candidates[] = [
                'trigger' => 'scar_taken',
                'subject' => $struckBy ?? $fall['record']['fell_at'],
                'place' => $fall['record']['fell_at'],
            ];
        }

        // Somebody who was walking beside them at the top of the turn and is
        // not walking anywhere any more. Rarer than everything below it and
        // rightly so: a companion lost is the most expensive thing in the
        // system, and the shelf is where that cost stops being a status.
        foreach (Actor::whereIn('id', $companionsBefore)->get() as $companion) {
            if (in_array($companion->status, Companions::LOST_STATUSES, true)) {
                $candidates[] = ['trigger' => 'companion_lost', 'subject' => $companion->name, 'place' => $place];
            }
        }

        // A multi-turn endeavor seen all the way through. Read straight off
        // the fill the resolution already fixed — the engine never asks the
        // shelf about it, it only hands the moment outward.
        if ($endeavorFilled !== null) {
            $candidates[] = ['trigger' => 'endeavor_filled', 'subject' => $endeavorFilled, 'place' => $place];
        }

        // An elite who was on their feet at the top of the turn and is down
        // or bound at the end of it.
        foreach (Actor::whereIn('id', $elitesBefore)->get() as $elite) {
            if (in_array($elite->status, ['defeated', 'dead', 'restrained'], true)) {
                $candidates[] = ['trigger' => 'elite_beaten', 'subject' => $elite->name, 'place' => $place];
            }
        }

        // Someone who was in a grip and is not any more, and is not an enemy:
        // a captive out of it and whole. (An enemy wrenching free of the
        // player's own hold is the grapple clock, not a rescue.)
        foreach (Actor::whereIn('id', $captivesBefore)->get() as $captive) {
            if (in_array($captive->status, ['active'], true)) {
                $candidates[] = ['trigger' => 'captive_freed', 'subject' => $captive->name, 'place' => $place];
            }
        }

        // New country. A pressed flower from the far side of the frontier,
        // the first time this tale ever stood in that zone.
        if ($after->zone_id !== $before->zone_id && $after->zone !== null) {
            $candidates[] = ['trigger' => 'first_ground', 'subject' => $after->zone->name, 'place' => $after->title];
        }

        return $candidates;
    }

    /**
     * The rescues in this turn's own facts: somebody who was in a grip when it
     * began, is loose and whole now, and was never an enemy. It is the one
     * rescue the engine can read without guessing, and it is what the grateful
     * path is allowed to fire off.
     *
     * @param  list<int>  $captivesBefore
     * @return list<int>
     */
    private function rescuedThisTurn(array $captivesBefore): array
    {
        if ($captivesBefore === []) {
            return [];
        }

        return Actor::whereIn('id', $captivesBefore)->where('status', 'active')
            ->where('kind', '!=', 'enemy')->pluck('id')->all();
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
    private function transitionScene(Scene $scene, Dice $dice, Turn $turn, array &$companionEvents = []): Scene
    {
        // Scene exit decides what became of whoever went down in it. Rolled
        // against the bond and nothing else, and only ever here: a companion on
        // the floor stays on the floor, visible and breathing, for as long as
        // the scene they fell in lasts.
        $loss = Companions::resolveDowned(
            $scene, $dice,
            fightLost: $scene->actors()->where('status', 'active')->where('kind', 'enemy')->exists(),
        );
        if ($loss['facts'] !== []) {
            $companionEvents['loss'] = array_merge($companionEvents['loss'] ?? [], $loss['facts']);
        }

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

        // And so does anyone who has taken to following without being asked.
        Companions::walkStrays($scene, $next);

        // New country walked together is worth a point on its own. The road
        // itself is one of the four things that move a bond, and it is the only
        // one nobody has to survive anything for.
        if ($next->zone_id !== $scene->zone_id) {
            foreach (Companions::present($next) as $companion) {
                Companions::nudge($companion, 1, $turn, 'road');
            }
        }

        // The endeavor, at the border. A goal about ground the tale has left
        // is a goal that can never be finished, and a board line promising one
        // would be a promise the engine cannot keep — so it expires here.
        // What the body itself learned comes along.
        Clocks::onSceneExit($scene, $next);

        // New ground, new air — one roll, kept for as long as this scene lasts.
        // Last, so the draws above keep the exact stream they have always had.
        $this->dresser->rollAmbient($next, $dice);

        return $next;
    }

    private function openNextTurn(Turn $turn, Character $character, Scene $scene, BranchTrigger $trigger, Dice $dice): Turn
    {
        $character = $character->fresh();
        $scene = $scene->fresh();
        // The turn's own stream carries into the card pass, so whether a
        // bargain is offered moves from turn to turn and still replays
        // identically when the same turn is resolved again.
        $cards = $this->composer->compose($character, $scene, $dice);

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
