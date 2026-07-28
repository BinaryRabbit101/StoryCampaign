<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * How one zone holds one tale's character: a clamped score and the append-only
 * record of the resolved facts that moved it.
 *
 * Campaign-scoped like a grudge, and for the same reason — a zone in the shared
 * world may be walked by many tales, and what one of them wrecked there is not
 * something the next one arrives already answering for.
 */
#[Fillable(['campaign_id', 'zone_id', 'score', 'history'])]
class Standing extends Model
{
    protected function casts(): array
    {
        return [
            'score' => 'integer',
            'history' => 'array',
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
}
