<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['campaign_id', 'slug', 'name', 'description', 'tags', 'source', 'evolution_run_id'])]
class Zone extends Model
{
    protected function casts(): array
    {
        return [
            'tags' => 'array',
        ];
    }

    /** Shared-world zones (campaign_id null) are archetypes and evolution's garden; the rest are one tale's own ground. */
    public function scopeShared($query)
    {
        return $query->whereNull('campaign_id');
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
