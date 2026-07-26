<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable([
    'campaign_id', 'scene_id', 'number', 'status', 'situation', 'cards',
    'submission', 'resolution', 'branch_trigger', 'meters_snapshot',
    'submitted_at', 'resolved_at', 'narrated_at', 'rolls_seen_at',
])]
class Turn extends Model
{
    public const STATUS_AWAITING = 'awaiting_player';

    public const STATUS_LOCKED = 'locked';

    public const STATUS_RESOLVING = 'resolving';

    public const STATUS_COMPLETE = 'complete';

    public const STATUS_ABORTED = 'aborted';

    protected function casts(): array
    {
        return [
            'cards' => 'array',
            'submission' => 'array',
            'resolution' => 'array',
            'meters_snapshot' => 'array',
            'submitted_at' => 'datetime',
            'resolved_at' => 'datetime',
            'narrated_at' => 'datetime',
            'rolls_seen_at' => 'datetime',
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

    public function chapter(): HasOne
    {
        return $this->hasOne(Chapter::class);
    }

    public function isOpen(): bool
    {
        return $this->status === self::STATUS_AWAITING;
    }

    /**
     * A turn nobody is resolving any more. Submission resolves inline, so a
     * turn still locked minutes later means the request carrying it died —
     * the sweep is a recovery path, not the normal one, and the window keeps
     * it from racing a request that is merely slow.
     */
    public function isAbandoned(): bool
    {
        if ($this->status !== self::STATUS_LOCKED || $this->submitted_at === null) {
            return false;
        }

        return $this->submitted_at
            ->addMinutes((int) config('game.abandoned_turn_minutes'))
            ->isPast();
    }

    /**
     * Resolved, unnarrated, and past the point where "being written" is still
     * an honest description.
     *
     * Narration takes tens of seconds. Beyond the window the chapter is not
     * coming without help — the Claude call is failing and the sweep is
     * retrying it every minute — and the page owes the player that fact
     * rather than an animation that never ends.
     */
    public function narrationIsLate(): bool
    {
        if ($this->resolved_at === null || $this->narrated_at !== null) {
            return false;
        }

        return $this->resolved_at
            ->addMinutes((int) config('game.narration_late_minutes'))
            ->isPast();
    }
}
