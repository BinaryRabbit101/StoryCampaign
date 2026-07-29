<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['campaign_id', 'kind', 'role', 'body', 'suggestions', 'granted', 'changes'])]
class InterviewMessage extends Model
{
    /**
     * What an offered answer would do to the sheet, in the narrator's own words
     * about its own suggestion.
     *
     * This is a LABEL, not a price. The engine prices the sheet and nothing
     * else; the kind says which way an answer reaches so the chip can be tinted
     * before anybody has drafted anything from it. `both` is the common case
     * for a well-written answer — a gift and the price it drags, in one breath —
     * and `neutral` is the honest default for anything unlabelled, including
     * every suggestion written before this column carried kinds at all.
     */
    public const KINDS = ['gift', 'price', 'both', 'neutral'];

    protected function casts(): array
    {
        return [
            'granted' => 'boolean',
            'changes' => 'array',
        ];
    }

    /**
     * Suggestions, always as `{text, kind}` however they were stored.
     *
     * The column began life holding plain strings and there are real rows in
     * every campaign that still do. Normalizing at the column rather than at
     * each reader means one shape reaches the pages, the tints, and the tests —
     * a backfill migration would have had to guess a kind for prose nobody
     * labelled, and guessing is exactly what the kind exists to avoid.
     */
    protected function suggestions(): Attribute
    {
        return Attribute::make(
            get: fn (?string $value) => self::normalizeSuggestions(
                $value === null ? null : json_decode($value, true),
            ),
            set: fn (mixed $value) => ($clean = self::normalizeSuggestions($value)) === null
                ? null
                : json_encode($clean),
        );
    }

    /**
     * @return list<array{text: string, kind: string}>|null
     */
    public static function normalizeSuggestions(mixed $raw): ?array
    {
        $clean = [];

        foreach (is_array($raw) ? $raw : [] as $entry) {
            $text = trim((string) (is_array($entry) ? ($entry['text'] ?? '') : $entry));

            if ($text === '') {
                continue;
            }

            $kind = is_array($entry) ? (string) ($entry['kind'] ?? 'neutral') : 'neutral';

            $clean[] = [
                'text' => $text,
                'kind' => in_array($kind, self::KINDS, true) ? $kind : 'neutral',
            ];
        }

        return $clean === [] ? null : $clean;
    }

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class);
    }
}
