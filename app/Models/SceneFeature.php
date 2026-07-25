<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An affordance-bearing scene feature. The `affordances` json tags what the
 * feature wants and its constraints, e.g.:
 *   {"reachable_via":["climb","swing","leap"],"height":11}
 *   {"flee_destination":true,"squeeze_required":"medium"}
 *   {"crossable_via":["swing","glide"],"gap":"far"}
 */
#[Fillable(['scene_id', 'zone_id', 'name', 'feature_type', 'affordances', 'state', 'source', 'evolution_run_id'])]
class SceneFeature extends Model
{
    protected function casts(): array
    {
        return [
            'affordances' => 'array',
            'state' => 'array',
        ];
    }

    public function scene(): BelongsTo
    {
        return $this->belongsTo(Scene::class);
    }

    public function zone(): BelongsTo
    {
        return $this->belongsTo(Zone::class);
    }

    /**
     * What this feature offers, said the way a person would say it. The
     * engine's magnitudes stay backstage — these lines reach both the
     * narrator (as inspect facts) and the reader's detail card, so they
     * carry sight and possibility, never numbers.
     *
     * @return list<string>
     */
    public function readings(): array
    {
        $a = $this->affordances ?? [];
        $reads = [];

        $ways = fn (array $vias) => collect($vias)
            ->map(fn (string $v) => match ($v) {
                'climb' => 'climbed',
                'swing' => 'swung up to',
                'leap' => 'jumped to',
                'glide' => 'glided to',
                'swim' => 'swum to',
                default => str_replace('_', ' ', $v).'ed',
            })->join(' or ');

        if (! empty($a['reachable_via'])) {
            $height = $a['height'] ?? null;
            $reads[] = 'The top of it could be '.$ways($a['reachable_via']).'.'.match (true) {
                $height === null => '',
                $height <= 6 => ' It sits a short way up.',
                $height <= 12 => ' It stands a good way above the ground.',
                default => ' It is a long way up.',
            };
        }

        if (! empty($a['crossable_via'])) {
            $reads[] = 'It could be crossed — '.$ways($a['crossable_via']).' — and the far side is '
                .match ($a['gap'] ?? 'medium') {
                    'short' => 'close enough to be tempting.',
                    'far' => 'further off than looks comfortable.',
                    default => 'a real distance away.',
                };
        }

        if ($a['flee_destination'] ?? false) {
            $reads[] = 'It leads out of here, and the way through is '
                .match ($a['squeeze_required'] ?? 'large') {
                    'small' => 'barely a crack.',
                    'medium' => 'narrow.',
                    default => 'wide enough to move through.',
                };
        }

        if ($a['hideable'] ?? false) {
            $reads[] = 'There is room behind it for someone who wanted not to be seen.';
        }

        if (isset($a['breakable'])) {
            $reads[] = 'It would come apart if something forced it.';
        }

        if (isset($a['lift_weight'])) {
            $weight = (int) $a['lift_weight'];
            $reads[] = 'It could be shifted, by someone '.match (true) {
                $weight <= 80 => 'strong enough to want to try.',
                $weight <= 160 => 'a great deal stronger than most.',
                default => 'far stronger than anyone here looks.',
            };
        }

        if (! empty($a['rideable_via'])) {
            $reads[] = 'It moves, and it would carry someone who committed to it.';
        }

        if ($reads === []) {
            $reads[] = 'It is exactly what it appears to be — no way up it, no way through it, nothing it is hiding.';
        }

        return $reads;
    }
}
