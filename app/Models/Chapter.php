<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The per-turn narration store. Every narrated chapter is a durable record
 * from day one — this is the raw material of the end-of-campaign book.
 */
#[Fillable(['campaign_id', 'turn_id', 'number', 'kind', 'intent_line', 'body'])]
class Chapter extends Model
{
    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class);
    }

    public function turn(): BelongsTo
    {
        return $this->belongsTo(Turn::class);
    }
}
