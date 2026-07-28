<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A want belonging to somebody who is not the player: the small story a
 * non-hostile soul is already in the middle of when the tale walks past them.
 *
 * Mechanically this is a clock the engine hung on a person — segments, a fill,
 * a closed payoff — and it is read exactly the way `Clock` is: the row is the
 * single source both the card's forecast and the resolver's tick consult, so a
 * card promising "helps Aldan's search" is quoting the very row that will move.
 *
 * The one thing it has that a clock does not is `revealed`, and it is the whole
 * feature. Until a social or inspect beat lands on the person, this row is
 * engine state and nothing else: no card quotes it, no board group names it,
 * and the narrator is never told it exists. That is the hidden-is-hidden rule
 * applied to story rather than to ground.
 */
#[Fillable([
    'campaign_id', 'actor_id', 'actor_name', 'kind', 'segments', 'filled',
    'age', 'revealed', 'subject', 'status', 'history',
])]
class Thread extends Model
{
    protected function casts(): array
    {
        return [
            'revealed' => 'boolean',
            'subject' => 'array',
            'history' => 'array',
        ];
    }

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class);
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(Actor::class);
    }
}
