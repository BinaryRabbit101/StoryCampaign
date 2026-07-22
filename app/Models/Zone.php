<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['slug', 'name', 'description', 'tags', 'source', 'evolution_run_id'])]
class Zone extends Model
{
    protected function casts(): array
    {
        return [
            'tags' => 'array',
        ];
    }

    public function scenes(): HasMany
    {
        return $this->hasMany(Scene::class);
    }

    /** Zone-level features become available to any scene set in this zone. */
    public function features(): HasMany
    {
        return $this->hasMany(SceneFeature::class);
    }

    public function actors(): HasMany
    {
        return $this->hasMany(Actor::class);
    }
}
