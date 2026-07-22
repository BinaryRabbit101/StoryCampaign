<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable([
    'campaign_id', 'scene_id', 'number', 'status', 'situation', 'cards',
    'submission', 'resolution', 'branch_trigger', 'meters_snapshot',
    'submitted_at', 'resolved_at', 'narrated_at',
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

    public function isDueForResolution(): bool
    {
        if ($this->status !== self::STATUS_LOCKED || $this->submitted_at === null) {
            return false;
        }

        return $this->submitted_at
            ->addMinutes((int) config('game.turn_cadence_minutes'))
            ->isPast();
    }
}
