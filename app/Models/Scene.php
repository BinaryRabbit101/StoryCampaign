<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;

#[Fillable(['campaign_id', 'zone_id', 'title', 'description', 'status', 'state', 'from_scene_id', 'from_direction', 'grid_x', 'grid_y'])]
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

    /** The ways out the dresser gave this ground, headings and all. */
    public function exits(): HasMany
    {
        return $this->hasMany(SceneExit::class);
    }

    /** The scene walked out of to get here — the map's road behind. */
    public function cameFrom(): BelongsTo
    {
        return $this->belongsTo(self::class, 'from_scene_id');
    }

    /**
     * Dressed scenes own their features outright (scene-scoped copies drawn
     * from the zone's templates at creation); legacy scenes keep the old
     * overlay of every zone template.
     *
     * @return Collection<int, SceneFeature>
     */
    public function allFeatures()
    {
        $own = $this->features()->get();

        if ($this->state['dressed'] ?? false) {
            return $own;
        }

        return $own->concat($this->zone->features()->whereNull('scene_id')->get());
    }

    /** Features the player can currently see and act on. @return Collection<int, SceneFeature> */
    public function visibleFeatures()
    {
        return $this->allFeatures()->reject(
            fn (SceneFeature $f) => ($f->state['hidden'] ?? false) || ($f->state['destroyed'] ?? false),
        )->values();
    }

    /** @return Collection<int, Actor> */
    public function activeActors()
    {
        return $this->actors()->where('status', 'active')->get();
    }

    /** Active actors the player is aware of — a lurking ambusher is not among them. @return Collection<int, Actor> */
    public function visibleActors()
    {
        return $this->activeActors()->reject(fn (Actor $a) => $a->tags['lurking'] ?? false)->values();
    }
}
