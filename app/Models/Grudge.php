<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An enemy who fled a resolved turn and is still out there. Campaign-scoped
 * (a grudge lives in one tale's private world), keyed by name (actor rows are
 * scene-scoped copies), and terminal once resolved — killed, kept, or
 * bargained with, they never return.
 */
#[Fillable(['campaign_id', 'actor_name', 'stats', 'tags', 'tier', 'history', 'heat', 'disposition', 'status', 'last_seen_chapter_id'])]
class Grudge extends Model
{
    protected function casts(): array
    {
        return [
            'stats' => 'array',
            'tags' => 'array',
            'history' => 'array',
        ];
    }

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class);
    }

    public function lastSeenChapter(): BelongsTo
    {
        return $this->belongsTo(Chapter::class, 'last_seen_chapter_id');
    }
}
