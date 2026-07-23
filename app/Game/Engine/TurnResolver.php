<?php

namespace App\Game\Engine;

use App\Game\BranchTrigger;
use App\Game\Meters;
use App\Models\Actor;
use App\Models\Character;
use App\Models\Scene;
use App\Models\Turn;
use Illuminate\Support\Facades\DB;

/**
 * Resolves a locked turn: the pre/main/post slot chain in order (conditional
 * chain with legality-driven abort), the scene's reaction, branch-trigger
 * evaluation, and generation of the next turn's cards. The engine owns all
 * dice and outcomes; the resolution it writes is handed to Claude to narrate.
 */
class TurnResolver
{
    public function __construct(private readonly CardComposer $composer) {}

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
            $offered = collect($turn->cards)->flatMap(fn ($cards) => $cards)->keyBy('id');

            $conditions = [
                'elevated' => (bool) ($scene->state['elevated'] ?? false),
                'concealed' => false,
                'time_slowed' => false,
                'hastened' => false,
                'readied' => false,
                'prior_failure' => false,
            ];

            $outcomes = [];
            $trigger = null;
            $moved = false;
            $wasInDanger = Meters::healthInDangerBand($character);

            foreach (['pre', 'main', 'post'] as $slot) {
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

                $outcome = $this->resolveBeat($card, $choice, $character, $scene, $dice, $conditions);
                $outcomes[] = $outcome;

                if (! $outcome->succeeded()) {
                    $conditions['prior_failure'] = true;
                }

                if (in_array($card['verb'], ['flee', 'cross'], true) && $outcome->succeeded()) {
                    $moved = true;
                }
            }

            // The scene answers: active enemies react to the player's beats.
            $enemyFacts = $this->enemyReaction($character, $scene, $dice, $conditions, $moved);

            // Living-world texture: a zone-level actor may join mid-scene.
            $newThreat = null;
            if (! $moved && $trigger === null) {
                $newThreat = $this->maybeIntroduceThreat($scene, $dice);
            }

            $character->refresh();
            $scene->refresh();

            $trigger ??= $this->evaluateTrigger($character, $scene, $outcomes, $moved, $newThreat, $wasInDanger);

            if ($moved) {
                $scene = $this->transitionScene($scene, $outcomes);
            }

            $turn->update([
                'status' => Turn::STATUS_COMPLETE,
                'resolution' => [
                    'beats' => array_map(fn (BeatOutcome $o) => $o->toArray(), $outcomes),
                    'scene_reaction' => $enemyFacts,
                    'new_threat' => $newThreat?->only(['id', 'name', 'kind', 'tier']),
                    'conditions' => $conditions,
                ],
                'branch_trigger' => $trigger->value,
                'meters_snapshot' => $character->meters,
                'resolved_at' => now(),
            ]);

            return $this->openNextTurn($turn, $character, $scene, $trigger);
        });
    }

    private function illegalReason(array $card, Scene $scene, Character $character, array $conditions): ?string
    {
        $target = $card['target'] ?? null;

        if (($target['type'] ?? null) === 'actor') {
            $actor = Actor::find($target['id']);
            if ($actor === null || $actor->status !== 'active') {
                return "{$target['name']} is no longer a factor.";
            }
        }

        if (($target['type'] ?? null) === 'feature') {
            $feature = $scene->allFeatures()->firstWhere('id', $target['id']);
            if ($feature === null || ($feature->state['destroyed'] ?? false)) {
                return "{$target['name']} is gone.";
            }
        }

        foreach ($card['cost'] ?? [] as $cost) {
            if (Meters::charges($character, $cost['meter']) < $cost['amount']) {
                return 'The '.str_replace('_', ' ', $cost['meter']).' is spent dry.';
            }
        }

        return null;
    }

    private function resolveBeat(array $card, array $choice, Character $character, Scene $scene, Dice $dice, array &$conditions): BeatOutcome
    {
        $verb = $card['verb'];
        $approach = $choice['modifiers']['approach'] ?? 'balanced';

        foreach ($card['cost'] ?? [] as $cost) {
            Meters::spend($character, $cost['meter'], $cost['amount']);
        }

        // Tempo and quiet beats auto-succeed; everything else rolls.
        if (in_array($verb, ['time_slow', 'haste', 'ready', 'examine', 'wait', 'catch_breath', 'reposition'], true)) {
            return $this->quietBeat($card, $character, $scene, $conditions);
        }

        $difficulty = 10 + match ($card['risk']) {
            'degraded' => 5,
            'risky' => 3,
            default => 0,
        };
        $difficulty += match ($approach) {
            'cautious' => -2,
            'bold' => 2,
            default => 0,
        };
        if ($conditions['prior_failure']) {
            $difficulty += 2; // degraded conditions: the world didn't cooperate
        }

        $bonus = 0;
        $bonus += $conditions['time_slowed'] ? 4 : 0;
        $bonus += $conditions['hastened'] ? 2 : 0;
        $bonus += $conditions['readied'] ? 2 : 0;
        $bonus += ($conditions['elevated'] && $verb === 'strike') ? 2 : 0;
        $bonus += ($conditions['concealed'] && in_array($verb, ['strike', 'restrain', 'haul'], true)) ? 3 : 0;

        $roll = $dice->d20();
        $total = $roll + $bonus;

        $degree = match (true) {
            $total >= $difficulty + 5 => BeatOutcome::STRONG,
            $total >= $difficulty => BeatOutcome::SUCCESS,
            $total >= $difficulty - 4 => BeatOutcome::PARTIAL,
            default => BeatOutcome::FAILURE,
        };

        $facts = $this->applyBeatEffects($verb, $card, $degree, $approach, $character, $scene, $conditions, $dice);

        return new BeatOutcome($card['slot'], $verb, $card['target'] ?? null, $degree, $roll, $total, $difficulty, $facts);
    }

    private function quietBeat(array $card, Character $character, Scene $scene, array &$conditions): BeatOutcome
    {
        $facts = [];

        switch ($card['verb']) {
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
                $facts[] = 'They studied the scene: '.$this->sceneSummary($scene);
                break;
            case 'wait':
                $facts[] = 'They held still and let the scene move first.';
                break;
            case 'catch_breath':
                $facts[] = 'They took a slow breath and steadied themselves.';
                break;
            case 'reposition':
                $facts[] = 'They shifted to safer footing.';
                break;
        }

        return new BeatOutcome($card['slot'], $card['verb'], $card['target'] ?? null,
            BeatOutcome::SUCCESS, 0, 0, 0, $facts);
    }

    /** @return list<string> */
    private function applyBeatEffects(string $verb, array $card, string $degree, string $approach, Character $character, Scene $scene, array &$conditions, Dice $dice): array
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
                    $actor->update(['status' => 'fled']);
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
                    $actor->update(['tags' => $tags, 'status' => $actor->kind === 'enemy' ? 'fled' : $actor->status]);
                    $facts[] = "Words landed: {$actor->name} was ".($verb === 'calm' ? 'calmed' : 'won over').'.';
                } else {
                    $facts[] = "The words found no purchase on {$targetName}.";
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
                $facts[] = $succeeded
                    ? "They heaved {$targetName} aside."
                    : "{$targetName} would not move.";
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

            case 'improvise':
                // Base stats, no special bonus — never better than a real
                // enumerated option, so there's no incentive to game it.
                $facts[] = $succeeded
                    ? 'The improvised gambit worked — barely, and only through nerve.'
                    : 'The improvisation fell apart; the moment reasserted itself.';
                break;

            default:
                $facts[] = $succeeded ? 'It worked.' : 'It failed.';
        }

        return $facts;
    }

    /** @return list<string> */
    private function enemyReaction(Character $character, Scene $scene, Dice $dice, array $conditions, bool $playerEscaped): array
    {
        if ($playerEscaped || $character->fresh()->status !== 'alive') {
            return [];
        }

        $facts = [];
        $enemies = $scene->actors()->where('status', 'active')->where('kind', 'enemy')->get();

        foreach ($enemies as $enemy) {
            $dodge = 12
                + ($conditions['elevated'] ? 3 : 0)
                + ($conditions['concealed'] ? 3 : 0)
                + ($conditions['readied'] ? 2 : 0);

            $roll = $dice->d20();
            if ($conditions['time_slowed']) {
                $roll = min($roll, $dice->d20()); // the slowed world blunts them
            }
            $attack = $roll + (int) ($enemy->stats['attack'] ?? 1);

            if ($attack >= $dodge) {
                $damage = max(1, (int) ($enemy->stats['attack'] ?? 1));
                Meters::damage($character, $damage);
                $facts[] = "{$enemy->name} answered and drew blood ({$damage} damage).";
            } else {
                $facts[] = "{$enemy->name} pressed in but found nothing to hit.";
            }
        }

        return $facts;
    }

    private function maybeIntroduceThreat(Scene $scene, Dice $dice): ?Actor
    {
        $hostilities = $scene->actors()->where('status', 'active')->where('kind', 'enemy')->exists();
        $chance = $hostilities ? 0.12 : 0.05;

        if (! $dice->chance($chance)) {
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

        return Actor::create([
            'scene_id' => $scene->id,
            'zone_id' => $scene->zone_id,
            'name' => $template->name,
            'kind' => $template->kind,
            'tier' => $template->tier,
            'stats' => $template->stats,
            'tags' => $template->tags,
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

    private function transitionScene(Scene $scene, array $outcomes): Scene
    {
        $scene->update(['status' => 'past']);

        return Scene::create([
            'campaign_id' => $scene->campaign_id,
            'zone_id' => $scene->zone_id,
            'title' => 'Beyond '.$scene->title,
            'description' => 'New ground, reached in motion. The old scene lies behind.',
            'status' => 'active',
            'state' => [],
        ]);
    }

    private function openNextTurn(Turn $turn, Character $character, Scene $scene, BranchTrigger $trigger): Turn
    {
        $cards = $this->composer->compose($character->fresh(), $scene->fresh());

        return Turn::create([
            'campaign_id' => $turn->campaign_id,
            'scene_id' => $scene->id,
            'number' => $turn->number + 1,
            'status' => Turn::STATUS_AWAITING,
            'situation' => $this->situationText($character, $scene, $trigger),
            'cards' => $cards,
        ]);
    }

    private function situationText(Character $character, Scene $scene, BranchTrigger $trigger): string
    {
        $health = $character->fresh()->meters['health'];
        $enemies = $scene->actors()->where('status', 'active')->where('kind', 'enemy')->pluck('name');

        $parts = [$trigger->description()];
        $parts[] = $enemies->isEmpty()
            ? 'No open threat stands against you.'
            : 'Facing you: '.$enemies->join(', ').'.';

        // Ground the offered options: name the bystanders and features the
        // cards will reference, so nothing appears narratively unannounced.
        $others = $scene->actors()->where('status', 'active')->where('kind', '!=', 'enemy')->pluck('name');
        if ($others->isNotEmpty()) {
            $parts[] = 'Also here: '.$others->join(', ').'.';
        }
        $features = $scene->allFeatures()
            ->reject(fn ($f) => $f->state['destroyed'] ?? false)
            ->pluck('name')->take(6);
        if ($features->isNotEmpty()) {
            $parts[] = 'Around you: '.$features->join(', ').'.';
        }

        $parts[] = "Health {$health['current']}/{$health['max']}.";
        if ($scene->state['elevated'] ?? false) {
            $parts[] = 'You hold the high ground.';
        }

        return implode(' ', $parts);
    }

    private function sceneSummary(Scene $scene): string
    {
        $features = $scene->allFeatures()->pluck('name')->join(', ');
        $actors = $scene->activeActors()->pluck('name')->join(', ');

        return trim(($features !== '' ? "Nearby: {$features}." : '').' '.($actors !== '' ? "Present: {$actors}." : ''));
    }
}
