<?php

namespace App\Models;

use App\Game\StoryAspects;
use App\Game\WorldFlavor;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable(['user_id', 'name', 'premise', 'opening', 'tone', 'world_flavor', 'setting', 'genre', 'drive', 'tech_level', 'starting_zone_id', 'next_zone_id', 'pending_sheet', 'status', 'title', 'back_cover', 'ended_early', 'started_at', 'ended_at'])]
class Campaign extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return [
            'pending_sheet' => 'array',
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

    /** This tale's private world: zones forged for it at creation and at the frontier. */
    public function zones(): HasMany
    {
        return $this->hasMany(Zone::class);
    }

    /** The pre-forged zone waiting past the current one's frontier, if any. */
    public function nextZone(): BelongsTo
    {
        return $this->belongsTo(Zone::class, 'next_zone_id');
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
        if ($this->opening !== null && $this->opening !== '') {
            $lines[] = "How the tale opens (the player asked for this moment — the FIRST scene must be true to it, and it must not be replayed later): {$this->opening}";
        }
        $lines[] = StoryAspects::brief(StoryAspects::drives(), $this->drive, 'What drives this tale:');
        if ($this->tone !== null && $this->tone !== '') {
            $lines[] = "Tone of the telling: {$this->tone}";
        }

        return implode("\n", array_filter($lines));
    }

    /**
     * The land this tale is set in — rolled once and kept. Campaigns created
     * before the roll existed take one the first time it is asked for, so an
     * old save never inherits whatever setting the bible happens to cite.
     */
    public function worldFlavor(): string
    {
        if ($this->world_flavor === null || ! WorldFlavor::has($this->world_flavor)) {
            // A tale with ground already standing cannot be relocated
            // mid-story: its world was built from the harbor lineage, so it
            // keeps it. Only a campaign with no world yet gets a fresh roll,
            // and only ever inside its own genre.
            $this->forceFill([
                'world_flavor' => $this->zones()->exists() ? WorldFlavor::DEFAULT : WorldFlavor::roll(
                    pool: WorldFlavor::keysForGenre(StoryAspects::resolve(StoryAspects::genres(), $this->genre)),
                ),
            ])->save();
        }

        return $this->world_flavor;
    }

    /**
     * What the world IS, as one prompt block: the land, the genre it wears,
     * and how much magic or machinery runs in it. Every Claude call that
     * invents or narrates ground works inside this.
     *
     * A player who described the land in their own words gets those words —
     * they replace the catalog's brief rather than sitting beside it, because
     * two descriptions of one place is how a tale ends up narrated in a
     * country the player never asked for. The rolled flavor stays underneath
     * as the engine's cold-forge kit and is never named here.
     */
    public function worldBrief(): string
    {
        $setting = trim((string) $this->setting);

        return implode("\n", array_filter([
            $setting !== ''
                ? "This campaign's world is the one the player named: {$setting}\n"
                    .'That is the world. It outranks every other description of place, including anything the design bible uses as an example.'
                : WorldFlavor::brief($this->worldFlavor()),
            StoryAspects::brief(StoryAspects::genres(), $this->genre, 'Genre of this world:'),
            StoryAspects::brief(StoryAspects::techLevels(), $this->tech_level, 'Magic and machinery here:'),
        ]));
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
