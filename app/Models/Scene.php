<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;

#[Fillable(['campaign_id', 'zone_id', 'title', 'description', 'status', 'state'])]
class Scene extends Model
{
    protected function casts(): array
    {
        return [
            'state' => 'array',
        ];
    }

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class);
    }

    public function zone(): BelongsTo
    {
        return $this->belongsTo(Zone::class);
    }

    public function features(): HasMany
    {
        return $this->hasMany(SceneFeature::class);
    }

    public function actors(): HasMany
    {
        return $this->hasMany(Actor::class);
    }

    /** @return Collection<int, SceneFeature> */
    public function allFeatures()
    {
        return $this->features()->get()
            ->concat($this->zone->features()->whereNull('scene_id')->get());
    }

    /** @return Collection<int, Actor> */
    public function activeActors()
    {
        return $this->actors()->where('status', 'active')->get();
    }
}
