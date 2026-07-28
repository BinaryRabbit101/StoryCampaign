<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A keepsake left behind by a notable resolved moment: the thing that was in
 * their hand when they went down, a token off a rival whose score finally
 * closed, something small picked up off new country.
 *
 * Mechanically inert, forever. A memento is not an Item — it grants nothing,
 * occupies no hands (Hands is scene matter; this is memory), never appears in
 * a card, an odds part, or any resolver path, and nothing under app/Game may
 * so much as import this class. The engine detects the MOMENT and hands the
 * minting outward (App\Services\Mementos); the shelf only ever reads back
 * into the campaign page, the book, and the widget.
 *
 * Append-only: no updated_at, because the only sanctioned write after the
 * mint is the narrator's clamped rewording of name/line and the chapter stamp
 * that completes provenance.
 */
#[Fillable(['campaign_id', 'turn_id', 'chapter_id', 'trigger', 'subject', 'name', 'line'])]
class Memento extends Model
{
    public const UPDATED_AT = null;

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class);
    }

    public function turn(): BelongsTo
    {
        return $this->belongsTo(Turn::class);
    }

    public function chapter(): BelongsTo
    {
        return $this->belongsTo(Chapter::class);
    }
}
