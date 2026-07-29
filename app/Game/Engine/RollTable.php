<?php

namespace App\Game\Engine;

use App\Models\Turn;

/**
 * The dice table: every die a resolved turn actually cast, laid out as a grid
 * the player watches fall before the chapter is read to them.
 *
 * Derived, never stored — the turn's resolution stays the single source of
 * truth. The engine already rolled these; the table is a replay, not a second
 * roll, which is exactly why the player may take as long as they like over it
 * and why nothing they do on that screen can change a number.
 *
 * @phpstan-type Part array{label:string,amount:int}
 * @phpstan-type Row array{id:string,side:string,actor:string,action:string,verb:string,icon:string,difficulty:int,band:string,roll:int,modifier:int,total:int,degree:string,crit:?string,outcome:?string,difficulty_parts:list<Part>,bonus_parts:list<Part>}
 */
class RollTable
{
    /** @return list<Row> */
    public static function for(Turn $turn): array
    {
        $resolution = $turn->resolution;
        if ($resolution === null) {
            return [];
        }

        $rows = [];
        $n = 0;
        $allies = self::companionNames($turn);
        $hero = $turn->campaign?->character?->name ?? 'You';

        foreach ($resolution['beats'] ?? [] as $beat) {
            // Quiet beats and beats that never happened cast no d20 against a
            // difficulty — but a quiet beat that cast the FORTUNE die still
            // has a die to pick up, and it goes on the table as its own kind
            // of card: no DC, no sum, just the face and which way it broke.
            if (($beat['skipped'] ?? false) || (int) ($beat['roll'] ?? 0) <= 0) {
                $fortune = $beat['fortune'] ?? null;
                if (! ($beat['skipped'] ?? false) && ($fortune['roll'] ?? 0) > 0) {
                    $row = self::row(
                        id: 'r'.++$n,
                        side: 'player',
                        actor: $hero,
                        action: self::actionLabel($beat),
                        verb: $beat['verb'],
                        difficulty: 0,
                        roll: (int) $fortune['roll'],
                        total: (int) $fortune['roll'],
                        degree: $fortune['kind'] ?? 'plain',
                        crit: null,
                        outcome: $fortune['fact'] ?? null,
                    );
                    $row['fortune'] = true;
                    $rows[] = $row;
                }

                continue;
            }

            $companion = $beat['slot'] === 'companion';
            $rows[] = self::row(
                id: 'r'.++$n,
                side: $companion ? 'ally' : 'player',
                actor: $companion ? (array_shift($allies) ?? 'Your companion') : $hero,
                action: self::actionLabel($beat),
                verb: $beat['verb'],
                difficulty: (int) $beat['difficulty'],
                roll: (int) $beat['roll'],
                total: (int) $beat['total'],
                degree: $beat['degree'],
                crit: $beat['crit'] ?? null,
                outcome: self::outcomeLine($beat['facts'] ?? []),
                difficultyParts: $beat['difficulty_parts'] ?? [],
                bonusParts: $beat['bonus_parts'] ?? [],
            );
        }

        foreach ($resolution['reaction_rolls'] ?? [] as $reaction) {
            $rows[] = self::row(
                id: 'r'.++$n,
                side: 'foe',
                actor: $reaction['actor'] ?? 'The scene',
                action: $reaction['label'] ?? 'Answers',
                verb: $reaction['verb'] ?? 'attack',
                difficulty: (int) ($reaction['difficulty'] ?? 0),
                roll: (int) ($reaction['roll'] ?? 0),
                total: (int) ($reaction['total'] ?? 0),
                degree: $reaction['degree'] ?? BeatOutcome::FAILURE,
                crit: $reaction['crit'] ?? null,
                outcome: $reaction['outcome'] ?? null,
                difficultyParts: $reaction['difficulty_parts'] ?? [],
                bonusParts: $reaction['bonus_parts'] ?? [],
            );
        }

        return $rows;
    }

    /** @return Row */
    private static function row(
        string $id,
        string $side,
        string $actor,
        string $action,
        string $verb,
        int $difficulty,
        int $roll,
        int $total,
        string $degree,
        ?string $crit,
        ?string $outcome,
        array $difficultyParts = [],
        array $bonusParts = [],
    ): array {
        return [
            'id' => $id,
            'side' => $side,
            'actor' => $actor,
            'action' => $action,
            'verb' => $verb,
            'icon' => ChapterEvents::iconFor($verb),
            'difficulty' => $difficulty,
            'band' => Odds::band($difficulty),
            'roll' => $roll,
            // The engine keeps the bonus inside the total; the table shows it
            // back as the +N the player earned with their set-up beats.
            'modifier' => $total - $roll,
            'total' => $total,
            'degree' => $degree,
            'crit' => $crit,
            'outcome' => $outcome,
            // Why both numbers were what they were. Turns committed before
            // the ledger existed carry no parts; the table simply shows the
            // arithmetic without the reasons for those.
            'difficulty_parts' => array_values($difficultyParts),
            'bonus_parts' => array_values($bonusParts),
            // A fortune card shows no DC and no sum: the die is the whole
            // event. Flipped on after construction by the one caller that
            // builds such a row.
            'fortune' => false,
        ];
    }

    /**
     * The engine's own verdict on the beat, cut to something a card can hold.
     * A crit's facts run long on purpose — they are written to give the
     * narrator room — but the dice table only needs the gist; the chapter is
     * one screen away and says the rest properly.
     */
    private static function outcomeLine(array $facts): ?string
    {
        $line = null;
        foreach ($facts as $fact) {
            // The crit banner is already a badge on the card; the line
            // underneath should say what actually changed.
            if (! str_starts_with($fact, 'CRITICAL ')) {
                $line = $fact;
                break;
            }
        }
        $line ??= $facts[0] ?? null;

        if ($line === null || mb_strlen($line) <= 120) {
            return $line;
        }

        $cut = mb_substr($line, 0, 120);
        $break = mb_strrpos($cut, ' ');

        return rtrim($break === false ? $cut : mb_substr($cut, 0, $break), ' ,.;:—').'…';
    }

    private static function actionLabel(array $beat): string
    {
        $verb = ucfirst(str_replace(['companion_', '_'], ['', ' '], $beat['verb']));
        $target = $beat['target']['name'] ?? null;

        return $target === null ? $verb : "{$verb} — {$target}";
    }

    /**
     * A companion beat records the target it acted on, never the companion
     * who acted. The resolver walks the submitted requests in order and emits
     * one beat per request it could match to an offered card — replay that
     * same filter and the names line up with the beats one for one.
     *
     * @return list<string>
     */
    private static function companionNames(Turn $turn): array
    {
        $offered = collect($turn->cards['companions'] ?? [])->keyBy('id');
        $names = [];

        foreach ($turn->submission['companions'] ?? [] as $companionId => $choice) {
            $entry = $offered->get((int) $companionId);
            if ($entry === null || $choice === null) {
                continue;
            }
            if (collect($entry['cards'])->firstWhere('id', $choice['card_id'] ?? '') === null) {
                continue;
            }
            $names[] = $entry['name'];
        }

        return $names;
    }
}
