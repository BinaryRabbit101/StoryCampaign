<?php

namespace App\Game;

/**
 * Suggested hero names for the interview's name field. Engine-side and
 * curated, never an LLM call: the field must be filled the instant the page
 * renders. Names are deliberately setting-neutral — short given names that
 * sit as comfortably in a boomtown as on a derelict station — because the
 * suggestion is rolled before the player has said a word about who they are.
 */
class NameForge
{
    /** @var list<string> */
    private const NAMES = [
        'Wren', 'Ash', 'Rook', 'Sable', 'Juno', 'Marlow', 'Vesper', 'Calder',
        'Isla', 'Bram', 'Nyx', 'Orrin', 'Tamsin', 'Flint', 'Maren', 'Cass',
        'Ember', 'Soren', 'Petra', 'Gideon', 'Lark', 'Hollis', 'Ines', 'Dov',
        'Reyna', 'Silas', 'Odile', 'Corvin', 'Tess', 'Aldous', 'Mira', 'Enoch',
        'Sparrow', 'Ivo', 'Greta', 'Lazlo', 'Noor', 'Perrin', 'Sada', 'Kestrel',
        'Ondine', 'Rufus', 'Zora', 'Basil', 'Imre', 'Selah', 'Torvald', 'Yara',
    ];

    /**
     * A stable shuffle of the pool for one campaign: the first entry
     * pre-fills the field, the rest feed the reroll. Seeded by campaign id
     * so a reloaded page suggests the same name it did the first time —
     * a suggestion that changes under the player reads as the game
     * forgetting what it offered.
     *
     * @return list<string>
     */
    public static function pool(int $campaignId, int $count = 10): array
    {
        $names = self::NAMES;

        // Deterministic Fisher–Yates off a cheap seeded generator; mt_srand
        // would hijack global random state mid-request.
        $seed = crc32("name-forge:{$campaignId}");
        for ($i = count($names) - 1; $i > 0; $i--) {
            $seed = ($seed * 1103515245 + 12345) & 0x7FFFFFFF;
            $j = $seed % ($i + 1);
            [$names[$i], $names[$j]] = [$names[$j], $names[$i]];
        }

        return array_slice($names, 0, $count);
    }
}
