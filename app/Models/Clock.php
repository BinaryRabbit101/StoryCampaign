<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An endeavor the player committed to: a multi-turn goal the engine offered,
 * the player spent a beat taking on, and ordinary qualifying beats fill.
 *
 * The row is the single source both the card's forecast and the resolver's
 * tick read — `advance_verbs` says which beats move it and `payoff` says what
 * happens when it is full, and neither is ever recomputed anywhere else. That
 * is the same rule the odds ladder lives by: a card promising "advances the
 * search of the long quay" is quoting the very list the tick will check.
 */
#[Fillable([
    'campaign_id', 'scene_id', 'kind', 'name', 'segments', 'filled',
    'advance_verbs', 'payoff', 'subject', 'portable', 'status',
])]
class Clock extends Model
{
    protected function casts(): array
    {
        return [
            'advance_verbs' => 'array',
            'subject' => 'array',
            'portable' => 'boolean',
        ];
    }

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class);
    }

    public function scene(): BelongsTo
    {
        return $this->belongsTo(Scene::class);
    }
}
