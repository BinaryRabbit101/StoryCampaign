<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One line of the growth ledger: points the tale paid out for a moment it
 * fixed, or points the sheet spent becoming something else.
 *
 * Append-only, and the balance is DERIVED from these rows rather than stored
 * anywhere — a cached total is a second authority on the same fact, and the
 * first thing it does is disagree with the rows underneath it.
 *
 * The engine detects the MOMENT and hands the minting outward, exactly as it
 * does for the shelf: nothing under app/Game may name this class, which is
 * what keeps a currency out of the cards, the odds, and the dice. What is
 * priced against it — a capability — is priced by App\Game\TraitCatalog, in
 * the same coin creation was.
 */
#[Fillable(['campaign_id', 'character_id', 'turn_id', 'kind', 'points', 'event', 'label'])]
class GrowthEntry extends Model
{
    protected $table = 'growth_ledger';

    public const EARN = 'earn';

    public const SPEND = 'spend';

    protected function casts(): array
    {
        return ['points' => 'integer'];
    }

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class);
    }

    public function character(): BelongsTo
    {
        return $this->belongsTo(Character::class);
    }

    public function turn(): BelongsTo
    {
        return $this->belongsTo(Turn::class);
    }
}
