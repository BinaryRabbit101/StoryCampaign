<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One piece of hearsay waiting to reach the character: something the evolver
 * or the forge really did off-screen, templated by the engine into a sentence
 * somebody could plausibly have passed on.
 *
 * Narration colour and nothing else, forever. A rumor never appears in a card,
 * an odds part, a board group, or a resolver path; it grants nothing, reveals
 * nothing, and moves no number — and specifically it may never expose a hidden
 * feature or a lurking actor in the CURRENT scene, because rumors are news
 * about elsewhere. The inertness is enforced by direction as well as by type:
 * nothing under app/Game may import this class (there is a test). The engine
 * detects the delivery MOMENT from facts it already has and hands the pick
 * outward to App\Services\Rumors.
 */
#[Fillable([
    'campaign_id', 'source', 'evolution_run_id', 'subject', 'subject_zone_id',
    'line', 'heard_turn_id', 'heard_chapter_id',
])]
class Rumor extends Model
{
    /** The evolver's nightly tending — new inhabitants, new ground, new things. */
    public const EVOLUTION = 'evolution';

    /** The frontier pre-forge: the road ahead has a name before anybody walks it. */
    public const FORGE = 'forge';

    /** An old score, developed off-screen. Word gets around. */
    public const GRUDGE = 'grudge';

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class);
    }

    public function subjectZone(): BelongsTo
    {
        return $this->belongsTo(Zone::class, 'subject_zone_id');
    }

    public function heardTurn(): BelongsTo
    {
        return $this->belongsTo(Turn::class, 'heard_turn_id');
    }

    public function heardChapter(): BelongsTo
    {
        return $this->belongsTo(Chapter::class, 'heard_chapter_id');
    }
}
