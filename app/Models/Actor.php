<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['scene_id', 'zone_id', 'name', 'kind', 'tier', 'stats', 'tags', 'status', 'source', 'evolution_run_id'])]
class Actor extends Model
{
    protected function casts(): array
    {
        return [
            'stats' => 'array',
            'tags' => 'array',
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
