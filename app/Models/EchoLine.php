<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One remembered line: something a finished tale of this player's already
 * preserved, surfacing inside the tale being written because a moment here
 * rhymed with the moment that preserved it.
 *
 * The name is `EchoLine` and the table is `echoes` because `Echo` is not a
 * class name PHP will accept — `echo` is a language construct, and `class Echo`
 * is a parse error before anything else gets a chance to be wrong about it.
 *
 * Narration colour and nothing else, forever. An echo never appears in a card,
 * an odds part, a board group, or a resolver path; it grants nothing, reveals
 * nothing, and moves no number. It is QUOTATION and never invention — the three
 * source columns resolve to the real memento or chapter the words came out of,
 * so nothing here can be a memory the player did not live. And it instantiates
 * NOTHING: a past companion's name may be spoken, but no actor, zone, item,
 * grudge, or feature ever crosses between campaigns through this row.
 *
 * The inertness is enforced by direction as well as by type: nothing under
 * app/Game may import this class (there is a test, the same sweep the shelf and
 * the queue live under). The engine detects the RHYME from facts it already has
 * and hands the pick outward to App\Services\Echoes.
 */
#[Fillable([
    'campaign_id', 'source_campaign_id', 'source_type', 'source_id',
    'rhyme', 'line', 'turn_id', 'chapter_id',
])]
class EchoLine extends Model
{
    protected $table = 'echoes';

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class);
    }

    /** The closed book these words came out of. */
    public function sourceCampaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class, 'source_campaign_id');
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
