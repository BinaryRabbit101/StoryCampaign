<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One way out of one scene, with a heading on it.
 *
 * Minted by the dresser when the ground is dressed, walked at most once:
 * to_scene_id null is an unwalked way (offered as a cross card and drawn as a
 * stub on the map), and set the moment a transition goes through it. The
 * locale it carries is the destination ground it will become — which is what
 * makes choosing north over east a real choice rather than a reskin of the
 * same random draw.
 */
#[Fillable(['scene_id', 'direction', 'label', 'locale', 'to_scene_id'])]
class SceneExit extends Model
{
    protected function casts(): array
    {
        return [
            'locale' => 'array',
        ];
    }

    public function scene(): BelongsTo
    {
        return $this->belongsTo(Scene::class);
    }

    public function destination(): BelongsTo
    {
        return $this->belongsTo(Scene::class, 'to_scene_id');
    }
}
