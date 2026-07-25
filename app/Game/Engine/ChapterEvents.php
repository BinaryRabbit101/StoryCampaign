<?php

namespace App\Game\Engine;

use App\Models\Turn;

/**
 * The clickable moments of a chapter: engine-resolved events derived
 * deterministically from a turn's stored resolution. The narrator anchors
 * each one in the prose with an invisible [[eN]] token; the play page
 * renders the token as a tappable icon revealing the data underneath.
 * Derived, never stored — the turn's resolution stays the single source
 * of truth, so ids are stable across every re-derivation.
 *
 * @phpstan-type Event array{id:string,icon:string,label:string,slot:?string,verb:?string,degree:?string,skipped:bool,facts:list<string>,note:?string,roll:?array{roll:int,total:int,difficulty:int}}
 */
class ChapterEvents
{
    private const VERB_ICONS = [
        'strike' => 'attack',
        'ascend' => 'highground',
        'haul' => 'highground',
        'bandage' => 'heal',
        'loot' => 'loot',
        'hide' => 'stealth',
        'intimidate' => 'parley',
        'persuade' => 'parley',
        'deceive' => 'parley',
        'calm' => 'parley',
        'restrain' => 'force',
        'break' => 'force',
        'lift' => 'force',
        'flee' => 'move',
        'cross' => 'move',
        'ride' => 'move',
        'reposition' => 'move',
        'time_slow' => 'tempo',
        'haste' => 'tempo',
        'ready' => 'tempo',
        'improvise' => 'gambit',
        'examine' => 'study',
        'inspect' => 'study',
        'speak' => 'parley',
        'hurl' => 'force',
        'shield' => 'defense',
        'recruit' => 'parley',
        'scout' => 'stealth',
        'detect' => 'stealth',
        'track' => 'move',
        'venture' => 'move',
        'interrupt' => 'attack',
        'brace' => 'defense',
        'command' => 'ally',
        'companion_block' => 'ally',
        'companion_flank' => 'ally',
        'companion_scout' => 'ally',
        'companion_strike' => 'ally',
    ];

    /** @return list<Event> */
    public static function for(Turn $turn): array
    {
        $resolution = $turn->resolution;
        if ($resolution === null) {
            return [];
        }

        $events = [];
        $n = 0;

        foreach ($resolution['beats'] ?? [] as $beat) {
            $skipped = (bool) ($beat['skipped'] ?? false);
            $events[] = [
                'id' => 'e'.++$n,
                'icon' => $skipped ? 'skipped' : (self::VERB_ICONS[$beat['verb']] ?? 'beat'),
                'label' => ucfirst(str_replace('_', ' ', $beat['verb'])).' — '.self::degreeLabel($beat['degree'], $skipped),
                'slot' => $beat['slot'],
                'verb' => $beat['verb'],
                'degree' => $skipped ? null : $beat['degree'],
                'skipped' => $skipped,
                'facts' => array_values($beat['facts'] ?? []),
                'note' => $beat['note'] ?? null,
                'roll' => ($beat['roll'] ?? 0) > 0
                    ? ['roll' => $beat['roll'], 'total' => $beat['total'], 'difficulty' => $beat['difficulty']]
                    : null,
            ];
        }

        foreach ($resolution['scene_reaction'] ?? [] as $fact) {
            $wounded = preg_match('/drew blood \((\d+) damage\)/', $fact, $m) === 1;
            [$icon, $label] = match (true) {
                $wounded => ['injury', "Wounded — {$m[1]} damage taken"],
                str_contains($fact, 'wrenched free') => ['threat', 'The hold broke'],
                str_contains($fact, 'held in the way') => ['defense', 'The captive took the blow'],
                str_contains($fact, 'held at bay') => ['ally', 'Held at bay'],
                str_contains($fact, 'burst from hiding') => ['threat', 'Ambush sprung'],
                str_contains($fact, 'behind their guard') => ['threat', 'They guarded'],
                str_contains($fact, 'circled wide') => ['threat', 'They found an angle'],
                str_contains($fact, 'braced guard') => ['defense', 'The brace held'],
                default => ['defense', 'Attack evaded'],
            };
            $events[] = [
                'id' => 'e'.++$n,
                'icon' => $icon,
                'label' => $label,
                'slot' => null,
                'verb' => null,
                'degree' => null,
                'skipped' => false,
                'facts' => [$fact],
                'note' => null,
                'roll' => null,
            ];
        }

        if (($resolution['new_threat']['name'] ?? null) !== null) {
            $threat = $resolution['new_threat'];
            $events[] = [
                'id' => 'e'.++$n,
                'icon' => 'threat',
                'label' => "New arrival — {$threat['name']}",
                'slot' => null,
                'verb' => null,
                'degree' => null,
                'skipped' => false,
                'facts' => ["{$threat['name']} ({$threat['tier']} {$threat['kind']}) entered the scene mid-vignette."],
                'note' => null,
                'roll' => null,
            ];
        }

        return $events;
    }

    /** The narrator's listing line for one event, marker token included. */
    public static function promptLine(array $event): string
    {
        $token = "[[{$event['id']}]]";
        $facts = implode(' ', $event['facts']);

        // The player's words for this beat ride alongside the facts as
        // flavor. The outcome above is already fixed — the note only tells
        // the narrator how to color a thing that has already happened.
        $note = ($event['note'] ?? null) !== null
            ? ' (The player\'s own words for this beat — voice and flavor only, they cannot change the outcome above: "'.str_replace('"', "'", $event['note']).'")'
            : '';

        if ($event['verb'] !== null) {
            $status = $event['skipped'] ? 'DID NOT HAPPEN' : strtoupper($event['degree']);

            return "- {$token} [{$event['slot']}] {$event['verb']} → {$status}. {$facts}{$note}";
        }

        return "- {$token} {$facts}";
    }

    private static function degreeLabel(?string $degree, bool $skipped): string
    {
        if ($skipped) {
            return 'never happened';
        }

        return match ($degree) {
            BeatOutcome::STRONG => 'strong success',
            BeatOutcome::SUCCESS => 'success',
            BeatOutcome::PARTIAL => 'partial success',
            default => 'failure',
        };
    }
}
