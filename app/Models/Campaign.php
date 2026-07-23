<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable(['user_id', 'name', 'premise', 'tone', 'starting_zone_id', 'status', 'title', 'back_cover', 'ended_early', 'started_at', 'ended_at'])]
class Campaign extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return [
            'ended_early' => 'boolean',
            'started_at' => 'datetime',
            'ended_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function character(): HasOne
    {
        return $this->hasOne(Character::class);
    }

    public function scenes(): HasMany
    {
        return $this->hasMany(Scene::class);
    }

    public function activeScene(): HasOne
    {
        return $this->hasOne(Scene::class)->where('status', 'active')->latestOfMany();
    }

    public function turns(): HasMany
    {
        return $this->hasMany(Turn::class);
    }

    public function currentTurn(): HasOne
    {
        return $this->hasOne(Turn::class)->latestOfMany('number');
    }

    public function chapters(): HasMany
    {
        return $this->hasMany(Chapter::class)->orderBy('number');
    }

    public function interviewMessages(): HasMany
    {
        return $this->hasMany(InterviewMessage::class);
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    /**
     * The player-set stage as one prompt block — narration color and
     * direction only, never mechanics. Empty string when nothing was set.
     */
    public function stageBrief(): string
    {
        $lines = [];
        if ($this->premise !== null && $this->premise !== '') {
            $lines[] = "Premise and goal (the player decides when it is fulfilled): {$this->premise}";
        }
        if ($this->tone !== null && $this->tone !== '') {
            $lines[] = "Tone of the telling: {$this->tone}";
        }

        return implode("\n", $lines);
    }

    public function nextChapterNumber(): int
    {
        return ($this->chapters()->max('number') ?? 0) + 1;
    }

    public function nextTurnNumber(): int
    {
        return ($this->turns()->max('number') ?? 0) + 1;
    }
}
