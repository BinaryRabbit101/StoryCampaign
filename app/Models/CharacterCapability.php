<?php

namespace App\Models;

use App\Game\Capability;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['character_id', 'capability', 'magnitude', 'grade', 'scope', 'source'])]
class CharacterCapability extends Model
{
    protected function casts(): array
    {
        return [
            'scope' => 'array',
        ];
    }

    public function character(): BelongsTo
    {
        return $this->belongsTo(Character::class);
    }

    public function asEnum(): ?Capability
    {
        return Capability::tryFrom($this->capability);
    }
}
