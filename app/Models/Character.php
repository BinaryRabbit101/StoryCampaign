<?php

namespace App\Models;

use App\Game\Capability;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['campaign_id', 'name', 'description', 'meters', 'status', 'meters_regenerated_at'])]
class Character extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return [
            'meters' => 'array',
            'meters_regenerated_at' => 'datetime',
        ];
    }

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class);
    }

    public function capabilities(): HasMany
    {
        return $this->hasMany(CharacterCapability::class);
    }

    public function constraints(): HasMany
    {
        return $this->hasMany(CharacterConstraint::class);
    }

    public function items(): BelongsToMany
    {
        return $this->belongsToMany(Item::class, 'character_items')
            ->withPivot(['equipped', 'charges'])
            ->withTimestamps();
    }

    /**
     * Effective capability map: innate capabilities merged with grants from
     * equipped items. Item grants never exceed innate magnitude clamps —
     * the clamping happened when the grant was recorded.
     *
     * @return array<string, CharacterCapability|object>
     */
    public function effectiveCapabilities(): array
    {
        $map = [];

        foreach ($this->capabilities as $capability) {
            $map[$capability->capability] = $capability;
        }

        foreach ($this->items as $item) {
            if (! $item->pivot->equipped) {
                continue;
            }
            foreach ($item->grants ?? [] as $grant) {
                $name = $grant['capability'] ?? null;
                if ($name === null) {
                    continue;
                }
                $existing = $map[$name] ?? null;
                $magnitude = $grant['magnitude'] ?? null;
                if ($existing === null || ($magnitude !== null && $magnitude > ($existing->magnitude ?? 0))) {
                    $map[$name] = (object) [
                        'capability' => $name,
                        'magnitude' => $magnitude,
                        'grade' => $grant['grade'] ?? null,
                        'scope' => $grant['scope'] ?? null,
                        'source' => 'item',
                    ];
                }
            }
        }

        return $map;
    }

    public function hasCapability(Capability $capability): bool
    {
        return array_key_exists($capability->value, $this->effectiveCapabilities());
    }

    public function magnitudeOf(Capability $capability): ?int
    {
        $entry = $this->effectiveCapabilities()[$capability->value] ?? null;

        return $entry?->magnitude;
    }
}
