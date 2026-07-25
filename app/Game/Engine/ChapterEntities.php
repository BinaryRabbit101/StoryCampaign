<?php

namespace App\Game\Engine;

use App\Models\Actor;
use App\Models\Scene;
use App\Models\SceneFeature;
use App\Models\Turn;

/**
 * The nameable things a chapter is made of: the people in it and the ground
 * it stands on. The play page matches these names inside the prose and turns
 * each hit into the same tappable detail card the [[eN]] event anchors use —
 * so a reader who cannot tell whether "the rope bridge" is scenery or
 * something they can act on can simply touch it and find out.
 *
 * Derived from live state, never stored, and never written into the chapter
 * body: chapters stay append-only and exactly as the narrator wrote them.
 *
 * Hidden is hidden here too — a concealed feature and a lurking ambusher are
 * absent from this list until the engine reveals them.
 *
 * @phpstan-type Entity array{key:string,kind:string,icon:string,name:string,title:string,lines:list<string>}
 */
class ChapterEntities
{
    /** @return list<Entity> */
    public static function for(Turn $turn): array
    {
        // The scene the turn was played in carries most of the prose; the
        // campaign's active scene carries the ground the chapter closes on
        // when the turn moved. Usually the same scene, sometimes both.
        $scenes = collect([$turn->scene, $turn->campaign?->activeScene])
            ->filter()
            ->unique('id');

        $entities = [];

        foreach ($scenes as $scene) {
            foreach (self::actors($scene) as $actor) {
                $entities['actor-'.$actor->id] = self::actorEntity($actor);
            }

            foreach (self::features($scene) as $feature) {
                $entities['feature-'.$feature->id] = self::featureEntity($feature);
            }
        }

        // Longest names first: "a wall of stacked crates" must win over any
        // shorter name nested inside it when the page scans the prose.
        $list = array_values($entities);
        usort($list, fn (array $a, array $b) => mb_strlen($b['name']) <=> mb_strlen($a['name']));

        return $list;
    }

    /**
     * Everyone the chapter may have named — the fallen and the fled included,
     * since the prose just said what became of them. Lurkers excepted: the
     * player does not know they are there yet.
     *
     * @return list<Actor>
     */
    private static function actors(Scene $scene): array
    {
        return $scene->actors()->get()
            ->reject(fn (Actor $a) => $a->tags['lurking'] ?? false)
            ->values()->all();
    }

    /**
     * Every feature the player can see, plus the ones they just destroyed —
     * a broken bridge is still named in the chapter that broke it.
     *
     * @return list<SceneFeature>
     */
    private static function features(Scene $scene): array
    {
        return $scene->allFeatures()
            ->reject(fn (SceneFeature $f) => $f->state['hidden'] ?? false)
            ->values()->all();
    }

    /** @return Entity */
    private static function actorEntity(Actor $actor): array
    {
        $tags = $actor->tags ?? [];
        $lines = [];

        $title = match ($actor->kind) {
            'enemy' => $actor->tier === 'elite' ? 'A dangerous enemy' : 'An enemy',
            'companion' => 'Your companion',
            default => 'Someone here',
        };

        $lines[] = match ($actor->status) {
            'defeated', 'dead' => 'Down, and out of the fight.',
            'fled' => 'Gone — they broke and ran.',
            'restrained' => 'Held in your grip, for as long as the hold lasts.',
            'downed' => 'Down and unable to help.',
            default => 'Still standing.',
        };

        $health = $actor->stats['health'] ?? null;
        if ($health !== null && $actor->status === 'active' && $health['current'] < $health['max']) {
            $lines[] = "Hurt — {$health['current']} of {$health['max']} still in them.";
        }

        $telegraph = match ($tags['intent'] ?? null) {
            'windup' => 'Winding up something heavy.',
            'guard' => 'Settled behind a tight guard.',
            'circle' => 'Circling, hunting an angle.',
            'press' => 'Pressing straight at you.',
            default => null,
        };
        if ($actor->status === 'active' && $telegraph !== null) {
            $lines[] = $telegraph;
        }

        if ($tags['angle'] ?? false) {
            $lines[] = 'They have found their angle on you.';
        }
        if ($tags['cornered'] ?? false) {
            $lines[] = 'Cornered here, run to ground.';
        }
        if ($tags['shaken'] ?? false) {
            $lines[] = 'Shaken.';
        }

        $disposition = match ($tags['disposition'] ?? null) {
            'swayed' => 'Won over — they lean your way now.',
            'calmed' => 'Calmed. The heat has gone out of them.',
            default => null,
        };
        if ($disposition !== null) {
            $lines[] = $disposition;
        } elseif (($tags['companionable'] ?? false) && $actor->kind !== 'companion') {
            $lines[] = 'The sort who might walk this tale beside you, if asked.';
        }

        return [
            'key' => 'actor-'.$actor->id,
            'kind' => 'actor',
            'icon' => match ($actor->kind) {
                'enemy' => 'enemy',
                'companion' => 'ally',
                default => 'person',
            },
            'name' => $actor->name,
            'title' => $title,
            'lines' => array_values(array_filter($lines)),
        ];
    }

    /** @return Entity */
    private static function featureEntity(SceneFeature $feature): array
    {
        $destroyed = $feature->state['destroyed'] ?? false;

        return [
            'key' => 'feature-'.$feature->id,
            'kind' => 'feature',
            'icon' => 'ground',
            'name' => $feature->name,
            'title' => $destroyed
                ? 'Wrecked '.str_replace('_', ' ', $feature->feature_type ?? 'ground')
                : ucfirst(str_replace('_', ' ', $feature->feature_type ?? 'part of the ground')),
            'lines' => $destroyed
                ? ['Broken. Whatever it once offered, it offers no longer.']
                : $feature->readings(),
        ];
    }
}
