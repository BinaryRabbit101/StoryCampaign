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
}
